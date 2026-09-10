<?php
/**
 * Cached merchant configuration (inquireMerchant).
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Api;

use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and caches GET /inquireMerchant/{merchid} so gateways can adapt (e.g. hide eCheck).
 */
final class MerchantInfo {

	const TTL         = 12 * HOUR_IN_SECONDS;
	const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient key for a credential set.
	 *
	 * @param Credentials $credentials Credentials.
	 */
	public static function transient_key( Credentials $credentials ): string {
		return 'paradox_cardpointe_merchant_' . $credentials->environment() . '_' . md5( $credentials->site . '|' . $credentials->merchant_id );
	}

	/**
	 * Returns the merchant info array, null when unknown (credentials incomplete or lookup failed).
	 *
	 * @param Credentials|null $credentials Credentials; defaults to active.
	 * @param bool             $force       Bypass the cache.
	 * @return array|null
	 */
	public static function get( ?Credentials $credentials = null, bool $force = false ) {
		$credentials = $credentials ?: Credentials::active();
		if ( ! $credentials->is_complete() ) {
			return null;
		}

		$key = self::transient_key( $credentials );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return isset( $cached['__failed'] ) ? null : $cached;
			}
		}

		try {
			$response = Plugin::instance()->client( $credentials )->inquire_merchant();
			$info     = $response->all();
			set_transient( $key, $info, self::TTL );
			return $info;
		} catch ( ApiException $e ) {
			set_transient( $key, array( '__failed' => $e->get_type() ), self::FAILURE_TTL );
			return null;
		}
	}

	/**
	 * Store the result of a successful manual lookup (Test connection button).
	 *
	 * @param Credentials $credentials Credentials.
	 * @param array       $info        Merchant info.
	 */
	public static function store( Credentials $credentials, array $info ) {
		set_transient( self::transient_key( $credentials ), $info, self::TTL );
	}

	/**
	 * Clears the cache for a credential set.
	 *
	 * @param Credentials $credentials Credentials.
	 */
	public static function forget( Credentials $credentials ) {
		delete_transient( self::transient_key( $credentials ) );
	}

	/**
	 * Whether the MID can process ACH. Null when unknown.
	 *
	 * @param Credentials|null $credentials Credentials.
	 * @return bool|null
	 */
	public static function supports_echeck( ?Credentials $credentials = null ) {
		$info = self::get( $credentials );
		if ( null === $info || ! isset( $info['echeck'] ) ) {
			return null;
		}
		return 'Y' === strtoupper( (string) $info['echeck'] );
	}
}
