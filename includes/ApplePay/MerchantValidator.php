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
 * Turns the validation URL Safari hands us into a merchant session.
 *
 * Apple requires the request to present the merchant identity certificate as a TLS
 * client certificate, which WordPress's HTTP API cannot express directly, so the
 * certificate is attached to the underlying cURL handle for this one request only.
 */
final class MerchantValidator {

	/**
	 * Hosts Apple documents for merchant validation, production and sandbox, all regions.
	 */
	const HOST_PATTERN = '/^(?:cn-)?apple-pay-gateway(?:-[a-z0-9]+)*\.apple\.com$/i';

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
	 * @param string $validation_url URL from the onvalidatemerchant event.
	 * @return array Merchant session, to be passed verbatim to completeMerchantValidation().
	 *
	 * @throws \RuntimeException When the URL, certificate, transport or response is unusable.
	 */
	public static function validate( string $validation_url ): array {
		if ( ! self::is_valid_url( $validation_url ) ) {
			throw new \RuntimeException( 'The validation URL is not an Apple Pay gateway host.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			throw new \RuntimeException( 'PHP cURL is required to present the merchant identity certificate.' );
		}

		$cert = ApplePay::cert_path();
		$key  = ApplePay::key_path();
		if ( '' === $cert || ! is_readable( $cert ) || '' === $key || ! is_readable( $key ) ) {
			throw new \RuntimeException( 'The merchant identity certificate or key is not readable.' );
		}

		$body = array(
			'merchantIdentifier' => ApplePay::merchant_id(),
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

		$passphrase = ApplePay::key_passphrase();
		$attach     = static function ( $handle, $parsed_args, $url ) use ( $validation_url, $cert, $key, $passphrase ) {
			if ( $url !== $validation_url ) {
				return;
			}
			curl_setopt( $handle, CURLOPT_SSLCERT, $cert ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_SSLCERTTYPE, 'PEM' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_SSLKEY, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_SSLKEYTYPE, 'PEM' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			if ( '' !== $passphrase ) {
				curl_setopt( $handle, CURLOPT_KEYPASSWD, $passphrase ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
			}
		};

		$logger  = Plugin::instance()->logger();
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
		$host    = (string) wp_parse_url( $validation_url, PHP_URL_HOST );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Apple did not accept the connection: ' . $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$session = json_decode( $raw, true );

		if ( 200 !== $status ) {
			throw new \RuntimeException( sprintf( 'Apple returned HTTP %d: %s', $status, substr( wp_strip_all_tags( $raw ), 0, 300 ) ) );
		}
		if ( ! is_array( $session ) || empty( $session['merchantSessionIdentifier'] ) ) {
			throw new \RuntimeException( 'Apple returned an unreadable merchant session.' );
		}

		$logger->info( 'Apple Pay merchant session created', array( 'host' => $host, 'domain' => $body['initiativeContext'], 'ms' => $elapsed ) );

		return $session;
	}
}
