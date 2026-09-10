<?php
/**
 * Card brand detection and labels.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Maps CardSecure tokens and BIN responses to card brands.
 *
 * CardSecure tokens keep the first two digits of the PAN after the leading "9",
 * which is enough to infer the brand without ever seeing the card number.
 */
final class CardTypes {

	const ALL = array( 'visa', 'mastercard', 'amex', 'discover', 'diners', 'jcb' );

	/**
	 * Options for the settings multiselect.
	 */
	public static function options(): array {
		return array(
			'visa'       => __( 'Visa', 'paradox-cardpointe-gateway' ),
			'mastercard' => __( 'Mastercard', 'paradox-cardpointe-gateway' ),
			'amex'       => __( 'American Express', 'paradox-cardpointe-gateway' ),
			'discover'   => __( 'Discover', 'paradox-cardpointe-gateway' ),
			'diners'     => __( 'Diners Club', 'paradox-cardpointe-gateway' ),
			'jcb'        => __( 'JCB', 'paradox-cardpointe-gateway' ),
		);
	}

	/**
	 * Human label for a brand.
	 *
	 * @param string $brand Brand slug.
	 */
	public static function label( string $brand ): string {
		$options = self::options();
		return $options[ $brand ] ?? ucfirst( $brand );
	}

	/**
	 * Infers the brand from a CardSecure token's second and third digits.
	 *
	 * @param string $token Token.
	 * @return string|null
	 */
	public static function from_token_prefix( string $token ) {
		if ( ! preg_match( '/^9(\d{2})/', $token, $m ) ) {
			return null;
		}
		$two = (int) $m[1];
		$one = (int) $m[1][0];

		if ( 4 === $one ) {
			return 'visa';
		}
		if ( ( $two >= 51 && $two <= 55 ) || ( $two >= 22 && $two <= 27 ) ) {
			return 'mastercard';
		}
		if ( in_array( $two, array( 34, 37 ), true ) ) {
			return 'amex';
		}
		if ( in_array( $two, array( 60, 64, 65 ), true ) ) {
			return 'discover';
		}
		if ( 35 === $two ) {
			return 'jcb';
		}
		if ( in_array( $two, array( 30, 36, 38, 39 ), true ) ) {
			return 'diners';
		}
		return null;
	}

	/**
	 * Maps the BIN service "product" code to a brand.
	 *
	 * @param string $product V, M, A, D or N.
	 * @return string|null
	 */
	public static function from_bin_product( string $product ) {
		switch ( strtoupper( $product ) ) {
			case 'V':
				return 'visa';
			case 'M':
				return 'mastercard';
			case 'A':
				return 'amex';
			case 'D':
				return 'discover';
			default:
				return null;
		}
	}

	/**
	 * Resolves the brand for a token, using the BIN service when enabled and falling back to the prefix.
	 *
	 * @param string      $token       Token.
	 * @param Client|null $client      Client for BIN lookups; null disables the lookup.
	 * @return string|null
	 */
	public static function resolve( string $token, ?Client $client ) {
		$brand = null;

		if ( null !== $client ) {
			$key    = 'paradox_cardpointe_bin_' . md5( $client->credentials()->merchant_id . '|' . $token );
			$cached = get_transient( $key );
			if ( is_string( $cached ) && '' !== $cached ) {
				$brand = 'none' === $cached ? null : $cached;
			} else {
				try {
					$response = $client->bin( $token );
					$brand    = self::from_bin_product( $response->string( 'product' ) );
					set_transient( $key, $brand ?: 'none', 12 * HOUR_IN_SECONDS );
				} catch ( ApiException $e ) {
					$brand = null;
				}
			}
		}

		if ( null === $brand ) {
			$brand = self::from_token_prefix( $token );
		}

		/**
		 * Filters the detected card brand.
		 *
		 * @param string|null $brand Brand slug or null.
		 * @param string      $token Token (masked usage only).
		 */
		return apply_filters( 'paradox_cardpointe_detected_card_type', $brand, $token );
	}

	/**
	 * Icon URL for a brand, from WooCommerce's bundled SVGs.
	 *
	 * @param string $brand Brand slug.
	 */
	public static function icon_url( string $brand ): string {
		$map = array(
			'visa'       => 'visa',
			'mastercard' => 'mastercard',
			'amex'       => 'amex',
			'discover'   => 'discover',
			'diners'     => 'diners',
			'jcb'        => 'jcb',
		);
		$file = $map[ $brand ] ?? $brand;
		return WC()->plugin_url() . '/assets/images/icons/credit-cards/' . $file . '.svg';
	}

	/**
	 * WooCommerce card_type value for WC_Payment_Token_CC (matches WC's built-in labels).
	 *
	 * @param string $brand Brand slug.
	 */
	public static function wc_card_type( string $brand ): string {
		switch ( $brand ) {
			case 'amex':
				return 'american express';
			default:
				return $brand;
		}
	}

	/**
	 * Brand slug from a WooCommerce token card_type value.
	 *
	 * @param string $card_type WC card type.
	 */
	public static function from_wc_card_type( string $card_type ): string {
		$card_type = strtolower( $card_type );
		return 'american express' === $card_type ? 'amex' : $card_type;
	}
}
