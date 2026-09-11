<?php
/**
 * API exception.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for transport failures, HTTP errors and unparseable responses.
 *
 * Declines are NOT exceptions: the gateway returns HTTP 200 with respstat "C".
 */
class ApiException extends \Exception {

	const TIMEOUT             = 'timeout';
	const CONNECTION          = 'connection';
	const INVALID_CREDENTIALS = 'invalid_credentials';
	const RATE_LIMITED        = 'rate_limited';
	const HTTP_ERROR          = 'http_error';
	const INVALID_RESPONSE    = 'invalid_response';

	/** @var string */
	private $type;

	/** @var int */
	private $http_status;

	/** @var mixed */
	private $body;

	/**
	 * @param string $type        One of the class constants.
	 * @param string $message     Human readable message (safe to log; may not be safe for customers).
	 * @param int    $http_status HTTP status, when applicable.
	 * @param mixed  $body        Decoded response body, when available.
	 */
	public function __construct( string $type, string $message, int $http_status = 0, $body = null ) {
		parent::__construct( $message, $http_status );
		$this->type        = $type;
		$this->http_status = $http_status;
		$this->body        = $body;
	}

	/**
	 * Exception type constant.
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * HTTP status code (0 when the request never completed).
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Decoded body when the gateway returned one.
	 *
	 * @return mixed
	 */
	public function get_body() {
		return $this->body;
	}

	/**
	 * Whether the request timed out (outcome unknown; reconciliation required for auths).
	 */
	public function is_timeout(): bool {
		return self::TIMEOUT === $this->type;
	}

	/**
	 * Whether the credentials were rejected.
	 */
	public function is_auth_failure(): bool {
		return self::INVALID_CREDENTIALS === $this->type;
	}

	/**
	 * A message that is safe to show to shoppers.
	 */
	public function customer_message(): string {
		switch ( $this->type ) {
			case self::TIMEOUT:
				return __( 'We could not confirm your payment because the payment service did not respond in time. You have not been charged. Please try again.', 'paradox-cardpointe-gateway-for-woocommerce' );
			case self::INVALID_CREDENTIALS:
				return __( 'The payment service rejected the store credentials. Please contact the store owner.', 'paradox-cardpointe-gateway-for-woocommerce' );
			case self::RATE_LIMITED:
				return __( 'The payment service is busy. Please wait a moment and try again.', 'paradox-cardpointe-gateway-for-woocommerce' );
			default:
				return __( 'We were unable to reach the payment service. Please try again or use a different payment method.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
	}
}
