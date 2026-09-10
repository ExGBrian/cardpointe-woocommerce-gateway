<?php
/**
 * Order meta accessors.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

use ParadoxSolutions\CardPointe\Api\Response;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for every _paradox_cardpointe_* meta key written to orders and subscriptions.
 */
final class OrderMeta {

	const PREFIX = '_paradox_cardpointe_';

	const ENVIRONMENT       = 'environment';
	const MERCHANT_ID       = 'merchant_id';
	const PAYMENT_TYPE      = 'payment_type';
	const ORDERID           = 'orderid';
	const PENDING_ORDERID   = 'pending_orderid';
	const RETREF            = 'retref';
	const AUTHCODE          = 'authcode';
	const AVSRESP           = 'avsresp';
	const CVVRESP           = 'cvvresp';
	const ENTRYMODE         = 'entrymode';
	const BINTYPE           = 'bintype';
	const AMOUNT_AUTHORIZED = 'amount_authorized';
	const CAPTURED          = 'captured';
	const CAPTURED_AMOUNT   = 'captured_amount';
	const VOIDED            = 'voided';
	const SETLSTAT          = 'setlstat';
	const AUTHORIZED_AT     = 'authorized_at';
	const CARD_TYPE         = 'card_type';
	const LAST4             = 'last4';
	const EXPIRY            = 'expiry';
	const ACCTTYPE          = 'accttype';
	const ACH_CONSENT       = 'ach_consent';
	const PROFILE_ID        = 'profile_id';
	const ACCT_ID           = 'acct_id';
	const TOKEN_ID          = 'token_id';
	const TOKEN             = 'token';
	const TOKEN_EXPIRY      = 'token_expiry';
	const RECEIPT           = 'receipt';
	const REFUNDS           = 'refunds';

	/**
	 * Full meta key for a name.
	 *
	 * @param string $name Short name.
	 */
	public static function key( string $name ): string {
		return self::PREFIX . $name;
	}

