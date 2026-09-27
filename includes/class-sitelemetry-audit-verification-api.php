<?php
/**
 * Client of the Sitelemetry ownership verification API (status, challenge, verify).
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three server-to-server calls, authenticated with the same MCP API key the
 * audits use:
 *
 * - GET  {base}/api/v1/verification/status?target=...   read only
 * - POST {base}/api/v1/verification/challenge {target}  the HTTP-file challenge
 * - POST {base}/api/v1/verification/verify    {target}  Sitelemetry fetches the file
 *
 * The requests go through the WordPress HTTP API, so they carry no Origin or
 * Sec-Fetch-Site header (the service refuses browser requests on these routes).
 * The only field sent is the target (this site's address); the service answers
 * with lower_snake codes that the plugin translates.
 *
 * The HTTP layer is injectable: the constructor takes a callable with the
 * signature of wp_remote_request() plus the method, so tests run every path
 * without a network.
 *
 * Results: the decoded JSON object of a successful answer, or a WP_Error with
 * one of these codes:
 * - ERROR_OFF       the service answered like an unknown path (the API is not
 *                   deployed or switched off there);
 * - ERROR_API       the service answered with an error code; data: http,
 *                   code, reason, http_status, retry_after, limit, url;
 * - ERROR_TRANSPORT the request did not complete; data: wp_code.
 */
class Sitelemetry_Audit_Verification_Api {

	const STATUS_PATH    = '/api/v1/verification/status';
	const CHALLENGE_PATH = '/api/v1/verification/challenge';
	const VERIFY_PATH    = '/api/v1/verification/verify';

	const ERROR_OFF       = 'sitelemetry_verification_off';
	const ERROR_API       = 'sitelemetry_verification_api';
	const ERROR_TRANSPORT = 'sitelemetry_verification_transport';

	/**
	 * MCP API key. Never logged or echoed.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Service base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * HTTP layer: function ( string $method, string $url, array $args ): array|WP_Error.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key   MCP API key.
	 * @param string        $base_url  Service base URL.
	 * @param callable|null $transport HTTP layer (default: the WordPress HTTP API).
	 */
	public function __construct( $api_key, $base_url, $transport = null ) {
		$this->api_key   = (string) $api_key;
		$this->base_url  = rtrim( (string) $base_url, '/' );
		$this->transport = is_callable( $transport ) ? $transport : array( __CLASS__, 'wordpress_transport' );
	}

	/**
	 * A client for the stored key and the configured service.
	 *
	 * @param callable|null $transport HTTP layer (default: the WordPress HTTP API).
	 * @return Sitelemetry_Audit_Verification_Api
	 */
	public static function from_settings( $transport = null ) {
		return new self( Sitelemetry_Audit_Settings::api_key(), Sitelemetry_Audit_Settings::base_url(), $transport );
	}

	/**
	 * Whether a key is configured.
	 *
	 * @return bool
	 */
	public function has_key() {
		return '' !== $this->api_key;
	}

	/**
	 * A short fingerprint of the key and the service, so a cached answer about the
	 * API is not reused after the key or the service changed. It is not the key.
	 *
	 * @return string
	 */
	public function fingerprint() {
		return substr( hash( 'sha256', $this->base_url . '|' . $this->api_key ), 0, 16 );
	}

	/**
	 * GET status: the verification state of the target's host. Never creates or
	 * changes anything on the service.
	 *
	 * @param string $target  Target URL.
	 * @param int    $timeout Seconds.
	 * @return array|WP_Error
	 */
	public function status( $target, $timeout = 15 ) {
		$url = $this->base_url . self::STATUS_PATH . '?target=' . rawurlencode( (string) $target );
		return $this->request( 'GET', $url, null, $timeout );
	}

	/**
	 * POST challenge: the pending challenge of the host (created, or renewed when
	 * the verification expired). A fresh verification comes back as verified,
	 * without a challenge: the service never replaces it.
	 *
	 * @param string $target Target URL.
	 * @return array|WP_Error
	 */
	public function challenge( $target ) {
		return $this->request( 'POST', $this->base_url . self::CHALLENGE_PATH, array( 'target' => (string) $target ), 20 );
	}

	/**
	 * POST verify: Sitelemetry fetches the stored challenge URL of the host and
	 * compares it with the token. It takes up to about ten seconds.
	 *
	 * @param string $target Target URL.
	 * @return array|WP_Error
	 */
	public function verify( $target ) {
		return $this->request( 'POST', $this->base_url . self::VERIFY_PATH, array( 'target' => (string) $target ), 30 );
	}

