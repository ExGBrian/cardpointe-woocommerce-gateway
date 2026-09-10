<?php
/**
 * Payment source value object.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Describes what the shopper is paying with: a new CardSecure token or a saved WooCommerce token.
 */
final class PaymentSource {

	const KIND_NEW   = 'new';
	const KIND_SAVED = 'saved';

	/** @var string new|saved */
	public $kind = self::KIND_NEW;

	/** @var string card|echeck */
	public $type = 'card';

	/** @var string CardSecure token. */
	public $token = '';

	/** @var string Expiry as YYYYMM (cards). */
	public $expiry = '';

	/** @var string ECHK|ESAV (eCheck). */
	public $accttype = '';

	/** @var string Card brand slug when known. */
	public $brand = '';

	/** @var \WC_Payment_Token|null Saved WooCommerce token. */
	public $wc_token = null;

	/** @var string CardPointe profile ID. */
	public $profile_id = '';

	/** @var string CardPointe account ID within the profile. */
	public $acct_id = '';

	/** @var bool Whether the shopper asked to save this method. */
	public $save = false;

	/** @var bool eCheck authorization checkbox. */
	public $consent = false;

	/**
	 * Builds the source from the current request for the given gateway.
	 *
	 * @param AbstractGateway $gateway Gateway.
	 *
	 * @throws \Exception With a customer-facing message when the input is missing or invalid.
	 */
	public static function from_request( AbstractGateway $gateway ): PaymentSource {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling the gateway.
		$id     = $gateway->id;
		$source = new self();
		$source->type = $gateway->payment_type();
		$source->save = self::posted_bool( 'wc-' . $id . '-new-payment-method' );

		$token_field = 'wc-' . $id . '-payment-token';
		$token_id    = isset( $_POST[ $token_field ] ) ? wc_clean( wp_unslash( $_POST[ $token_field ] ) ) : '';

		if ( '' !== $token_id && 'new' !== $token_id ) {
			return self::from_saved_token( $gateway, absint( $token_id ) );
		}

		$source->kind  = self::KIND_NEW;
		$source->token = isset( $_POST[ $id . '_token' ] ) ? preg_replace( '/\D/', '', wc_clean( wp_unslash( $_POST[ $id . '_token' ] ) ) ) : '';

		if ( ! preg_match( '/^\d{15,19}$/', $source->token ) ) {
			throw new \Exception(
				'card' === $source->type
					? __( 'Please enter your card details.', 'paradox-cardpointe-gateway' )
					: __( 'Please enter your bank routing and account numbers.', 'paradox-cardpointe-gateway' )
			);
		}

		if ( 'card' === $source->type ) {
			$source->expiry = isset( $_POST[ $id . '_expiry' ] ) ? preg_replace( '/\D/', '', wc_clean( wp_unslash( $_POST[ $id . '_expiry' ] ) ) ) : '';
			$source->expiry = self::normalise_expiry( $source->expiry );
			if ( '' === $source->expiry ) {
				throw new \Exception( __( 'Please enter a valid card expiration date.', 'paradox-cardpointe-gateway' ) );
			}
			$hint          = isset( $_POST[ $id . '_brand_hint' ] ) ? sanitize_key( wp_unslash( $_POST[ $id . '_brand_hint' ] ) ) : '';
			$source->brand = in_array( $hint, CardTypes::ALL, true ) ? $hint : '';
		} else {
			$accttype         = isset( $_POST[ $id . '_accttype' ] ) ? strtoupper( sanitize_key( wp_unslash( $_POST[ $id . '_accttype' ] ) ) ) : '';
			$allowed          = $gateway instanceof EcheckGateway ? $gateway->account_types() : array( 'ECHK', 'ESAV' );
			$source->accttype = in_array( $accttype, $allowed, true ) ? $accttype : '';
			if ( '' === $source->accttype ) {
				throw new \Exception( __( 'Please choose your bank account type.', 'paradox-cardpointe-gateway' ) );
			}
			$source->consent = self::posted_bool( $id . '_consent' );
			if ( ! $source->consent ) {
				throw new \Exception( __( 'Please authorize the bank account debit to continue.', 'paradox-cardpointe-gateway' ) );
			}
		}

		return $source;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Builds the source from a saved WooCommerce token owned by the current user.
	 *
	 * @param AbstractGateway $gateway  Gateway.
	 * @param int             $token_id WC token ID.
	 *
	 * @throws \Exception When the token is missing, foreign, or from another environment.
	 */
	public static function from_saved_token( AbstractGateway $gateway, int $token_id ): PaymentSource {
		$token = $token_id > 0 ? \WC_Payment_Tokens::get( $token_id ) : null;
		$user  = get_current_user_id();

		if ( ! $token || $token->get_gateway_id() !== $gateway->id || ! $user || (int) $token->get_user_id() !== $user ) {
			throw new \Exception( __( 'The selected saved payment method is not available. Please choose another one.', 'paradox-cardpointe-gateway' ) );
		}

		$env = (string) $token->get_meta( 'paradox_cardpointe_environment', true );
		if ( '' !== $env && $env !== $gateway->credentials()->environment() ) {
			throw new \Exception( __( 'The selected saved payment method belongs to a different environment. Please add it again.', 'paradox-cardpointe-gateway' ) );
		}

		return self::from_wc_token( $token, $gateway->payment_type() );
	}

	/**
	 * Builds a source from a WooCommerce token object (already validated).
	 *
	 * @param \WC_Payment_Token $token Token.
	 * @param string            $type  card|echeck.
	 */
	public static function from_wc_token( \WC_Payment_Token $token, string $type ): PaymentSource {
		$source             = new self();
		$source->kind       = self::KIND_SAVED;
		$source->type       = $type;
		$source->wc_token   = $token;
		$source->token      = (string) $token->get_token();
		$source->profile_id = (string) $token->get_meta( 'paradox_cardpointe_profile_id', true );
		$source->acct_id    = (string) $token->get_meta( 'paradox_cardpointe_acct_id', true );

		if ( $token instanceof \WC_Payment_Token_CC ) {
			$source->expiry = $token->get_expiry_year() . str_pad( (string) $token->get_expiry_month(), 2, '0', STR_PAD_LEFT );
			$source->brand  = CardTypes::from_wc_card_type( (string) $token->get_card_type() );
		} else {
			$source->accttype = (string) $token->get_meta( 'paradox_cardpointe_accttype', true );
		}

		return $source;
	}

	/**
	 * Builds a source from vaulted order/subscription meta (merchant-initiated charges).
	 *
	 * @param array $stored Output of OrderMeta::stored_payment_method().
	 */
	public static function from_stored( array $stored ): PaymentSource {
		$source             = new self();
		$source->kind       = self::KIND_SAVED;
		$source->type       = $stored['type'] ?: 'card';
		$source->token      = (string) $stored['token'];
		$source->expiry     = (string) $stored['expiry'];
		$source->accttype   = (string) $stored['accttype'];
		$source->profile_id = (string) $stored['profile_id'];
		$source->acct_id    = (string) $stored['acct_id'];
		$source->brand      = (string) ( $stored['brand'] ?? '' );

		if ( ! empty( $stored['token_id'] ) ) {
			$wc_token = \WC_Payment_Tokens::get( (int) $stored['token_id'] );
			if ( $wc_token ) {
				$source->wc_token = $wc_token;
			}
		}
		return $source;
	}

	/**
	 * Whether this is a saved method.
	 */
	public function is_saved(): bool {
		return self::KIND_SAVED === $this->kind;
	}

	/**
	 * Whether a CardPointe profile is available for this source.
	 */
	public function has_profile(): bool {
		return '' !== $this->profile_id;
	}

	/**
	 * "profileid/acctid" reference for the profile field.
	 */
	public function profile_reference(): string {
		if ( ! $this->has_profile() ) {
			return '';
		}
		return '' !== $this->acct_id ? $this->profile_id . '/' . $this->acct_id : $this->profile_id;
	}

	/**
	 * Last four digits of the underlying account (tokens preserve them).
	 */
	public function last4(): string {
		if ( $this->wc_token && method_exists( $this->wc_token, 'get_last4' ) ) {
			$last4 = (string) $this->wc_token->get_last4();
			if ( '' !== $last4 ) {
				return $last4;
			}
		}
		return '' !== $this->token ? substr( $this->token, -4 ) : '';
	}

	/**
	 * Converts YYYYMM/MMYY to YYYYMM and validates it is not in the past.
	 *
	 * @param string $expiry Digits only.
	 */
	public static function normalise_expiry( string $expiry ): string {
		if ( 4 === strlen( $expiry ) ) {
			$month = (int) substr( $expiry, 0, 2 );
			$year  = 2000 + (int) substr( $expiry, 2, 2 );
		} elseif ( 6 === strlen( $expiry ) ) {
			$year  = (int) substr( $expiry, 0, 4 );
			$month = (int) substr( $expiry, 4, 2 );
		} else {
			return '';
		}
		if ( $month < 1 || $month > 12 || $year < (int) gmdate( 'Y' ) || $year > (int) gmdate( 'Y' ) + 25 ) {
			return '';
		}
		if ( $year === (int) gmdate( 'Y' ) && $month < (int) gmdate( 'n' ) ) {
			return '';
		}
		return sprintf( '%04d%02d', $year, $month );
	}

	/**
	 * Expiry formatted as MMYY for the gateway.
	 */
	public function expiry_mmyy(): string {
		if ( 6 !== strlen( $this->expiry ) ) {
			return '';
		}
		return substr( $this->expiry, 4, 2 ) . substr( $this->expiry, 2, 2 );
	}

	/**
	 * Reads a boolean-ish POST value (checkbox or Blocks boolean).
	 *
	 * @param string $field Field name.
	 */
	private static function posted_bool( string $field ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $field ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = wp_unslash( $_POST[ $field ] );
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'on', 'yes' ), true );
	}
}
