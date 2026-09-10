<?php
/**
 * CardPointe Gateway REST client.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Api;

use ParadoxSolutions\CardPointe\Logging\Logger;
use ParadoxSolutions\CardPointe\Settings\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for https://{site}.cardconnect.com/cardconnect/rest/.
 *
 * Every method returns a Response for HTTP 2xx bodies (including declines) and throws
 * ApiException for transport failures, HTTP errors and unparseable bodies.
 */
final class Client {

	const TIMEOUT_WRITE = 30;
	const TIMEOUT_READ  = 15;

	/** @var Credentials */
	private $credentials;

	/** @var Logger */
	private $logger;

	/**
	 * @param Credentials $credentials Credentials for one environment.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Logger $logger ) {
		$this->credentials = $credentials;
		$this->logger      = $logger;
	}

	/**
	 * Credentials in use.
	 */
	public function credentials(): Credentials {
		return $this->credentials;
	}

	/* ---------------------------------------------------------------------
	 * Endpoint helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Authorization (optionally with capture).
	 *
	 * @param array $body Request body without merchid.
	 */
	public function auth( array $body ): Response {
		return $this->request( 'POST', 'auth', $body );
	}

	/**
	 * Capture a prior authorization.
	 *
	 * @param string      $retref Retrieval reference.
	 * @param string|null $amount Amount to capture; null captures the full authorization.
	 * @param array       $extra  Additional fields (authcode, receipt, level 2/3 data).
	 */
	public function capture( string $retref, ?string $amount = null, array $extra = array() ): Response {
		$body = array_merge( $extra, array( 'retref' => $retref ) );
		if ( null !== $amount ) {
			$body['amount'] = $amount;
		}
		return $this->request( 'POST', 'capture', $body );
	}

	/**
	 * Void an authorized or queued-for-capture transaction.
	 *
	 * @param string      $retref Retrieval reference.
	 * @param string|null $amount Partial amount (only valid while setlstat is Authorized).
	 */
	public function void( string $retref, ?string $amount = null ): Response {
		$body = array( 'retref' => $retref );
		if ( null !== $amount ) {
			$body['amount'] = $amount;
		}
		return $this->request( 'POST', 'void', $body );
	}

	/**
	 * Void by order ID. Retries up to three times on timeouts, as CardPointe recommends.
	 *
	 * @param string      $orderid Order ID used on the original auth.
	 * @param string|null $amount  Optional amount.
	 */
	public function void_by_orderid( string $orderid, ?string $amount = null ): Response {
		$body = array( 'orderid' => $orderid );
		if ( null !== $amount ) {
			$body['amount'] = $amount;
		}
		$attempt = 0;
		while ( true ) {
			$attempt++;
			try {
				return $this->request( 'POST', 'voidByOrderId', $body );
			} catch ( ApiException $e ) {
				if ( ! $e->is_timeout() || $attempt >= 3 ) {
					throw $e;
				}
				$this->logger->warning( 'voidByOrderId timed out, retrying', array( 'orderid' => $orderid, 'attempt' => $attempt ) );
			}
		}
	}

	/**
	 * Refund a settled transaction (or an unsettled one when the MID allows it).
	 *
	 * @param string      $retref  Retrieval reference of the original transaction.
	 * @param string|null $amount  Amount; null refunds the full amount.
	 * @param string|null $orderid Optional order ID for the refund record.
	 */
	public function refund( string $retref, ?string $amount = null, ?string $orderid = null ): Response {
		$body = array( 'retref' => $retref );
		if ( null !== $amount ) {
			$body['amount'] = $amount;
		}
		if ( null !== $orderid ) {
			$body['orderid'] = $orderid;
		}
		return $this->request( 'POST', 'refund', $body );
	}

	/**
	 * Transaction status by retref.
	 *
	 * @param string $retref Retrieval reference.
	 */
	public function inquire( string $retref ): Response {
		return $this->request( 'GET', 'inquire/' . rawurlencode( $retref ) . '/' . rawurlencode( $this->credentials->merchant_id ) );
	}

	/**
	 * Transactions by order ID (restricted to this MID).
	 *
	 * @param string $orderid Order ID.
	 */
	public function inquire_by_orderid( string $orderid ): Response {
		return $this->request( 'GET', 'inquireByOrderid/' . rawurlencode( $orderid ) . '/' . rawurlencode( $this->credentials->merchant_id ) . '/1' );
	}

	/**
	 * Create or update a stored profile / add an account to it.
	 *
	 * @param array $body Profile request body.
	 */
	public function profile_put( array $body ): Response {
		return $this->request( 'PUT', 'profile', $body );
	}

	/**
	 * Read a profile (all accounts when $acctid is empty).
	 *
	 * @param string $profileid Profile ID.
	 * @param string $acctid    Account ID.
	 */
	public function profile_get( string $profileid, string $acctid = '' ): Response {
		return $this->request( 'GET', 'profile/' . rawurlencode( $profileid ) . '/' . rawurlencode( $acctid ) . '/' . rawurlencode( $this->credentials->merchant_id ) );
	}

