<?php
/**
 * Payment source value object.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
use ParadoxSolutions\CardPointe\ApplePay\PaymentData;
use ParadoxSolutions\CardPointe\Plugin;
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

	/** @var string Wallet that produced the token (apple_pay), or empty for typed card details. */
	public $wallet = '';

	/** @var string The wallet's own description of the card, e.g. "Visa 1234". */
	public $wallet_display = '';

	/** @var string Total the shopper approved in the wallet's payment sheet, as a decimal string. */
	public $wallet_total = '';

	/**
	 * Builds the source from the current request for the given gateway.
	 *
	 * @param AbstractGateway $gateway         Gateway.
	 * @param bool            $tokenize_wallet Whether a wallet payload should be exchanged
	 *                                         for a token now (see from_wallet()).
	 *
	 * @throws \Exception With a customer-facing message when the input is missing or invalid.
	 */
	public static function from_request( AbstractGateway $gateway, bool $tokenize_wallet = true ): PaymentSource {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling the gateway.
		$id = $gateway->id;

		$wallet = isset( $_POST[ $id . '_wallet' ] ) ? sanitize_key( wp_unslash( $_POST[ $id . '_wallet' ] ) ) : '';
		if ( '' !== $wallet ) {
			return self::from_wallet( $gateway, $wallet, $tokenize_wallet );
		}

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
					? __( 'Please enter your card details.', 'paradox-cardpointe-gateway-for-woocommerce' )
					: __( 'Please enter your bank routing and account numbers.', 'paradox-cardpointe-gateway-for-woocommerce' )
			);
		}

		if ( 'card' === $source->type ) {
			$raw_expiry     = isset( $_POST[ $id . '_expiry' ] ) ? preg_replace( '/\D/', '', wc_clean( wp_unslash( $_POST[ $id . '_expiry' ] ) ) ) : '';
			$source->expiry = self::normalise_expiry( $raw_expiry );
			if ( '' === $source->expiry ) {
				// Record the shape, never the value, so an unreadable expiry can be
				// diagnosed from the log without writing cardholder data to disk.
				Plugin::instance()->logger()->warning(
					'Could not read the card expiry returned by the tokenizer.',
					array(
						'field_present' => isset( $_POST[ $id . '_expiry' ] ) ? 'yes' : 'no',
						'digit_count'   => strlen( $raw_expiry ),
					)
				);
				throw new \Exception( __( 'Please enter a valid card expiration date.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			}
			$hint          = isset( $_POST[ $id . '_brand_hint' ] ) ? sanitize_key( wp_unslash( $_POST[ $id . '_brand_hint' ] ) ) : '';
			$source->brand = in_array( $hint, CardTypes::ALL, true ) ? $hint : '';
		} else {
			$accttype         = isset( $_POST[ $id . '_accttype' ] ) ? strtoupper( sanitize_key( wp_unslash( $_POST[ $id . '_accttype' ] ) ) ) : '';
			$allowed          = $gateway instanceof EcheckGateway ? $gateway->account_types() : array( 'ECHK', 'ESAV' );
			$source->accttype = in_array( $accttype, $allowed, true ) ? $accttype : '';
			if ( '' === $source->accttype ) {
				throw new \Exception( __( 'Please choose your bank account type.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			}
			$source->consent = self::posted_bool( $id . '_consent' );
			if ( ! $source->consent ) {
				throw new \Exception( __( 'Please authorize the bank account debit to continue.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			}
		}

		return $source;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Builds the source from an encrypted wallet token (Apple Pay).
	 *
	 * The classic checkout calls from_request() twice, once to validate the fields and
	 * once to pay, and a wallet payload is only good for one tokenization. So the
	 * CardSecure call happens on the paying call only, after every other checkout field
	 * has validated, and is memoised for the life of the request.
	 *
	 * @param AbstractGateway $gateway  Gateway.
	 * @param string          $wallet   Wallet identifier posted with the form.
	 * @param bool            $tokenize Whether to exchange the payload for a token now.
	 *
	 * @throws \Exception With a customer-facing message.
	 */
	private static function from_wallet( AbstractGateway $gateway, string $wallet, bool $tokenize ): PaymentSource {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id = $gateway->id;

		if ( ApplePay::WALLET !== $wallet || ! $gateway instanceof CardGateway || ! ApplePay::is_configured() ) {
			throw new \Exception( __( 'Apple Pay is not available on this store. Please pay another way.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$raw = isset( $_POST[ $id . '_wallet_data' ] ) ? (string) wp_unslash( $_POST[ $id . '_wallet_data' ] ) : '';
		try {
			$payment_data = PaymentData::from_json( $raw );
		} catch ( \InvalidArgumentException $e ) {
			// The reason names a field, never a value.
			Plugin::instance()->logger()->warning( 'Apple Pay payload rejected before tokenization', array( 'reason' => $e->getMessage() ) );
			throw new \Exception( __( 'The Apple Pay payment could not be read. Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$network = isset( $_POST[ $id . '_wallet_network' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $id . '_wallet_network' ] ) ) : '';
		$display = isset( $_POST[ $id . '_wallet_display' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $id . '_wallet_display' ] ) ) : '';

		$source                 = new self();
		$source->kind           = self::KIND_NEW;
		$source->type           = 'card';
		$source->wallet         = ApplePay::WALLET;
		$source->wallet_display = function_exists( 'mb_substr' ) ? mb_substr( $display, 0, 40 ) : substr( $display, 0, 40 );
		$source->brand          = PaymentData::brand_from_network( $network );
		$source->wallet_total   = isset( $_POST[ $id . '_wallet_total' ] ) ? preg_replace( '/[^0-9.]/', '', (string) wp_unslash( $_POST[ $id . '_wallet_total' ] ) ) : '';
		$source->save           = false;

		if ( $tokenize ) {
			list( $source->token, $source->expiry ) = self::tokenize_wallet( $gateway, $payment_data );
			if ( '' === $source->brand ) {
				$source->brand = (string) CardTypes::from_token_prefix( $source->token );
			}
		}

		return $source;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Exchanges an Apple Pay payload for a CardSecure token, once per request.
	 *
	 * @param CardGateway $gateway      Gateway.
	 * @param array       $payment_data Validated payload (PaymentData::from_json()).
	 * @return array Token, then expiry as YYYYMM or an empty string when CardSecure returned none.
	 *
	 * @throws \Exception With a customer-facing message.
	 */
	private static function tokenize_wallet( CardGateway $gateway, array $payment_data ): array {
		static $memo = array();

		$key = PaymentData::fingerprint( $payment_data );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$logger = Plugin::instance()->logger();

		try {
			$response = $gateway->client()->tokenize_devicedata( PaymentData::devicedata( $payment_data ), PaymentData::HANDLER );
		} catch ( ApiException $e ) {
			// Nothing here is secret: the hash identifies the public half of the Payment
			// Processing Certificate Apple encrypted to, which is what Fiserv needs in order
			// to say whether CardSecure holds the matching key.
			$context = array(
				'error'                => $e->getMessage(),
				'host'                 => $gateway->credentials()->host(),
				'processing_cert_hash' => $payment_data['publicKeyHash'],
				'data_length'          => strlen( $payment_data['data'] ),
				'application_data'     => '' !== $payment_data['applicationData'] ? 'yes' : 'no',
			);
			if ( false !== stripos( $e->getMessage(), 'decryption' ) ) {
				$context['hint'] = 'CardSecure could not decrypt the Apple Pay token. It can only decrypt tokens made with a Payment Processing Certificate created from the CSR Fiserv issued for this merchant ID and this environment (sandbox and production are separate). Give Fiserv the processing_cert_hash above to confirm which certificate Apple used.';
			}
			$logger->error( 'Apple Pay tokenization failed', $context );
			throw new \Exception( __( 'Apple Pay could not be processed right now. Please try again or pay another way.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		// CardSecure reports failures with a non-zero errorcode and the reason in message;
		// on success message carries the token (some responses name it token instead).
		$errorcode = $response->string( 'errorcode', '0' );
		$token     = $response->string( 'token' );
		if ( '' === $token ) {
			$token = $response->string( 'message' );
		}
		$token = preg_replace( '/\D/', '', $token );

		if ( ( '' !== $errorcode && '0' !== $errorcode ) || ! preg_match( '/^\d{15,19}$/', $token ) ) {
			$logger->warning( 'CardSecure rejected the Apple Pay payload', array( 'errorcode' => $errorcode, 'message' => $response->string( 'message' ) ) );
			throw new \Exception( __( 'Apple Pay could not be processed (the payment token was not accepted). Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$raw_expiry = $response->string( 'expiry' );
		$expiry     = self::normalise_wallet_expiry( $raw_expiry );

		$logger->info(
			'Apple Pay payload tokenized',
			array(
				'token'          => $token,
				'expiry_present' => '' !== $raw_expiry ? 'yes' : 'no',
				'expiry_usable'  => '' !== $expiry ? 'yes' : 'no',
			)
		);

		$memo[ $key ] = array( $token, $expiry );
		return $memo[ $key ];
	}

	/**
	 * Expiry from a CardSecure wallet response as YYYYMM.
	 *
	 * The layouts the card tokenizer uses are tried first; decrypted wallet data can
	 * also carry the application expiration date as YYMMDD.
	 *
	 * @param string $expiry Raw value, possibly empty.
	 */
	public static function normalise_wallet_expiry( string $expiry ): string {
		$digits = preg_replace( '/\D/', '', $expiry );
		if ( '' === $digits ) {
			return '';
		}
		$normalised = self::normalise_expiry( $digits );
		if ( '' !== $normalised ) {
			return $normalised;
		}
		if ( 6 === strlen( $digits ) ) {
			$year  = 2000 + (int) substr( $digits, 0, 2 );
			$month = (int) substr( $digits, 2, 2 );
			if ( self::is_plausible_expiry( $year, $month ) ) {
				return sprintf( '%04d%02d', $year, $month );
			}
		}
		return '';
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
			throw new \Exception( __( 'The selected saved payment method is not available. Please choose another one.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
		}

		$env = (string) $token->get_meta( 'paradox_cardpointe_environment', true );
		if ( '' !== $env && $env !== $gateway->credentials()->environment() ) {
			throw new \Exception( __( 'The selected saved payment method belongs to a different environment. Please add it again.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
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
	 * Whether the token came from a digital wallet rather than typed card details.
	 */
	public function is_wallet(): bool {
		return '' !== $this->wallet;
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
	 * Converts a tokenizer expiry into the YYYYMM the gateway expects.
	 *
	 * Do not assume a field order here. The hosted tokenizer reports the expiry
	 * differently between its dropdown and text-field modes, and reading a six digit
	 * value as YYYYMM when it is really MMYYYY rejects every card with "please enter a
	 * valid card expiration date". Instead try each layout the digits could represent and
	 * keep the first that yields a real month in a plausible future year. Only one layout
	 * can satisfy both tests, so the choice is unambiguous: "122029" is December 2029 and
	 * never year 1220, and "202912" is the reverse.
	 *
	 * @param string $expiry Expiry digits, with or without separators.
	 * @return string YYYYMM, or an empty string when no plausible date can be read.
	 */
	public static function normalise_expiry( string $expiry ): string {
		$expiry = preg_replace( '/\D/', '', $expiry );

		switch ( strlen( $expiry ) ) {
			case 4:
				// MMYY, else YYMM.
				$layouts = array(
					array( 2000 + (int) substr( $expiry, 2, 2 ), (int) substr( $expiry, 0, 2 ) ),
					array( 2000 + (int) substr( $expiry, 0, 2 ), (int) substr( $expiry, 2, 2 ) ),
				);
				break;
			case 5:
				// YYYYM, else MYYYY.
				$layouts = array(
					array( (int) substr( $expiry, 0, 4 ), (int) substr( $expiry, 4, 1 ) ),
					array( (int) substr( $expiry, 1, 4 ), (int) substr( $expiry, 0, 1 ) ),
				);
				break;
			case 6:
				// YYYYMM, else MMYYYY.
				$layouts = array(
					array( (int) substr( $expiry, 0, 4 ), (int) substr( $expiry, 4, 2 ) ),
					array( (int) substr( $expiry, 2, 4 ), (int) substr( $expiry, 0, 2 ) ),
				);
				break;
			case 8:
				// YYYYMMDD.
				$layouts = array(
					array( (int) substr( $expiry, 0, 4 ), (int) substr( $expiry, 4, 2 ) ),
				);
				break;
			default:
				return '';
		}

		foreach ( $layouts as $layout ) {
			list( $year, $month ) = $layout;
			if ( self::is_plausible_expiry( $year, $month ) ) {
				return sprintf( '%04d%02d', $year, $month );
			}
		}

		return '';
	}

	/**
	 * Whether a year and month describe a card that has not already expired.
	 *
	 * Cards stay valid through the last day of their expiry month, so the current
	 * month counts as valid.
	 *
	 * @param int $year  Four digit year.
	 * @param int $month Month, 1-12.
	 */
	private static function is_plausible_expiry( int $year, int $month ): bool {
		if ( $month < 1 || $month > 12 ) {
			return false;
		}
		$current_year = (int) gmdate( 'Y' );
		if ( $year < $current_year || $year > $current_year + 25 ) {
			return false;
		}
		return ! ( $year === $current_year && $month < (int) gmdate( 'n' ) );
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
