<?php
/**
 * Payment processing.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\Api\Client;
use ParadoxSolutions\CardPointe\Api\RequestBuilder;
use ParadoxSolutions\CardPointe\Api\Response;
use ParadoxSolutions\CardPointe\Compatibility;
use ParadoxSolutions\CardPointe\Logging\Logger;
use ParadoxSolutions\CardPointe\Settings\Credentials;
use ParadoxSolutions\CardPointe\Tokens\ProfileService;
use ParadoxSolutions\CardPointe\Tokens\TokenManager;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a WooCommerce order plus a PaymentSource into CardPointe transactions.
 *
 * Handles checkout charges and authorizations, $0 orders, pre-order vaulting,
 * subscription payment-method changes, merchant-initiated charges, and the
 * timeout reconciliation that prevents double charges.
 */
final class PaymentProcessor {

	/** @var AbstractGateway */
	private $gateway;

	/** @var Client */
	private $client;

	/** @var Credentials */
	private $credentials;

	/** @var Logger */
	private $logger;

	/**
	 * @param AbstractGateway $gateway Gateway handling the payment.
	 */
	public function __construct( AbstractGateway $gateway ) {
		$this->gateway     = $gateway;
		$this->credentials = $gateway->credentials();
		$this->client      = $gateway->client();
		$this->logger      = $gateway->logger();
	}

	/* ---------------------------------------------------------------------
	 * Entry point
	 * ------------------------------------------------------------------ */

	/**
	 * Processes a checkout / order-pay / subscription change request.
	 *
	 * @param \WC_Order     $order  Order (or WC_Subscription for payment method changes).
	 * @param PaymentSource $source Source.
	 * @return array WooCommerce result array.
	 *
	 * @throws \Exception With a customer-facing message on failure.
	 */
	public function process( \WC_Order $order, PaymentSource $source ): array {
		if ( Compatibility::has_subscriptions() && wcs_is_subscription( $order ) ) {
			return $this->process_subscription_change( $order, $source );
		}

		$this->enforce_card_type( $source );

		if ( Compatibility::has_pre_orders()
			&& \WC_Pre_Orders_Order::order_contains_pre_order( $order )
			&& \WC_Pre_Orders_Order::order_requires_payment_tokenization( $order ) ) {
			return $this->process_pre_order( $order, $source );
		}

		if ( (float) $order->get_total() <= 0 ) {
			return $this->process_zero_total( $order, $source );
		}

		return $this->process_charge( $order, $source );
	}

	/* ---------------------------------------------------------------------
	 * Checkout flows
	 * ------------------------------------------------------------------ */

