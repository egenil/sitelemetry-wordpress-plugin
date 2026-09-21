<?php
/**
 * Minimal MCP client (JSON-RPC 2.0 over Streamable HTTP) on top of the WordPress HTTP API.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to {base}/mcp with the account's MCP API key as a Bearer token.
 *
 * Errors are WP_Error objects with one of these codes:
 * - sitelemetry_http      HTTP status outside 2xx; data: status, code (server error code or null), retry_after_ms.
 * - sitelemetry_rpc       JSON-RPC error object; data: code, data.
 * - sitelemetry_transport the request did not complete (DNS, TLS, timeout, non-JSON body); data: wp_code.
 * - sitelemetry_empty     the server answered 2xx without a JSON-RPC response.
 */
class Sitelemetry_Audit_Client {

	const PROTOCOL_VERSION = '2025-06-18';
	const CLIENT_NAME      = 'sitelemetry-audit-wordpress';

	/**
	 * Absolute MCP endpoint.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * MCP API key. Never logged or echoed.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * HTTP timeout per request, in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Next JSON-RPC id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Session id announced by the server, if any.
	 *
	 * @var string
	 */
	private $session_id = '';

	/**
	 * Protocol version negotiated with the server.
	 *
	 * @var string
	 */
	private $protocol_version = self::PROTOCOL_VERSION;

	/**
	 * Whether initialize() succeeded in this PHP request.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Whether the session was restored from a previous PHP request instead of
	 * negotiated here (then a rejected session is worth one fresh handshake).
	 *
	 * @var bool
	 */
	private $resumed = false;

	/**
	 * Constructor.
	 *
	 * @param string $api_key  MCP API key.
	 * @param string $base_url Service base URL (no trailing slash needed).
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct( $api_key, $base_url, $timeout = 60 ) {
		$this->api_key  = (string) $api_key;
		$this->endpoint = rtrim( (string) $base_url, '/' ) . '/mcp';
		$this->timeout  = max( 10, (int) $timeout );
	}

	/**
	 * The MCP endpoint.
	 *
	 * @return string
	 */
	public function endpoint() {
		return $this->endpoint;
	}

	/**
	 * User agent sent with every request: the client name and the plugin version,
	 * nothing about the sending site. The Privacy section of readme.txt states
	 * exactly this string; keep the two in sync.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return self::CLIENT_NAME . '/' . SITELEMETRY_AUDIT_VERSION;
	}

	/**
	 * Session id negotiated with (or restored for) the server; '' when there is none.
	 *
	 * @return string
	 */
	public function session_id() {
		return $this->session_id;
	}

	/**
	 * Protocol version in use.
	 *
	 * @return string
	 */
	public function protocol_version() {
		return $this->protocol_version;
	}

	/**
	 * Restores the session of an earlier PHP request, so a poll is one request
	 * instead of a new handshake. A session the server no longer knows is
	 * replaced by a fresh handshake in call_tool().
	 *
	 * @param string $session_id       Session id announced by the server.
	 * @param string $protocol_version Protocol version negotiated then.
	 * @return void
	 */
	public function resume_session( $session_id, $protocol_version = '' ) {
		$session_id = is_string( $session_id ) ? trim( $session_id ) : '';
		if ( '' === $session_id ) {
			return;
		}
		$this->session_id = $session_id;
		if ( is_string( $protocol_version ) && '' !== $protocol_version ) {
			$this->protocol_version = $protocol_version;
		}
		$this->initialized = true;
		$this->resumed     = true;
	}

	/**
	 * Forgets the session and the handshake, so the next call initializes again.
	 *
	 * @return void
	 */
	private function reset_session() {
		$this->session_id       = '';
		$this->protocol_version = self::PROTOCOL_VERSION;
		$this->initialized      = false;
		$this->resumed          = false;
	}

