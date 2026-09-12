<?php
/**
 * Plugin Name: Paradox CardPointe Gateway for WooCommerce
 * Plugin URI: https://github.com/ExGBrian/cardpointe-woocommerce-gateway
 * Description: Accept credit cards and eChecks (ACH) through CardPointe using the Hosted iFrame Tokenizer. Supports authorize/capture, refunds, saved payment methods, WooCommerce Subscriptions and Pre-Orders, and the block-based checkout.
 * Version: 1.0.2
 * Author: Paradox Solutions
 * Author URI: https://paradoxsolutions.io
 * Text Domain: paradox-cardpointe-gateway-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.1
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package ParadoxSolutions\CardPointe
 */

defined( 'ABSPATH' ) || exit;

define( 'PARADOX_CARDPOINTE_VERSION', '1.0.2' );
define( 'PARADOX_CARDPOINTE_FILE', __FILE__ );
define( 'PARADOX_CARDPOINTE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PARADOX_CARDPOINTE_URL', plugin_dir_url( __FILE__ ) );
define( 'PARADOX_CARDPOINTE_BASENAME', plugin_basename( __FILE__ ) );
define( 'PARADOX_CARDPOINTE_MIN_PHP', '7.4' );
define( 'PARADOX_CARDPOINTE_MIN_WC', '9.0' );

/**
 * PSR-4 style autoloader for the ParadoxSolutions\CardPointe namespace.
 *
 * Maps ParadoxSolutions\CardPointe\Foo\Bar to includes/Foo/Bar.php.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'ParadoxSolutions\\CardPointe\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = PARADOX_CARDPOINTE_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Declare compatibility with High-Performance Order Storage and the block-based checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded and WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	static function () {
		$problem = \ParadoxSolutions\CardPointe\Compatibility::check();
		if ( null !== $problem ) {
			\ParadoxSolutions\CardPointe\Compatibility::show_problem( $problem );
			return;
		}
		\ParadoxSolutions\CardPointe\Plugin::instance()->init();
	},
	11
);

/**
 * Remember that the plugin was just activated so a "get started" notice can be shown once.
 */
register_activation_hook(
	__FILE__,
	static function () {
		set_transient( 'paradox_cardpointe_activated', 1, MINUTE_IN_SECONDS * 5 );
	}
);
