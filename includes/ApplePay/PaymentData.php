<?php
/**
 * Apple Pay payment token handling for CardSecure.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\ApplePay;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the encrypted token Safari produces and reformats it the way CardSecure
 * expects it in the tokenize request.
 *
 * The token is opaque here: the merchant never holds the key that decrypts it. What
 * this class checks is only that each field has the shape Apple documents, so that
 * garbage never reaches CardSecure and nothing but base64 and hex is interpolated
 * into the devicedata string.
 */
final class PaymentData {

	/** CardSecure encryption handler for Apple Pay payloads. */
	const HANDLER = 'EC_APPLE_PAY';

	/** Apple Pay payloads expire two minutes after Safari issues them. */
	const MAX_AGE_SECONDS = 120;

	const BASE64_PATTERN = '/^[A-Za-z0-9+\/]+={0,2}$/';
	const HEX_PATTERN    = '/^[0-9a-fA-F]+$/';

	/**
	 * Decodes and validates the paymentData object from the Apple Pay JS API.
	 *
	 * @param string $json JSON as posted by the browser.
	 * @return array Keys: data, signature, ephemeralPublicKey, publicKeyHash, transactionId, applicationData.
	 *
	 * @throws \InvalidArgumentException Describing what was wrong (for the log, not the shopper).
	 */
	public static function from_json( string $json ): array {
		$json = trim( $json );
		if ( '' === $json || strlen( $json ) > 20000 ) {
			throw new \InvalidArgumentException( 'payment data missing or oversized' );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			throw new \InvalidArgumentException( 'payment data is not a JSON object' );
		}

		$version = (string) ( $decoded['version'] ?? '' );
		if ( 'EC_v1' !== $version ) {
			// RSA_v1 payloads carry a wrappedKey instead of an ephemeral key and are not what
			// an elliptic-curve Payment Processing Certificate produces.
			throw new \InvalidArgumentException( 'unsupported token version "' . substr( preg_replace( '/[^A-Za-z0-9_]/', '', $version ), 0, 12 ) . '"' );
		}

		$header = isset( $decoded['header'] ) && is_array( $decoded['header'] ) ? $decoded['header'] : array();

		$out = array(
			'data'               => self::base64_field( $decoded, 'data', 16000 ),
			'signature'          => self::base64_field( $decoded, 'signature', 16000 ),
			'ephemeralPublicKey' => self::base64_field( $header, 'ephemeralPublicKey', 400 ),
			'publicKeyHash'      => self::base64_field( $header, 'publicKeyHash', 200 ),
			'transactionId'      => self::hex_field( $header, 'transactionId', 128, true ),
			'applicationData'    => self::hex_field( $header, 'applicationData', 128, false ),
		);

		return $out;
	}

	/**
	 * The devicedata string CardSecure expects for an Apple Pay payload.
	 *
	 * Per CardPointe's guide the encrypted data comes first and bare; the remaining
	 * parameters are key=value pairs and their order is not significant. The values
	 * are base64 and hex exactly as Apple produced them, not URL-encoded.
	 *
	 * @param array $payment_data Output of from_json().
	 */
	public static function devicedata( array $payment_data ): string {
		$string = $payment_data['data']
			. '&ectype=apple'
			. '&ecsig=' . $payment_data['signature']
			. '&eckey=' . $payment_data['ephemeralPublicKey']
			. '&ectid=' . $payment_data['transactionId'];

		if ( '' !== $payment_data['applicationData'] ) {
			$string .= '&echash=' . $payment_data['applicationData'];
		}

		$string .= '&ecpublickeyhash=' . $payment_data['publicKeyHash'];

		/**
		 * Filters the devicedata string sent to CardSecure for an Apple Pay payload.
		 *
		 * @param string $string       devicedata.
		 * @param array  $payment_data Validated token fields.
		 */
		return (string) apply_filters( 'paradox_cardpointe_apple_pay_devicedata', $string, $payment_data );
	}

	/**
	 * Stable identifier for a payload, so one checkout request tokenizes it once.
	 *
	 * @param array $payment_data Output of from_json().
	 */
	public static function fingerprint( array $payment_data ): string {
		return sha1( $payment_data['transactionId'] . '|' . $payment_data['data'] );
	}

	/**
	 * Plugin brand slug for the network name Apple reports on paymentMethod.network.
	 *
	 * @param string $network Apple network name (Visa, MasterCard, AmEx, Discover, JCB, ...).
	 */
	public static function brand_from_network( string $network ): string {
		$key = strtolower( preg_replace( '/[^a-z]/i', '', $network ) );
		$map = array(
			'visa'            => 'visa',
			'mastercard'      => 'mastercard',
			'amex'            => 'amex',
			'americanexpress' => 'amex',
			'discover'        => 'discover',
			'jcb'             => 'jcb',
		);
		return $map[ $key ] ?? '';
	}

	/**
	 * Reads a required base64 field.
	 *
	 * @param array  $source Array to read from.
	 * @param string $key    Key.
	 * @param int    $max    Maximum length.
	 *
	 * @throws \InvalidArgumentException When missing or malformed.
	 */
	private static function base64_field( array $source, string $key, int $max ): string {
		$value = isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? trim( $source[ $key ] ) : '';
		if ( '' === $value || strlen( $value ) > $max || ! preg_match( self::BASE64_PATTERN, $value ) ) {
			throw new \InvalidArgumentException( $key . ' is missing or not base64' );
		}
		return $value;
	}

	/**
	 * Reads a hex field.
	 *
	 * @param array  $source   Array to read from.
	 * @param string $key      Key.
	 * @param int    $max      Maximum length.
	 * @param bool   $required Whether the field must be present.
	 *
	 * @throws \InvalidArgumentException When required and missing, or malformed.
	 */
	private static function hex_field( array $source, string $key, int $max, bool $required ): string {
		$value = isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? trim( $source[ $key ] ) : '';
		if ( '' === $value ) {
			if ( $required ) {
				throw new \InvalidArgumentException( $key . ' is missing' );
			}
			return '';
		}
		if ( strlen( $value ) > $max || ! preg_match( self::HEX_PATTERN, $value ) ) {
			throw new \InvalidArgumentException( $key . ' is not hex' );
		}
		return $value;
	}
}