	/**
	 * MCP handshake: initialize, then the initialized notification.
	 *
	 * @return array|WP_Error Server result.
	 */
	public function initialize() {
		$result = $this->rpc(
			'initialize',
			array(
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => new stdClass(),
				'clientInfo'      => array(
					'name'    => self::CLIENT_NAME,
					'version' => SITELEMETRY_AUDIT_VERSION,
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_array( $result ) && isset( $result['protocolVersion'] ) && is_string( $result['protocolVersion'] ) ) {
			$this->protocol_version = $result['protocolVersion'];
		}
		$this->initialized = true;
		// A notification has no id; the outcome is irrelevant.
		$this->post(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);
		return is_array( $result ) ? $result : array();
	}

	/**
	 * tools/call. Initializes first when needed.
	 *
	 * @param string $name      Tool name, for example audit_security.
	 * @param array  $arguments Tool arguments (target, or the server's pollArguments unchanged).
	 * @return array|WP_Error The tool result (content, structuredContent, isError).
	 */
	public function call_tool( $name, array $arguments ) {
		if ( ! $this->initialized ) {
			$init = $this->initialize();
			if ( is_wp_error( $init ) ) {
				return $init;
			}
		}
		$params = array(
			'name'      => (string) $name,
			'arguments' => empty( $arguments ) ? new stdClass() : $arguments,
		);
		$result = $this->rpc( 'tools/call', $params );
		if ( $this->resumed && self::is_session_error( $result ) ) {
			// The restored session is gone (MCP: 404 for an unknown session).
			// Start a new one and send the same call once more.
			$this->reset_session();
			$init = $this->initialize();
			if ( is_wp_error( $init ) ) {
				return $init;
			}
			$result = $this->rpc( 'tools/call', $params );
		}
		return $result;
	}

	/**
	 * Whether an error says the session is unknown to the server.
	 *
	 * @param mixed $error Result of a call.
	 * @return bool
	 */
	public static function is_session_error( $error ) {
		if ( ! is_wp_error( $error ) || 'sitelemetry_http' !== $error->get_error_code() ) {
			return false;
		}
		$status = self::error_status( $error );
		if ( 404 === $status ) {
			return true;
		}
		$code = self::error_server_code( $error );
		return 400 === $status && is_string( $code ) && false !== stripos( $code, 'session' );
	}

	/**
	 * One JSON-RPC request.
	 *
	 * @param string     $method Method name.
	 * @param array|null $params Params.
	 * @return array|WP_Error
	 */
	private function rpc( $method, $params = null ) {
		$id      = $this->next_id++;
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => $method,
		);
		if ( null !== $params ) {
			$message['params'] = $params;
		}
		$response = $this->post( $message );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( null === $response ) {
			return new WP_Error( 'sitelemetry_empty', sprintf( 'Empty response for %s.', $method ) );
		}
		if ( array_key_exists( 'error', $response ) ) {
			return self::rpc_error( $response['error'] );
		}
		return isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
	}

	/**
	 * POSTs one message and returns the matching JSON-RPC response, null for
	 * an empty or 202 body, or a WP_Error.
	 *
	 * @param array $message JSON-RPC message.
	 * @return array|null|WP_Error
	 */
	private function post( array $message ) {
		$headers = array(
			'Content-Type'         => 'application/json',
			'Accept'               => 'application/json, text/event-stream',
			'Authorization'        => 'Bearer ' . $this->api_key,
			'MCP-Protocol-Version' => $this->protocol_version,
		);
		if ( '' !== $this->session_id ) {
			$headers['Mcp-Session-Id'] = $this->session_id;
		}
		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout'    => $this->timeout,
				'headers'    => $headers,
				'body'       => wp_json_encode( $message ),
				'user-agent' => self::user_agent(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'sitelemetry_transport', $response->get_error_message(), array( 'wp_code' => $response->get_error_code() ) );
		}
		$session = self::header_string( wp_remote_retrieve_header( $response, 'mcp-session-id' ) );
		if ( '' !== $session ) {
			$this->session_id = $session;
		}
		return self::interpret_http(
			(int) wp_remote_retrieve_response_code( $response ),
			self::header_string( wp_remote_retrieve_header( $response, 'content-type' ) ),
			(string) wp_remote_retrieve_body( $response ),
			self::header_string( wp_remote_retrieve_header( $response, 'retry-after' ) ),
			isset( $message['id'] ) ? $message['id'] : null
		);
	}

	/**
	 * First value of a header that may arrive as a string or a list.
	 *
	 * @param mixed $value Header value.
	 * @return string
	 */
	private static function header_string( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Turns an HTTP status, content type and body into a JSON-RPC response (pure, testable).
	 *
	 * @param int      $status       HTTP status.
	 * @param string   $content_type Content-Type header.
	 * @param string   $body         Response body.
	 * @param string   $retry_after  Retry-After header ('' when absent).
	 * @param int|null $id           Request id to select in batches and streams.
	 * @return array|null|WP_Error
	 */
	public static function interpret_http( $status, $content_type, $body, $retry_after, $id ) {
		$status = (int) $status;
		if ( $status < 200 || $status >= 300 ) {
			return self::http_error( $status, $body, $retry_after );
		}
		if ( 202 === $status || '' === trim( (string) $body ) ) {
			return null;
		}
		if ( false !== stripos( (string) $content_type, 'text/event-stream' ) ) {
			$messages = self::parse_sse( $body );
		} else {
			$decoded = json_decode( (string) $body, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'sitelemetry_transport', 'Sitelemetry returned a response that is not JSON.', array( 'wp_code' => 'invalid_json' ) );
			}
			$messages = array( $decoded );
		}
		return self::select_response( $messages, $id );
	}

