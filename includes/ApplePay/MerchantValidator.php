<?php
/**
 * Apple Pay merchant validation.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\ApplePay;

use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a validation URL into a merchant session.
 *
 * Apple requires the request to present the Merchant Identity Certificate as a TLS
 * client certificate, which WordPress's HTTP API cannot express directly, so the PEM
 * is attached to the underlying cURL handle for this one request only.
 *
 * The same call doubles as the setup test: a session from Apple's production endpoint
 * proves the Merchant ID, the certificate and the domain registration in one go.
 */
final class MerchantValidator {

	/** Hosts Apple documents for merchant validation, production and sandbox, all regions. */
	const HOST_PATTERN = '/^(?:cn-)?apple-pay-gateway(?:-[a-z0-9]+)*\.apple\.com$/i';

	/** Endpoint used by the settings screen test, where there is no Safari to supply one. */
	const TEST_URL = 'https://apple-pay-gateway.apple.com/paymentservices/paymentSession';

	const TIMEOUT = 20;

	/**
	 * Whether a URL is one Apple would hand out for validation.
	 *
	 * Safari supplies the URL and the browser is untrusted, so anything that is not an
	 * HTTPS Apple Pay gateway host is refused before the server makes a request to it.
	 *
	 * @param string $url URL.
	 */
	public static function is_valid_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return false;
		}
		return (bool) preg_match( self::HOST_PATTERN, $parts['host'] );
	}

	/**
	 * Requests a merchant session from Apple.
	 *
	 * @param string $validation_url URL from the onvalidatemerchant event, or TEST_URL.
	 * @param array  $overrides      Optional merchant_id and cert_path to use instead of the
	 *                               saved settings (the settings screen tests unsaved values).
	 * @return array Merchant session, to be passed verbatim to completeMerchantValidation().
	 *
	 * @throws \RuntimeException With an administrator-facing explanation.
	 */
	public static function validate( string $validation_url, array $overrides = array() ): array {
		if ( ! self::is_valid_url( $validation_url ) ) {
			throw new \RuntimeException( __( 'The validation URL is not an Apple Pay gateway host.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			throw new \RuntimeException( __( 'PHP cURL is required to present the certificate to Apple.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$merchant_id = ! empty( $overrides['merchant_id'] ) ? (string) $overrides['merchant_id'] : ApplePay::merchant_id();
		$pem         = ! empty( $overrides['cert_path'] ) ? (string) $overrides['cert_path'] : ApplePay::cert_path();

		if ( '' === $merchant_id ) {
			throw new \RuntimeException( __( 'The Apple Merchant ID is empty.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}
		if ( '' === $pem || ! @is_readable( $pem ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( __( 'The certificate file cannot be read at the configured path.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$body = array(
			'merchantIdentifier' => $merchant_id,
			'displayName'        => ApplePay::display_name(),
			'initiative'         => 'web',
			'initiativeContext'  => ApplePay::domain(),
		);

		/**
		 * Filters the merchant validation request body.
		 *
		 * @param array  $body           Body.
		 * @param string $validation_url Apple validation URL.
		 */
		$body = apply_filters( 'paradox_cardpointe_apple_pay_validation_body', $body, $validation_url );

		// One PEM holds both halves, so it serves as certificate and key.
		$attach = static function ( $handle, $parsed_args, $url ) use ( $validation_url, $pem ) {
			if ( $url !== $validation_url ) {
				return;
			}
			curl_setopt( $handle, CURLOPT_SSLCERT, $pem ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_SSLCERTTYPE, 'PEM' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_SSLKEY, $pem ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_SSLKEYTYPE, 'PEM' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		};

		$started = microtime( true );

		add_action( 'http_api_curl', $attach, 10, 3 );
		try {
			$response = wp_remote_post(
				$validation_url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'        => wp_json_encode( $body ),
					'user-agent'  => 'ParadoxCardPointe/' . PARADOX_CARDPOINTE_VERSION,
				)
			);
		} finally {
			remove_action( 'http_api_curl', $attach, 10 );
		}

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: transport error */
					__( 'The connection to Apple failed: %s', 'paradox-cardpointe-gateway-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$session = json_decode( $raw, true );

		if ( 200 !== $status ) {
			$detail = is_array( $session ) && ! empty( $session['statusMessage'] ) ? (string) $session['statusMessage'] : wp_strip_all_tags( $raw );
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: HTTP status, 2: Apple's message */
					__( 'Apple refused the request (HTTP %1$d): %2$s', 'paradox-cardpointe-gateway-for-woocommerce' ),
					$status,
					'' !== trim( $detail ) ? substr( trim( $detail ), 0, 300 ) : __( 'no details given', 'paradox-cardpointe-gateway-for-woocommerce' )
				)
			);
		}
		if ( ! is_array( $session ) || empty( $session['merchantSessionIdentifier'] ) ) {
			throw new \RuntimeException( __( 'Apple returned an unreadable merchant session.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		Plugin::instance()->logger()->info(
			'Apple Pay merchant session created',
			array(
				'host'   => (string) wp_parse_url( $validation_url, PHP_URL_HOST ),
				'domain' => $body['initiativeContext'],
				'ms'     => $elapsed,
			)
		);

		return $session;
	}

	/**
	 * Likely causes for a failed validation, for the settings screen.
	 *
	 * @param string $message Exception message from validate().
	 * @return string[]
	 */
	public static function explain( string $message ): array {
		$hints = array();
		$lower = strtolower( $message );

		if ( false !== strpos( $lower, 'could not load pem' ) || false !== strpos( $lower, 'unable to set private key' ) || false !== strpos( $lower, 'error 58' ) || false !== strpos( $lower, 'unable to use client certificate' ) ) {
			$hints[] = __( 'cURL could not use the PEM as a client certificate. It must contain both the certificate and its private key, with no passphrase.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( false !== strpos( $lower, 'handshake' ) || false !== strpos( $lower, 'error 35' ) || false !== strpos( $lower, 'error 56' ) || false !== strpos( $lower, 'alert' ) ) {
			$hints[] = __( 'Apple turned the certificate away during the TLS handshake. That usually means it is the wrong certificate (it must be the Merchant Identity Certificate, not the Payment Processing Certificate), or it has expired or been revoked.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( false !== strpos( $lower, 'http 4' ) || false !== strpos( $lower, 'http 5' ) ) {
			$hints[] = sprintf(
				/* translators: %s: domain */
				__( 'Apple accepted the certificate but not the request. Check that the Apple Merchant ID is exactly the one the certificate was created under, and that the domain %s is registered and shows as verified under that Merchant ID in the Apple Developer portal.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				ApplePay::domain()
			);
		}
		if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'error 28' ) || false !== strpos( $lower, 'could not resolve' ) || false !== strpos( $lower, 'error 6' ) || false !== strpos( $lower, 'error 7' ) ) {
			$hints[] = __( 'The server could not reach apple-pay-gateway.apple.com on port 443. Ask your host whether outbound connections are blocked by a firewall.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		return $hints;
	}
}
