<?php
/**
 * WooCommerce payment token management.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Tokens;

use ParadoxSolutions\CardPointe\Api\RequestBuilder;
use ParadoxSolutions\CardPointe\Gateway\AbstractGateway;
use ParadoxSolutions\CardPointe\Gateway\CardTypes;
use ParadoxSolutions\CardPointe\Gateway\PaymentProcessor;
use ParadoxSolutions\CardPointe\Gateway\PaymentSource;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Creates WC_Payment_Token records for vaulted CardPointe accounts and keeps them in sync.
 */
final class TokenManager {

	const META_PROFILE_ID  = 'paradox_cardpointe_profile_id';
	const META_ACCT_ID     = 'paradox_cardpointe_acct_id';
	const META_ENVIRONMENT = 'paradox_cardpointe_environment';
	const META_MERCHANT_ID = 'paradox_cardpointe_merchant_id';
	const META_ACCTTYPE    = 'paradox_cardpointe_accttype';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'filter_tokens_by_environment' ), 10, 3 );
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'on_token_deleted' ), 10, 2 );
		add_filter( 'woocommerce_payment_methods_list_item', array( $this, 'list_item' ), 10, 2 );
	}

	/**
	 * Saves a vaulted source as a WooCommerce token (or returns the existing one).
	 *
	 * @param int           $user_id     User ID.
	 * @param string        $gateway_id  Gateway ID.
	 * @param PaymentSource $source      Source with token/profile data.
	 * @param Credentials   $credentials Credentials.
	 * @return \WC_Payment_Token|null
	 */
	public static function save_source( int $user_id, string $gateway_id, PaymentSource $source, Credentials $credentials ) {
		if ( $user_id <= 0 || '' === $source->token ) {
			return null;
		}

		$existing = self::find_existing( $user_id, $gateway_id, $source->token, $credentials->environment() );
		if ( $existing ) {
			// Refresh profile references in case they changed.
			if ( '' !== $source->profile_id ) {
				$existing->update_meta_data( self::META_PROFILE_ID, $source->profile_id );
				$existing->update_meta_data( self::META_ACCT_ID, $source->acct_id );
			}
			if ( $existing instanceof \WC_Payment_Token_CC && 6 === strlen( $source->expiry ) ) {
				$existing->set_expiry_year( substr( $source->expiry, 0, 4 ) );
				$existing->set_expiry_month( substr( $source->expiry, 4, 2 ) );
			}
			$existing->save();
			return $existing;
		}

		if ( 'echeck' === $source->type ) {
			$token = new \WC_Payment_Token_eCheck();
			$token->set_last4( $source->last4() );
			$token->update_meta_data( self::META_ACCTTYPE, $source->accttype );
		} else {
			$token = new \WC_Payment_Token_CC();
			$token->set_card_type( CardTypes::wc_card_type( '' !== $source->brand ? $source->brand : 'card' ) );
			$token->set_last4( $source->last4() );
			if ( 6 === strlen( $source->expiry ) ) {
				$token->set_expiry_year( substr( $source->expiry, 0, 4 ) );
				$token->set_expiry_month( substr( $source->expiry, 4, 2 ) );
			}
		}

		$token->set_token( $source->token );
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->update_meta_data( self::META_PROFILE_ID, $source->profile_id );
		$token->update_meta_data( self::META_ACCT_ID, $source->acct_id );
		$token->update_meta_data( self::META_ENVIRONMENT, $credentials->environment() );
		$token->update_meta_data( self::META_MERCHANT_ID, $credentials->merchant_id );

		if ( empty( \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id ) ) ) {
			$token->set_default( true );
		}

		if ( ! $token->validate() || ! $token->save() ) {
			Plugin::instance()->logger()->error( 'Could not save payment token', array( 'user_id' => $user_id, 'gateway' => $gateway_id ) );
			return null;
		}

		return $token;
	}

	/**
	 * Finds a token for the same CardSecure token value in the same environment.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $gateway_id Gateway ID.
	 * @param string $token      CardSecure token.
	 * @param string $env        Environment.
	 * @return \WC_Payment_Token|null
	 */
	public static function find_existing( int $user_id, string $gateway_id, string $token, string $env ) {
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id ) as $existing ) {
			if ( $existing->get_token() !== $token ) {
				continue;
			}
			$token_env = (string) $existing->get_meta( self::META_ENVIRONMENT, true );
			if ( '' === $token_env || $token_env === $env ) {
				return $existing;
			}
		}
		return null;
	}

	/**
	 * Hides tokens created in the other environment.
	 *
	 * @param \WC_Payment_Token[] $tokens      Tokens.
	 * @param int                 $customer_id Customer.
	 * @param string              $gateway_id  Gateway ID filter.
	 * @return \WC_Payment_Token[]
	 */
	public function filter_tokens_by_environment( $tokens, $customer_id, $gateway_id ) {
		if ( ! is_array( $tokens ) ) {
			return $tokens;
		}
		$env = Credentials::active()->environment();
		foreach ( $tokens as $id => $token ) {
			if ( ! Plugin::is_our_gateway( $token->get_gateway_id() ) ) {
				continue;
			}
			$token_env = (string) $token->get_meta( self::META_ENVIRONMENT, true );
			if ( '' !== $token_env && $token_env !== $env ) {
				unset( $tokens[ $id ] );
			}
		}
		return $tokens;
	}

	/**
	 * Removes the account from the CardPointe profile when the customer deletes a token.
	 *
	 * @param int               $token_id Token ID.
	 * @param \WC_Payment_Token $token    Token.
	 */
	public function on_token_deleted( $token_id, $token ) {
		if ( ! $token instanceof \WC_Payment_Token || ! Plugin::is_our_gateway( $token->get_gateway_id() ) ) {
			return;
		}
		$profile_id  = (string) $token->get_meta( self::META_PROFILE_ID, true );
		$acct_id     = (string) $token->get_meta( self::META_ACCT_ID, true );
		$token_env   = (string) $token->get_meta( self::META_ENVIRONMENT, true );
		$credentials = Credentials::active();

		if ( '' === $profile_id || '' === $acct_id ) {
			return;
		}
		if ( '' !== $token_env && $token_env !== $credentials->environment() ) {
			$settings    = Credentials::settings();
			$credentials = Credentials::from_settings( $settings, 'sandbox' === $token_env );
			if ( ! $credentials->is_complete() ) {
				Plugin::instance()->logger()->warning( 'Token deleted but its environment credentials are missing; profile account left in place', array( 'token_id' => $token_id ) );
				return;
			}
		}

		$service = new ProfileService( Plugin::instance()->client( $credentials ) );
		$service->delete_account( $profile_id, $acct_id );
	}

	/**
	 * Nicer "method" column for eCheck tokens in My Account.
	 *
	 * @param array             $item  List item.
	 * @param \WC_Payment_Token $token Token.
	 * @return array
	 */
	public function list_item( $item, $token ) {
		if ( ! $token instanceof \WC_Payment_Token_eCheck || ! Plugin::is_our_gateway( $token->get_gateway_id() ) ) {
			return $item;
		}
		$type = 'ESAV' === $token->get_meta( self::META_ACCTTYPE, true )
			? __( 'Savings account', 'paradox-cardpointe-gateway-for-woocommerce' )
			: __( 'Checking account', 'paradox-cardpointe-gateway-for-woocommerce' );
		$item['method']['last4'] = $token->get_last4();
		$item['method']['brand'] = $type;
		return $item;
	}

	/**
	 * My Account > Add payment method handler.
	 *
	 * @param AbstractGateway $gateway Gateway.
	 * @return array WooCommerce result.
	 *
	 * @throws \Exception On failure.
	 */
	public function add_payment_method( AbstractGateway $gateway ): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			throw new \Exception( __( 'Please log in to save a payment method.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$source = PaymentSource::from_request( $gateway );
		if ( $source->is_saved() ) {
			throw new \Exception( __( 'Please enter a new payment method.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$processor = new PaymentProcessor( $gateway );
		$processor->enforce_card_type( $source );

		$customer = new \WC_Customer( $user_id );
		$processor->verify_and_vault(
			$user_id,
			$source,
			RequestBuilder::billing_from_customer( $customer ),
			array(
				'orderid'      => RequestBuilder::orderid( $user_id, 'APM' ),
				'cofscheduled' => 'N',
				'context'      => 'add_payment_method',
			)
		);

		if ( ! $source->wc_token ) {
			$source->wc_token = self::save_source( $user_id, $gateway->id, $source, $gateway->credentials() );
		}
		if ( ! $source->wc_token ) {
			throw new \Exception( __( 'The payment method was verified but could not be saved. Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}
}
