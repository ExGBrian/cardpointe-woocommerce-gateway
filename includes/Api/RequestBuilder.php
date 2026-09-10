<?php
/**
 * Builds Gateway API request bodies.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Api;

use ParadoxSolutions\CardPointe\Gateway\PaymentSource;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions that turn WooCommerce objects into CardPointe request arrays.
 */
final class RequestBuilder {

	/**
	 * Formats an amount with two decimals, as the gateway expects.
	 *
	 * @param float|string $amount Amount.
	 */
	public static function amount( $amount ): string {
		$formatted = number_format( (float) $amount, 2, '.', '' );
		/**
		 * Filters the formatted amount sent to the gateway.
		 *
		 * @param string       $formatted Formatted amount.
		 * @param float|string $amount    Original amount.
		 */
		return (string) apply_filters( 'paradox_cardpointe_format_amount', $formatted, $amount );
	}

	/**
	 * Unique order ID for a gateway request. Never Luhn-valid because it contains letters.
	 *
	 * @param int    $order_id WooCommerce order/subscription/user ID.
	 * @param string $context  Optional suffix (R, APM, PO, REL).
	 * @param mixed  $order    Order object for filters.
	 */
	public static function orderid( int $order_id, string $context = '', $order = null ): string {
		$random  = strtolower( wp_generate_password( 6, false, false ) );
		$orderid = 'WC-' . $order_id . ( '' !== $context ? '-' . strtoupper( $context ) : '' ) . '-' . $random;
		$orderid = substr( preg_replace( '/[^A-Za-z0-9\-]/', '', $orderid ), 0, 50 );

		/**
		 * Filters the gateway order ID.
		 *
		 * @param string $orderid  Order ID.
		 * @param int    $order_id WooCommerce ID.
		 * @param string $context  Context suffix.
		 * @param mixed  $order    Order object.
		 */
		return (string) apply_filters( 'paradox_cardpointe_orderid', $orderid, $order_id, $context, $order );
	}

	/**
	 * Billing fields from an order.
	 *
	 * @param \WC_Abstract_Order $order Order.
	 */
	public static function billing_from_order( \WC_Abstract_Order $order ): array {
		return self::billing(
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'company'    => $order->get_billing_company(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'country'    => $order->get_billing_country(),
				'postcode'   => $order->get_billing_postcode(),
				'phone'      => $order->get_billing_phone(),
				'email'      => $order->get_billing_email(),
			)
		);
	}

