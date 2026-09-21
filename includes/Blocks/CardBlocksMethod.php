<?php
/**
 * Block checkout: credit card.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Blocks;

use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
use ParadoxSolutions\CardPointe\Frontend\Assets;
use ParadoxSolutions\CardPointe\Gateway\AbstractGateway;
use ParadoxSolutions\CardPointe\Gateway\CardGateway;
use ParadoxSolutions\CardPointe\Gateway\CardTypes;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the card payment method with the Checkout block.
 */
final class CardBlocksMethod extends AbstractBlocksMethod {

	/** @var string */
	protected $name = Plugin::CARD_GATEWAY_ID;

	/**
	 * {@inheritDoc}
	 */
	protected function script_handle(): string {
		return 'paradox-cardpointe-blocks-card';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function script_file(): string {
		return 'blocks-card.js';
	}

	/**
	 * The card form hosts the in-box Apple Pay button, so it needs the Apple Pay core.
	 *
	 * @return string[]
	 */
	protected function script_dependencies(): array {
		$deps = parent::script_dependencies();
		if ( ApplePay::is_configured() ) {
			Assets::register_apple_pay();
			$deps[] = 'wp-data';
			$deps[] = 'paradox-cardpointe-apple-pay-core';
		}
		return $deps;
	}

	/**
	 * Adds the express payment method (Cart and Checkout blocks) next to the card method.
	 */
	public function get_payment_method_script_handles() {
		$handles = parent::get_payment_method_script_handles();

		if ( ApplePay::is_configured() && ( ApplePay::is_location_enabled( 'cart' ) || ApplePay::is_location_enabled( 'checkout' ) ) ) {
			$express = 'paradox-cardpointe-blocks-apple-pay';
			if ( ! wp_script_is( $express, 'registered' ) ) {
				Assets::register_apple_pay();
				wp_register_script(
					$express,
					PARADOX_CARDPOINTE_URL . 'assets/js/blocks-apple-pay.js',
					array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-data', 'paradox-cardpointe-apple-pay-core' ),
					PARADOX_CARDPOINTE_VERSION,
					true
				);
			}
			$handles[] = $express;
		}

		return $handles;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function extra_data( AbstractGateway $gateway ): array {
		$types = $gateway instanceof CardGateway ? $gateway->accepted_card_types() : CardTypes::ALL;
		$icons = array();
		foreach ( $types as $brand ) {
			$icons[] = array(
				'id'  => $brand,
				'src' => CardTypes::icon_url( $brand ),
				'alt' => CardTypes::label( $brand ),
			);
		}
		// Which Apple Pay buttons this page gets. Carts that must keep a card on file are
		// handled by the express method declaring that it only supports plain products.
		$apple_pay = null;
		if ( $gateway instanceof CardGateway && ApplePay::is_configured() ) {
			$page      = is_cart() ? 'cart' : ( is_checkout() ? 'checkout' : '' );
			$apple_pay = array(
				'page'       => $page,
				'express'    => '' !== $page && ApplePay::is_location_enabled( $page ) && ApplePay::express_available( $gateway ),
				'paymentBox' => ApplePay::is_location_enabled( 'payment_box' ) && ! $gateway->is_forced_save_context() && ( is_ssl() || wc_checkout_is_https() ),
			);
		}

		return array(
			'allowedCardTypes' => $types,
			'icons'            => $icons,
			'applePay'         => $apple_pay,
		);
	}
}
