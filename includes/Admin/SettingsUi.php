<?php
/**
 * Settings page extras.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Admin;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\Api\MerchantInfo;
use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
use ParadoxSolutions\CardPointe\ApplePay\CertificateStore;
use ParadoxSolutions\CardPointe\ApplePay\MerchantValidator;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen AJAX: CardPointe connection test, Apple Pay certificate upload and setup test.
 */
final class SettingsUi {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_paradox_cardpointe_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_paradox_cardpointe_apple_pay_upload', array( $this, 'ajax_apple_pay_upload' ) );
		add_action( 'wp_ajax_paradox_cardpointe_apple_pay_test', array( $this, 'ajax_apple_pay_test' ) );
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

	/**
	 * Refuses anyone who may not manage the store.
	 */
	private function require_manager() {
		check_ajax_referer( 'paradox_cardpointe_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 403 );
		}
	}

	/**
	 * Receives the Merchant Identity PEM, checks it, stores it privately and saves its path.
	 *
	 * The path is saved straight away so the upload cannot be orphaned by a forgotten
	 * "Save changes"; the Merchant ID is taken from the certificate when none is set.
	 */
	public function ajax_apple_pay_upload() {
		$this->require_manager();

		$file = isset( $_FILES['certificate'] ) && is_array( $_FILES['certificate'] ) ? $_FILES['certificate'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tmp_name, error and size are read.
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( empty( $file ) || ! empty( $file['error'] ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'The upload did not complete. Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' ) ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > CertificateStore::MAX_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'The file is too large to be a certificate.', 'paradox-cardpointe-gateway-for-woocommerce' ) ) );
		}

		$report = CertificateStore::inspect( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
		if ( ! empty( $report['errors'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This file cannot be used, so nothing was changed:', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'errors'  => $report['errors'],
				)
			);
		}

		try {
			$path = CertificateStore::store( $report['normalised'] );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		$option   = 'woocommerce_' . Plugin::CARD_GATEWAY_ID . '_settings';
		$settings = get_option( $option, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$previous = isset( $settings['apple_pay_cert_path'] ) ? (string) $settings['apple_pay_cert_path'] : '';

		$settings['apple_pay_cert_path'] = $path;
		if ( '' === trim( (string) ( $settings['apple_pay_merchant_id'] ?? '' ) ) && '' !== $report['merchant_id'] ) {
			$settings['apple_pay_merchant_id'] = $report['merchant_id'];
		}
		update_option( $option, $settings );

		// A certificate this plugin stored earlier somewhere else is a stray private key now.
		if ( '' !== $previous && $previous !== $path && CertificateStore::is_managed( $previous ) && file_exists( $previous ) ) {
			wp_delete_file( $previous );
		}
		delete_option( ApplePay::VERIFIED_OPTION );

		Plugin::instance()->logger()->info( 'Apple Pay certificate uploaded', array( 'outside_web_root' => CertificateStore::is_inside_web_root( $path ) ? 'no' : 'yes' ) );

		wp_send_json_success(
			array(
				'path'        => $path,
				'merchant_id' => $report['merchant_id'],
				'common_name' => $report['common_name'],
				'expires'     => $report['expires'] > 0 ? wp_date( get_option( 'date_format' ), $report['expires'] ) : '',
				'warnings'    => $report['warnings'],
				'message'     => CertificateStore::is_inside_web_root( $path )
					? __( 'Certificate saved in a protected uploads folder under a random name, because the folder above the web root is not writable.', 'paradox-cardpointe-gateway-for-woocommerce' )
					: __( 'Certificate saved above the web root, where no web address can reach it.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			)
		);
	}

	/**
	 * Asks Apple for a merchant session with the values in the form.
	 *
	 * Without a validation URL this is the setup test against Apple's production endpoint.
	 * With one, it is Safari opening the test payment sheet from the settings screen.
	 */
	public function ajax_apple_pay_test() {
		$this->require_manager();

		$merchant_id = isset( $_POST['merchant_id'] ) ? substr( preg_replace( '/[^A-Za-z0-9.\-_]/', '', (string) wp_unslash( $_POST['merchant_id'] ) ), 0, 128 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$cert_path   = isset( $_POST['cert_path'] ) ? str_replace( array( "\0", "\n", "\r" ), '', trim( (string) wp_unslash( $_POST['cert_path'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a server path.
		$sheet_url   = isset( $_POST['validation_url'] ) ? esc_url_raw( wp_unslash( $_POST['validation_url'] ) ) : '';

		$merchant_id = '' !== $merchant_id ? $merchant_id : ApplePay::merchant_id();
		$cert_path   = '' !== $cert_path ? $cert_path : ApplePay::cert_path();

		// Name certificate problems precisely rather than letting them surface as a TLS error.
		$certificate = CertificateStore::inspect_file( $cert_path );
		if ( ! empty( $certificate['errors'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The certificate file is not usable yet:', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'hints'   => $certificate['errors'],
				)
			);
		}
		if ( '' !== $certificate['merchant_id'] && '' !== $merchant_id && 0 !== strcasecmp( $certificate['merchant_id'], $merchant_id ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: merchant ID in the certificate, 2: merchant ID entered */
						__( 'The certificate was issued for %1$s but the Apple Merchant ID entered is %2$s. They must be the same.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						$certificate['merchant_id'],
						$merchant_id
					),
					'hints'   => array(),
				)
			);
		}

		try {
			$session = MerchantValidator::validate(
				'' !== $sheet_url ? $sheet_url : MerchantValidator::TEST_URL,
				array(
					'merchant_id' => $merchant_id,
					'cert_path'   => $cert_path,
				)
			);
		} catch ( \Exception $e ) {
			Plugin::instance()->logger()->warning( 'Apple Pay setup test failed', array( 'error' => $e->getMessage() ) );
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'hints'   => MerchantValidator::explain( $e->getMessage() ),
				)
			);
		}

		if ( '' !== $sheet_url ) {
			wp_send_json_success( array( 'session' => $session ) );
		}

		ApplePay::mark_verified( $merchant_id, $cert_path );

		$unsaved = $merchant_id !== ApplePay::merchant_id() || $cert_path !== ApplePay::cert_path();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: domain */
					__( 'Apple accepted the Merchant ID, the certificate and the domain %s. Apple Pay is set up correctly.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					ApplePay::domain()
				),
				'notes'   => array_values(
					array_filter(
						array(
							$unsaved ? __( 'These values are not saved yet. Click "Save changes" to keep them.', 'paradox-cardpointe-gateway-for-woocommerce' ) : '',
							ApplePay::is_enabled() ? '' : __( 'Tick "Accept Apple Pay" and save to show the button to shoppers.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						)
					)
				),
			)
		);
	}
}
