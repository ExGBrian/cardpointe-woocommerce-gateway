<?php
/**
 * Admin notices.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Admin;

use ParadoxSolutions\CardPointe\Api\MerchantInfo;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration warnings shown to shop managers.
 */
final class Notices {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Outputs the relevant notices.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$on_wc     = false !== strpos( $screen_id, 'woocommerce' ) || false !== strpos( $screen_id, 'shop_order' ) || in_array( $screen_id, array( 'dashboard', 'plugins' ), true );
		if ( ! $on_wc ) {
			return;
		}

		$settings_url = Plugin::settings_url();

		if ( get_transient( 'paradox_cardpointe_activated' ) ) {
			delete_transient( 'paradox_cardpointe_activated' );
			$this->notice(
				'info',
				sprintf(
					/* translators: %s: settings URL */
					__( 'Thanks for installing CardPointe Payment Gateway. <a href="%s">Enter your CardPointe credentials</a> to start accepting payments.', 'paradox-cardpointe-gateway' ),
					esc_url( $settings_url )
				)
			);
		}

		$card_settings   = Credentials::settings();
		$echeck_settings = get_option( 'woocommerce_' . Plugin::ECHECK_GATEWAY_ID . '_settings', array() );
		$card_enabled    = isset( $card_settings['enabled'] ) && 'yes' === $card_settings['enabled'];
		$echeck_enabled  = is_array( $echeck_settings ) && isset( $echeck_settings['enabled'] ) && 'yes' === $echeck_settings['enabled'];

		if ( ! $card_enabled && ! $echeck_enabled ) {
			return;
		}

		$credentials = Credentials::active();

		if ( ! $credentials->is_complete() ) {
			$this->notice(
				'error',
				sprintf(
					/* translators: 1: environment, 2: settings URL */
					__( 'CardPointe is enabled but the %1$s credentials are incomplete. <a href="%2$s">Complete the settings</a> to accept payments.', 'paradox-cardpointe-gateway' ),
					$credentials->sandbox ? __( 'sandbox', 'paradox-cardpointe-gateway' ) : __( 'production', 'paradox-cardpointe-gateway' ),
					esc_url( $settings_url )
				)
			);
			return;
		}

		if ( ! $credentials->sandbox && ! wc_checkout_is_https() ) {
			$this->notice(
				'warning',
				__( 'CardPointe is in production mode but checkout is not served over HTTPS. The payment methods will be hidden until HTTPS is enabled.', 'paradox-cardpointe-gateway' )
			);
		}

		if ( $credentials->sandbox && 'woocommerce_page_wc-settings' === $screen_id ) {
			$this->notice(
				'info',
				__( 'CardPointe sandbox mode is on: customers cannot make real payments.', 'paradox-cardpointe-gateway' )
			);
		}

		if ( $echeck_enabled && false === MerchantInfo::supports_echeck( $credentials ) ) {
			$this->notice(
				'warning',
				__( 'The CardPointe eCheck gateway is enabled but your merchant ID is not configured for ACH. Contact CardPointe support to enable ACH, or disable the eCheck gateway.', 'paradox-cardpointe-gateway' )
			);
		}
	}

	/**
	 * Prints one notice.
	 *
	 * @param string $type    info|warning|error.
	 * @param string $message Message (limited HTML allowed).
	 */
	private function notice( string $type, string $message ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . wp_kses( $message, array( 'a' => array( 'href' => array() ), 'strong' => array() ) ) . '</p></div>';
	}
}
