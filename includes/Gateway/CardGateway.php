<?php
/**
 * Credit card gateway.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
use ParadoxSolutions\CardPointe\ApplePay\CertificateStore;
use ParadoxSolutions\CardPointe\Frontend\TokenizerConfig;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;
use ParadoxSolutions\CardPointe\Settings\FormFields;

defined( 'ABSPATH' ) || exit;

/**
 * Credit/debit card payments through the Hosted iFrame Tokenizer.
 */
class CardGateway extends AbstractGateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Plugin::CARD_GATEWAY_ID;
		$this->method_title       = __( 'CardPointe - Credit Card', 'paradox-cardpointe-gateway-for-woocommerce' );
		$this->method_description = __( 'Accept credit and debit cards through CardPointe. Card data is entered in a hosted iframe and tokenized by CardSecure, so it never touches your server.', 'paradox-cardpointe-gateway-for-woocommerce' );
		$this->icon               = '';

		parent::__construct();
	}

	/**
	 * {@inheritDoc}
	 */
	public function payment_type(): string {
		return 'card';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function saved_methods_option(): string {
		return 'saved_cards';
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields() {
		$this->form_fields = FormFields::card();
	}

	/**
	 * charge|authorize.
	 */
	public function transaction_type(): string {
		return 'authorize' === $this->get_option( 'transaction_type', 'charge' ) ? 'authorize' : 'charge';
	}

	/**
	 * {@inheritDoc}
	 */
	public function should_capture(): bool {
		return 'charge' === $this->transaction_type();
	}

	/**
	 * Accepted card brand slugs.
	 *
	 * @return string[]
	 */
	public function accepted_card_types(): array {
		$types = $this->get_option( 'accepted_card_types', array( 'visa', 'mastercard', 'amex', 'discover' ) );
		$types = is_array( $types ) ? array_values( array_intersect( $types, CardTypes::ALL ) ) : array();
		/**
		 * Filters the accepted card brands.
		 *
		 * @param string[] $types Brand slugs.
		 */
		return apply_filters( 'paradox_cardpointe_allowed_card_types', $types );
	}

	/**
	 * Whether to use the BIN service before charging.
	 */
	public function bin_enforcement(): bool {
		return $this->option_bool( 'bin_enforcement', true );
	}

	/**
	 * Whether to send Level 2 data.
	 */
	public function level2_enabled(): bool {
		return $this->option_bool( 'level2_data', true );
	}

	/**
	 * Card brand icons.
	 */
	public function get_icon() {
		$html = '';
		foreach ( $this->accepted_card_types() as $brand ) {
			$html .= '<img src="' . esc_url( CardTypes::icon_url( $brand ) ) . '" alt="' . esc_attr( CardTypes::label( $brand ) ) . '" class="paradox-cardpointe-card-icon" width="32" height="20" />';
		}
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_new_method_form() {
		Plugin::template(
			'checkout/card-fields.php',
			array(
				'gateway'       => $this,
				'field_prefix'  => $this->id,
				'tokenizer_url' => $this->tokenizer_url(),
				'iframe_height' => TokenizerConfig::height( $this ),
				'card_types'    => $this->accepted_card_types(),
				'apple_pay'     => ApplePay::button_context( $this ),
			)
		);
	}

	/**
	 * Sanitises the site name fields.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_production_site_field( $key, $value ) {
		return Credentials::sanitize_site( wp_unslash( $value ) );
	}

	/**
	 * Sanitises the site name fields.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_sandbox_site_field( $key, $value ) {
		return Credentials::sanitize_site( wp_unslash( $value ) );
	}

	/**
	 * Sanitises merchant IDs.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_production_merchant_id_field( $key, $value ) {
		return Credentials::sanitize_merchant_id( wp_unslash( $value ) );
	}

	/**
	 * Sanitises merchant IDs.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_sandbox_merchant_id_field( $key, $value ) {
		return Credentials::sanitize_merchant_id( wp_unslash( $value ) );
	}

	/**
	 * Keeps the stored password when the field is disabled by a constant.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_production_api_password_field( $key, $value ) {
		return Credentials::has_password_constant( 'production' ) ? (string) $this->get_option( $key ) : (string) wp_unslash( $value );
	}

	/**
	 * Keeps the stored password when the field is disabled by a constant.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_sandbox_api_password_field( $key, $value ) {
		return Credentials::has_password_constant( 'sandbox' ) ? (string) $this->get_option( $key ) : (string) wp_unslash( $value );
	}

	/**
	 * Only known brands may be stored.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_accepted_card_types_field( $key, $value ) {
		$value = is_array( $value ) ? array_map( 'sanitize_key', wp_unslash( $value ) ) : array();
		return array_values( array_intersect( $value, CardTypes::ALL ) );
	}

	/* ---------------------------------------------------------------------
	 * Apple Pay settings
	 * ------------------------------------------------------------------ */

	/**
	 * Apple merchant identifiers are reverse-DNS names.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_merchant_id_field( $key, $value ) {
		return substr( preg_replace( '/[^A-Za-z0-9.\-_]/', '', (string) wp_unslash( $value ) ), 0, 128 );
	}

	/**
	 * Apple caps the sheet's display name at 64 characters.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_display_name_field( $key, $value ) {
		$name = sanitize_text_field( wp_unslash( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 64 ) : substr( $name, 0, 64 );
	}

	/**
	 * A server path: trimmed, control characters removed, nothing else assumed.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_cert_path_field( $key, $value ) {
		$path = trim( (string) wp_unslash( $value ) );
		$path = str_replace( array( "\0", "\n", "\r" ), '', $path );
		return substr( $path, 0, 500 );
	}

	/**
	 * Only known placements may be stored. Always an array, so that deselecting
	 * everything means "nowhere" rather than falling back to the default of everywhere.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_locations_field( $key, $value ) {
		$value = is_array( $value ) ? array_map( 'sanitize_key', wp_unslash( $value ) ) : array();
		return array_values( array_intersect( ApplePay::LOCATIONS, $value ) );
	}

	/**
	 * Whitelists the button style.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_button_style_field( $key, $value ) {
		$value = sanitize_key( wp_unslash( $value ) );
		return in_array( $value, array( 'black', 'white', 'white-outline' ), true ) ? $value : 'black';
	}

	/**
	 * Whitelists the button type.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_button_type_field( $key, $value ) {
		$value = sanitize_key( wp_unslash( $value ) );
		return in_array( $value, array( 'plain', 'buy', 'pay', 'check-out', 'order' ), true ) ? $value : 'plain';
	}

	/**
	 * The setup check row stores nothing.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_paradox_apple_pay_test_field( $key, $value ) {
		return '';
	}

	/**
	 * Certificate path row: the path, an upload button, the web root for reference and
	 * what the plugin makes of the file currently configured.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 */
	public function generate_paradox_apple_pay_certificate_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$value     = (string) $this->get_option( $key );
		$report    = '' !== $value ? CertificateStore::inspect_file( $value ) : null;
		$example   = trailingslashit( dirname( untrailingslashit( CertificateStore::web_root() ) ) ) . 'certificates.pem';

		ob_start();
		?>
		<tr valign="top" class="paradox-cardpointe-cert-row">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ?? '' ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce escapes the tooltip. ?></label>
			</th>
			<td class="forminp">
				<input class="input-text regular-input" type="text" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $example ); ?>" autocomplete="off" spellcheck="false" />
				<input type="file" id="paradox-cardpointe-apple-pay-file" accept=".pem,.txt,.crt,.key,application/x-pem-file" hidden />
				<button type="button" class="button" id="paradox-cardpointe-apple-pay-upload" data-nonce="<?php echo esc_attr( wp_create_nonce( 'paradox_cardpointe_admin' ) ); ?>"><?php esc_html_e( 'Upload PEM file', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></button>
				<span class="spinner"></span>
				<p class="description paradox-cardpointe-webroot">
					<?php
					printf(
						/* translators: %s: web root path */
						esc_html__( 'For reference, your current web root path is: %s', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'<code>' . esc_html( CertificateStore::web_root() ) . '</code>'
					);
					?>
				</p>
				<p class="description"><?php esc_html_e( 'One PEM file containing the Merchant Identity Certificate and its private key, with no passphrase. Upload it here, or place it on the server yourself and enter its full path. Because the file contains a private key, an upload is stored just above the web root when the server allows it, where no web address can reach it.', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
				<div id="paradox-cardpointe-apple-pay-cert-result" aria-live="polite"><?php echo $report ? $this->certificate_report_html( $report ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?></div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Setup check row: what is still missing, a test against Apple, and once that has
	 * passed, the Apple Pay button itself.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 */
	public function generate_paradox_apple_pay_test_html( $key, $data ) {
		$report   = ApplePay::setup_report();
		$verified = ApplePay::is_verified();
		$style    = ApplePay::button_style();
		$type     = ApplePay::button_type();

		ob_start();
		?>
		<tr valign="top" class="paradox-cardpointe-apple-pay-test-row">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ?? '' ); ?></th>
			<td class="forminp">
				<div id="paradox-cardpointe-apple-pay-status"><?php echo $this->setup_report_html( $report, $verified ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?></div>
				<p>
					<button type="button" class="button button-secondary" id="paradox-cardpointe-apple-pay-test" data-nonce="<?php echo esc_attr( wp_create_nonce( 'paradox_cardpointe_admin' ) ); ?>"><?php esc_html_e( 'Test Apple Pay setup', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></button>
					<span class="spinner"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Asks Apple for a merchant session using the Merchant ID and certificate path currently in the form (unsaved changes included) and this site\'s domain. Passing proves Apple accepts all three. Nothing is charged.', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
				<div id="paradox-cardpointe-apple-pay-test-result" class="paradox-cardpointe-test-result" aria-live="polite"></div>

				<div id="paradox-cardpointe-apple-pay-preview" class="paradox-cardpointe-apple-pay-preview"
					data-verified="<?php echo $verified ? '1' : '0'; ?>"
					data-label="<?php echo esc_attr( ApplePay::display_name() ); ?>"
					data-currency="<?php echo esc_attr( get_woocommerce_currency() ); ?>"
					data-country="<?php echo esc_attr( WC()->countries->get_base_country() ); ?>"
					data-networks="<?php echo esc_attr( implode( ',', ApplePay::networks( $this ) ) ); ?>"
					hidden>
					<p class="paradox-cardpointe-ok"><?php esc_html_e( 'Apple Pay is set up. This is the button shoppers see in the credit card box:', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
					<div class="paradox-cardpointe-apple-pay-slot" hidden></div>
					<div class="paradox-cardpointe-apple-pay-mock is-style-<?php echo esc_attr( $style ); ?>" aria-hidden="true" hidden><?php esc_html_e( 'Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></div>
					<p class="description paradox-cardpointe-apple-pay-sheet-hint" hidden><?php esc_html_e( 'Click it to open the Apple Pay sheet. This is a test: nothing is sent to CardPointe and no payment is taken.', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
					<p class="description paradox-cardpointe-apple-pay-nosafari" hidden><?php esc_html_e( 'This is a preview. This browser cannot show the real Apple Pay button: turn on "Other Browsers" above and save, or open this page in Safari on a device with Apple Pay.', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
					<div id="paradox-cardpointe-apple-pay-sheet-result" class="paradox-cardpointe-test-result" aria-live="polite"></div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * What the plugin found in the configured certificate file.
	 *
	 * @param array $report CertificateStore::inspect_file() result.
	 */
	private function certificate_report_html( array $report ): string {
		$lines = array();
		foreach ( $report['errors'] as $message ) {
			$lines[] = array( 'fail', $message );
		}
		if ( empty( $report['errors'] ) ) {
			$lines[] = array( 'ok', __( 'Certificate and private key found, and they belong together.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			if ( '' !== $report['common_name'] ) {
				$lines[] = array( 'ok', $report['common_name'] );
			}
			if ( $report['expires'] > 0 ) {
				/* translators: %s: date */
				$lines[] = array( 'ok', sprintf( __( 'Valid until %s.', 'paradox-cardpointe-gateway-for-woocommerce' ), wp_date( get_option( 'date_format' ), $report['expires'] ) ) );
			}
		}
		foreach ( $report['warnings'] as $message ) {
			$lines[] = array( 'warn', $message );
		}
		return self::report_list_html( $lines );
	}

	/**
	 * Overall setup state for the setup check row.
	 *
	 * @param array $report   ApplePay::setup_report() result.
	 * @param bool  $verified Whether Apple accepted the saved settings.
	 */
	private function setup_report_html( array $report, bool $verified ): string {
		$lines = array();
		foreach ( $report['problems'] as $message ) {
			$lines[] = array( 'fail', $message );
		}
		if ( $verified ) {
			$lines[] = array(
				'ok',
				sprintf(
					/* translators: 1: date, 2: domain */
					__( 'Verified with Apple on %1$s for %2$s.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					wp_date( get_option( 'date_format' ), ApplePay::verified_at() ),
					ApplePay::domain()
				),
			);
		} elseif ( empty( $report['problems'] ) ) {
			$lines[] = array( 'info', __( 'Not tested with Apple yet. Run the test below.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		foreach ( $report['notes'] as $message ) {
			$lines[] = array( 'warn', $message );
		}
		return self::report_list_html( $lines );
	}

	/**
	 * Renders status lines.
	 *
	 * @param array $lines Pairs of state (ok|fail|warn|info) and message.
	 */
	private static function report_list_html( array $lines ): string {
		if ( empty( $lines ) ) {
			return '';
		}
		$html = '<ul class="paradox-cardpointe-report">';
		foreach ( $lines as $line ) {
			$html .= '<li class="is-' . esc_attr( $line[0] ) . '">' . esc_html( $line[1] ) . '</li>';
		}
		return $html . '</ul>';
	}
}
