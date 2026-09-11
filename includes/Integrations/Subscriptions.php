<?php
/**
 * WooCommerce Subscriptions integration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Integrations;

use ParadoxSolutions\CardPointe\Gateway\CardTypes;
use ParadoxSolutions\CardPointe\Gateway\OrderMeta;
use ParadoxSolutions\CardPointe\Gateway\PaymentException;
use ParadoxSolutions\CardPointe\Gateway\PaymentProcessor;
use ParadoxSolutions\CardPointe\Gateway\PaymentSource;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal charges, payment meta for admins, and meta propagation to subscriptions.
 */
final class Subscriptions {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		foreach ( Plugin::gateway_ids() as $id ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $id, array( $this, 'scheduled_payment' ), 10, 2 );
			add_action( 'woocommerce_subscriptions_changed_failing_payment_method_' . $id, array( $this, 'changed_failing_payment_method' ), 10, 2 );
		}
		add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'payment_meta' ), 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_payment_meta' ), 10, 2 );
		add_action( 'paradox_cardpointe_payment_approved', array( $this, 'copy_to_subscriptions' ), 10, 4 );
		add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'display_payment_method' ), 10, 2 );
	}

	/**
	 * Charges a renewal order with the vaulted method.
	 *
	 * @param float     $amount        Amount to charge.
	 * @param \WC_Order $renewal_order Renewal order.
	 */
	public function scheduled_payment( $amount, $renewal_order ) {
		if ( ! $renewal_order instanceof \WC_Order ) {
			return;
		}
		$gateway = Plugin::gateway_for_order( $renewal_order );
		if ( ! $gateway ) {
			return;
		}

		$stored = OrderMeta::stored_payment_method( $renewal_order );
		if ( ! OrderMeta::has_stored_payment_method( $renewal_order ) ) {
			// Fall back to the parent subscription's meta.
			foreach ( wcs_get_subscriptions_for_renewal_order( $renewal_order ) as $subscription ) {
				if ( OrderMeta::has_stored_payment_method( $subscription ) ) {
					$stored = OrderMeta::stored_payment_method( $subscription );
					OrderMeta::set_stored_payment_method( $renewal_order, $stored );
					break;
				}
			}
		}

		if ( '' === $stored['profile_id'] && '' === $stored['token'] ) {
			$renewal_order->update_status( 'failed', __( 'CardPointe renewal failed: no vaulted payment method is stored for this subscription.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			return;
		}

		$credentials = Credentials::active();
		if ( '' !== $stored['environment'] && $stored['environment'] !== $credentials->environment() ) {
			$renewal_order->update_status(
				'failed',
				sprintf(
					/* translators: %s: environment */
					__( 'CardPointe renewal failed: the payment method was vaulted in the %s environment, which is not active.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					$stored['environment']
				)
			);
			return;
		}

		if ( (float) $amount <= 0 ) {
			$renewal_order->payment_complete();
			return;
		}

		$source    = PaymentSource::from_stored( $stored );
		$processor = new PaymentProcessor( $gateway );
		try {
			$processor->charge_stored(
				$renewal_order,
				$source,
				array(
					'cofscheduled' => 'Y',
					'suffix'       => 'REN',
					'context'      => 'subscription_renewal',
					'ach_entry'    => 'WEB',
				)
			);
		} catch ( PaymentException $e ) {
			// The order is already marked failed with a note; Subscriptions reacts to the status.
			Plugin::instance()->logger()->warning( 'Renewal charge failed', array( 'order_id' => $renewal_order->get_id(), 'error' => $e->getMessage() ) );
		} catch ( \Exception $e ) {
			$renewal_order->update_status( 'failed', __( 'CardPointe renewal failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
		}
	}

	/**
	 * A customer paid a failed renewal with a new method: use it for future renewals.
	 *
	 * @param \WC_Subscription $subscription  Subscription.
	 * @param \WC_Order        $renewal_order Renewal order paid with the new method.
	 */
	public function changed_failing_payment_method( $subscription, $renewal_order ) {
		if ( ! OrderMeta::has_stored_payment_method( $renewal_order ) ) {
			return;
		}
		OrderMeta::set_stored_payment_method( $subscription, OrderMeta::stored_payment_method( $renewal_order ) );
		$subscription->save();
	}

	/**
	 * Copies the vaulted method from an initial order to its subscriptions.
	 *
	 * @param \WC_Order          $order    Order.
	 * @param mixed              $response Response or null.
	 * @param string             $context  Context.
	 * @param PaymentSource|null $source   Source.
	 */
	public function copy_to_subscriptions( $order, $response, $context, $source = null ) {
		if ( ! in_array( $context, array( 'checkout', 'zero_total' ), true ) || ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return;
		}
		if ( ! wcs_order_contains_subscription( $order, 'any' ) || ! OrderMeta::has_stored_payment_method( $order ) ) {
			return;
		}
		$stored = OrderMeta::stored_payment_method( $order );
		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
			OrderMeta::set_stored_payment_method( $subscription, $stored );
			$subscription->save();
		}
	}

	/**
	 * Exposes the vaulted references in the admin "payment method" editor.
	 *
	 * @param array            $payment_meta Meta.
	 * @param \WC_Subscription $subscription Subscription.
	 * @return array
	 */
	public function payment_meta( $payment_meta, $subscription ) {
		foreach ( Plugin::gateway_ids() as $id ) {
			$payment_meta[ $id ] = array(
				'post_meta' => array(
					OrderMeta::key( OrderMeta::PROFILE_ID )   => array(
						'value' => (string) OrderMeta::get( $subscription, OrderMeta::PROFILE_ID ),
						'label' => __( 'CardPointe profile ID', 'paradox-cardpointe-gateway-for-woocommerce' ),
					),
					OrderMeta::key( OrderMeta::ACCT_ID )      => array(
						'value' => (string) OrderMeta::get( $subscription, OrderMeta::ACCT_ID ),
						'label' => __( 'CardPointe account ID', 'paradox-cardpointe-gateway-for-woocommerce' ),
					),
					OrderMeta::key( OrderMeta::TOKEN )        => array(
						'value' => (string) OrderMeta::get( $subscription, OrderMeta::TOKEN ),
						'label' => __( 'CardSecure token', 'paradox-cardpointe-gateway-for-woocommerce' ),
					),
					OrderMeta::key( OrderMeta::TOKEN_EXPIRY ) => array(
						'value' => (string) OrderMeta::get( $subscription, OrderMeta::TOKEN_EXPIRY ),
						'label' => __( 'Token expiry (YYYYMM)', 'paradox-cardpointe-gateway-for-woocommerce' ),
					),
				),
			);
		}
		return $payment_meta;
	}

	/**
	 * Validates admin-entered payment meta.
	 *
	 * @param string $payment_method_id Gateway ID.
	 * @param array  $payment_meta      Meta.
	 *
	 * @throws \Exception When invalid.
	 */
	public function validate_payment_meta( $payment_method_id, $payment_meta ) {
		if ( ! Plugin::is_our_gateway( $payment_method_id ) ) {
			return;
		}
		$meta       = $payment_meta['post_meta'] ?? array();
		$profile_id = trim( (string) ( $meta[ OrderMeta::key( OrderMeta::PROFILE_ID ) ]['value'] ?? '' ) );
		$token      = trim( (string) ( $meta[ OrderMeta::key( OrderMeta::TOKEN ) ]['value'] ?? '' ) );
		$expiry     = trim( (string) ( $meta[ OrderMeta::key( OrderMeta::TOKEN_EXPIRY ) ]['value'] ?? '' ) );

		if ( '' === $profile_id && '' === $token ) {
			throw new \Exception( __( 'Enter a CardPointe profile ID or a CardSecure token.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( '' !== $profile_id && ! preg_match( '/^\d{1,20}$/', $profile_id ) ) {
			throw new \Exception( __( 'The CardPointe profile ID must be numeric (up to 20 digits).', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( '' !== $token && ! preg_match( '/^\d{15,19}$/', $token ) ) {
			throw new \Exception( __( 'The CardSecure token must be 15 to 19 digits.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( '' !== $token && Plugin::CARD_GATEWAY_ID === $payment_method_id && ! preg_match( '/^\d{6}$/', $expiry ) ) {
			throw new \Exception( __( 'Enter the token expiry as YYYYMM when using a CardSecure token.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
	}

	/**
	 * "Visa ending in 1234" in My Account > Subscriptions.
	 *
	 * @param string           $label        Label.
	 * @param \WC_Subscription $subscription Subscription.
	 * @return string
	 */
	public function display_payment_method( $label, $subscription ) {
		if ( ! Plugin::is_our_gateway( $subscription->get_payment_method() ) ) {
			return $label;
		}
		$stored = OrderMeta::stored_payment_method( $subscription );
		if ( '' === $stored['last4'] ) {
			return $label;
		}
		if ( 'echeck' === $stored['type'] ) {
			$name = 'ESAV' === $stored['accttype'] ? __( 'Savings account', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'Checking account', 'paradox-cardpointe-gateway-for-woocommerce' );
		} else {
			$name = '' !== $stored['brand'] ? CardTypes::label( $stored['brand'] ) : __( 'Card', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		/* translators: 1: method name, 2: last four digits */
		return sprintf( __( '%1$s ending in %2$s', 'paradox-cardpointe-gateway-for-woocommerce' ), $name, $stored['last4'] );
	}
}