	/**
	 * Regular charge or authorization.
	 *
	 * @param \WC_Order     $order  Order.
	 * @param PaymentSource $source Source.
	 */
	private function process_charge( \WC_Order $order, PaymentSource $source ): array {
		$contains_subscription = $this->order_contains_subscription( $order );
		$needs_vault           = $source->save || $contains_subscription;
		$user_id               = (int) $order->get_user_id();
		$existing_profile      = '';
		$create_profile        = false;

		if ( $needs_vault && ! $source->is_saved() ) {
			$existing_profile = $user_id ? ProfileService::get_user_profile_id( $user_id, $this->credentials ) : '';
			$create_profile   = '' === $existing_profile;
		}

		$capture = $this->gateway->should_capture();
		$opts    = array(
			'capture'        => $capture,
			'orderid'        => RequestBuilder::orderid( $order->get_id(), '', $order ),
			'ecomind'        => 'E',
			'cof'            => ( $source->is_saved() || $needs_vault ) ? 'C' : null,
			'cofscheduled'   => ( $source->is_saved() || $needs_vault ) ? ( $contains_subscription ? 'Y' : 'N' ) : null,
			'create_profile' => $create_profile,
			'receipt'        => $this->gateway->receipt_enabled(),
			'level2'         => $this->gateway instanceof CardGateway && $this->gateway->level2_enabled(),
			'sec_mode'       => $this->gateway instanceof EcheckGateway ? $this->gateway->sec_mode() : 'both',
			'ach_entry'      => 'WEB',
			'context'        => 'checkout',
		);

		$body     = RequestBuilder::auth_for_order( $order, $source, $opts );
		$response = $this->authorize_with_reconciliation( $order, $body, 'checkout' );

		$this->record_approval( $order, $response, $source, $capture );

		if ( $needs_vault ) {
			$this->vault_after_auth( $order, $source, $response, $existing_profile );
		}

		/**
		 * Fires after an approved payment.
		 *
		 * @param \WC_Order     $order    Order.
		 * @param Response|null $response Gateway response.
		 * @param string        $context  Context.
		 * @param PaymentSource $source   Source.
		 */
		do_action( 'paradox_cardpointe_payment_approved', $order, $response, 'checkout', $source );

		$this->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->gateway->get_return_url( $order ),
		);
	}

	/**
	 * $0 order (free trial, fully discounted): verify and vault, no charge.
	 *
	 * @param \WC_Order     $order  Order.
	 * @param PaymentSource $source Source.
	 */
	private function process_zero_total( \WC_Order $order, PaymentSource $source ): array {
		$contains_subscription = $this->order_contains_subscription( $order );

		if ( ! $source->is_saved() ) {
			$this->verify_and_vault(
				(int) $order->get_user_id(),
				$source,
				RequestBuilder::billing_from_order( $order ),
				array(
					'orderid'      => RequestBuilder::orderid( $order->get_id(), 'VER', $order ),
					'cofscheduled' => $contains_subscription ? 'Y' : 'N',
					'currency'     => $order->get_currency(),
					'context'      => 'zero_total',
				)
			);
		}

		$this->store_source_on_order( $order, $source );
		$order->add_order_note(
			sprintf(
				/* translators: %s: payment method description */
				__( 'CardPointe: no charge required. Payment method verified and vaulted (%s).', 'paradox-cardpointe-gateway' ),
				$this->describe_source( $source )
			)
		);
		$order->payment_complete();

		do_action( 'paradox_cardpointe_payment_approved', $order, null, 'zero_total', $source );

		$this->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->gateway->get_return_url( $order ),
		);
	}

	/**
	 * Pre-order charged upon release: verify and vault now, charge later.
	 *
	 * @param \WC_Order     $order  Order.
	 * @param PaymentSource $source Source.
	 */
	private function process_pre_order( \WC_Order $order, PaymentSource $source ): array {
		if ( ! $source->is_saved() ) {
			$this->verify_and_vault(
				(int) $order->get_user_id(),
				$source,
				RequestBuilder::billing_from_order( $order ),
				array(
					'orderid'      => RequestBuilder::orderid( $order->get_id(), 'PO', $order ),
					'cofscheduled' => 'N',
					'currency'     => $order->get_currency(),
					'context'      => 'pre_order',
				)
			);
		}

		$this->store_source_on_order( $order, $source );
		$order->add_order_note(
			sprintf(
				/* translators: %s: payment method description */
				__( 'CardPointe: payment method vaulted for pre-order (%s). It will be charged when the pre-order is released.', 'paradox-cardpointe-gateway' ),
				$this->describe_source( $source )
			)
		);
		$order->save();

		\WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );

		do_action( 'paradox_cardpointe_payment_approved', $order, null, 'pre_order', $source );

		$this->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->gateway->get_return_url( $order ),
		);
	}

	/**
	 * WooCommerce Subscriptions "change payment method": vault, no charge.
	 *
	 * @param \WC_Order     $subscription Subscription.
	 * @param PaymentSource $source       Source.
	 */
	private function process_subscription_change( \WC_Order $subscription, PaymentSource $source ): array {
		$this->enforce_card_type( $source );

		if ( ! $source->is_saved() ) {
			$this->verify_and_vault(
				(int) $subscription->get_user_id(),
				$source,
				RequestBuilder::billing_from_order( $subscription ),
				array(
					'orderid'      => RequestBuilder::orderid( $subscription->get_id(), 'PMC', $subscription ),
					'cofscheduled' => 'Y',
					'currency'     => $subscription->get_currency(),
					'context'      => 'subscription_change',
				)
			);
		}

		$this->store_source_on_order( $subscription, $source );
		$subscription->add_order_note(
			sprintf(
				/* translators: %s: payment method description */
				__( 'CardPointe payment method updated (%s).', 'paradox-cardpointe-gateway' ),
				$this->describe_source( $source )
			)
		);
		$subscription->save();

		return array(
			'result'   => 'success',
			'redirect' => $subscription->get_view_order_url(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Merchant-initiated charges (renewals, pre-order release)
	 * ------------------------------------------------------------------ */

	/**
	 * Charges a vaulted payment method for an order without customer interaction.
	 *
	 * @param \WC_Order     $order   Order to charge (renewal order or released pre-order).
	 * @param PaymentSource $source  Vaulted source.
	 * @param array         $opts    Options: cofscheduled (Y|N), suffix (orderid suffix), context, ach_entry.
	 *
	 * @throws PaymentException On failure (order is marked failed).
	 */
	public function charge_stored( \WC_Order $order, PaymentSource $source, array $opts = array() ): Response {
		$opts = array_merge(
			array(
				'cofscheduled' => 'Y',
				'suffix'       => 'MIT',
				'context'      => 'merchant_initiated',
				'ach_entry'    => 'WEB',
			),
			$opts
		);

		if ( ! $source->has_profile() && '' === $source->token ) {
			throw new PaymentException( __( 'No vaulted payment method is available for this order.', 'paradox-cardpointe-gateway' ) );
		}

		$body = RequestBuilder::auth_for_order(
			$order,
			$source,
			array(
				'capture'      => true,
				'orderid'      => RequestBuilder::orderid( $order->get_id(), $opts['suffix'], $order ),
				'ecomind'      => 'R',
				'cof'          => 'M',
				'cofscheduled' => $opts['cofscheduled'],
				'receipt'      => $this->gateway->receipt_enabled(),
				'level2'       => $this->gateway instanceof CardGateway && $this->gateway->level2_enabled(),
				'sec_mode'     => $this->gateway instanceof EcheckGateway ? $this->gateway->sec_mode() : 'both',
				'ach_entry'    => $opts['ach_entry'],
				'context'      => $opts['context'],
			)
		);

		$response = $this->authorize_with_reconciliation( $order, $body, $opts['context'] );
		$this->record_approval( $order, $response, $source, true );

		do_action( 'paradox_cardpointe_payment_approved', $order, $response, $opts['context'], $source );

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Authorization with reconciliation
	 * ------------------------------------------------------------------ */

	/**
	 * Sends an auth and resolves timeouts/retries through inquireByOrderid + voidByOrderId.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $body    Auth body.
	 * @param string    $context Context for logs.
	 *
	 * @throws PaymentException When the payment did not succeed (order marked failed).
	 */
	public function authorize_with_reconciliation( \WC_Order $order, array $body, string $context ): Response {
		$orderid = (string) ( $body['orderid'] ?? '' );

		OrderMeta::set( $order, OrderMeta::PENDING_ORDERID, $orderid );
		OrderMeta::set( $order, OrderMeta::ENVIRONMENT, $this->credentials->environment() );
		OrderMeta::set( $order, OrderMeta::MERCHANT_ID, $this->credentials->merchant_id );
		$order->save();

		$this->logger->info( 'Authorizing', array( 'order_id' => $order->get_id(), 'orderid' => $orderid, 'context' => $context, 'amount' => $body['amount'] ?? '' ) );

		try {
			$response = $this->client->auth( $body );
		} catch ( ApiException $e ) {
			if ( $e->is_timeout() ) {
				$recovered = $this->reconcile( $order, $orderid );
				if ( $recovered ) {
					return $recovered;
				}
				$this->fail( $order, __( 'CardPointe did not respond in time and no approved transaction was found for this order.', 'paradox-cardpointe-gateway' ), $context );
				throw new PaymentException( $e->customer_message() );
			}
			$this->fail( $order, $e->getMessage(), $context );
			throw new PaymentException( $e->customer_message() );
		}

		if ( $response->is_retry() ) {
			$recovered = $this->reconcile( $order, $orderid );
			if ( $recovered ) {
				return $recovered;
			}
			$this->fail( $order, sprintf( 'CardPointe asked to retry (%s) and no approved transaction was found.', $response->error_text() ), $context );
			throw new PaymentException( __( 'We could not confirm your payment. You have not been charged. Please try again.', 'paradox-cardpointe-gateway' ) );
		}

		if ( ! $response->is_approved() ) {
			$this->record_decline( $order, $response, $context );
			throw new PaymentException( $this->decline_message( $response ) );
		}

		OrderMeta::set( $order, OrderMeta::ORDERID, $orderid );

		return $response;
	}

	/**
	 * Looks for an approved transaction by order ID; voids anything found otherwise.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $orderid Gateway order ID.
	 * @return Response|null Approved transaction, or null when none.
	 */
	public function reconcile( \WC_Order $order, string $orderid ) {
		$this->logger->warning( 'Reconciling after timeout/retry', array( 'order_id' => $order->get_id(), 'orderid' => $orderid ) );

		try {
			$inquiry = $this->client->inquire_by_orderid( $orderid );
			foreach ( $inquiry->transactions() as $txn ) {
				if ( $txn->is_approved() && '' !== $txn->string( 'retref' ) ) {
					$this->logger->warning( 'Recovered approved transaction after timeout', array( 'order_id' => $order->get_id(), 'retref' => $txn->string( 'retref' ) ) );
					$order->add_order_note( __( 'CardPointe timed out but the transaction was found approved via inquireByOrderid.', 'paradox-cardpointe-gateway' ) );
					OrderMeta::set( $order, OrderMeta::ORDERID, $orderid );
					return $txn;
				}
			}
		} catch ( ApiException $e ) {
			$this->logger->error( 'inquireByOrderid failed during reconciliation', array( 'error' => $e->getMessage() ) );
		}

		try {
			$void = $this->client->void_by_orderid( $orderid );
			$this->logger->warning( 'voidByOrderId after timeout', array( 'orderid' => $orderid, 'respstat' => $void->respstat(), 'resptext' => $void->string( 'resptext' ) ) );
		} catch ( ApiException $e ) {
			$this->logger->error( 'voidByOrderId failed during reconciliation', array( 'error' => $e->getMessage() ) );
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Recording outcomes
	 * ------------------------------------------------------------------ */

	/**
	 * Stores meta, notes and status for an approved auth.
	 *
	 * @param \WC_Order     $order    Order.
	 * @param Response      $response Response.
	 * @param PaymentSource $source   Source.
	 * @param bool          $captured Whether funds were captured.
	 */
	public function record_approval( \WC_Order $order, Response $response, PaymentSource $source, bool $captured ) {
		OrderMeta::apply_auth_response( $order, $response, $source, $this->credentials, $captured );

		$amount = wc_price( (float) $response->string( 'amount', (string) $order->get_total() ), array( 'currency' => $order->get_currency() ) );
		$detail = sprintf(
			/* translators: 1: amount, 2: payment method description, 3: retref, 4: auth code, 5: AVS text, 6: CVV text */
			__( '%1$s via %2$s. Retref: %3$s. Auth code: %4$s. AVS: %5$s. CVV: %6$s.', 'paradox-cardpointe-gateway' ),
			wp_strip_all_tags( $amount ),
			$this->describe_source( $source ),
			$response->string( 'retref' ),
			$response->string( 'authcode', '-' ),
			OrderMeta::avs_text( $response->string( 'avsresp' ) ),
			OrderMeta::cvv_text( $response->string( 'cvvresp' ) )
		);

		if ( $this->credentials->sandbox ) {
			$detail .= ' ' . __( '[Sandbox]', 'paradox-cardpointe-gateway' );
		}

		if ( $captured ) {
			if ( $this->gateway instanceof EcheckGateway && 'on-hold' === $this->gateway->approved_status() ) {
				$order->update_status( 'on-hold', __( 'CardPointe eCheck accepted, awaiting funds:', 'paradox-cardpointe-gateway' ) . ' ' . $detail );
			} else {
				$order->add_order_note( __( 'CardPointe payment approved:', 'paradox-cardpointe-gateway' ) . ' ' . $detail );
				$order->payment_complete( $response->string( 'retref' ) );
			}
		} else {
			$order->update_status( 'on-hold', __( 'CardPointe authorization approved (not yet captured):', 'paradox-cardpointe-gateway' ) . ' ' . $detail . ' ' . __( 'Capture from the CardPointe panel or by changing the status to Processing.', 'paradox-cardpointe-gateway' ) );
		}

		$order->save();
		$this->logger->info( 'Payment approved', array( 'order_id' => $order->get_id(), 'retref' => $response->string( 'retref' ), 'captured' => $captured ) );
	}

	/**
	 * Marks an order failed after a decline.
	 *
	 * @param \WC_Order $order    Order.
	 * @param Response  $response Decline response.
	 * @param string    $context  Context.
	 */
	public function record_decline( \WC_Order $order, Response $response, string $context ) {
		$note = sprintf(
			/* translators: %s: gateway error text */
			__( 'CardPointe declined the payment: %s', 'paradox-cardpointe-gateway' ),
			$response->error_text()
		);
		OrderMeta::delete( $order, OrderMeta::PENDING_ORDERID );
		$order->update_status( 'failed', $note );
		$this->logger->warning( 'Payment declined', array( 'order_id' => $order->get_id(), 'context' => $context, 'respcode' => $response->string( 'respcode' ), 'resptext' => $response->string( 'resptext' ) ) );

		/**
		 * Fires after a declined or failed payment.
		 *
		 * @param \WC_Order     $order    Order.
		 * @param Response|null $response Response when available.
		 * @param string        $context  Context.
		 */
		do_action( 'paradox_cardpointe_payment_failed', $order, $response, $context );
	}

	/**
	 * Marks an order failed after a transport/API error.
	 *
	 * @param \WC_Order $order   Order.
	 * @param string    $reason  Reason for the note.
	 * @param string    $context Context.
	 */
	private function fail( \WC_Order $order, string $reason, string $context ) {
		OrderMeta::delete( $order, OrderMeta::PENDING_ORDERID );
		$order->update_status( 'failed', __( 'CardPointe payment failed:', 'paradox-cardpointe-gateway' ) . ' ' . $reason );
		$this->logger->error( 'Payment failed', array( 'order_id' => $order->get_id(), 'context' => $context, 'reason' => $reason ) );
		do_action( 'paradox_cardpointe_payment_failed', $order, null, $context );
	}

	/**
	 * Customer-facing decline text.
	 *
	 * @param Response $response Response.
	 */
	private function decline_message( Response $response ): string {
		$text = $response->string( 'resptext' );
		$text = '' !== $text ? $text : __( 'the transaction was declined', 'paradox-cardpointe-gateway' );
		return sprintf(
			/* translators: %s: decline reason */
			__( 'Your payment was not approved (%s). Please check your details or try another payment method.', 'paradox-cardpointe-gateway' ),
			$text
		);
	}

	/* ---------------------------------------------------------------------
	 * Vaulting
	 * ------------------------------------------------------------------ */

	/**
	 * Verifies a new payment method and stores it in the CardPointe vault (and as a WC token).
	 *
	 * Cards use a $0 authorization; ACH uses PUT /profile directly. Mutates $source.
	 *
	 * @param int           $user_id Customer ID (0 for guests: vaulted on the order only).
	 * @param PaymentSource $source  New source.
	 * @param array         $billing Billing fields.
	 * @param array         $opts    orderid, cofscheduled, currency, context.
	 * @return Response|null The verification response, when one was made.
	 *
	 * @throws PaymentException On failure.
	 */
	public function verify_and_vault( int $user_id, PaymentSource $source, array $billing, array $opts ): ?Response {
		$existing = $user_id ? ProfileService::get_user_profile_id( $user_id, $this->credentials ) : '';
		$profiles = new ProfileService( $this->client );
		$response = null;

		if ( 'card' === $source->type ) {
			$body = RequestBuilder::zero_auth(
				$source,
				$billing,
				array(
					'orderid'        => $opts['orderid'] ?? RequestBuilder::orderid( $user_id, 'VER' ),
					'create_profile' => '' === $existing,
					'cofscheduled'   => $opts['cofscheduled'] ?? 'N',
					'currency'       => $opts['currency'] ?? get_woocommerce_currency(),
					'context'        => $opts['context'] ?? 'verify',
				)
			);

			try {
				$response = $this->client->auth( $body );
			} catch ( ApiException $e ) {
				throw new PaymentException( $e->customer_message() );
			}

			if ( ! $response->is_approved() ) {
				if ( $this->is_zero_auth_unsupported( $response ) ) {
					$this->logger->warning( '$0 authorization not supported by MID, vaulting via profile', array( 'resptext' => $response->string( 'resptext' ) ) );
					$response = null;
				} else {
					$this->logger->warning( 'Card verification declined', array( 'user_id' => $user_id, 'respcode' => $response->string( 'respcode' ), 'resptext' => $response->string( 'resptext' ) ) );
					throw new PaymentException( $this->decline_message( $response ) );
				}
			}
		}

		$profile_id = $response ? $response->string( 'profileid' ) : '';
		$acct_id    = $response ? $response->string( 'acctid' ) : '';

		if ( '' === $profile_id ) {
			list( $profile_id, $acct_id ) = $profiles->add_account( $source, $billing, $existing );
		}

		$source->profile_id = $profile_id;
		$source->acct_id    = $acct_id;

		if ( $user_id ) {
			if ( '' !== $profile_id ) {
				ProfileService::set_user_profile_id( $user_id, $this->credentials, $profile_id );
			}
			if ( $this->gateway->saved_methods_enabled() ) {
				$source->wc_token = TokenManager::save_source( $user_id, $this->gateway->id, $source, $this->credentials );
			}
		}

		return $response;
	}

	/**
	 * After an approved checkout auth that asked for profile creation, finish vaulting.
	 *
	 * @param \WC_Order     $order            Order.
	 * @param PaymentSource $source           Source (mutated).
	 * @param Response      $response         Approved auth response.
	 * @param string        $existing_profile Existing profile ID for the user, if any.
	 */
	private function vault_after_auth( \WC_Order $order, PaymentSource $source, Response $response, string $existing_profile ) {
		$user_id = (int) $order->get_user_id();
		if ( ! $user_id || $source->is_saved() ) {
			return;
		}

		$profile_id = $response->string( 'profileid' );
		$acct_id    = $response->string( 'acctid' );

		try {
			if ( '' === $profile_id ) {
				$profiles = new ProfileService( $this->client );
				list( $profile_id, $acct_id ) = $profiles->add_account( $source, RequestBuilder::billing_from_order( $order ), $existing_profile );
			}
		} catch ( \Exception $e ) {
			// The payment already succeeded; a vaulting problem must not fail the order.
			$this->logger->error( 'Could not add account to profile after payment', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) );
			$order->add_order_note( __( 'CardPointe: the payment method could not be added to the customer profile; the token will be used instead.', 'paradox-cardpointe-gateway' ) );
		}

		$source->profile_id = $profile_id;
		$source->acct_id    = $acct_id;
		if ( '' !== $profile_id ) {
			ProfileService::set_user_profile_id( $user_id, $this->credentials, $profile_id );
		}

		if ( $this->gateway->saved_methods_enabled() ) {
			$source->wc_token = TokenManager::save_source( $user_id, $this->gateway->id, $source, $this->credentials );
		}

		$this->store_source_on_order( $order, $source );
		$order->save();
	}

	/**
	 * Whether a declined $0 auth means the MID does not allow account verification.
	 *
	 * @param Response $response Response.
	 */
	private function is_zero_auth_unsupported( Response $response ): bool {
		$text = strtolower( $response->string( 'resptext' ) );
		return (bool) preg_match( '/zero|\$0|invalid amount|amount not allowed|not (?:enabled|allowed)/', $text );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Rejects unsupported card brands before any charge (cards only, new tokens and saved tokens).
	 *
	 * @param PaymentSource $source Source.
	 *
	 * @throws PaymentException When the brand is not accepted.
	 */
	public function enforce_card_type( PaymentSource $source ) {
		if ( 'card' !== $source->type || ! $this->gateway instanceof CardGateway ) {
			return;
		}
		$accepted = $this->gateway->accepted_card_types();
		if ( empty( $accepted ) ) {
			return;
		}

		if ( $source->is_saved() ) {
			$brand = $source->brand;
		} else {
			$brand = CardTypes::resolve( $source->token, $this->gateway->bin_enforcement() ? $this->client : null );
			if ( null === $brand ) {
				$brand = $source->brand;
			}
			$source->brand = (string) $brand;
		}

		if ( '' !== (string) $brand && ! in_array( $brand, $accepted, true ) ) {
			throw new PaymentException(
				sprintf(
					/* translators: %s: card brand */
					__( '%s cards are not accepted. Please use a different card.', 'paradox-cardpointe-gateway' ),
					CardTypes::label( (string) $brand )
				)
			);
		}
	}

	/**
	 * Stores the vaulted source details on an order or subscription (without saving).
	 *
	 * @param \WC_Abstract_Order $order  Order.
	 * @param PaymentSource      $source Source.
	 */
	public function store_source_on_order( \WC_Abstract_Order $order, PaymentSource $source ) {
		OrderMeta::set_stored_payment_method(
			$order,
			array(
				'type'        => $source->type,
				'profile_id'  => $source->profile_id,
				'acct_id'     => $source->acct_id,
				'token'       => $source->token,
				'expiry'      => $source->expiry,
				'accttype'    => $source->accttype,
				'token_id'    => $source->wc_token ? $source->wc_token->get_id() : 0,
				'environment' => $this->credentials->environment(),
				'brand'       => $source->brand,
				'last4'       => $source->last4(),
			)
		);
		OrderMeta::set( $order, OrderMeta::MERCHANT_ID, $this->credentials->merchant_id );
	}

	/**
	 * Short description of a source for notes.
	 *
	 * @param PaymentSource $source Source.
	 */
	public function describe_source( PaymentSource $source ): string {
		if ( 'echeck' === $source->type ) {
			$type = 'ESAV' === $source->accttype ? __( 'savings account', 'paradox-cardpointe-gateway' ) : __( 'checking account', 'paradox-cardpointe-gateway' );
			/* translators: 1: account type, 2: last four digits */
			return sprintf( __( '%1$s ending in %2$s', 'paradox-cardpointe-gateway' ), $type, $source->last4() );
		}
		$brand = '' !== $source->brand ? CardTypes::label( $source->brand ) : __( 'card', 'paradox-cardpointe-gateway' );
		/* translators: 1: card brand, 2: last four digits */
		return sprintf( __( '%1$s ending in %2$s', 'paradox-cardpointe-gateway' ), $brand, $source->last4() );
	}

	/**
	 * Whether the order contains a subscription (initial purchase).
	 *
	 * @param \WC_Order $order Order.
	 */
	private function order_contains_subscription( \WC_Order $order ): bool {
		return Compatibility::has_subscriptions() && function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'any' );
	}

	/**
	 * Empties the cart when one exists (not on order-pay pages).
	 */
	private function empty_cart() {
		if ( function_exists( 'WC' ) && WC()->cart && ! is_checkout_pay_page() ) {
			WC()->cart->empty_cart();
		}
	}
}
