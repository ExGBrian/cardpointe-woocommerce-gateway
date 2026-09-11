<?php
/**
 * Settings page extras.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Admin;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\Api\MerchantInfo;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * "Test connection" AJAX handler.
 */
final class SettingsUi {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_paradox_cardpointe_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Tests the posted (possibly unsaved) credentials with inquireMerchant.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'paradox_cardpointe_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 403 );
		}

		$env      = isset( $_POST['environment'] ) && 'production' === $_POST['environment'] ? 'production' : 'sandbox';
		$site     = isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '';
		$merchant = isset( $_POST['merchant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_id'] ) ) : '';
		$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered.

		$saved = Credentials::from_settings( Credentials::settings(), 'sandbox' === $env );
		if ( '' === $password ) {
			$password = $saved->password;
		}
		if ( Credentials::has_password_constant( $env ) ) {
			$password = $saved->password;
		}

		$credentials = Credentials::from_values( $site, $merchant, $username, $password, 'sandbox' === $env );
		if ( ! $credentials->is_complete() ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in the site name, merchant ID, API username and API password first.', 'paradox-cardpointe-gateway-for-woocommerce' ) ) );
		}

		try {
			$response = Plugin::instance()->client( $credentials )->inquire_merchant();
		} catch ( ApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		$info = $response->all();
		MerchantInfo::store( $credentials, $info );

		$warnings = array();
		$site_out = (string) ( $info['site'] ?? '' );
		if ( '' !== $site_out && strtolower( $site_out ) !== strtolower( $credentials->site ) && strtolower( $site_out ) !== strtolower( $credentials->site . '-uat' ) ) {
			$warnings[] = sprintf(
				/* translators: %s: site name */
				__( 'CardPointe reports this merchant ID is boarded to site "%s". Use that as the site name if requests fail.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				$site_out
			);
		}
		if ( isset( $info['enabled'] ) && ! filter_var( $info['enabled'], FILTER_VALIDATE_BOOLEAN ) && 'Y' !== strtoupper( (string) $info['enabled'] ) ) {
			$warnings[] = __( 'The merchant account is not enabled for processing.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		wp_send_json_success(
			array(
				'host'        => $credentials->host(),
				'site'        => $site_out,
				'enabled'     => $info['enabled'] ?? '',
				'echeck'      => $info['echeck'] ?? '',
				'cvv'         => $info['cvv'] ?? '',
				'avs'         => $info['avs'] ?? '',
				'acctupdater' => $info['acctupdater'] ?? '',
				'cardproc'    => $info['cardproc'] ?? '',
				'warnings'    => $warnings,
			)
		);
	}
}
