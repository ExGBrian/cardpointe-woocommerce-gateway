<?php
/**
 * Apple Pay on the web.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\ApplePay;

use ParadoxSolutions\CardPointe\Gateway\CardGateway;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, availability, and the two server-side pieces Apple Pay on the web needs:
 * the merchant validation endpoint and the domain verification file.
 *
 * Card data never comes through here. Apple hands the browser an encrypted payment
 * token, the browser posts it with the checkout form, and PaymentSource passes it to
 * CardSecure, which holds the decryption key (the Payment Processing Certificate is
 * issued against a CSR that CardPointe generates) and returns an ordinary CardSecure
 * token. From there the payment is a normal card authorization.
 */
final class ApplePay {

	const WALLET        = 'apple_pay';
	const AJAX_VALIDATE = 'paradox_cardpointe_apple_pay_validate';
	const NONCE_ACTION  = 'paradox_cardpointe_apple_pay';

	/** Apple's verification file path, with and without the .txt suffix Apple now issues. */
	const ASSOCIATION_PATHS = array(
		'.well-known/apple-developer-merchantid-domain-association',
		'.well-known/apple-developer-merchantid-domain-association.txt',
	);

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve_domain_association' ), 0 );
		add_action( 'wc_ajax_' . self::AJAX_VALIDATE, array( $this, 'ajax_validate_merchant' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings (stored with the card gateway)
	 * ------------------------------------------------------------------ */

	/**
	 * Reads one apple_pay_* setting as a string.
	 *
	 * @param string $key     Key without the apple_pay_ prefix.
	 * @param string $default Default.
	 */
	public static function setting( string $key, string $default = '' ): string {
		$settings = Credentials::settings();
		$value    = $settings[ 'apple_pay_' . $key ] ?? $default;
		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	/**
	 * Whether the merchant switched Apple Pay on.
	 */
	public static function is_enabled(): bool {
		return 'yes' === self::setting( 'enabled', 'no' );
	}

	/**
	 * Apple merchant identifier (merchant.com.example).
	 */
	public static function merchant_id(): string {
		return self::setting( 'merchant_id' );
	}

	/**
	 * Name shown on the payment sheet; Apple caps it at 64 characters.
	 */
	public static function display_name(): string {
		$name = self::setting( 'display_name' );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 64 ) : substr( $name, 0, 64 );
	}

	/**
	 * Path to the merchant identity certificate (PEM). A wp-config constant wins.
	 */
	public static function cert_path(): string {
		if ( defined( 'PARADOX_CARDPOINTE_APPLE_PAY_CERT_PATH' ) ) {
			return (string) PARADOX_CARDPOINTE_APPLE_PAY_CERT_PATH;
		}
		return self::setting( 'cert_path' );
	}

	/**
	 * Path to the private key (PEM). Falls back to the certificate file, which may hold both.
	 */
	public static function key_path(): string {
		if ( defined( 'PARADOX_CARDPOINTE_APPLE_PAY_KEY_PATH' ) ) {
			return (string) PARADOX_CARDPOINTE_APPLE_PAY_KEY_PATH;
		}
		$path = self::setting( 'key_path' );
		return '' !== $path ? $path : self::cert_path();
	}

	/**
	 * Private key passphrase, if the key is encrypted.
	 */
	public static function key_passphrase(): string {
		if ( defined( 'PARADOX_CARDPOINTE_APPLE_PAY_KEY_PASSPHRASE' ) ) {
			return (string) PARADOX_CARDPOINTE_APPLE_PAY_KEY_PASSPHRASE;
		}
		return self::setting( 'key_passphrase' );
	}

	/**
	 * Whether a wp-config constant overrides a setting.
	 *
	 * @param string $key cert_path|key_path|key_passphrase.
	 */
	public static function has_constant( string $key ): bool {
		return defined( 'PARADOX_CARDPOINTE_APPLE_PAY_' . strtoupper( $key ) );
	}

	/**
	 * Contents of the domain verification file, when the merchant pasted it into the settings.
	 */
	public static function domain_association(): string {
		return self::setting( 'domain_association' );
	}

	/**
	 * Domain registered with Apple. Must match the host the checkout is served from.
	 */
	public static function domain(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		/**
		 * Filters the domain sent to Apple as initiativeContext.
		 *
		 * @param string $host Host name.
		 */
		return (string) apply_filters( 'paradox_cardpointe_apple_pay_domain', $host );
	}

	/**
	 * Button appearance.
	 */
	public static function button_style(): string {
		$style = self::setting( 'button_style', 'black' );
		return in_array( $style, array( 'black', 'white', 'white-outline' ), true ) ? $style : 'black';
	}

	/**
	 * Button label variant.
	 */
	public static function button_type(): string {
		$type = self::setting( 'button_type', 'plain' );
		return in_array( $type, array( 'plain', 'buy', 'pay', 'check-out', 'order' ), true ) ? $type : 'plain';
	}

	/* ---------------------------------------------------------------------
	 * Configuration state
	 * ------------------------------------------------------------------ */

	/**
	 * Everything that still stands between the merchant and a working button.
	 *
	 * @return string[] Human readable problems; empty when fully configured.
	 */
	public static function configuration_problems(): array {
		$problems = array();

		if ( '' === self::merchant_id() ) {
			$problems[] = __( 'The Apple merchant identifier is empty.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			$problems[] = __( 'PHP cURL is not available; merchant validation needs it to present the merchant identity certificate to Apple.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		$cert = self::cert_path();
		if ( '' === $cert ) {
			$problems[] = __( 'The merchant identity certificate path is empty.', 'paradox-cardpointe-gateway-for-woocommerce' );
		} elseif ( ! is_readable( $cert ) ) {
			$problems[] = __( 'The merchant identity certificate file cannot be read at the configured path.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		$key = self::key_path();
		if ( '' !== $key && $key !== $cert && ! is_readable( $key ) ) {
			$problems[] = __( 'The private key file cannot be read at the configured path.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		if ( '' === self::domain_association() && ! self::physical_association_file_exists() ) {
			$problems[] = __( 'The domain verification file is not configured and none was found under /.well-known/.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		return $problems;
	}

	/**
	 * Whether everything needed to validate a merchant session is in place.
	 */
	public static function is_configured(): bool {
		if ( ! self::is_enabled() || '' === self::merchant_id() || ! function_exists( 'curl_init' ) ) {
			return false;
		}
		$cert = self::cert_path();
		$key  = self::key_path();
		return '' !== $cert && is_readable( $cert ) && '' !== $key && is_readable( $key );
	}

	/**
	 * Whether the merchant placed the verification file on disk instead of in the settings.
	 */
	public static function physical_association_file_exists(): bool {
		foreach ( self::ASSOCIATION_PATHS as $path ) {
			if ( is_readable( trailingslashit( ABSPATH ) . $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Public URL Apple fetches during domain verification.
	 */
	public static function association_url(): string {
		return home_url( '/' . self::ASSOCIATION_PATHS[1] );
	}

	/* ---------------------------------------------------------------------
	 * Availability at checkout
	 * ------------------------------------------------------------------ */

	/**
	 * Apple network names for the accepted card brands. Diners Club has no Apple Pay network.
	 *
	 * @param CardGateway $gateway Card gateway.
	 * @return string[]
	 */
	public static function networks( CardGateway $gateway ): array {
		$map      = array(
			'visa'       => 'visa',
			'mastercard' => 'masterCard',
			'amex'       => 'amex',
			'discover'   => 'discover',
			'jcb'        => 'jcb',
		);
		$networks = array();
		foreach ( $gateway->accepted_card_types() as $brand ) {
			if ( isset( $map[ $brand ] ) ) {
				$networks[] = $map[ $brand ];
			}
		}
		/**
		 * Filters the Apple Pay supported networks.
		 *
		 * @param string[] $networks Apple network names.
		 */
		return (array) apply_filters( 'paradox_cardpointe_apple_pay_networks', $networks );
	}

	/**
	 * Whether the button can be offered on the current page.
	 *
	 * Apple Pay always needs HTTPS, sandbox or not, and cannot vault a card for later
	 * charges, so it stays hidden for subscriptions, charge-on-release pre-orders and the
	 * My Account "add payment method" and "change payment method" screens.
	 *
	 * @param CardGateway $gateway Card gateway.
	 */
	public static function is_available( CardGateway $gateway ): bool {
		$available = self::is_configured()
			&& 'yes' === $gateway->enabled
			&& ( is_ssl() || wc_checkout_is_https() )
			&& ! is_add_payment_method_page()
			&& ! isset( $_GET['change_payment_method'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& ! $gateway->is_forced_save_context()
			&& ! empty( self::networks( $gateway ) );

		/**
		 * Filters whether the Apple Pay button is offered.
		 *
		 * @param bool        $available Availability.
		 * @param CardGateway $gateway   Gateway.
		 */
		return (bool) apply_filters( 'paradox_cardpointe_apple_pay_available', $available, $gateway );
	}

	/**
	 * Data the button needs for the current cart or order, or null when it should not render.
	 *
	 * The amount is rendered into the payment box, which WooCommerce refreshes whenever
	 * the totals change, so the sheet always opens with the current total.
	 *
	 * @param CardGateway $gateway Card gateway.
	 * @return array|null
	 */
	public static function button_context( CardGateway $gateway ) {
		if ( ! self::is_available( $gateway ) ) {
			return null;
		}

		$currency = get_woocommerce_currency();
		$total    = 0.0;

		if ( is_checkout_pay_page() ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( ! $order ) {
				return null;
			}
			$total    = (float) $order->get_total();
			$currency = $order->get_currency();
		} elseif ( WC()->cart ) {
			$total = (float) WC()->cart->get_total( 'edit' );
		}

		if ( $total <= 0 ) {
			return null;
		}

		return array(
			'amount'   => number_format( $total, wc_get_price_decimals(), '.', '' ),
			'currency' => $currency,
			'country'  => WC()->countries->get_base_country(),
			'label'    => self::display_name(),
			'networks' => self::networks( $gateway ),
			'style'    => self::button_style(),
			'type'     => self::button_type(),
		);
	}

	/**
	 * Static configuration for the front-end script.
	 */
	public static function client_config(): array {
		return array(
			'validateUrl' => \WC_AJAX::get_endpoint( self::AJAX_VALIDATE ),
			'checkoutUrl' => \WC_AJAX::get_endpoint( 'checkout' ),
			'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
			'buttonStyle' => self::button_style(),
			'buttonType'  => self::button_type(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Domain verification file
	 * ------------------------------------------------------------------ */

	/**
	 * Serves the pasted verification file at the path Apple fetches.
	 *
	 * Hooked early on init so it runs before any rewrite handling. A physical file in
	 * /.well-known/ is served by the web server first and never reaches here.
	 */
	public function maybe_serve_domain_association() {
		if ( ! self::is_enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = ltrim( (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! in_array( $path, self::ASSOCIATION_PATHS, true ) ) {
			return;
		}
		$content = self::domain_association();
		if ( '' === $content ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verification blob, served as text/plain.
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Merchant validation endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Exchanges Apple's validation URL for a merchant session (wc-ajax, shoppers need no login).
	 */
	public function ajax_validate_merchant() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 403 );
		}
		if ( ! self::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Apple Pay is not configured on this store.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 400 );
		}

		$validation_url = isset( $_POST['validation_url'] ) ? esc_url_raw( wp_unslash( $_POST['validation_url'] ) ) : '';

		try {
			$session = MerchantValidator::validate( $validation_url );
		} catch ( \Exception $e ) {
			Plugin::instance()->logger()->error( 'Apple Pay merchant validation failed', array( 'error' => $e->getMessage() ) );
			wp_send_json_error( array( 'message' => __( 'Apple Pay could not be started. Please try again or pay another way.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 502 );
		}

		wp_send_json_success( $session );
	}
}