	/**
	 * One request.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $url     URL.
	 * @param array|null $body    JSON body for POST.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error
	 */
	private function request( $method, $url, $body, $timeout ) {
		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
		);
		$args    = array(
			'timeout'             => (int) $timeout,
			// A redirect would carry the key to wherever it points; the API never redirects.
			'redirection'         => 0,
			'limit_response_size' => 65536,
			'user-agent'          => Sitelemetry_Audit_Client::user_agent(),
			'headers'             => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		return self::interpret( call_user_func( $this->transport, $method, $url, $args ) );
	}

	/**
	 * The WordPress HTTP API.
	 *
	 * @param string $method GET or POST.
	 * @param string $url    URL.
	 * @param array  $args   Request arguments.
	 * @return array|WP_Error
	 */
	public static function wordpress_transport( $method, $url, $args ) {
		return 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );
	}

	/**
	 * Turns an HTTP response into the decoded answer or a WP_Error (pure).
	 *
	 * The service always answers these routes with a JSON object that has either
	 * "ok": true or a string "code". Anything else (the HTML 404 page, a bare 405,
	 * a page without JSON) means the routes are not there: the API is switched off
	 * or not deployed on that service.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	public static function interpret( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::ERROR_TRANSPORT, $response->get_error_message(), array( 'wp_code' => $response->get_error_code() ) );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$object  = is_array( $decoded ) && ( array() === $decoded || array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) );
		if ( $object && $status >= 200 && $status < 300 && isset( $decoded['ok'] ) && true === $decoded['ok'] ) {
			return $decoded;
		}
		if ( $object && isset( $decoded['code'] ) && is_string( $decoded['code'] ) && '' !== $decoded['code'] ) {
			$retry = isset( $decoded['retryAfter'] ) && is_numeric( $decoded['retryAfter'] ) ? (int) $decoded['retryAfter'] : null;
			if ( null === $retry ) {
				$header = wp_remote_retrieve_header( $response, 'retry-after' );
				$header = is_array( $header ) ? reset( $header ) : $header;
				$retry  = is_numeric( $header ) ? (int) $header : null;
			}
			return new WP_Error(
				self::ERROR_API,
				isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? Sitelemetry_Audit_Outcome::clip( $decoded['error'], 300 ) : '',
				array(
					'http'        => $status,
					'code'        => strtolower( substr( preg_replace( '/[^A-Za-z0-9_]/', '_', $decoded['code'] ), 0, 64 ) ),
					'reason'      => isset( $decoded['reason'] ) && is_string( $decoded['reason'] ) ? sanitize_key( $decoded['reason'] ) : '',
					'http_status' => isset( $decoded['httpStatus'] ) && is_numeric( $decoded['httpStatus'] ) ? (int) $decoded['httpStatus'] : null,
					'retry_after' => null === $retry ? null : max( 0, $retry ),
					'limit'       => isset( $decoded['limit'] ) && is_numeric( $decoded['limit'] ) ? (int) $decoded['limit'] : null,
					'url'         => isset( $decoded['url'] ) && is_string( $decoded['url'] ) ? Sitelemetry_Audit_Outcome::clip( $decoded['url'], 300 ) : '',
				)
			);
		}
		if ( ( $status >= 200 && $status < 300 ) || 404 === $status || 405 === $status ) {
			return new WP_Error( self::ERROR_OFF, 'The verification API is not available on this service.' );
		}
		// A server or proxy error without the service's JSON envelope.
		return new WP_Error(
			self::ERROR_API,
			sprintf( 'HTTP %d', $status ),
			array(
				'http'        => $status,
				'code'        => 'http_' . $status,
				'reason'      => '',
				'http_status' => null,
				'retry_after' => null,
				'limit'       => null,
				'url'         => '',
			)
		);
	}

	/**
	 * The data of an ERROR_API error, with every field present.
	 *
	 * @param WP_Error $error Error.
	 * @return array { http: int, code: string, reason: string, http_status: int|null, retry_after: int|null, limit: int|null, url: string }
	 */
	public static function error_data( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;
		$data = is_array( $data ) ? $data : array();
		return array(
			'http'        => isset( $data['http'] ) ? (int) $data['http'] : 0,
			'code'        => isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '',
			'reason'      => isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : '',
			'http_status' => isset( $data['http_status'] ) ? (int) $data['http_status'] : null,
			'retry_after' => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : null,
			'limit'       => isset( $data['limit'] ) ? (int) $data['limit'] : null,
			'url'         => isset( $data['url'] ) && is_string( $data['url'] ) ? $data['url'] : '',
		);
	}
}
