<?php
/**
 * Credit card gateway.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
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
	 * Keeps the stored path when a constant overrides it.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_cert_path_field( $key, $value ) {
		return ApplePay::has_constant( 'cert_path' ) ? (string) $this->get_option( $key ) : $this->sanitize_path( $value );
	}

	/**
	 * Keeps the stored path when a constant overrides it.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_key_path_field( $key, $value ) {
		return ApplePay::has_constant( 'key_path' ) ? (string) $this->get_option( $key ) : $this->sanitize_path( $value );
	}

	/**
	 * Keeps the stored passphrase when a constant overrides it.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_key_passphrase_field( $key, $value ) {
		return ApplePay::has_constant( 'key_passphrase' ) ? (string) $this->get_option( $key ) : (string) wp_unslash( $value );
	}

	/**
	 * The verification file is an opaque blob Apple issues; only markup is stripped.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_apple_pay_domain_association_field( $key, $value ) {
		$value = wp_strip_all_tags( (string) wp_unslash( $value ) );
		return substr( trim( $value ), 0, 20000 );
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
	 * A server path: trimmed, control characters removed, nothing else assumed.
	 *
	 * @param mixed $value Value.
	 */
	private function sanitize_path( $value ): string {
		$path = trim( (string) wp_unslash( $value ) );
		$path = str_replace( array( "\0", "\n", "\r" ), '', $path );
		return substr( $path, 0, 500 );
	}
}
