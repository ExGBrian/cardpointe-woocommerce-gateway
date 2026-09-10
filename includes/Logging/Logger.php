<?php
/**
 * WooCommerce logger wrapper with redaction.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log under the "paradox-cardpointe" source.
 *
 * Debug and info entries are only written when logging is enabled in the settings;
 * warnings and errors are always written. Sensitive values are redacted first.
 */
final class Logger {

	const SOURCE = 'paradox-cardpointe';

	/** @var bool */
	private $enabled;

	/** @var \WC_Logger_Interface|null */
	private $wc_logger = null;

	/**
	 * @param bool $enabled Whether verbose (debug/info) logging is enabled.
	 */
	public function __construct( bool $enabled ) {
		$this->enabled = $enabled;
	}

	/**
	 * Whether verbose logging is on.
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Extra data (redacted before writing).
	 */
	public function debug( string $message, array $context = array() ) {
		if ( $this->enabled ) {
			$this->write( 'debug', $message, $context );
		}
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Extra data (redacted before writing).
	 */
	public function info( string $message, array $context = array() ) {
		if ( $this->enabled ) {
			$this->write( 'info', $message, $context );
		}
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Extra data (redacted before writing).
	 */
	public function warning( string $message, array $context = array() ) {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Extra data (redacted before writing).
	 */
	public function error( string $message, array $context = array() ) {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Writes one entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 */
	private function write( string $level, string $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( null === $this->wc_logger ) {
			$this->wc_logger = wc_get_logger();
		}
		$context = self::redact( $context );
		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}
		$this->wc_logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Recursively masks sensitive keys.
	 *
	 * @param array $data Data to redact.
	 * @return array
	 */
	public static function redact( array $data ): array {
		$last4_keys  = array( 'account', 'token', 'profile' );
		$remove_keys = array( 'cvv2', 'bankaba', 'password', 'authorization', 'track', 'signature', 'emvtagdata', 'receipt', 'receiptobj', 'securevalue' );
		$mask_keys   = array( 'expiry', 'address', 'address2', 'phone', 'email', 'api_password', 'production_api_password', 'sandbox_api_password' );

		/**
		 * Filters the redaction lists.
		 *
		 * @param array $lists Keys grouped by treatment.
		 */
		$lists = apply_filters(
			'paradox_cardpointe_log_redact_keys',
			array(
				'last4'  => $last4_keys,
				'remove' => $remove_keys,
				'mask'   => $mask_keys,
			)
		);

		$out = array();
		foreach ( $data as $key => $value ) {
			$lower = strtolower( (string) $key );
			if ( is_array( $value ) ) {
				$out[ $key ] = self::redact( $value );
				continue;
			}
			if ( is_object( $value ) ) {
				$out[ $key ] = '[object]';
				continue;
			}
			if ( in_array( $lower, $lists['remove'], true ) ) {
				$out[ $key ] = '[redacted]';
			} elseif ( in_array( $lower, $lists['last4'], true ) ) {
				$string      = (string) $value;
				$out[ $key ] = strlen( $string ) > 4 ? str_repeat( '*', max( 0, strlen( $string ) - 4 ) ) . substr( $string, -4 ) : $string;
			} elseif ( in_array( $lower, $lists['mask'], true ) ) {
				$out[ $key ] = '' === (string) $value ? '' : '[masked]';
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * URL of the WooCommerce log viewer filtered to this source.
	 */
	public static function log_viewer_url(): string {
		return admin_url( 'admin.php?page=wc-status&tab=logs&source=' . self::SOURCE );
	}
}
