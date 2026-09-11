<?php
/**
 * Block checkout: eCheck.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Blocks;

use ParadoxSolutions\CardPointe\Gateway\AbstractGateway;
use ParadoxSolutions\CardPointe\Gateway\EcheckGateway;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the eCheck payment method with the Checkout block.
 */
final class EcheckBlocksMethod extends AbstractBlocksMethod {

	/** @var string */
	protected $name = Plugin::ECHECK_GATEWAY_ID;

	/**
	 * {@inheritDoc}
	 */
	protected function script_handle(): string {
		return 'paradox-cardpointe-blocks-echeck';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function script_file(): string {
		return 'blocks-echeck.js';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function extra_data( AbstractGateway $gateway ): array {
		if ( ! $gateway instanceof EcheckGateway ) {
			return array();
		}
		$labels = array(
			'ECHK' => __( 'Checking', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'ESAV' => __( 'Savings', 'paradox-cardpointe-gateway-for-woocommerce' ),
		);
		$types  = array();
		foreach ( $gateway->account_types() as $type ) {
			$types[] = array(
				'value' => $type,
				'label' => $labels[ $type ] ?? $type,
			);
		}
		// The block re-renders the consent text with the live cart total on the client.
		return array(
			'accountTypes'        => $types,
			'consentTemplate'     => (string) $gateway->get_option( 'consent_text' ),
			'consentCompany'      => get_bloginfo( 'name' ),
			'accountTypeLabel'    => __( 'Account type', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'routingLabel'        => __( 'Routing number / Account number', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'routingHelp'         => __( 'Type your routing number, a slash, then your account number, e.g. 123456789/000123456.', 'paradox-cardpointe-gateway-for-woocommerce' ),
		);
	}
}
