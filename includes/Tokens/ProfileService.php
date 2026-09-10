<?php
/**
 * CardPointe profile management.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Tokens;

use ParadoxSolutions\CardPointe\Api\ApiException;
use ParadoxSolutions\CardPointe\Api\Client;
use ParadoxSolutions\CardPointe\Api\RequestBuilder;
use ParadoxSolutions\CardPointe\Gateway\PaymentException;
use ParadoxSolutions\CardPointe\Gateway\PaymentSource;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * One CardPointe profile per customer per environment; accounts inside it map to WooCommerce tokens.
 */
final class ProfileService {

	/** @var Client */
	private $client;

	/**
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * User meta key for the profile ID in an environment.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param string      $what        profile_id|merchant_id.
	 */
	public static function user_meta_key( Credentials $credentials, string $what = 'profile_id' ): string {
		return '_paradox_cardpointe_' . $what . '_' . $credentials->environment();
	}

	/**
	 * Profile ID stored for a user (empty when none or when boarded to a different MID).
	 *
	 * @param int         $user_id     User ID.
	 * @param Credentials $credentials Credentials.
	 */
	public static function get_user_profile_id( int $user_id, Credentials $credentials ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$profile_id = (string) get_user_meta( $user_id, self::user_meta_key( $credentials, 'profile_id' ), true );
		$merchant   = (string) get_user_meta( $user_id, self::user_meta_key( $credentials, 'merchant_id' ), true );
		if ( '' === $profile_id || ( '' !== $merchant && $merchant !== $credentials->merchant_id ) ) {
			return '';
		}
		return $profile_id;
	}

	/**
	 * Stores the profile ID for a user.
	 *
	 * @param int         $user_id     User ID.
	 * @param Credentials $credentials Credentials.
	 * @param string      $profile_id  Profile ID.
	 */
	public static function set_user_profile_id( int $user_id, Credentials $credentials, string $profile_id ) {
		if ( $user_id <= 0 || '' === $profile_id ) {
			return;
		}
		update_user_meta( $user_id, self::user_meta_key( $credentials, 'profile_id' ), $profile_id );
		update_user_meta( $user_id, self::user_meta_key( $credentials, 'merchant_id' ), $credentials->merchant_id );
	}

	/**
	 * Removes the stored profile reference for a user.
	 *
	 * @param int         $user_id     User ID.
	 * @param Credentials $credentials Credentials.
	 */
	public static function forget_user_profile( int $user_id, Credentials $credentials ) {
		delete_user_meta( $user_id, self::user_meta_key( $credentials, 'profile_id' ) );
		delete_user_meta( $user_id, self::user_meta_key( $credentials, 'merchant_id' ) );
	}

	/**
	 * Adds an account to a profile (creating the profile when $existing_profile_id is empty).
	 *
	 * @param PaymentSource $source              New source (token present).
	 * @param array         $billing             Billing fields.
	 * @param string        $existing_profile_id Existing profile ID or empty.
	 * @return array [profile_id, acct_id]
	 *
	 * @throws PaymentException On failure.
	 */
	public function add_account( PaymentSource $source, array $billing, string $existing_profile_id ): array {
		$body = RequestBuilder::profile_add( $source, $billing, $existing_profile_id );

		try {
			$response = $this->client->profile_put( $body );
		} catch ( ApiException $e ) {
			throw new PaymentException( $e->customer_message() );
		}

		if ( ! $response->is_approved() || '' === $response->string( 'profileid' ) ) {
			Plugin::instance()->logger()->warning( 'Profile creation failed', array( 'resptext' => $response->string( 'resptext' ), 'respcode' => $response->string( 'respcode' ) ) );
			throw new PaymentException(
				sprintf(
					/* translators: %s: gateway message */
					__( 'The payment method could not be saved (%s).', 'paradox-cardpointe-gateway' ),
					$response->string( 'resptext', __( 'unknown error', 'paradox-cardpointe-gateway' ) )
				)
			);
		}

		return array( $response->string( 'profileid' ), $response->string( 'acctid' ) );
	}

	/**
	 * Deletes an account from a profile. Clears the default flag first when needed.
	 *
	 * @param string $profile_id Profile ID.
	 * @param string $acct_id    Account ID.
	 * @return bool Whether the account was deleted.
	 */
	public function delete_account( string $profile_id, string $acct_id ): bool {
		if ( '' === $profile_id || '' === $acct_id ) {
			return false;
		}
		$logger = Plugin::instance()->logger();

		try {
			$response = $this->client->profile_delete( $profile_id, $acct_id );
			if ( $response->is_approved() ) {
				return true;
			}
			$text = strtolower( $response->string( 'resptext' ) );
			if ( false === strpos( $text, 'default' ) ) {
				$logger->warning( 'Profile account delete refused', array( 'profile_id' => $profile_id, 'acct_id' => $acct_id, 'resptext' => $response->string( 'resptext' ) ) );
				return false;
			}
			// Cannot delete the default account: clear the flag, then retry.
			$this->client->profile_put(
				array(
					'profile'       => $profile_id . '/' . $acct_id,
					'defaultacct'   => 'N',
					'profileupdate' => 'Y',
				)
			);
			$retry = $this->client->profile_delete( $profile_id, $acct_id );
			return $retry->is_approved();
		} catch ( ApiException $e ) {
			$logger->error( 'Profile account delete failed', array( 'profile_id' => $profile_id, 'acct_id' => $acct_id, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Whether a profile/account pair exists at CardPointe.
	 *
	 * @param string $profile_id Profile ID.
	 * @param string $acct_id    Account ID.
	 * @return bool|null Null when the lookup failed.
	 */
	public function account_exists( string $profile_id, string $acct_id ) {
		try {
			$response = $this->client->profile_get( $profile_id, $acct_id );
		} catch ( ApiException $e ) {
			return null;
		}
		foreach ( $response->transactions() as $item ) {
			if ( $item->string( 'profileid' ) === $profile_id ) {
				return true;
			}
		}
		return '' !== $response->string( 'profileid' );
	}
}