	/**
	 * Builds the WP_Error for a non-2xx response.
	 *
	 * @param int    $status      HTTP status.
	 * @param string $body        Body text.
	 * @param string $retry_after Retry-After header.
	 * @return WP_Error
	 */
	public static function http_error( $status, $body, $retry_after ) {
		$payload = json_decode( (string) $body, true );
		$payload = is_array( $payload ) ? $payload : array();
		$message = '';
		if ( isset( $payload['error'] ) && is_string( $payload['error'] ) ) {
			$message = $payload['error'];
		} elseif ( isset( $payload['error']['message'] ) && is_string( $payload['error']['message'] ) ) {
			$message = $payload['error']['message'];
		} elseif ( isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
			$message = $payload['message'];
		}
		if ( '' === $message ) {
			$text    = trim( wp_strip_all_tags( (string) $body ) );
			$message = '' !== $text ? substr( $text, 0, 300 ) : sprintf( 'HTTP %d', (int) $status );
		}
		$code = null;
		if ( isset( $payload['code'] ) && is_string( $payload['code'] ) ) {
			$code = $payload['code'];
		} elseif ( isset( $payload['error']['code'] ) && is_string( $payload['error']['code'] ) ) {
			$code = $payload['error']['code'];
		}
		return new WP_Error(
			'sitelemetry_http',
			$message,
			array(
				'status'         => (int) $status,
				'code'           => $code,
				'retry_after_ms' => self::parse_retry_after( $retry_after ),
			)
		);
	}

	/**
	 * Builds the WP_Error for a JSON-RPC error object.
	 *
	 * @param mixed $error The "error" member.
	 * @return WP_Error
	 */
	public static function rpc_error( $error ) {
		$error   = is_array( $error ) ? $error : array();
		$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : 'JSON-RPC error';
		return new WP_Error(
			'sitelemetry_rpc',
			$message,
			array(
				'code' => isset( $error['code'] ) ? $error['code'] : null,
				'data' => isset( $error['data'] ) ? $error['data'] : null,
			)
		);
	}

	/**
	 * Retry-After header (seconds or HTTP date) to milliseconds.
	 *
	 * @param mixed $raw Header value.
	 * @return int|null
	 */
	public static function parse_retry_after( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$raw = trim( (string) $raw );
		if ( is_numeric( $raw ) ) {
			return max( 0, (int) round( (float) $raw * 1000 ) );
		}
		$at = strtotime( $raw );
		return false === $at ? null : max( 0, ( $at - time() ) * 1000 );
	}

	/**
	 * Parses a text/event-stream body into the JSON payloads of its data lines.
	 *
	 * @param string $text Body.
	 * @return array
	 */
	public static function parse_sse( $text ) {
		$messages = array();
		foreach ( preg_split( '/\r?\n\r?\n/', (string) $text ) as $block ) {
			$data = array();
			foreach ( preg_split( '/\r?\n/', $block ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data[] = preg_replace( '/^ /', '', substr( $line, 5 ) );
				}
			}
			$joined = implode( "\n", $data );
			if ( '' === trim( $joined ) ) {
				continue;
			}
			$decoded = json_decode( $joined, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$messages[] = $decoded;
			}
		}
		return $messages;
	}

	/**
	 * Picks the JSON-RPC response for a request id (batches and streams may carry several).
	 *
	 * @param array    $messages Decoded messages (objects or batches).
	 * @param int|null $id       Request id.
	 * @return array|null
	 */
	public static function select_response( array $messages, $id ) {
		$flat = array();
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && self::is_list( $message ) ) {
				foreach ( $message as $inner ) {
					$flat[] = $inner;
				}
			} else {
				$flat[] = $message;
			}
		}
		$flat = array_values( array_filter( $flat, 'is_array' ) );
		foreach ( $flat as $message ) {
			if ( isset( $message['id'] ) && null !== $id && (string) $message['id'] === (string) $id ) {
				return $message;
			}
		}
		$last = null;
		foreach ( $flat as $message ) {
			if ( array_key_exists( 'result', $message ) || array_key_exists( 'error', $message ) ) {
				$last = $message;
			}
		}
		return $last;
	}

	/**
	 * Whether an array is a JSON list (a JSON-RPC batch) rather than an object.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		if ( array() === $value ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether an error is worth an automatic retry (network failures, 5xx, 408).
	 *
	 * @param mixed $error WP_Error.
	 * @return bool
	 */
	public static function is_transient_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$code = $error->get_error_code();
		if ( 'sitelemetry_rpc' === $code ) {
			return false;
		}
		if ( 'sitelemetry_http' === $code ) {
			$status = self::error_status( $error );
			return $status >= 500 || 408 === $status;
		}
		return true;
	}

	/**
	 * HTTP status carried by a sitelemetry_http error (0 otherwise).
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	public static function error_status( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Server error code string carried by a sitelemetry_http error (null otherwise).
	 *
	 * @param WP_Error $error Error.
	 * @return string|null
	 */
	public static function error_server_code( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : null;
	}

	/**
	 * Retry-After in milliseconds carried by a sitelemetry_http error (null otherwise).
	 *
	 * @param WP_Error $error Error.
	 * @return int|null
	 */
	public static function error_retry_after_ms( $error ) {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;
		return is_array( $data ) && isset( $data['retry_after_ms'] ) && null !== $data['retry_after_ms'] ? (int) $data['retry_after_ms'] : null;
	}
}
