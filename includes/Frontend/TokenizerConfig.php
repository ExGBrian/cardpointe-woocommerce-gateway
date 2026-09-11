<?php
/**
 * Hosted iFrame Tokenizer configuration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Frontend;

use ParadoxSolutions\CardPointe\Gateway\AbstractGateway;
use ParadoxSolutions\CardPointe\Settings\FormFields;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the tokenizer iframe URL and query parameters for each gateway.
 */
final class TokenizerConfig {

	/**
	 * Query parameters for the tokenizer.
	 *
	 * @param AbstractGateway $gateway Gateway.
	 */
	public static function params( AbstractGateway $gateway ): array {
		$css = (string) $gateway->get_option( 'iframe_css', FormFields::default_iframe_css( $gateway->payment_type() ) );
		/**
		 * Filters the CSS injected into the tokenizer iframe.
		 *
		 * @param string $css        CSS.
		 * @param string $gateway_id Gateway ID.
		 */
		$css = (string) apply_filters( 'paradox_cardpointe_tokenizer_css', $css, $gateway->id );

		if ( 'echeck' === $gateway->payment_type() ) {
			$params = array(
				'useexpiry'             => 'false',
				'usecvv'                => 'false',
				'enhancedresponse'      => 'true',
				'formatinput'           => 'false',
				'cardnumbernumericonly' => 'false',
				'fullmobilekeyboard'    => 'true',
				'tokenizewheninactive'  => 'true',
				'inactivityto'          => '2000',
				'invalidinputevent'     => 'true',
				'sendcssloadedevent'    => 'true',
				'sendcardtypingevent'   => 'true',
				'placeholder'           => __( 'Routing number / Account number', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'cardtitle'             => __( 'Bank routing and account number', 'paradox-cardpointe-gateway-for-woocommerce' ),
			);
		} else {
			$params = array(
				'useexpiry'              => 'true',
				'useexpiryfield'         => 'true',
				'usecvv'                 => 'true',
				'enhancedresponse'       => 'true',
				'formatinput'            => 'true',
				'cardnumbernumericonly'  => 'true',
				'cvvnumericonly'         => 'true',
				'invalidcreditcardevent' => 'true',
				'invalidcvvevent'        => 'true',
				'invalidexpiryevent'     => 'true',
				'tokenizewheninactive'   => 'true',
				'inactivityto'           => '500',
				'sendcssloadedevent'     => 'true',
				'sendcardtypingevent'    => 'true',
				'placeholder'            => __( 'Card number', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'placeholdercvv'         => __( 'CVV', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'placeholdermonth'       => 'MM',
				'placeholderyear'        => 'YYYY',
				'cardlabel'              => __( 'Card number', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'expirylabel'            => __( 'Expiration date', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'cvvlabel'               => __( 'Security code', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'cardtitle'              => __( 'Credit card number', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'expirymonthtitle'       => __( 'Expiration month', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'expiryyeartitle'        => __( 'Expiration year', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'cvvtitle'               => __( 'Card security code', 'paradox-cardpointe-gateway-for-woocommerce' ),
			);
		}

		if ( '' !== trim( $css ) ) {
			$params['css'] = preg_replace( '/\s+/', ' ', trim( $css ) );
		}

		/**
		 * Filters the tokenizer query parameters.
		 *
		 * @param array  $params     Parameters.
		 * @param string $gateway_id Gateway ID.
		 */
		return apply_filters( 'paradox_cardpointe_tokenizer_params', $params, $gateway->id );
	}

	/**
	 * Full tokenizer URL including the encoded query string.
	 *
	 * @param AbstractGateway $gateway Gateway.
	 */
	public static function url( AbstractGateway $gateway ): string {
		$base   = $gateway->credentials()->tokenizer_url();
		$params = self::params( $gateway );
		$parts  = array();
		foreach ( $params as $key => $value ) {
			$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return $base . ( $parts ? '?' . implode( '&', $parts ) : '' );
	}

	/**
	 * Suggested iframe height in pixels.
	 *
	 * The frame cannot resize itself to its content across origins, so this has to cover
	 * the tallest the form gets. With the default stylesheet a card form is three
	 * label-plus-input groups; the allowance absorbs validation messages and taller fonts
	 * rather than clipping the security code field off the bottom.
	 *
	 * @param AbstractGateway $gateway Gateway.
	 */
	public static function height( AbstractGateway $gateway ): int {
		$height = 'echeck' === $gateway->payment_type() ? 80 : 285;
		/**
		 * Filters the tokenizer iframe height.
		 *
		 * @param int    $height     Height in px.
		 * @param string $gateway_id Gateway ID.
		 */
		return (int) apply_filters( 'paradox_cardpointe_tokenizer_height', $height, $gateway->id );
	}
}