	/**
	 * Read a value.
	 *
	 * @param \WC_Abstract_Order $order   Order or subscription.
	 * @param string             $name    Short name.
	 * @param mixed              $default Default.
	 * @return mixed
	 */
	public static function get( \WC_Abstract_Order $order, string $name, $default = '' ) {
		$value = $order->get_meta( self::key( $name ), true );
		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * Write a value (does not save the order).
	 *
	 * @param \WC_Abstract_Order $order Order or subscription.
	 * @param string             $name  Short name.
	 * @param mixed              $value Value.
	 */
	public static function set( \WC_Abstract_Order $order, string $name, $value ) {
		$order->update_meta_data( self::key( $name ), $value );
	}

	/**
	 * Delete a value (does not save the order).
	 *
	 * @param \WC_Abstract_Order $order Order or subscription.
	 * @param string             $name  Short name.
	 */
	public static function delete( \WC_Abstract_Order $order, string $name ) {
		$order->delete_meta_data( self::key( $name ) );
	}

	/**
	 * Retrieval reference of the payment (empty when none).
	 *
	 * @param \WC_Abstract_Order $order Order.
	 */
	public static function retref( \WC_Abstract_Order $order ): string {
		$retref = (string) self::get( $order, self::RETREF );
		return '' !== $retref ? $retref : (string) $order->get_transaction_id();
	}

	/**
	 * Whether funds were captured.
	 *
	 * @param \WC_Abstract_Order $order Order.
	 */
	public static function is_captured( \WC_Abstract_Order $order ): bool {
		return 'yes' === self::get( $order, self::CAPTURED );
	}

	/**
	 * Whether the transaction was voided.
	 *
	 * @param \WC_Abstract_Order $order Order.
	 */
	public static function is_voided( \WC_Abstract_Order $order ): bool {
		return 'yes' === self::get( $order, self::VOIDED );
	}

	/**
	 * Whether the order's transaction belongs to the currently active environment.
	 *
	 * @param \WC_Abstract_Order $order       Order.
	 * @param Credentials        $credentials Active credentials.
	 */
	public static function matches_environment( \WC_Abstract_Order $order, Credentials $credentials ): bool {
		$env = (string) self::get( $order, self::ENVIRONMENT );
		return '' === $env || $env === $credentials->environment();
	}

	/**
	 * Records everything useful from an approved auth response.
	 *
	 * @param \WC_Order     $order       Order.
	 * @param Response      $response    Approved auth response.
	 * @param PaymentSource $source      Payment source used.
	 * @param Credentials   $credentials Credentials used.
	 * @param bool          $captured    Whether the auth included capture.
	 */
	public static function apply_auth_response( \WC_Order $order, Response $response, PaymentSource $source, Credentials $credentials, bool $captured ) {
		$retref = $response->string( 'retref' );

		self::set( $order, self::ENVIRONMENT, $credentials->environment() );
		self::set( $order, self::MERCHANT_ID, $credentials->merchant_id );
		self::set( $order, self::PAYMENT_TYPE, $source->type );
		self::set( $order, self::RETREF, $retref );
		self::set( $order, self::AUTHCODE, $response->string( 'authcode' ) );
		self::set( $order, self::AVSRESP, $response->string( 'avsresp' ) );
		self::set( $order, self::CVVRESP, $response->string( 'cvvresp' ) );
		self::set( $order, self::ENTRYMODE, $response->string( 'entrymode' ) );
		self::set( $order, self::BINTYPE, $response->string( 'bintype' ) );
		self::set( $order, self::AMOUNT_AUTHORIZED, $response->string( 'amount' ) );
		self::set( $order, self::CAPTURED, $captured ? 'yes' : 'no' );
		self::set( $order, self::AUTHORIZED_AT, time() );
		self::set( $order, self::LAST4, $source->last4() ?: substr( $response->string( 'account' ), -4 ) );
		self::delete( $order, self::PENDING_ORDERID );

		if ( $captured ) {
			self::set( $order, self::CAPTURED_AMOUNT, $response->string( 'amount' ) );
		}
		if ( 'card' === $source->type ) {
			self::set( $order, self::CARD_TYPE, $source->brand );
			self::set( $order, self::EXPIRY, $source->expiry );
		} else {
			self::set( $order, self::ACCTTYPE, $source->accttype );
		}

		if ( '' !== $retref ) {
			$order->set_transaction_id( $retref );
		}

		$profile_id = $response->string( 'profileid' ) ?: $source->profile_id;
		$acct_id    = $response->string( 'acctid' ) ?: $source->acct_id;
		if ( '' !== $profile_id ) {
			self::set( $order, self::PROFILE_ID, $profile_id );
			self::set( $order, self::ACCT_ID, $acct_id );
		}
		if ( '' !== $source->token ) {
			self::set( $order, self::TOKEN, $source->token );
			self::set( $order, self::TOKEN_EXPIRY, $source->expiry );
		}
		if ( $source->wc_token ) {
			self::set( $order, self::TOKEN_ID, $source->wc_token->get_id() );
		}

		$receipt = $response->receipt();
		if ( null !== $receipt ) {
			self::set( $order, self::RECEIPT, $receipt );
		}
	}

	/**
	 * Stores the vaulted payment method details (used by subscriptions and pre-orders).
	 *
	 * @param \WC_Abstract_Order $order  Order or subscription.
	 * @param array              $stored Keys: type, profile_id, acct_id, token, expiry, accttype, token_id, environment, brand, last4.
	 */
	public static function set_stored_payment_method( \WC_Abstract_Order $order, array $stored ) {
		$map = array(
			'type'        => self::PAYMENT_TYPE,
			'profile_id'  => self::PROFILE_ID,
			'acct_id'     => self::ACCT_ID,
			'token'       => self::TOKEN,
			'expiry'      => self::TOKEN_EXPIRY,
			'accttype'    => self::ACCTTYPE,
			'token_id'    => self::TOKEN_ID,
			'environment' => self::ENVIRONMENT,
			'brand'       => self::CARD_TYPE,
			'last4'       => self::LAST4,
		);
		foreach ( $map as $from => $to ) {
			if ( isset( $stored[ $from ] ) && '' !== $stored[ $from ] ) {
				self::set( $order, $to, $stored[ $from ] );
			}
		}
	}

	/**
	 * Reads the vaulted payment method details.
	 *
	 * @param \WC_Abstract_Order $order Order or subscription.
	 */
	public static function stored_payment_method( \WC_Abstract_Order $order ): array {
		return array(
			'type'        => (string) self::get( $order, self::PAYMENT_TYPE, 'card' ),
			'profile_id'  => (string) self::get( $order, self::PROFILE_ID ),
			'acct_id'     => (string) self::get( $order, self::ACCT_ID ),
			'token'       => (string) self::get( $order, self::TOKEN ),
			'expiry'      => (string) self::get( $order, self::TOKEN_EXPIRY ),
			'accttype'    => (string) self::get( $order, self::ACCTTYPE ),
			'token_id'    => (int) self::get( $order, self::TOKEN_ID, 0 ),
			'environment' => (string) self::get( $order, self::ENVIRONMENT ),
			'brand'       => (string) self::get( $order, self::CARD_TYPE ),
			'last4'       => (string) self::get( $order, self::LAST4 ),
		);
	}

	/**
	 * Whether an order/subscription has enough vaulted data to charge later.
	 *
	 * @param \WC_Abstract_Order $order Order or subscription.
	 */
	public static function has_stored_payment_method( \WC_Abstract_Order $order ): bool {
		$stored = self::stored_payment_method( $order );
		return '' !== $stored['profile_id'] || '' !== $stored['token'];
	}

	/**
	 * Appends a void/refund record.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $record Keys: type (void|refund), retref, amount, orderid.
	 */
	public static function add_refund_record( \WC_Order $order, array $record ) {
		$records          = self::refunds( $order );
		$record['date']   = gmdate( 'c' );
		$records[]        = $record;
		self::set( $order, self::REFUNDS, $records );
	}

	/**
	 * Void/refund records.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function refunds( \WC_Order $order ): array {
		$records = self::get( $order, self::REFUNDS, array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Human readable AVS response.
	 *
	 * @param string $code AVS code.
	 */
	public static function avs_text( string $code ): string {
		$map = array(
			'A' => __( 'Address matches, ZIP does not', 'paradox-cardpointe-gateway' ),
			'B' => __( 'Address matches, postal code not verified', 'paradox-cardpointe-gateway' ),
			'D' => __( 'Address and postal code match (international)', 'paradox-cardpointe-gateway' ),
			'E' => __( 'AVS not allowed for this card type', 'paradox-cardpointe-gateway' ),
			'F' => __( 'Address and postal code match (UK)', 'paradox-cardpointe-gateway' ),
			'G' => __( 'Not supported by issuer (international)', 'paradox-cardpointe-gateway' ),
			'I' => __( 'Address not verified (international)', 'paradox-cardpointe-gateway' ),
			'M' => __( 'Address and postal code match', 'paradox-cardpointe-gateway' ),
			'N' => __( 'No match on address or ZIP', 'paradox-cardpointe-gateway' ),
			'P' => __( 'Postal code matches, address not verified', 'paradox-cardpointe-gateway' ),
			'R' => __( 'Retry, system unavailable', 'paradox-cardpointe-gateway' ),
			'S' => __( 'Service not supported by issuer', 'paradox-cardpointe-gateway' ),
			'U' => __( 'Address information unavailable', 'paradox-cardpointe-gateway' ),
			'W' => __( '9-digit ZIP matches, address does not', 'paradox-cardpointe-gateway' ),
			'X' => __( 'Address and 9-digit ZIP match', 'paradox-cardpointe-gateway' ),
			'Y' => __( 'Address and 5-digit ZIP match', 'paradox-cardpointe-gateway' ),
			'Z' => __( '5-digit ZIP matches, address does not', 'paradox-cardpointe-gateway' ),
		);
		$code = strtoupper( $code );
		return $map[ $code ] ?? ( '' === $code ? __( 'Not checked', 'paradox-cardpointe-gateway' ) : $code );
	}

	/**
	 * Human readable CVV response.
	 *
	 * @param string $code CVV code.
	 */
	public static function cvv_text( string $code ): string {
		$map = array(
			'M' => __( 'Match', 'paradox-cardpointe-gateway' ),
			'N' => __( 'No match', 'paradox-cardpointe-gateway' ),
			'P' => __( 'Not processed', 'paradox-cardpointe-gateway' ),
			'S' => __( 'CVV not present', 'paradox-cardpointe-gateway' ),
			'U' => __( 'Issuer not certified', 'paradox-cardpointe-gateway' ),
			'X' => __( 'No response', 'paradox-cardpointe-gateway' ),
		);
		$code = strtoupper( $code );
		return $map[ $code ] ?? ( '' === $code ? __( 'Not checked', 'paradox-cardpointe-gateway' ) : $code );
	}
}
