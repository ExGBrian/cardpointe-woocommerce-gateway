<?php
/**
 * Post-authorization transaction management.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\Api\Client;
use ParadoxSolutions\CardPointe\Api\RequestBuilder;
use ParadoxSolutions\CardPointe\Api\Response;
use ParadoxSolutions\CardPointe\Logging\Logger;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Capture, void, refund and inquire for existing orders.
 */
final class TransactionManager {

	/** @var AbstractGateway|null */
	private $gateway;

	/** @var Credentials */
	private $credentials;

	/** @var Client */
	private $client;

	/** @var Logger */
	private $logger;

	/**
	 * @param AbstractGateway|null $gateway Gateway; defaults to the active credentials.
	 */
	public function __construct( ?AbstractGateway $gateway = null ) {
		$this->gateway     = $gateway;
		$this->credentials = $gateway ? $gateway->credentials() : Credentials::active();
		$this->client      = Plugin::instance()->client( $this->credentials );
		$this->logger      = Plugin::instance()->logger();
	}

	/**
	 * Captures an authorized order.
	 *
	 * @param \WC_Order  $order  Order.
	 * @param float|null $amount Amount; null captures the full authorization.
	 *
	 * @throws \Exception On failure (message suitable for admins).
	 */
	public function capture( \WC_Order $order, ?float $amount = null ): Response {
		$retref = $this->guard( $order );

		if ( OrderMeta::is_captured( $order ) ) {
			throw new \Exception( __( 'This order has already been captured.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( OrderMeta::is_voided( $order ) ) {
			throw new \Exception( __( 'This authorization was voided and cannot be captured.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$authorized = (float) OrderMeta::get( $order, OrderMeta::AMOUNT_AUTHORIZED, $order->get_total() );
		if ( null !== $amount && $amount <= 0 ) {
			throw new \Exception( __( 'Enter an amount greater than zero.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		/**
		 * Filters whether captures above the authorized amount are allowed (requires MID entitlement).
		 *
		 * @param bool      $allow Default false.
		 * @param \WC_Order $order Order.
		 */
		$allow_over = apply_filters( 'paradox_cardpointe_allow_over_capture', false, $order );
		if ( null !== $amount && ! $allow_over && $amount > $authorized + 0.005 ) {
			throw new \Exception( __( 'The capture amount cannot exceed the authorized amount.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$extra = array();
		if ( $this->gateway && $this->gateway->receipt_enabled() ) {
			$extra['receipt'] = 'Y';
		}
		$authcode = (string) OrderMeta::get( $order, OrderMeta::AUTHCODE );
		if ( '' !== $authcode ) {
			$extra['authcode'] = $authcode;
		}
		/**
		 * Filters the capture request extras.
		 *
		 * @param array     $extra Extra fields.
		 * @param \WC_Order $order Order.
		 */
		$extra = apply_filters( 'paradox_cardpointe_capture_request_args', $extra, $order );

		$formatted = null !== $amount ? RequestBuilder::amount( $amount ) : null;

		try {
			$response = $this->client->capture( $retref, $formatted, $extra );
		} catch ( ApiException $e ) {
			$order->add_order_note( __( 'CardPointe capture failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
			throw new \Exception( $e->getMessage() );
		}

		$setlstat = $response->setlstat();
		$approved = $response->is_approved() || in_array( $setlstat, array( 'Queued for Capture', 'Settled' ), true );

		if ( ! $approved ) {
			$order->add_order_note( __( 'CardPointe capture declined:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $response->error_text() . ( '' !== $setlstat ? ' [' . $setlstat . ']' : '' ) );
			$order->save();
			throw new \Exception( $response->error_text() );
		}

		$captured_amount = $response->string( 'amount' ) ?: ( $formatted ?: RequestBuilder::amount( $authorized ) );

		OrderMeta::set( $order, OrderMeta::CAPTURED, 'yes' );
		OrderMeta::set( $order, OrderMeta::CAPTURED_AMOUNT, $captured_amount );
		if ( '' !== $setlstat ) {
			OrderMeta::set( $order, OrderMeta::SETLSTAT, $setlstat );
		}
		$receipt = $response->receipt();
		if ( null !== $receipt ) {
			OrderMeta::set( $order, OrderMeta::RECEIPT, $receipt );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: retref */
				__( 'CardPointe captured %1$s (retref %2$s).', 'paradox-cardpointe-gateway-for-woocommerce' ),
				wp_strip_all_tags( wc_price( (float) $captured_amount, array( 'currency' => $order->get_currency() ) ) ),
				$retref
			)
		);

		if ( $order->has_status( array( 'on-hold', 'pending', 'failed' ) ) ) {
			$order->payment_complete( $retref );
		} elseif ( ! $order->get_date_paid( 'edit' ) ) {
			$order->set_date_paid( time() );
		}
		$order->save();

		$this->logger->info( 'Captured', array( 'order_id' => $order->get_id(), 'retref' => $retref, 'amount' => $captured_amount ) );
		do_action( 'paradox_cardpointe_captured', $order, $response );

		return $response;
	}

	/**
	 * Voids an authorized or queued-for-capture order.
	 *
	 * @param \WC_Order  $order  Order.
	 * @param float|null $amount Partial amount (only valid while Authorized); null voids everything.
	 *
	 * @throws \Exception On failure.
	 */
	public function void( \WC_Order $order, ?float $amount = null ): Response {
		$retref = $this->guard( $order );

		if ( OrderMeta::is_voided( $order ) ) {
			throw new \Exception( __( 'This transaction has already been voided.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		try {
			$response = $this->client->void( $retref, null !== $amount ? RequestBuilder::amount( $amount ) : null );
		} catch ( ApiException $e ) {
			$order->add_order_note( __( 'CardPointe void failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
			throw new \Exception( $e->getMessage() );
		}

		if ( ! $response->is_approved() && 'REVERS' !== strtoupper( $response->string( 'authcode' ) ) ) {
			$order->add_order_note( __( 'CardPointe void declined:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $response->error_text() );
			$order->save();
			throw new \Exception( $response->error_text() );
		}

		$voided_amount = $response->string( 'amount' ) ?: ( null !== $amount ? RequestBuilder::amount( $amount ) : (string) OrderMeta::get( $order, OrderMeta::AMOUNT_AUTHORIZED, $order->get_total() ) );

		if ( null === $amount ) {
			OrderMeta::set( $order, OrderMeta::VOIDED, 'yes' );
			OrderMeta::set( $order, OrderMeta::SETLSTAT, 'Voided' );
		}
		OrderMeta::add_refund_record(
			$order,
			array(
				'type'   => 'void',
				'retref' => $response->string( 'retref', $retref ),
				'amount' => $voided_amount,
			)
		);
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: retref */
				__( 'CardPointe voided %1$s (retref %2$s).', 'paradox-cardpointe-gateway-for-woocommerce' ),
				wp_strip_all_tags( wc_price( (float) $voided_amount, array( 'currency' => $order->get_currency() ) ) ),
				$retref
			)
		);
		$order->save();

		$this->logger->info( 'Voided', array( 'order_id' => $order->get_id(), 'retref' => $retref, 'amount' => $voided_amount ) );
		do_action( 'paradox_cardpointe_voided', $order, $response );

		return $response;
	}

	/**
	 * Refund handler for WooCommerce's process_refund().
	 *
	 * Voids when the transaction is still unsettled and the full amount is being returned;
	 * otherwise issues a refund against the retref.
	 *
	 * @param \WC_Order $order  Order.
	 * @param float     $amount Amount to return.
	 * @param string    $reason Reason.
	 * @return true|\WP_Error
	 */
	public function refund( \WC_Order $order, float $amount, string $reason = '' ) {
		try {
			$retref = $this->guard( $order );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'paradox_cardpointe_refund', $e->getMessage() );
		}

		if ( $amount <= 0 ) {
			return new \WP_Error( 'paradox_cardpointe_refund', __( 'Refund amount must be greater than zero.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$captured        = OrderMeta::is_captured( $order );
		$captured_amount = (float) OrderMeta::get( $order, OrderMeta::CAPTURED_AMOUNT, OrderMeta::get( $order, OrderMeta::AMOUNT_AUTHORIZED, $order->get_total() ) );
		$is_full         = empty( OrderMeta::refunds( $order ) ) && abs( $amount - $captured_amount ) < 0.005;

		$inquiry    = null;
		$setlstat   = '';
		$voidable   = null;
		$refundable = null;
		try {
			$inquiry    = $this->client->inquire( $retref );
			$setlstat   = $inquiry->setlstat();
			$voidable   = 'Y' === strtoupper( $inquiry->string( 'voidable' ) );
			$refundable = 'Y' === strtoupper( $inquiry->string( 'refundable' ) );
			if ( '' !== $setlstat ) {
				OrderMeta::set( $order, OrderMeta::SETLSTAT, $setlstat );
			}
		} catch ( ApiException $e ) {
			$this->logger->warning( 'Inquire failed before refund; proceeding on stored state', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) );
		}

		// 1. Authorization only: void everything or ask for a capture instead.
		if ( ! $captured || 'Authorized' === $setlstat ) {
			if ( $is_full ) {
				return $this->try_void_for_refund( $order );
			}
			return new \WP_Error(
				'paradox_cardpointe_refund',
				__( 'This order is authorized but not captured. Capture the reduced amount from the CardPointe panel instead, or void the full authorization.', 'paradox-cardpointe-gateway-for-woocommerce' )
			);
		}

		// 2. Captured but not yet settled.
		if ( 'Queued for Capture' === $setlstat ) {
			if ( $is_full && false !== $voidable ) {
				$result = $this->try_void_for_refund( $order );
				if ( true === $result || true !== $refundable ) {
					return $result;
				}
			}
			if ( true !== $refundable ) {
				return new \WP_Error(
					'paradox_cardpointe_refund',
					__( 'This transaction is captured but not yet settled, so a partial refund is not possible yet. Try again after settlement (usually the next business day), or refund the full amount to void it.', 'paradox-cardpointe-gateway-for-woocommerce' )
				);
			}
		}

		// 3. Settled (or unknown): refund by retref.
		if ( false === $refundable ) {
			if ( $is_full && true === $voidable ) {
				return $this->try_void_for_refund( $order );
			}
			return new \WP_Error(
				'paradox_cardpointe_refund',
				sprintf(
					/* translators: %s: settlement status */
					__( 'CardPointe reports this transaction is not refundable right now (status: %s). Please try again later or refund from the CardPointe portal.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'' !== $setlstat ? $setlstat : __( 'unknown', 'paradox-cardpointe-gateway-for-woocommerce' )
				)
			);
		}

		$orderid = RequestBuilder::orderid( $order->get_id(), 'R', $order );
		/**
		 * Filters the refund request arguments.
		 *
		 * @param array     $args  retref, amount, orderid.
		 * @param \WC_Order $order Order.
		 */
		$args = apply_filters(
			'paradox_cardpointe_refund_request_args',
			array(
				'retref'  => $retref,
				'amount'  => RequestBuilder::amount( $amount ),
				'orderid' => $orderid,
			),
			$order
		);

		try {
			$response = $this->client->refund( $args['retref'], $args['amount'], $args['orderid'] );
		} catch ( ApiException $e ) {
			$order->add_order_note( __( 'CardPointe refund failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
			return new \WP_Error( 'paradox_cardpointe_refund', $e->getMessage() );
		}

		if ( ! $response->is_approved() ) {
			$order->add_order_note( __( 'CardPointe refund declined:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $response->error_text() );
			$order->save();
			return new \WP_Error( 'paradox_cardpointe_refund', $response->error_text() );
		}

		OrderMeta::add_refund_record(
			$order,
			array(
				'type'    => 'refund',
				'retref'  => $response->string( 'retref' ),
				'amount'  => $args['amount'],
				'orderid' => $args['orderid'],
			)
		);
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: refund retref, 3: reason */
				__( 'CardPointe refunded %1$s (refund retref %2$s). %3$s', 'paradox-cardpointe-gateway-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ),
				$response->string( 'retref' ),
				'' !== $reason ? __( 'Reason:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $reason : ''
			)
		);
		$order->save();

		$this->logger->info( 'Refunded', array( 'order_id' => $order->get_id(), 'retref' => $retref, 'refund_retref' => $response->string( 'retref' ), 'amount' => $args['amount'] ) );
		do_action( 'paradox_cardpointe_refunded', $order, $response, $amount );

		return true;
	}

	/**
	 * Runs a full void as part of a WooCommerce refund.
	 *
	 * @param \WC_Order $order Order.
	 * @return true|\WP_Error
	 */
	private function try_void_for_refund( \WC_Order $order ) {
		try {
			$this->void( $order );
			return true;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'paradox_cardpointe_refund', $e->getMessage() );
		}
	}

	/**
	 * Fetches the latest transaction status and stores setlstat.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @throws \Exception On failure.
	 */
	public function inquire( \WC_Order $order ): Response {
		$retref = $this->guard( $order );
		try {
			$response = $this->client->inquire( $retref );
		} catch ( ApiException $e ) {
			throw new \Exception( $e->getMessage() );
		}
		$setlstat = $response->setlstat();
		if ( '' !== $setlstat ) {
			OrderMeta::set( $order, OrderMeta::SETLSTAT, $setlstat );
			if ( 'Voided' === $setlstat ) {
				OrderMeta::set( $order, OrderMeta::VOIDED, 'yes' );
			}
			$order->save();
		}
		return $response;
	}

	/**
	 * Resolves an order left with a pending order ID (e.g. PHP died mid-request).
	 *
	 * @param \WC_Order $order Order.
	 * @return Response|null Approved transaction when found.
	 *
	 * @throws \Exception On API failure.
	 */
	public function reconcile_pending( \WC_Order $order ) {
		$orderid = (string) OrderMeta::get( $order, OrderMeta::PENDING_ORDERID );
		if ( '' === $orderid ) {
			return null;
		}
		try {
			$inquiry = $this->client->inquire_by_orderid( $orderid );
		} catch ( ApiException $e ) {
			throw new \Exception( $e->getMessage() );
		}
		foreach ( $inquiry->transactions() as $txn ) {
			if ( $txn->is_approved() && '' !== $txn->string( 'retref' ) ) {
				$gateway = Plugin::gateway_for_order( $order );
				$source  = PaymentSource::from_stored( OrderMeta::stored_payment_method( $order ) );
				$capture = 'Queued for Capture' === $txn->setlstat() || 'Settled' === $txn->setlstat() || ( $gateway && $gateway->should_capture() );
				OrderMeta::apply_auth_response( $order, $txn, $source, $this->credentials, $capture );
				OrderMeta::set( $order, OrderMeta::ORDERID, $orderid );
				$order->add_order_note( __( 'CardPointe: pending transaction reconciled and found approved.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
				if ( $capture ) {
					$order->payment_complete( $txn->string( 'retref' ) );
				} else {
					$order->update_status( 'on-hold' );
				}
				$order->save();
				return $txn;
			}
		}
		OrderMeta::delete( $order, OrderMeta::PENDING_ORDERID );
		$order->add_order_note( __( 'CardPointe: no approved transaction found for the pending order ID.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		$order->save();
		return null;
	}

	/**
	 * Common checks; returns the retref.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @throws \Exception When the order cannot be managed.
	 */
	private function guard( \WC_Order $order ): string {
		if ( ! Plugin::is_our_gateway( $order->get_payment_method() ) ) {
			throw new \Exception( __( 'This order was not paid with CardPointe.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		$retref = OrderMeta::retref( $order );
		if ( '' === $retref ) {
			throw new \Exception( __( 'No CardPointe transaction reference is stored for this order.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( ! OrderMeta::matches_environment( $order, $this->credentials ) ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: environment */
					__( 'This order was processed in the %s environment, which is not the active one. Switch environments to manage it.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					(string) OrderMeta::get( $order, OrderMeta::ENVIRONMENT )
				)
			);
		}
		return $retref;
	}
}
