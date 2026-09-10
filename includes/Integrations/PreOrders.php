<?php
/**
 * WooCommerce Pre-Orders integration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Integrations;

use ParadoxSolutions\CardPointe\Gateway\OrderMeta;
use ParadoxSolutions\CardPointe\Gateway\PaymentException;
use ParadoxSolutions\CardPointe\Gateway\PaymentProcessor;
use ParadoxSolutions\CardPointe\Gateway\PaymentSource;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Charges vaulted payment methods when a pre-order is released.
 */
final class PreOrders {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		foreach ( Plugin::gateway_ids() as $id ) {
			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $id, array( $this, 'process_release' ) );
		}
	}

	/**
	 * Charges the order total using the vaulted method.
	 *
	 * @param \WC_Order $order Pre-order being released.
	 */
	public function process_release( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$gateway = Plugin::gateway_for_order( $order );
		if ( ! $gateway ) {
			return;
		}

		if ( ! OrderMeta::has_stored_payment_method( $order ) ) {
			$order->update_status( 'failed', __( 'CardPointe pre-order charge failed: no vaulted payment method is stored on the order.', 'paradox-cardpointe-gateway' ) );
			return;
		}

		$stored      = OrderMeta::stored_payment_method( $order );
		$credentials = Credentials::active();
		if ( '' !== $stored['environment'] && $stored['environment'] !== $credentials->environment() ) {
			$order->update_status( 'failed', __( 'CardPointe pre-order charge failed: the payment method was vaulted in a different environment.', 'paradox-cardpointe-gateway' ) );
			return;
		}

		if ( (float) $order->get_total() <= 0 ) {
			$order->payment_complete();
			return;
		}

		$processor = new PaymentProcessor( $gateway );
		try {
			$processor->charge_stored(
				$order,
				PaymentSource::from_stored( $stored ),
				array(
					'cofscheduled' => 'N',
					'suffix'       => 'REL',
					'context'      => 'pre_order_release',
					'ach_entry'    => 'WEB',
				)
			);
		} catch ( PaymentException $e ) {
			Plugin::instance()->logger()->warning( 'Pre-order release charge failed', array( 'order_id' => $order->get_id(), 'error' => $e->getMessage() ) );
		} catch ( \Exception $e ) {
			$order->update_status( 'failed', __( 'CardPointe pre-order charge failed:', 'paradox-cardpointe-gateway' ) . ' ' . $e->getMessage() );
		}
	}
}
