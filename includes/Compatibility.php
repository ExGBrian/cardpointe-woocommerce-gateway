<?php
/**
 * Environment and dependency checks.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies PHP, WordPress and WooCommerce requirements before the plugin boots.
 */
final class Compatibility {

	/**
	 * Returns a human readable problem description, or null when the environment is fine.
	 *
	 * @return string|null
	 */
	public static function check() {
		if ( version_compare( PHP_VERSION, PARADOX_CARDPOINTE_MIN_PHP, '<' ) ) {
			return sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'CardPointe Payment Gateway requires PHP %1$s or newer. This server runs PHP %2$s.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				PARADOX_CARDPOINTE_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			return __( 'CardPointe Payment Gateway requires WooCommerce to be installed and active.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		if ( version_compare( WC_VERSION, PARADOX_CARDPOINTE_MIN_WC, '<' ) ) {
			return sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version */
				__( 'CardPointe Payment Gateway requires WooCommerce %1$s or newer. You are running WooCommerce %2$s.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				PARADOX_CARDPOINTE_MIN_WC,
				WC_VERSION
			);
		}

		if ( ! wp_http_supports( array( 'ssl' ) ) ) {
			return __( 'CardPointe Payment Gateway requires an HTTP transport with SSL support (cURL with OpenSSL) to talk to the CardPointe API.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		return null;
	}

	/**
	 * Displays the environment problem as an admin notice.
	 *
	 * @param string $problem Message to show.
	 */
	public static function show_problem( string $problem ) {
		add_action(
			'admin_notices',
			static function () use ( $problem ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>' . esc_html( $problem ) . '</p></div>';
			}
		);
	}

	/**
	 * Whether High-Performance Order Storage is the active order data store.
	 */
	public static function is_hpos_enabled(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Screen ID of the order edit screen, for meta boxes.
	 */
	public static function order_screen_id(): string {
		if ( self::is_hpos_enabled() && function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-order' );
		}
		return 'shop_order';
	}

	/**
	 * Whether WooCommerce Subscriptions is active.
	 */
	public static function has_subscriptions(): bool {
		return class_exists( 'WC_Subscriptions' ) && function_exists( 'wcs_is_subscription' );
	}

	/**
	 * Whether WooCommerce Pre-Orders is active.
	 */
	public static function has_pre_orders(): bool {
		return class_exists( 'WC_Pre_Orders' ) && class_exists( 'WC_Pre_Orders_Order' );
	}
}
