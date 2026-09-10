<?php
/**
 * Plugin bootstrap.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe;

use ParadoxSolutions\CardPointe\Api\Client;
use ParadoxSolutions\CardPointe\Gateway\AbstractGateway;
use ParadoxSolutions\CardPointe\Gateway\CardGateway;
use ParadoxSolutions\CardPointe\Gateway\EcheckGateway;
use ParadoxSolutions\CardPointe\Logging\Logger;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together. Instantiated once from the main plugin file.
 */
final class Plugin {

	const CARD_GATEWAY_ID   = 'paradox_cardpointe';
	const ECHECK_GATEWAY_ID = 'paradox_cardpointe_echeck';
	const VERSION_OPTION    = 'paradox_cardpointe_version';

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Logger|null
	 */
	private $logger = null;

	/**
	 * @var Client[] Clients keyed by environment + merchant ID.
	 */
	private $clients = array();

	/**
	 * @var bool
	 */
	private $initialised = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks. Safe to call once.
	 */
	public function init() {
		if ( $this->initialised ) {
			return;
		}
		$this->initialised = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateways' ) );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_blocks_support();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
		}
		add_filter( 'plugin_action_links_' . PARADOX_CARDPOINTE_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );

		new Frontend\Assets();
		new Tokens\TokenManager();
		new Gateway\Receipt();

		// OrderActions also owns the status-transition automation, which must run outside wp-admin (REST, CLI, cron).
		new Admin\OrderActions();
		if ( is_admin() ) {
			new Admin\Notices();
			new Admin\SettingsUi();
		}

		new Integrations\Privacy();

		if ( Compatibility::has_subscriptions() ) {
			new Integrations\Subscriptions();
		}
		if ( Compatibility::has_pre_orders() ) {
			new Integrations\PreOrders();
		}
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'paradox-cardpointe-gateway', false, dirname( PARADOX_CARDPOINTE_BASENAME ) . '/languages' );
	}

	/**
	 * Adds both gateways to WooCommerce.
	 *
	 * @param array $gateways Registered gateway class names.
	 * @return array
	 */
	public function register_gateways( $gateways ) {
		$gateways[] = CardGateway::class;
		$gateways[] = EcheckGateway::class;
		return $gateways;
	}

	/**
	 * Registers the block-based checkout integrations.
	 */
	public function register_blocks_support() {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
				$registry->register( new Blocks\CardBlocksMethod() );
				$registry->register( new Blocks\EcheckBlocksMethod() );
			}
		);
	}

	/**
	 * Adds Settings / Documentation links on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'paradox-cardpointe-gateway' ) . '</a>';
		$docs     = '<a href="https://paradoxsolutions.io" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Paradox Solutions', 'paradox-cardpointe-gateway' ) . '</a>';
		array_unshift( $links, $settings, $docs );
		return $links;
	}

	/**
	 * Runs one-time upgrade routines when the stored version changes.
	 */
	public function maybe_upgrade() {
		$stored = get_option( self::VERSION_OPTION, '' );
		if ( PARADOX_CARDPOINTE_VERSION === $stored ) {
			return;
		}
		// Future migrations go here, keyed on $stored.
		update_option( self::VERSION_OPTION, PARADOX_CARDPOINTE_VERSION, false );
		do_action( 'paradox_cardpointe_upgraded', $stored, PARADOX_CARDPOINTE_VERSION );
	}

	/**
	 * URL of the card gateway settings page (where the shared credentials live).
	 *
	 * @param string $gateway_id Gateway ID to link to.
	 */
	public static function settings_url( string $gateway_id = self::CARD_GATEWAY_ID ): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . $gateway_id );
	}

	/**
	 * Shared logger instance.
	 */
	public function logger(): Logger {
		if ( null === $this->logger ) {
			$settings      = get_option( 'woocommerce_' . self::CARD_GATEWAY_ID . '_settings', array() );
			$this->logger  = new Logger( isset( $settings['logging'] ) && 'yes' === $settings['logging'] );
		}
		return $this->logger;
	}

	/**
	 * API client for the given (or active) credentials.
	 *
	 * @param Credentials|null $credentials Credentials to use; defaults to the active environment.
	 */
	public function client( ?Credentials $credentials = null ): Client {
		$credentials = $credentials ?: Credentials::active();
		$key         = $credentials->environment() . ':' . $credentials->merchant_id . ':' . $credentials->site;
		if ( ! isset( $this->clients[ $key ] ) ) {
			$this->clients[ $key ] = new Client( $credentials, $this->logger() );
		}
		return $this->clients[ $key ];
	}

	/**
	 * Returns a loaded gateway instance by ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return AbstractGateway|null
	 */
	public static function gateway( string $gateway_id ) {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gateways[ $gateway_id ] ) && $gateways[ $gateway_id ] instanceof AbstractGateway ) {
			return $gateways[ $gateway_id ];
		}
		return null;
	}

	/**
	 * Gateway instance for an order's payment method.
	 *
	 * @param \WC_Order $order Order.
	 * @return AbstractGateway|null
	 */
	public static function gateway_for_order( \WC_Order $order ) {
		$method = $order->get_payment_method();
		if ( ! self::is_our_gateway( $method ) ) {
			return null;
		}
		return self::gateway( $method );
	}

	/**
	 * Whether a payment method ID belongs to this plugin.
	 *
	 * @param string $gateway_id Gateway ID.
	 */
	public static function is_our_gateway( $gateway_id ): bool {
		return in_array( (string) $gateway_id, array( self::CARD_GATEWAY_ID, self::ECHECK_GATEWAY_ID ), true );
	}

	/**
	 * All gateway IDs provided by this plugin.
	 *
	 * @return string[]
	 */
	public static function gateway_ids(): array {
		return array( self::CARD_GATEWAY_ID, self::ECHECK_GATEWAY_ID );
	}

	/**
	 * Loads a plugin template, allowing theme overrides in {theme}/paradox-cardpointe-gateway/.
	 *
	 * @param string $template Relative template path, e.g. checkout/card-fields.php.
	 * @param array  $args     Variables exposed to the template.
	 */
	public static function template( string $template, array $args = array() ) {
		wc_get_template( $template, $args, 'paradox-cardpointe-gateway/', PARADOX_CARDPOINTE_PATH . 'templates/' );
	}

	/**
	 * Returns a template as a string.
	 *
	 * @param string $template Relative template path.
	 * @param array  $args     Variables exposed to the template.
	 */
	public static function template_html( string $template, array $args = array() ): string {
		return wc_get_template_html( $template, $args, 'paradox-cardpointe-gateway/', PARADOX_CARDPOINTE_PATH . 'templates/' );
	}
}
