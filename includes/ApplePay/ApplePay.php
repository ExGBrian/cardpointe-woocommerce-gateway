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
 * Settings, availability and the shopper-facing merchant validation endpoint.
 *
 * Card data never comes through here. Apple hands the browser an encrypted payment
 * token, the browser posts it with the checkout form, and PaymentSource passes it to
 * CardSecure, which holds the decryption key (the Payment Processing Certificate is
 * issued against a CSR that CardPointe generates) and returns an ordinary CardSecure
 * token. From there the payment is a normal card authorization.
 *
 * The only secret the store holds is the Merchant Identity Certificate: one PEM file
 * with the certificate and its private key, presented to Apple when a payment sheet
 * is opened (see MerchantValidator and CertificateStore).
 */
final class ApplePay {

	const WALLET          = 'apple_pay';
	const AJAX_VALIDATE   = 'paradox_cardpointe_apple_pay_validate';
	const NONCE_ACTION    = 'paradox_cardpointe_apple_pay';
	const VERIFIED_OPTION = 'paradox_cardpointe_apple_pay_verified';

	/** Where Apple looks for the domain verification file, with and without the .txt it now issues. */
	const ASSOCIATION_PATHS = array(
		'.well-known/apple-developer-merchantid-domain-association.txt',
		'.well-known/apple-developer-merchantid-domain-association',
	);

	/**
	 * Registers hooks.
	 */
	public function __construct() {
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
	 * Whether the merchant switched Apple Pay on for shoppers.
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
	 * Path to the PEM holding the Merchant Identity Certificate and its private key.
	 */
	public static function cert_path(): string {
		return self::setting( 'cert_path' );
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
	 * Whether there is enough to ask Apple for a merchant session: a Merchant ID, cURL
	 * and a readable PEM. Independent of the shopper-facing switch, so the setup can
	 * be tested before it goes live.
	 */
	public static function has_credentials(): bool {
		$cert = self::cert_path();
		return '' !== self::merchant_id()
			&& function_exists( 'curl_init' )
			&& '' !== $cert
			&& @is_readable( $cert ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir raises a warning for paths outside it.
	}

	/**
	 * Whether Apple Pay is switched on and usable. Deliberately cheap: it runs on every checkout.
	 */
	public static function is_configured(): bool {
		return self::is_enabled() && self::has_credentials();
	}

	/**
	 * Everything the settings screen should tell the merchant about the setup.
	 *
	 * @return array { problems: string[] (block Apple Pay), notes: string[] (worth knowing) }
	 */
	public static function setup_report(): array {
		$problems = array();
		$notes    = array();

		if ( ! self::is_enabled() ) {
			$notes[] = __( 'Apple Pay is not switched on for shoppers yet ("Accept Apple Pay"). You can still test the setup first.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( '' === self::merchant_id() ) {
			$problems[] = __( 'The Apple Merchant ID is empty.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			$problems[] = __( 'PHP cURL is not available. It is needed to present the certificate to Apple; ask your host to enable it.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		if ( ! is_ssl() && ! wc_checkout_is_https() ) {
			$problems[] = __( 'This site is not served over HTTPS. Apple Pay only works on HTTPS pages, in sandbox mode too.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		$certificate = CertificateStore::inspect_file( self::cert_path() );
		$problems    = array_merge( $problems, $certificate['errors'] );
		$notes       = array_merge( $notes, $certificate['warnings'] );

		if ( '' !== self::merchant_id() && '' !== $certificate['merchant_id'] && 0 !== strcasecmp( self::merchant_id(), $certificate['merchant_id'] ) ) {
			$problems[] = sprintf(
				/* translators: 1: merchant ID in the certificate, 2: merchant ID in the settings */
				__( 'The certificate was issued for %1$s but the Apple Merchant ID entered is %2$s. They must be the same.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				$certificate['merchant_id'],
				self::merchant_id()
			);
		}

		if ( ! self::domain_file_found() ) {
			$notes[] = sprintf(
				/* translators: 1: folder path, 2: URL */
				__( 'Apple\'s domain verification file was not found in %1$s. Apple has to be able to load it from %2$s to verify this domain. If the domain already shows as verified in the Apple Developer portal, you can ignore this.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				self::association_dir(),
				self::association_url()
			);
		}

		return array(
			'problems' => $problems,
			'notes'    => $notes,
		);
	}

	/**
	 * Folder the domain verification file belongs in.
	 */
	public static function association_dir(): string {
		return CertificateStore::web_root() . '.well-known/';
	}

	/**
	 * Public URL Apple fetches during domain verification.
	 */
	public static function association_url(): string {
		return home_url( '/' . self::ASSOCIATION_PATHS[0] );
	}

	/**
	 * Whether the merchant placed the verification file on disk.
	 */
	public static function domain_file_found(): bool {
		foreach ( self::ASSOCIATION_PATHS as $path ) {
			if ( @is_readable( CertificateStore::web_root() . $path ) || @is_readable( trailingslashit( ABSPATH ) . $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * "Verified with Apple" marker
	 * ------------------------------------------------------------------ */

	/**
	 * Identifies one combination of Merchant ID, certificate file and domain.
	 *
	 * Tied to the file's modification time, so replacing the certificate, editing the
	 * Merchant ID or moving the site to another domain all require a fresh test.
	 *
	 * @param string $merchant_id Merchant ID.
	 * @param string $cert_path   Certificate path.
	 */
	public static function verification_signature( string $merchant_id, string $cert_path ): string {
		$mtime = '' !== $cert_path && @is_readable( $cert_path ) ? (string) filemtime( $cert_path ) : '0'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return sha1( strtolower( $merchant_id ) . '|' . $cert_path . '|' . $mtime . '|' . strtolower( self::domain() ) );
	}

	/**
	 * Records that Apple issued a merchant session for these values.
	 *
	 * @param string $merchant_id Merchant ID that was tested.
	 * @param string $cert_path   Certificate path that was tested.
	 */
	public static function mark_verified( string $merchant_id, string $cert_path ) {
		update_option(
			self::VERIFIED_OPTION,
			array(
				'signature' => self::verification_signature( $merchant_id, $cert_path ),
				'time'      => time(),
				'domain'    => self::domain(),
			),
			false
		);
	}

	/**
	 * Whether the saved settings are the ones Apple last accepted.
	 */
	public static function is_verified(): bool {
		$stored = get_option( self::VERIFIED_OPTION );
		return is_array( $stored )
			&& ! empty( $stored['signature'] )
			&& hash_equals( (string) $stored['signature'], self::verification_signature( self::merchant_id(), self::cert_path() ) );
	}

	/**
	 * When Apple last accepted the saved settings (0 when never).
	 */
	public static function verified_at(): int {
		$stored = get_option( self::VERIFIED_OPTION );
		return self::is_verified() && is_array( $stored ) ? (int) ( $stored['time'] ?? 0 ) : 0;
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
	 * Merchant validation endpoint (shoppers)
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
