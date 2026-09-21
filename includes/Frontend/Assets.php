<?php
/**
 * Script and style registration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Frontend;

use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
use ParadoxSolutions\CardPointe\Compatibility;
use ParadoxSolutions\CardPointe\Gateway\CardTypes;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers front-end and admin assets.
 */
final class Assets {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin' ) );
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
		add_filter( 'script_loader_tag', array( $this, 'sdk_crossorigin' ), 10, 2 );
	}

	/**
	 * Registers the Apple Pay core script, with Apple's SDK in front of it when other
	 * browsers are to be supported. Used by the classic pages, the blocks and the settings screen.
	 *
	 * Both are deferred, which keeps them off the critical path and still runs them in
	 * order; WordPress quietly makes them blocking where a non-deferred script depends on them.
	 */
	public static function register_apple_pay() {
		if ( wp_script_is( 'paradox-cardpointe-apple-pay-core', 'registered' ) ) {
			return;
		}
		$deps = array();
		if ( ApplePay::other_browsers() ) {
			wp_register_script( 'paradox-cardpointe-apple-pay-sdk', ApplePay::SDK_URL, array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Apple versions the URL itself.
			$deps[] = 'paradox-cardpointe-apple-pay-sdk';
		}
		wp_register_script( 'paradox-cardpointe-apple-pay-core', PARADOX_CARDPOINTE_URL . 'assets/js/apple-pay-core.js', $deps, PARADOX_CARDPOINTE_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'paradox-cardpointe-apple-pay-core', 'paradox_cardpointe_apple_pay', ApplePay::client_config() );
	}

	/**
	 * Apple Pay for classic (shortcode) pages: the credit card box and the express buttons.
	 */
	public static function enqueue_apple_pay() {
		self::register_tokenizer();
		self::register_apple_pay();
		wp_enqueue_style( 'paradox-cardpointe-checkout' );
		wp_enqueue_script( 'paradox-cardpointe-apple-pay', PARADOX_CARDPOINTE_URL . 'assets/js/apple-pay.js', array( 'paradox-cardpointe-apple-pay-core' ), PARADOX_CARDPOINTE_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * Apple asks for its SDK to be loaded with the crossorigin attribute.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 */
	public function sdk_crossorigin( $tag, $handle ) {
		if ( 'paradox-cardpointe-apple-pay-sdk' === $handle && false === strpos( $tag, 'crossorigin' ) ) {
			$tag = str_replace( '<script ', '<script crossorigin ', $tag );
		}
		return $tag;
	}

	/**
	 * Whether the current front-end page can show an Apple Pay button.
	 *
	 * @param bool $checkoutish Whether this is a checkout-like page.
	 */
	private function page_wants_apple_pay( bool $checkoutish ): bool {
		if ( ! ApplePay::is_configured() || is_add_payment_method_page() || isset( $_GET['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		if ( $checkoutish ) {
			return ApplePay::is_location_enabled( 'payment_box' ) || ApplePay::is_location_enabled( 'checkout' );
		}
		if ( is_cart() ) {
			return ApplePay::is_location_enabled( 'cart' );
		}
		return is_product() && ApplePay::is_location_enabled( 'product' );
	}

	/**
	 * Registers the shared tokenizer bridge (used by classic checkout and Blocks).
	 */
	public static function register_tokenizer() {
		if ( ! wp_script_is( 'paradox-cardpointe-tokenizer', 'registered' ) ) {
			wp_register_script( 'paradox-cardpointe-tokenizer', PARADOX_CARDPOINTE_URL . 'assets/js/tokenizer.js', array(), PARADOX_CARDPOINTE_VERSION, true );
		}
		if ( ! wp_style_is( 'paradox-cardpointe-checkout', 'registered' ) ) {
			wp_register_style( 'paradox-cardpointe-checkout', PARADOX_CARDPOINTE_URL . 'assets/css/checkout.css', array(), PARADOX_CARDPOINTE_VERSION );
		}
	}

	/**
	 * Front-end assets: the card form on checkout-like pages, and Apple Pay wherever a
	 * button may appear (which also includes the cart and single product pages).
	 */
	public function frontend() {
		self::register_tokenizer();

		$is_change_payment = isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$checkoutish       = is_checkout() || is_checkout_pay_page() || is_add_payment_method_page() || $is_change_payment;

		if ( $checkoutish ) {
			wp_enqueue_style( 'paradox-cardpointe-checkout' );
			wp_register_script( 'paradox-cardpointe-checkout', PARADOX_CARDPOINTE_URL . 'assets/js/checkout-classic.js', array( 'jquery', 'paradox-cardpointe-tokenizer' ), PARADOX_CARDPOINTE_VERSION, true );
			wp_localize_script( 'paradox-cardpointe-checkout', 'paradox_cardpointe_params', self::params() );
			wp_enqueue_script( 'paradox-cardpointe-checkout' );
		}

		if ( $this->page_wants_apple_pay( $checkoutish ) ) {
			self::enqueue_apple_pay();
		}
	}

	/**
	 * Localized parameters shared by the classic checkout script.
	 */
	public static function params(): array {
		$card   = Plugin::gateway( Plugin::CARD_GATEWAY_ID );
		$params = array(
			'version'          => PARADOX_CARDPOINTE_VERSION,
			'gateways'         => Plugin::gateway_ids(),
			'allowedCardTypes' => $card ? $card->accepted_card_types() : CardTypes::ALL,
			'iconUrls'         => array(),
			'i18n'             => self::i18n(),
		);
		foreach ( CardTypes::ALL as $brand ) {
			$params['iconUrls'][ $brand ] = CardTypes::icon_url( $brand );
		}
		/**
		 * Filters the front-end script parameters.
		 *
		 * @param array $params Parameters.
		 */
		return apply_filters( 'paradox_cardpointe_frontend_params', $params );
	}

	/**
	 * Translatable strings for the scripts.
	 */
	/**
	 * Warms the connection to the tokenizer host on checkout-like pages.
	 *
	 * The iframe is created by JavaScript after the document is parsed, so without a hint
	 * the DNS lookup and TLS handshake to CardPointe only start at that point and are on
	 * the critical path of the first paint of the payment form.
	 *
	 * @param array  $urls          URLs for this relation type.
	 * @param string $relation_type Relation type being processed.
	 * @return array
	 */
	public function resource_hints( $urls, $relation_type ): array {
		if ( 'preconnect' !== $relation_type && 'dns-prefetch' !== $relation_type ) {
			return $urls;
		}
		$origins = array();
		$ids     = is_checkout() || is_checkout_pay_page() || is_add_payment_method_page() ? Plugin::gateway_ids() : array();
		foreach ( $ids as $gateway_id ) {
			$gateway = Plugin::gateway( $gateway_id );
			if ( ! $gateway || 'yes' !== $gateway->enabled ) {
				continue;
			}
			$origin = $gateway->tokenizer_origin();
			if ( '' !== $origin && ! in_array( $origin, $origins, true ) && ! in_array( $origin, $urls, true ) ) {
				$origins[] = $origin;
			}
		}

		// The SDK comes in as a dependency, so it is never "enqueued" in its own right.
		if ( ApplePay::other_browsers() && ( wp_script_is( 'paradox-cardpointe-apple-pay', 'enqueued' ) || wp_script_is( 'paradox-cardpointe-blocks-apple-pay', 'enqueued' ) ) ) {
			$origins[] = 'https://applepay.cdn-apple.com';
		}

		return array_merge( $urls, $origins );
	}

	public static function i18n(): array {
		return array(
			'enterCard'        => __( 'Please enter your card details.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'enterBank'        => __( 'Please enter your bank routing and account numbers.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'invalidCard'      => __( 'The card details are not valid. Please check them and try again.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'invalidBank'      => __( 'Enter your routing number, a slash, then your account number (for example 123456789/000123456).', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'cardNotAccepted'  => __( '%s cards are not accepted.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'chooseAccttype'   => __( 'Please choose your bank account type.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'consentRequired'  => __( 'Please authorize the bank account debit to continue.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'timeout'          => __( 'The secure payment form did not respond. Please re-enter your details.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'loading'          => __( 'Loading secure payment form…', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'retryLoad'        => __( 'Reload the payment form', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'detected'         => __( 'Card type: %s', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'brands'           => CardTypes::options(),
			'errorCodes'       => array(
				'1001' => __( 'Invalid account number.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'1002' => __( 'Invalid security code.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'1003' => __( 'Invalid expiration date.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'1004' => __( 'Card number is required.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'1005' => __( 'Security code is required.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'1006' => __( 'Expiration date is required.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
		);
	}

	/**
	 * Admin assets for the settings page and the order screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function admin( $hook ) {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';

		wp_register_style( 'paradox-cardpointe-admin', PARADOX_CARDPOINTE_URL . 'assets/css/admin.css', array(), PARADOX_CARDPOINTE_VERSION );

		// Settings page for either gateway.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'woocommerce_page_wc-settings' === $screen_id && Plugin::is_our_gateway( $section ) ) {
			wp_enqueue_style( 'paradox-cardpointe-admin' );
			self::register_apple_pay();
			wp_enqueue_script( 'paradox-cardpointe-admin-settings', PARADOX_CARDPOINTE_URL . 'assets/js/admin-settings.js', array( 'jquery', 'paradox-cardpointe-apple-pay-core' ), PARADOX_CARDPOINTE_VERSION, true );
			wp_localize_script(
				'paradox-cardpointe-admin-settings',
				'paradox_cardpointe_admin',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'gatewayId' => Plugin::CARD_GATEWAY_ID,
					'applePay'  => array(
						'tabGeneral'   => __( 'General', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'tabApplePay'  => __( 'Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'uploading'    => __( 'Checking and saving the certificate…', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'uploadFailed' => __( 'The upload failed. Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'pairOk'       => __( 'Certificate and private key found, and they belong together.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						/* translators: %s: date */
						'validUntil'   => __( 'Valid until %s.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'merchantSet'  => __( 'The Apple Merchant ID was read from the certificate.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'testing'      => __( 'Asking Apple for a merchant session…', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'testFailed'   => __( 'Apple Pay is not set up yet:', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'requestError' => __( 'The request to this site failed. Please reload the page and try again.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'sheetLabel'   => __( 'Test (not charged)', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'sheetOk'      => __( 'The Apple Pay sheet completed. This was a test: nothing was sent to CardPointe and no payment was taken.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'sheetOpened'  => __( 'Apple opened the payment sheet, so merchant validation works from Safari too. You can cancel it, or authorize to finish the test; nothing is charged.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'sheetFailed'  => __( 'The Apple Pay sheet could not be opened:', 'paradox-cardpointe-gateway-for-woocommerce' ),
					),
					'i18n'      => array(
						'testing'   => __( 'Testing connection…', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'success'   => __( 'Connected.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'failure'   => __( 'Connection failed.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'yes'       => __( 'Yes', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'no'        => __( 'No', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'labels'    => array(
							'host'        => __( 'Host', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'site'        => __( 'Site', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'enabled'     => __( 'Merchant enabled', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'echeck'      => __( 'ACH / eCheck', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'cvv'         => __( 'CVV verification', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'avs'         => __( 'AVS verification', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'acctupdater' => __( 'Account updater', 'paradox-cardpointe-gateway-for-woocommerce' ),
							'cardproc'    => __( 'Processor', 'paradox-cardpointe-gateway-for-woocommerce' ),
						),
					),
				)
			);
		}

		// Order edit screen (HPOS or legacy).
		if ( $screen_id === Compatibility::order_screen_id() || 'shop_order' === $screen_id ) {
			wp_enqueue_style( 'paradox-cardpointe-admin' );
			wp_enqueue_script( 'paradox-cardpointe-admin-order', PARADOX_CARDPOINTE_URL . 'assets/js/admin-order.js', array( 'jquery' ), PARADOX_CARDPOINTE_VERSION, true );
			wp_localize_script(
				'paradox-cardpointe-admin-order',
				'paradox_cardpointe_order',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'paradox_cardpointe_order_action' ),
					'i18n'    => array(
						'confirmVoid'    => __( 'Void this transaction? The customer will not be charged.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'confirmCapture' => __( 'Capture %s now?', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'working'        => __( 'Working…', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'error'          => __( 'The request failed. Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					),
				)
			);
		}
	}
}