	/**
	 * Delete a profile account (or the whole profile when $acctid is empty).
	 *
	 * @param string $profileid Profile ID.
	 * @param string $acctid    Account ID.
	 */
	public function profile_delete( string $profileid, string $acctid = '' ): Response {
		return $this->request( 'DELETE', 'profile/' . rawurlencode( $profileid ) . '/' . rawurlencode( $acctid ) . '/' . rawurlencode( $this->credentials->merchant_id ) );
	}

	/**
	 * BIN lookup by CardSecure token.
	 *
	 * @param string $token CardSecure token.
	 */
	public function bin( string $token ): Response {
		return $this->request( 'GET', 'bin/' . rawurlencode( $this->credentials->merchant_id ) . '/' . rawurlencode( $token ) );
	}

	/**
	 * Merchant configuration lookup.
	 */
	public function inquire_merchant(): Response {
		return $this->request( 'GET', 'inquireMerchant/' . rawurlencode( $this->credentials->merchant_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * Performs one HTTP request against the gateway.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Endpoint path relative to /cardconnect/rest/.
	 * @param array  $body   JSON body for write requests (merchid is injected).
	 * @param array  $opts   Options: timeout (int).
	 *
	 * @throws ApiException On transport/HTTP/parse failures.
	 */
	public function request( string $method, string $path, array $body = array(), array $opts = array() ): Response {
		$method = strtoupper( $method );
		$url    = $this->credentials->api_base() . ltrim( $path, '/' );
		$is_get = in_array( $method, array( 'GET', 'DELETE' ), true );

		$timeout = $opts['timeout'] ?? ( $is_get ? self::TIMEOUT_READ : self::TIMEOUT_WRITE );
		/**
		 * Filters the HTTP timeout in seconds for a gateway request.
		 *
		 * @param int    $timeout Seconds.
		 * @param string $path    Endpoint path.
		 * @param string $method  HTTP method.
		 */
		$timeout = (int) apply_filters( 'paradox_cardpointe_http_timeout', $timeout, $path, $method );

		$args = array(
			'method'      => $method,
			'timeout'     => max( 5, $timeout ),
			'redirection' => 0,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'user-agent'  => 'ParadoxCardPointe/' . PARADOX_CARDPOINTE_VERSION . ' WooCommerce/' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) . ' WordPress/' . get_bloginfo( 'version' ),
			'headers'     => array(
				'Authorization' => $this->credentials->auth_header(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( ! $is_get ) {
			$body            = array_merge( array( 'merchid' => $this->credentials->merchant_id ), $body );
			$args['body']    = wp_json_encode( $body );
		}

		$this->logger->debug( 'Request ' . $method . ' ' . $this->redact_url( $url ), $is_get ? array() : array( 'body' => $body ) );

		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$this->logger->error( 'HTTP failure for ' . $path, array( 'error' => $message, 'ms' => $elapsed ) );
			if ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) || false !== strpos( $message, 'cURL error 28' ) ) {
				throw new ApiException( ApiException::TIMEOUT, $message );
			}
			throw new ApiException( ApiException::CONNECTION, $message );
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		$this->logger->debug(
			'Response ' . $status . ' for ' . $path,
			array(
				'ms'   => $elapsed,
				'body' => is_array( $decoded ) ? $decoded : array( 'raw' => substr( $raw_body, 0, 500 ) ),
			)
		);

		if ( 401 === $status || 403 === $status ) {
			throw new ApiException( ApiException::INVALID_CREDENTIALS, __( 'The CardPointe API rejected the username or password.', 'paradox-cardpointe-gateway' ), $status, $decoded );
		}

		if ( 429 === $status ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'x-rate-limit-retry-after-seconds' );
			throw new ApiException(
				ApiException::RATE_LIMITED,
				sprintf(
					/* translators: %d: seconds */
					__( 'The CardPointe API rate limit was reached. Retry after %d seconds.', 'paradox-cardpointe-gateway' ),
					max( 1, $retry_after )
				),
				$status,
				$decoded
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = '';
			if ( is_array( $decoded ) ) {
				$message = (string) ( $decoded['message'] ?? $decoded['errormsg'] ?? $decoded['resptext'] ?? '' );
				if ( '' !== $message && ! empty( $decoded['errorcode'] ) ) {
					$message = $decoded['errorcode'] . ' - ' . $message;
				}
			}
			if ( '' === $message ) {
				$message = wp_remote_retrieve_response_message( $response );
			}
			throw new ApiException(
				ApiException::HTTP_ERROR,
				sprintf(
					/* translators: 1: HTTP status, 2: message */
					__( 'CardPointe API error (HTTP %1$d): %2$s', 'paradox-cardpointe-gateway' ),
					$status,
					$message
				),
				$status,
				$decoded
			);
		}

		if ( ! is_array( $decoded ) ) {
			throw new ApiException( ApiException::INVALID_RESPONSE, __( 'The CardPointe API returned an unreadable response.', 'paradox-cardpointe-gateway' ), $status, $raw_body );
		}

		return Response::from_decoded( $decoded, $status );
	}

	/**
	 * Masks tokens that appear in GET paths (bin lookups) before logging.
	 *
	 * @param string $url URL.
	 */
	private function redact_url( string $url ): string {
		return preg_replace_callback(
			'#/(9\d{14,18})(?=/|$)#',
			static function ( $m ) {
				return '/' . str_repeat( '*', strlen( $m[1] ) - 4 ) . substr( $m[1], -4 );
			},
			$url
		);
	}
}
