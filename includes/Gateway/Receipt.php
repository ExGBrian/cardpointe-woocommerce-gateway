<?php
/**
 * Gateway receipt display.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the CardPointe receipt data stored on an order in order pages and emails.
 */
final class Receipt {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_on_order_page' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_in_email' ), 20, 4 );
	}

	/**
	 * Receipt data for an order, if any.
	 *
	 * @param \WC_Order $order Order.
	 * @return array|null
	 */
	public static function get( \WC_Order $order ) {
		$receipt = OrderMeta::get( $order, OrderMeta::RECEIPT, null );
		return is_array( $receipt ) && ! empty( $receipt ) ? $receipt : null;
	}

	/**
	 * Renders the receipt template.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $plain Plain text output.
	 */
	public static function render( \WC_Order $order, bool $plain = false ): string {
		$receipt = self::get( $order );
		if ( null === $receipt ) {
			return '';
		}

		$date_raw = (string) ( $receipt['dateTime'] ?? '' );
		$date     = '';
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $date_raw, $m ) ) {
			$timestamp = gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );
			$date      = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		}

		$stored = OrderMeta::stored_payment_method( $order );
		$method = 'echeck' === $stored['type']
			? ( 'ESAV' === $stored['accttype'] ? __( 'Savings account', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'Checking account', 'paradox-cardpointe-gateway-for-woocommerce' ) )
			: ( '' !== $stored['brand'] ? CardTypes::label( $stored['brand'] ) : __( 'Card', 'paradox-cardpointe-gateway-for-woocommerce' ) );

		$rows = array(
			'dba'      => array( __( 'Merchant', 'paradox-cardpointe-gateway-for-woocommerce' ), (string) ( $receipt['dba'] ?? '' ) ),
			'address'  => array( __( 'Address', 'paradox-cardpointe-gateway-for-woocommerce' ), trim( (string) ( $receipt['address1'] ?? '' ) . ' ' . (string) ( $receipt['address2'] ?? '' ) ) ),
			'phone'    => array( __( 'Phone', 'paradox-cardpointe-gateway-for-woocommerce' ), (string) ( $receipt['phone'] ?? '' ) ),
			'date'     => array( __( 'Date', 'paradox-cardpointe-gateway-for-woocommerce' ), $date ),
			'method'   => array( __( 'Payment method', 'paradox-cardpointe-gateway-for-woocommerce' ), trim( $method . ' ' . ( '' !== $stored['last4'] ? '****' . $stored['last4'] : '' ) ) ),
			'name'     => array( __( 'Name', 'paradox-cardpointe-gateway-for-woocommerce' ), (string) ( $receipt['nameOnCard'] ?? '' ) ),
			'amount'   => array( __( 'Amount', 'paradox-cardpointe-gateway-for-woocommerce' ), wp_strip_all_tags( wc_price( (float) OrderMeta::get( $order, OrderMeta::CAPTURED_AMOUNT, OrderMeta::get( $order, OrderMeta::AMOUNT_AUTHORIZED, $order->get_total() ) ), array( 'currency' => $order->get_currency() ) ) ) ),
			'authcode' => array( __( 'Authorization code', 'paradox-cardpointe-gateway-for-woocommerce' ), (string) OrderMeta::get( $order, OrderMeta::AUTHCODE ) ),
			'retref'   => array( __( 'Reference', 'paradox-cardpointe-gateway-for-woocommerce' ), OrderMeta::retref( $order ) ),
		);
		$rows = array_filter(
			$rows,
			static function ( $row ) {
				return '' !== trim( (string) $row[1] );
			}
		);

		/**
		 * Filters the receipt rows and raw data before rendering.
		 *
		 * @param array     $rows    Label/value pairs.
		 * @param array     $receipt Raw receipt.
		 * @param \WC_Order $order   Order.
		 */
		$rows = apply_filters( 'paradox_cardpointe_receipt_data', $rows, $receipt, $order );

		return Plugin::template_html(
			'receipt.php',
			array(
				'order'   => $order,
				'receipt' => $receipt,
				'rows'    => $rows,
				'header'  => (string) ( $receipt['header'] ?? '' ),
				'footer'  => (string) ( $receipt['footer'] ?? '' ),
				'plain'   => $plain,
			)
		);
	}

	/**
	 * Order received page and My Account order view.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function render_on_order_page( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$gateway = Plugin::gateway_for_order( $order );
		if ( ! $gateway || ! $gateway->option_bool( 'receipt_on_thankyou', true ) ) {
			return;
		}
		echo wp_kses_post( self::render( $order, false ) );
	}

	/**
	 * Customer emails.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain text email.
	 * @param mixed     $email         Email object.
	 */
	public function render_in_email( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( ! $order instanceof \WC_Order || $sent_to_admin ) {
			return;
		}
		$gateway = Plugin::gateway_for_order( $order );
		if ( ! $gateway || ! $gateway->option_bool( 'receipt_in_emails', false ) ) {
			return;
		}
		if ( $plain_text ) {
			echo esc_html( wp_strip_all_tags( self::render( $order, true ) ) ) . "\n";
		} else {
			echo wp_kses_post( self::render( $order, false ) );
		}
	}
}
