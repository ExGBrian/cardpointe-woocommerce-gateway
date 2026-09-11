<?php
/**
 * Block checkout integration base.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use ParadoxSolutions\CardPointe\Frontend\Assets;
use ParadoxSolutions\CardPointe\Frontend\TokenizerConfig;
use ParadoxSolutions\CardPointe\Gateway\AbstractGateway;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for the card and eCheck block payment methods.
 */
abstract class AbstractBlocksMethod extends AbstractPaymentMethodType {

	/**
	 * Script handle for the block client.
	 */
	abstract protected function script_handle(): string;

	/**
	 * Script file relative to assets/js/.
	 */
	abstract protected function script_file(): string;

	/**
	 * Extra client data for the subclass.
	 *
	 * @param AbstractGateway $gateway Gateway.
	 */
	abstract protected function extra_data( AbstractGateway $gateway ): array;

	/**
	 * Loads settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Gateway instance.
	 *
	 * @return AbstractGateway|null
	 */
	protected function gateway() {
		return Plugin::gateway( $this->name );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active() {
		$gateway = $this->gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_payment_method_script_handles() {
		Assets::register_tokenizer();
		wp_enqueue_style( 'paradox-cardpointe-checkout' );

		$handle = $this->script_handle();
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				PARADOX_CARDPOINTE_URL . 'assets/js/' . $this->script_file(),
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n', 'paradox-cardpointe-tokenizer' ),
				PARADOX_CARDPOINTE_VERSION,
				true
			);
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( $handle, 'paradox-cardpointe-gateway-for-woocommerce', PARADOX_CARDPOINTE_PATH . 'languages' );
			}
		}
		return array( $handle );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			return array();
		}
		$logged_in = is_user_logged_in();
		$data      = array(
			'name'            => $this->name,
			'title'           => $gateway->get_title(),
			'description'     => $gateway->get_description(),
			'type'            => $gateway->payment_type(),
			'tokenizerUrl'    => $gateway->tokenizer_url(),
			'tokenizerOrigin' => $gateway->tokenizer_origin(),
			'iframeHeight'    => TokenizerConfig::height( $gateway ),
			'supports'        => array_values( array_filter( $gateway->supports, 'is_string' ) ),
			'showSavedCards'  => $logged_in && $gateway->supports( 'tokenization' ),
			'showSaveOption'  => $logged_in && $gateway->supports( 'tokenization' ) && ! $gateway->is_forced_save_context(),
			'isSandbox'       => $gateway->is_sandbox(),
			'sandboxNotice'   => __( 'SANDBOX MODE: no real payments are processed.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'i18n'            => Assets::i18n(),
		);
		return array_merge( $data, $this->extra_data( $gateway ) );
	}
}
