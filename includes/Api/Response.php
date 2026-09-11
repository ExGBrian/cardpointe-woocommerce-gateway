<?php
/**
 * Gateway API response wrapper.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around a decoded JSON response from the CardPointe Gateway.
 */
final class Response {

	const APPROVED = 'A';
	const RETRY    = 'B';
	const DECLINED = 'C';

	/** @var array */
	private $data;

	/** @var int */
	private $http_status;

	/**
	 * @param array $data        Decoded body.
	 * @param int   $http_status HTTP status.
	 */
	public function __construct( array $data, int $http_status = 200 ) {
		$this->data        = $data;
		$this->http_status = $http_status;
	}

	/**
	 * Builds a response from a decoded body that may be a list (inquireByOrderid).
	 *
	 * @param mixed $decoded     Decoded JSON.
	 * @param int   $http_status HTTP status.
	 */
	public static function from_decoded( $decoded, int $http_status ): Response {
		if ( is_array( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
			return new self( array( 'transactions' => $decoded ), $http_status );
		}
		return new self( is_array( $decoded ) ? $decoded : array(), $http_status );
	}

	/**
	 * Raw value by key (case-sensitive, as returned by the gateway).
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default when missing.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( array_key_exists( $key, $this->data ) ) {
			return $this->data[ $key ];
		}
		// The gateway is inconsistent about casing (orderId vs orderid).
		foreach ( $this->data as $k => $v ) {
			if ( strtolower( $k ) === strtolower( $key ) ) {
				return $v;
			}
		}
		return $default;
	}

	/**
	 * String value by key, trimmed.
	 *
	 * @param string $key     Key.
	 * @param string $default Default.
	 */
	public function string( string $key, string $default = '' ): string {
		$value = $this->get( $key );
		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	/**
	 * Whether a key exists.
	 *
	 * @param string $key Key.
	 */
	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	/**
	 * Entire decoded body.
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * HTTP status of the response.
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * respstat value (A, B or C), upper-cased.
	 */
	public function respstat(): string {
		return strtoupper( $this->string( 'respstat' ) );
	}

	/**
	 * Whether the transaction was approved.
	 */
	public function is_approved(): bool {
		return self::APPROVED === $this->respstat();
	}

	/**
	 * Whether the gateway asked for a retry (outcome unknown).
	 */
	public function is_retry(): bool {
		return self::RETRY === $this->respstat();
	}

	/**
	 * Whether the transaction was declined.
	 */
	public function is_declined(): bool {
		return self::DECLINED === $this->respstat();
	}

	/**
	 * Settlement status, when present (capture/inquire responses).
	 */
	public function setlstat(): string {
		return $this->string( 'setlstat' );
	}

	/**
	 * Transactions list from inquireByOrderid.
	 *
	 * @return Response[]
	 */
	public function transactions(): array {
		$list = $this->get( 'transactions' );
		if ( ! is_array( $list ) ) {
			return empty( $this->data ) ? array() : array( $this );
		}
		$out = array();
		foreach ( $list as $item ) {
			if ( is_array( $item ) ) {
				$out[] = new self( $item, $this->http_status );
			}
		}
		return $out;
	}

	/**
	 * Human readable error text: "respcode - resptext" plus the decline category when present.
	 */
	public function error_text(): string {
		$parts = array_filter( array( $this->string( 'respcode' ), $this->string( 'resptext' ) ) );
		$text  = implode( ' - ', $parts );
		$hint  = $this->string( 'declineCategoryText' );
		if ( '' !== $hint ) {
			$text .= ' (' . $hint . ')';
		}
		if ( '' === $text ) {
			$text = __( 'Unknown gateway response', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		return $text;
	}

	/**
	 * Decoded receipt data when the request asked for one.
	 *
	 * @return array|null
	 */
	public function receipt() {
		$raw = $this->get( 'receipt' );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}
}
