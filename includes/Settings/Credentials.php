<?php
/**
 * API credentials value object.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Settings;

use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Holds one set of CardPointe credentials (production or sandbox) and derives the URLs from them.
 */
final class Credentials {

	const ENV_PRODUCTION = 'production';
	const ENV_SANDBOX    = 'sandbox';

	/** @var string CardPointe site name, e.g. "fts". */
	public $site = '';

	/** @var string Merchant ID (MID). */
	public $merchant_id = '';

	/** @var string API username. */
	public $username = '';

	/** @var string API password. */
	public $password = '';

	/** @var bool Whether these are sandbox (UAT) credentials. */
	public $sandbox = true;

	/**
	 * Builds the credentials for the environment currently selected in the gateway settings.
	 */
	public static function active(): Credentials {
		$settings = self::settings();
		$sandbox  = ! isset( $settings['sandbox_mode'] ) || 'yes' === $settings['sandbox_mode'];
		return self::from_settings( $settings, $sandbox );
	}

	/**
	 * Builds credentials for a specific environment from a settings array.
	 *
	 * @param array $settings Card gateway settings (option value).
	 * @param bool  $sandbox  Whether to read the sandbox or production set.
	 */
	public static function from_settings( array $settings, bool $sandbox ): Credentials {
		$env  = $sandbox ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
		$self = new self();

		$self->sandbox     = $sandbox;
		$self->site        = self::sanitize_site( $settings[ $env . '_site' ] ?? 'fts' );
		$self->merchant_id = self::sanitize_merchant_id( $settings[ $env . '_merchant_id' ] ?? '' );
		$self->username    = trim( (string) ( $settings[ $env . '_api_username' ] ?? '' ) );
		$self->password    = (string) ( $settings[ $env . '_api_password' ] ?? '' );

		$constant = 'PARADOX_CARDPOINTE_' . strtoupper( $env ) . '_API_PASSWORD';
		if ( defined( $constant ) && '' !== constant( $constant ) ) {
			$self->password = (string) constant( $constant );
		}

		/**
		 * Filters the credentials before use.
		 *
		 * @param Credentials $self Credentials object.
		 */
		return apply_filters( 'paradox_cardpointe_credentials', $self );
	}

	/**
	 * Builds credentials from raw values (used by the "Test connection" button).
	 *
	 * @param string $site        Site name.
	 * @param string $merchant_id Merchant ID.
	 * @param string $username    API username.
	 * @param string $password    API password.
	 * @param bool   $sandbox     Environment flag.
	 */
	public static function from_values( string $site, string $merchant_id, string $username, string $password, bool $sandbox ): Credentials {
		$self              = new self();
		$self->site        = self::sanitize_site( $site );
		$self->merchant_id = self::sanitize_merchant_id( $merchant_id );
		$self->username    = trim( $username );
		$self->password    = $password;
		$self->sandbox     = $sandbox;
		return $self;
	}

	/**
	 * Raw card gateway settings array (the option shared by both gateways).
	 */
	public static function settings(): array {
		$settings = get_option( 'woocommerce_' . Plugin::CARD_GATEWAY_ID . '_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether a password override constant is defined for an environment.
	 *
	 * @param string $env production|sandbox.
	 */
	public static function has_password_constant( string $env ): bool {
		$constant = 'PARADOX_CARDPOINTE_' . strtoupper( $env ) . '_API_PASSWORD';
		return defined( $constant ) && '' !== constant( $constant );
	}

	/**
	 * Sanitizes a site name: letters, digits and hyphens only.
	 *
	 * @param string $site Raw site.
	 */
	public static function sanitize_site( $site ): string {
		$site = strtolower( trim( (string) $site ) );
		$site = preg_replace( '/[^a-z0-9-]/', '', $site );
		return '' === $site ? 'fts' : substr( $site, 0, 32 );
	}

	/**
	 * Sanitizes a merchant ID: digits only, max 16.
	 *
	 * @param string $merchant_id Raw MID.
	 */
	public static function sanitize_merchant_id( $merchant_id ): string {
		return substr( preg_replace( '/\D/', '', (string) $merchant_id ), 0, 16 );
	}

	/**
	 * Environment name.
	 */
	public function environment(): string {
		return $this->sandbox ? self::ENV_SANDBOX : self::ENV_PRODUCTION;
	}

	/**
	 * Whether all required values are present.
	 */
	public function is_complete(): bool {
		return '' !== $this->site && '' !== $this->merchant_id && '' !== $this->username && '' !== $this->password;
	}

	/**
	 * Host name for both the REST API and the tokenizer.
	 *
	 * Sandbox uses {site}-uat.cardconnect.com; entering "fts-uat" directly is tolerated.
	 */
	public function host(): string {
		$site = $this->site;
		if ( $this->sandbox && '-uat' !== substr( $site, -4 ) ) {
			$site .= '-uat';
		}
		return $site . '.cardconnect.com';
	}

	/**
	 * Base URL of the Gateway REST API, with trailing slash.
	 */
	public function api_base(): string {
		return 'https://' . $this->host() . '/cardconnect/rest/';
	}

	/**
	 * Hosted iFrame Tokenizer page URL (without query string).
	 */
	public function tokenizer_url(): string {
		return 'https://' . $this->host() . '/itoke/ajax-tokenizer.html';
	}

	/**
	 * Origin the tokenizer posts messages from; used to validate postMessage events.
	 */
	public function tokenizer_origin(): string {
		return 'https://' . $this->host();
	}

	/**
	 * Value for the HTTP Basic Authorization header.
	 */
	public function auth_header(): string {
		return 'Basic ' . base64_encode( $this->username . ':' . $this->password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
