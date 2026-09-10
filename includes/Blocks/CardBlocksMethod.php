<?php
/**
 * Block checkout: credit card.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Blocks;

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
		return array(
			'allowedCardTypes' => $types,
			'icons'            => $icons,
		);
	}
}
