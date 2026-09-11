<?php
/**
 * eCheck (ACH) gateway.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\MerchantInfo;
use ParadoxSolutions\CardPointe\Frontend\TokenizerConfig;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\FormFields;

defined( 'ABSPATH' ) || exit;

/**
 * Bank account (ACH) payments through the Hosted iFrame Tokenizer.
 */
class EcheckGateway extends AbstractGateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Plugin::ECHECK_GATEWAY_ID;
		$this->method_title       = __( 'CardPointe - eCheck (ACH)', 'paradox-cardpointe-gateway-for-woocommerce' );
		$this->method_description = __( 'Accept eCheck payments through the ACH network using CardPointe. Bank details are tokenized in a hosted iframe. Requires ACH to be enabled on your CardPointe merchant account.', 'paradox-cardpointe-gateway-for-woocommerce' );
		$this->icon               = '';

		parent::__construct();

		add_filter( 'woocommerce_payment_gateway_get_new_payment_method_option_html_label', array( $this, 'new_method_label' ), 10, 2 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function payment_type(): string {
		return 'echeck';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function saved_methods_option(): string {
		return 'saved_accounts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields() {
		$this->form_fields = FormFields::echeck();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		// Hide when we know for sure the MID cannot process ACH; unknown keeps it visible.
		return false !== MerchantInfo::supports_echeck( $this->credentials() );
	}

	/**
	 * Allowed account types (ECHK/ESAV).
	 *
	 * @return string[]
	 */
	public function account_types(): array {
		$types = $this->get_option( 'account_types', array( 'ECHK', 'ESAV' ) );
		$types = is_array( $types ) ? array_values( array_intersect( $types, array( 'ECHK', 'ESAV' ) ) ) : array();
		return $types ?: array( 'ECHK', 'ESAV' );
	}

	/**
	 * both|fiserv|profitstars.
	 */
	public function sec_mode(): string {
		$mode = (string) $this->get_option( 'sec_mode', 'both' );
		return in_array( $mode, array( 'both', 'fiserv', 'profitstars' ), true ) ? $mode : 'both';
	}

	/**
	 * Order status after an accepted ACH payment: processing|on-hold.
	 */
	public function approved_status(): string {
		return 'on-hold' === $this->get_option( 'approved_status', 'processing' ) ? 'on-hold' : 'processing';
	}

	/**
	 * Authorization text with placeholders replaced.
	 *
	 * @param float|null $amount Amount to show; null uses the cart total.
	 */
	public function consent_text( $amount = null ): string {
		$text = (string) $this->get_option( 'consent_text' );
		if ( null === $amount && WC()->cart ) {
			$amount = (float) WC()->cart->get_total( 'edit' );
		}
		$company = get_bloginfo( 'name' );
		$text    = str_replace(
			array( '{amount}', '{company}', '{site}' ),
			array( wp_strip_all_tags( wc_price( (float) $amount ) ), $company, $company ),
			$text
		);
		/**
		 * Filters the ACH authorization text.
		 *
		 * @param string     $text   Text.
		 * @param float|null $amount Amount.
		 */
		return (string) apply_filters( 'paradox_cardpointe_ach_consent_text', $text, $amount );
	}

	/**
	 * Wording of the "new method" radio when saved accounts exist.
	 *
	 * @param string              $label   Label.
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 */
	public function new_method_label( $label, $gateway ) {
		if ( $gateway instanceof self ) {
			return __( 'Use a new bank account', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		return $label;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_new_method_form() {
		$amount = null;
		if ( is_checkout_pay_page() ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( $order ) {
				$amount = (float) $order->get_total();
			}
		}
		Plugin::template(
			'checkout/echeck-fields.php',
			array(
				'gateway'       => $this,
				'field_prefix'  => $this->id,
				'tokenizer_url' => $this->tokenizer_url(),
				'iframe_height' => TokenizerConfig::height( $this ),
				'account_types' => $this->account_types(),
				'consent_text'  => $this->consent_text( $amount ),
			)
		);
	}

	/**
	 * Only known account types may be stored.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_account_types_field( $key, $value ) {
		$value = is_array( $value ) ? array_map( 'strtoupper', array_map( 'sanitize_key', wp_unslash( $value ) ) ) : array();
		$value = array_values( array_intersect( $value, array( 'ECHK', 'ESAV' ) ) );
		return $value ?: array( 'ECHK', 'ESAV' );
	}

	/**
	 * Plain-text authorization sentence.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function validate_consent_text_field( $key, $value ) {
		return sanitize_textarea_field( wp_unslash( $value ) );
	}
}
