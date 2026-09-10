<?php
/**
 * Shared gateway behaviour.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\Client;
use ParadoxSolutions\CardPointe\Api\MerchantInfo;
use ParadoxSolutions\CardPointe\Frontend\TokenizerConfig;
use ParadoxSolutions\CardPointe\Logging\Logger;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;
use ParadoxSolutions\CardPointe\Tokens\TokenManager;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the credit card and eCheck gateways.
 */
abstract class AbstractGateway extends \WC_Payment_Gateway {

	const SUPPORTS = array(
		'products',
		'refunds',
		'tokenization',
		'add_payment_method',
		'pre-orders',
		'subscriptions',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
		'subscription_amount_changes',
		'subscription_date_changes',
		'subscription_payment_method_change',
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change_admin',
		'multiple_subscriptions',
	);

	/**
	 * Sets up settings and hooks. Subclasses set id/method_title before calling this.
	 */
	public function __construct() {
		$this->has_fields = true;
		$this->supports   = self::SUPPORTS;

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		if ( ! $this->saved_methods_enabled() ) {
			$this->supports = array_values( array_diff( $this->supports, array( 'tokenization', 'add_payment_method' ) ) );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * card|echeck.
	 */
	abstract public function payment_type(): string;

	/**
	 * Settings key that toggles saved methods for this gateway.
	 */
	abstract protected function saved_methods_option(): string;

	/* ---------------------------------------------------------------------
	 * Settings helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Active credentials (shared option on the card gateway).
	 */
	public function credentials(): Credentials {
		return Credentials::active();
	}

	/**
	 * Whether sandbox mode is on.
	 */
	public function is_sandbox(): bool {
		return $this->credentials()->sandbox;
	}

	/**
	 * API client for the active credentials.
	 */
	public function client(): Client {
		return Plugin::instance()->client( $this->credentials() );
	}

	/**
	 * Shared logger.
	 */
	public function logger(): Logger {
		return Plugin::instance()->logger();
	}

	/**
	 * Boolean option helper.
	 *
	 * @param string $key     Option key.
	 * @param bool   $default Default.
	 */
	public function option_bool( string $key, bool $default = false ): bool {
		return 'yes' === $this->get_option( $key, $default ? 'yes' : 'no' );
	}

	/**
	 * Card gateway settings (the shared ones live there).
	 */
	public function shared_settings(): array {
		return Credentials::settings();
	}

	/**
	 * Whether saved methods are enabled for this gateway.
	 */
	public function saved_methods_enabled(): bool {
		return $this->option_bool( $this->saved_methods_option(), true );
	}

	/**
	 * Whether transactions capture immediately. eCheck always captures.
	 */
	public function should_capture(): bool {
		return true;
	}

	/**
	 * Whether gateway receipts are requested.
	 */
	public function receipt_enabled(): bool {
		return $this->option_bool( 'receipt_enabled', false );
	}

	/**
	 * Whether to auto-capture/void on order status changes (card setting).
	 */
	public function capture_on_status_change(): bool {
		$settings = $this->shared_settings();
		return ! isset( $settings['capture_on_status_change'] ) || 'yes' === $settings['capture_on_status_change'];
	}

	/**
	 * Full tokenizer iframe URL.
	 */
	public function tokenizer_url(): string {
		return TokenizerConfig::url( $this );
	}

	/**
	 * Origin the tokenizer posts from.
	 */
	public function tokenizer_origin(): string {
		return $this->credentials()->tokenizer_origin();
	}

	/**
	 * Name of a checkout POST field for this gateway.
	 *
	 * @param string $suffix Field suffix.
	 */
	public function field( string $suffix ): string {
		return $this->id . '_' . $suffix;
	}

	/* ---------------------------------------------------------------------
	 * Availability and display
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the gateway can be offered at checkout.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$credentials = $this->credentials();
		if ( ! $credentials->is_complete() ) {
			return false;
		}
		if ( ! $credentials->sandbox && ! wc_checkout_is_https() && ! is_admin() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the gateway needs configuration (shown as a badge in the payments list).
	 */
	public function needs_setup() {
		return ! $this->credentials()->is_complete();
	}

	/**
	 * Renders the checkout fields.
	 */
	public function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}

		if ( $this->is_sandbox() ) {
			echo '<p class="paradox-cardpointe-sandbox-notice">' . esc_html( $this->sandbox_notice() ) . '</p>';
		}

		$show_saved = $this->supports( 'tokenization' ) && is_user_logged_in() && ! is_add_payment_method_page();
		if ( $show_saved ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}

		$this->render_new_method_form();

		if ( $show_saved && ! $this->is_forced_save_context() ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Outputs the new-method form (template).
	 */
	abstract protected function render_new_method_form();

	/**
	 * Text shown at checkout in sandbox mode.
	 */
	protected function sandbox_notice(): string {
		return __( 'SANDBOX MODE: no real payments are processed.', 'paradox-cardpointe-gateway' );
	}

	/**
	 * Whether the current checkout must vault the method regardless of the checkbox (subscriptions / pre-orders).
	 */
	public function is_forced_save_context(): bool {
		if ( \ParadoxSolutions\CardPointe\Compatibility::has_subscriptions() && class_exists( 'WC_Subscriptions_Cart' ) ) {
			if ( \WC_Subscriptions_Cart::cart_contains_subscription() ) {
				return true;
			}
			if ( function_exists( 'wcs_is_subscription' ) && isset( $_GET['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		if ( \ParadoxSolutions\CardPointe\Compatibility::has_pre_orders() && class_exists( 'WC_Pre_Orders_Cart' ) && WC()->cart ) {
			if ( \WC_Pre_Orders_Cart::cart_contains_pre_order() && \WC_Pre_Orders_Product::product_is_charged_upon_release( \WC_Pre_Orders_Cart::get_pre_order_product() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validates checkout fields before the order is created (classic checkout).
	 */
	public function validate_fields() {
		try {
			PaymentSource::from_request( $this );
			return true;
		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return false;
		}
	}

	/* ---------------------------------------------------------------------
	 * Payment processing
	 * ------------------------------------------------------------------ */

	/**
	 * Processes checkout (also used for order-pay and Subscriptions payment method changes).
	 *
	 * @param int $order_id Order or subscription ID.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'The order could not be found.', 'paradox-cardpointe-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$source    = PaymentSource::from_request( $this );
			$processor = new PaymentProcessor( $this );
			return $processor->process( $order, $source );
		} catch ( \Exception $e ) {
			$this->logger()->error( 'process_payment failed', array( 'order_id' => $order_id, 'error' => $e->getMessage() ) );
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Refund from the order screen.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'paradox_cardpointe_order', __( 'Order not found.', 'paradox-cardpointe-gateway' ) );
		}
		$manager = new TransactionManager( $this );
		return $manager->refund( $order, (float) $amount, (string) $reason );
	}

	/**
	 * Whether the automatic refund button should be available for an order.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function can_refund_order( $order ) {
		if ( ! $order || ! parent::can_refund_order( $order ) ) {
			return false;
		}
		return '' !== OrderMeta::retref( $order ) && ! OrderMeta::is_voided( $order );
	}

	/**
	 * My Account > Add payment method.
	 */
	public function add_payment_method() {
		try {
			$manager = new TokenManager();
			return $manager->add_payment_method( $this );
		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	/**
	 * Settings page wrapper.
	 */
	public function admin_options() {
		$credentials = $this->credentials();
		$badge       = $credentials->sandbox
			? '<span class="paradox-cardpointe-badge paradox-cardpointe-badge-sandbox">' . esc_html__( 'Sandbox mode', 'paradox-cardpointe-gateway' ) . '</span>'
			: '<span class="paradox-cardpointe-badge paradox-cardpointe-badge-live">' . esc_html__( 'Production mode', 'paradox-cardpointe-gateway' ) . '</span>';

		echo '<div class="paradox-cardpointe-settings-header">';
		echo '<h2>' . esc_html( $this->get_method_title() ) . ' ' . wp_kses_post( $badge ) . '</h2>';
		echo '<p>' . wp_kses_post( $this->get_method_description() ) . '</p>';
		echo '<p class="description">' . sprintf(
			/* translators: 1: plugin version, 2: link */
			esc_html__( 'Version %1$s by %2$s', 'paradox-cardpointe-gateway' ),
			esc_html( PARADOX_CARDPOINTE_VERSION ),
			'<a href="https://paradoxsolutions.io" target="_blank" rel="noopener noreferrer">Paradox Solutions</a>'
		) . '</p>';
		echo '</div>';

		parent::admin_options();
	}

	/**
	 * Saves settings and clears caches that depend on them.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		foreach ( array( true, false ) as $sandbox ) {
			MerchantInfo::forget( Credentials::from_settings( Credentials::settings(), $sandbox ) );
		}

		if ( Plugin::CARD_GATEWAY_ID === $this->id ) {
			update_option( 'paradox_cardpointe_remove_data_on_uninstall', $this->get_option( 'remove_data_on_uninstall', 'no' ), false );
		}

		return $saved;
	}

	/**
	 * Renders an informational row (custom field type "paradox_notice").
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 */
	public function generate_paradox_notice_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ?? '' ); ?></th>
			<td class="forminp"><p class="description"><?php echo wp_kses_post( $data['description'] ?? '' ); ?></p></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the "Test connection" row (custom field type "paradox_test_connection").
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 */
	public function generate_paradox_test_connection_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ?? '' ); ?></th>
			<td class="forminp">
				<button type="button" class="button button-secondary" id="paradox-cardpointe-test-connection" data-nonce="<?php echo esc_attr( wp_create_nonce( 'paradox_cardpointe_admin' ) ); ?>">
					<?php esc_html_e( 'Test connection', 'paradox-cardpointe-gateway' ); ?>
				</button>
				<span class="spinner" style="float:none;margin-top:0;"></span>
				<div id="paradox-cardpointe-test-connection-result" class="paradox-cardpointe-test-result" aria-live="polite"></div>
				<p class="description"><?php esc_html_e( 'Checks the credentials of the environment selected above using the values currently in the form (unsaved changes included).', 'paradox-cardpointe-gateway' ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Custom field types store nothing.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_paradox_notice_field( $key, $value ) {
		return '';
	}

	/**
	 * Custom field types store nothing.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_paradox_test_connection_field( $key, $value ) {
		return '';
	}

	/**
	 * Sanitises the iframe CSS.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_iframe_css_field( $key, $value ) {
		$value = wp_strip_all_tags( (string) wp_unslash( $value ) );
		$value = str_replace( array( '<', '>' ), '', $value );
		return substr( $value, 0, 3000 );
	}

	/**
	 * Whether this gateway is the given order's payment method.
	 *
	 * @param \WC_Abstract_Order $order Order.
	 */
	public function owns_order( \WC_Abstract_Order $order ): bool {
		return $order->get_payment_method() === $this->id;
	}
}
