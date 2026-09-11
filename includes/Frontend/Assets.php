<?php
/**
 * Script and style registration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Frontend;

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
	 * Front-end assets for checkout-like pages.
	 */
	public function frontend() {
		self::register_tokenizer();

		$is_change_payment = isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_checkout() && ! is_checkout_pay_page() && ! is_add_payment_method_page() && ! $is_change_payment ) {
			return;
		}

		wp_enqueue_style( 'paradox-cardpointe-checkout' );
		wp_register_script( 'paradox-cardpointe-checkout', PARADOX_CARDPOINTE_URL . 'assets/js/checkout-classic.js', array( 'jquery', 'paradox-cardpointe-tokenizer' ), PARADOX_CARDPOINTE_VERSION, true );
		wp_localize_script( 'paradox-cardpointe-checkout', 'paradox_cardpointe_params', self::params() );
		wp_enqueue_script( 'paradox-cardpointe-checkout' );
	}

	/**
	 * Localized parameters shared by the classic checkout script.
	 */
	public static function params(): array {
		$card   = Plugin::gateway( Plugin::CARD_GATEWAY_ID );
		$params = array(
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
			wp_enqueue_script( 'paradox-cardpointe-admin-settings', PARADOX_CARDPOINTE_URL . 'assets/js/admin-settings.js', array( 'jquery' ), PARADOX_CARDPOINTE_VERSION, true );
			wp_localize_script(
				'paradox-cardpointe-admin-settings',
				'paradox_cardpointe_admin',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'gatewayId' => Plugin::CARD_GATEWAY_ID,
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