	/**
	 * Billing fields from a customer (My Account flows).
	 *
	 * @param \WC_Customer $customer Customer.
	 */
	public static function billing_from_customer( \WC_Customer $customer ): array {
		return self::billing(
			array(
				'first_name' => $customer->get_billing_first_name() ?: $customer->get_first_name(),
				'last_name'  => $customer->get_billing_last_name() ?: $customer->get_last_name(),
				'company'    => $customer->get_billing_company(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'country'    => $customer->get_billing_country(),
				'postcode'   => $customer->get_billing_postcode(),
				'phone'      => $customer->get_billing_phone(),
				'email'      => $customer->get_billing_email() ?: $customer->get_email(),
			)
		);
	}

	/**
	 * Normalises a WooCommerce-style address array into gateway fields with spec max lengths.
	 *
	 * @param array $address Address.
	 */
	public static function billing( array $address ): array {
		$country = strtoupper( substr( trim( (string) ( $address['country'] ?? '' ) ), 0, 2 ) );
		$postal  = trim( (string) ( $address['postcode'] ?? '' ) );
		if ( 'US' === $country ) {
			$postal = preg_replace( '/[^0-9-]/', '', $postal );
			if ( 5 !== strlen( $postal ) && 10 !== strlen( $postal ) ) {
				$postal = substr( preg_replace( '/\D/', '', $postal ), 0, 5 );
			}
		} else {
			$postal = preg_replace( '/[^A-Za-z0-9 -]/', '', $postal );
		}

		$name = trim( (string) ( $address['first_name'] ?? '' ) . ' ' . (string) ( $address['last_name'] ?? '' ) );

		$fields = array(
			'name'     => self::truncate( $name, 30 ),
			'company'  => self::truncate( (string) ( $address['company'] ?? '' ), 128 ),
			'address'  => self::truncate( (string) ( $address['address_1'] ?? '' ), 30 ),
			'address2' => self::truncate( (string) ( $address['address_2'] ?? '' ), 30 ),
			'city'     => self::truncate( (string) ( $address['city'] ?? '' ), 30 ),
			'region'   => self::truncate( (string) ( $address['state'] ?? '' ), 20 ),
			'country'  => $country,
			'postal'   => self::truncate( $postal, 10 ),
			'phone'    => self::truncate( preg_replace( '/\D/', '', (string) ( $address['phone'] ?? '' ) ), 15 ),
			'email'    => self::truncate( sanitize_email( (string) ( $address['email'] ?? '' ) ), 128 ),
		);

		return array_filter(
			$fields,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * Adds the account (token or profile reference) fields for a source.
	 *
	 * @param PaymentSource $source Source.
	 * @param array         $body   Body being built.
	 * @param bool          $prefer_profile Use the profile reference when available.
	 */
	public static function apply_source( PaymentSource $source, array $body, bool $prefer_profile = true ): array {
		if ( $prefer_profile && $source->has_profile() ) {
			$body['profile'] = $source->profile_reference();
		} else {
			$body['account'] = $source->token;
			if ( 'card' === $source->type && '' !== $source->expiry ) {
				$body['expiry'] = $source->expiry;
			}
		}
		if ( 'echeck' === $source->type && '' !== $source->accttype ) {
			$body['accttype'] = $source->accttype;
		}
		return $body;
	}

	/**
	 * SEC-code fields for ACH.
	 *
	 * @param string $sec_mode both|fiserv|profitstars.
	 * @param string $entry    WEB|PPD|TEL|CCD.
	 */
	public static function ach_fields( string $sec_mode, string $entry = 'WEB' ): array {
		$fields = array();
		if ( 'profitstars' !== $sec_mode ) {
			$fields['achEntryCode'] = $entry;
		}
		if ( 'fiserv' !== $sec_mode ) {
			// ProfitStars maps ecomind E => WEB, R => PPD, T => TEL.
			$map               = array(
				'WEB' => 'E',
				'PPD' => 'R',
				'TEL' => 'T',
				'CCD' => 'R',
			);
			$fields['ecomind'] = $map[ $entry ] ?? 'E';
		}
		/**
		 * Filters the ACH SEC-code fields.
		 *
		 * @param array  $fields   Fields.
		 * @param string $sec_mode Configured mode.
		 * @param string $entry    SEC code.
		 */
		return apply_filters( 'paradox_cardpointe_ach_fields', $fields, $sec_mode, $entry );
	}

	/**
	 * Authorization body for an order.
	 *
	 * @param \WC_Abstract_Order $order  Order.
	 * @param PaymentSource      $source Source.
	 * @param array              $opts   Options:
	 *   - capture (bool)          Capture immediately.
	 *   - amount (string|null)    Override amount (formatted).
	 *   - orderid (string)        Gateway order ID.
	 *   - ecomind (string)        E or R (cards).
	 *   - cof (string|null)       C or M.
	 *   - cofscheduled (string|null) Y or N.
	 *   - create_profile (bool)   Send profile:Y.
	 *   - receipt (bool)          Request receipt.
	 *   - level2 (bool)           Send taxamnt/invoiceid.
	 *   - sec_mode (string)       ACH SEC mode.
	 *   - ach_entry (string)      ACH SEC code.
	 *   - context (string)        For filters.
	 */
	public static function auth_for_order( \WC_Abstract_Order $order, PaymentSource $source, array $opts ): array {
		$opts = array_merge(
			array(
				'capture'        => true,
				'amount'         => null,
				'orderid'        => '',
				'ecomind'        => 'E',
				'cof'            => null,
				'cofscheduled'   => null,
				'create_profile' => false,
				'receipt'        => false,
				'level2'         => false,
				'sec_mode'       => 'both',
				'ach_entry'      => 'WEB',
				'context'        => 'checkout',
			),
			$opts
		);

		$body = array(
			'amount'   => null !== $opts['amount'] ? $opts['amount'] : self::amount( $order->get_total() ),
			'currency' => $order->get_currency(),
			'capture'  => $opts['capture'] ? 'Y' : 'N',
			'orderid'  => $opts['orderid'] ?: self::orderid( $order->get_id(), '', $order ),
		);

		$body = self::apply_source( $source, $body );

		if ( 'echeck' === $source->type ) {
			$body = array_merge( $body, self::ach_fields( $opts['sec_mode'], $opts['ach_entry'] ) );
		} else {
			$body['ecomind'] = $opts['ecomind'];
			if ( null !== $opts['cof'] ) {
				$body['cof'] = $opts['cof'];
			}
			if ( null !== $opts['cofscheduled'] ) {
				$body['cofscheduled'] = $opts['cofscheduled'];
			}
		}

		if ( $opts['create_profile'] ) {
			$body['profile']       = 'Y';
			$body['cofpermission'] = 'Y';
		}

		if ( $opts['receipt'] ) {
			$body['receipt'] = 'Y';
		}

		if ( $opts['level2'] ) {
			$tax = (float) $order->get_total_tax();
			if ( $tax > 0 ) {
				$body['taxamnt'] = self::amount( $tax );
			}
			$body['invoiceid'] = self::truncate( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $order->get_order_number() ), 12 );
		}

		$body = array_merge( $body, self::billing_from_order( $order ) );

		/**
		 * Filters the authorization request body.
		 *
		 * @param array              $body    Body.
		 * @param \WC_Abstract_Order $order   Order.
		 * @param PaymentSource      $source  Source.
		 * @param string             $context Context.
		 */
		return apply_filters( 'paradox_cardpointe_auth_request_args', $body, $order, $source, $opts['context'] );
	}

	/**
	 * $0 account verification body (cards only).
	 *
	 * @param PaymentSource $source  Source.
	 * @param array         $billing Billing fields.
	 * @param array         $opts    Options: orderid, create_profile, cofscheduled, context.
	 */
	public static function zero_auth( PaymentSource $source, array $billing, array $opts ): array {
		$opts = array_merge(
			array(
				'orderid'        => '',
				'create_profile' => true,
				'cofscheduled'   => 'N',
				'currency'       => get_woocommerce_currency(),
				'context'        => 'verify',
			),
			$opts
		);

		$body = array(
			'amount'       => '0',
			'currency'     => $opts['currency'],
			'capture'      => 'N',
			'orderid'      => $opts['orderid'],
			'ecomind'      => 'E',
			'cof'          => 'C',
			'cofscheduled' => $opts['cofscheduled'],
		);
		$body = self::apply_source( $source, $body, false );
		if ( $opts['create_profile'] ) {
			$body['profile']       = 'Y';
			$body['cofpermission'] = 'Y';
		}
		$body = array_merge( $body, $billing );

		/**
		 * Filters the $0 verification request body.
		 *
		 * @param array         $body    Body.
		 * @param PaymentSource $source  Source.
		 * @param string        $context Context.
		 */
		return apply_filters( 'paradox_cardpointe_verify_request_args', $body, $source, $opts['context'] );
	}

	/**
	 * PUT /profile body to create a profile or add an account to an existing one.
	 *
	 * @param PaymentSource $source              Source (new token).
	 * @param array         $billing             Billing fields.
	 * @param string        $existing_profile_id Existing profile ID, empty to create a new profile.
	 */
	public static function profile_add( PaymentSource $source, array $billing, string $existing_profile_id ): array {
		$body = array(
			'account'       => $source->token,
			'cofpermission' => 'Y',
		);
		if ( '' !== $existing_profile_id ) {
			$body['profile'] = $existing_profile_id;
		}
		if ( 'card' === $source->type ) {
			$body['expiry'] = $source->expiry;
		} else {
			$body['accttype'] = $source->accttype;
		}
		$body = array_merge( $body, $billing );

		/**
		 * Filters the profile creation request body.
		 *
		 * @param array         $body   Body.
		 * @param PaymentSource $source Source.
		 */
		return apply_filters( 'paradox_cardpointe_profile_request_args', $body, $source );
	}

	/**
	 * Truncates a string to a byte length without breaking multibyte characters.
	 *
	 * @param string $value  Value.
	 * @param int    $length Max length.
	 */
	public static function truncate( string $value, int $length ): string {
		$value = trim( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}
		return substr( $value, 0, $length );
	}
}
