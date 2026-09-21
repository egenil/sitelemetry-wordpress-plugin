<?php
/**
 * Test bootstrap.
 *
 * Provides the handful of WordPress functions the pure-PHP classes call (options,
 * transients, translation, escaping, HTTP API, cron) as in-memory stand-ins, an
 * in-process mock of the hosted service with the same behaviour as the reference
 * mock server, and a PHPUnit TestCase shim when PHPUnit is not installed.
 *
 * Run with PHPUnit:  phpunit --bootstrap tests/bootstrap.php tests
 * Run without:       php tests/run.php
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SITELEMETRY_AUDIT_VERSION' ) ) {
	define( 'SITELEMETRY_AUDIT_VERSION', '0.1.1' );
}
if ( ! defined( 'SITELEMETRY_AUDIT_DIR' ) ) {
	define( 'SITELEMETRY_AUDIT_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SITELEMETRY_AUDIT_FILE' ) ) {
	define( 'SITELEMETRY_AUDIT_FILE', dirname( __DIR__ ) . '/sitelemetry-audit.php' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

$GLOBALS['sitelemetry_test_options']         = array();
$GLOBALS['sitelemetry_test_transients']      = array();
$GLOBALS['sitelemetry_test_actions']         = array();
$GLOBALS['sitelemetry_test_cron']            = array();
$GLOBALS['sitelemetry_test_settings_errors'] = array();
$GLOBALS['sitelemetry_test_http']            = null;

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/**
		 * Errors.
		 *
		 * @var array
		 */
		public $errors = array();
		/**
		 * Data.
		 *
		 * @var array
		 */
		public $error_data = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		/**
		 * First code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return isset( $codes[0] ) ? $codes[0] : '';
		}

		/**
		 * First message.
		 *
		 * @param string $code Code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		/**
		 * Data of a code.
		 *
		 * @param string $code Code.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Whether a value is a WP_Error.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stand-in.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	/**
	 * Plural stand-in.
	 *
	 * @param string $single Single.
	 * @param string $plural Plural.
	 * @param int    $number Number.
	 * @param string $domain Domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape stand-in.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape stand-in.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape stand-in.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Escape stand-in.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON stand-in.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags stand-in.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitize stand-in.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize stand-in.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Merge stand-in.
	 *
	 * @param array $args     Args.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * parse_url stand-in.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Escape stand-in.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Site URL stand-in.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stand-in: returns the value.
	 *
	 * @param string $hook_name Hook.
	 * @param mixed  $value     Value.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Action stand-in: records the call.
	 *
	 * @param string $hook_name Hook.
	 * @return void
	 */
	function do_action( $hook_name ) {
		$GLOBALS['sitelemetry_test_actions'][] = func_get_args();
	}
}
if ( ! function_exists( 'add_action' ) ) {
	/**
	 * No-op.
	 *
	 * @return void
	 */
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * No-op.
	 *
	 * @return void
	 */
	function add_filter() {}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option stand-in.
	 *
	 * @param string $name          Name.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['sitelemetry_test_options'] ) ? $GLOBALS['sitelemetry_test_options'][ $name ] : $default_value;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Option stand-in.
	 *
	 * @param string $name       Name.
	 * @param mixed  $value      Value.
	 * @param string $deprecated Deprecated.
	 * @param string $autoload   Autoload.
	 * @return bool
	 */
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['sitelemetry_test_options'] ) ) {
			return false;
		}
		$GLOBALS['sitelemetry_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Option stand-in.
	 *
	 * @param string $name     Name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['sitelemetry_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Option stand-in.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_option( $name ) {
		unset( $GLOBALS['sitelemetry_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Transient stand-in.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	function get_transient( $name ) {
		if ( ! isset( $GLOBALS['sitelemetry_test_transients'][ $name ] ) ) {
			return false;
		}
		$row = $GLOBALS['sitelemetry_test_transients'][ $name ];
		if ( $row['expires'] > 0 && $row['expires'] < time() ) {
			unset( $GLOBALS['sitelemetry_test_transients'][ $name ] );
			return false;
		}
		return $row['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Transient stand-in.
	 *
	 * @param string $name       Name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Seconds.
	 * @return bool
	 */
	function set_transient( $name, $value, $expiration = 0 ) {
		$GLOBALS['sitelemetry_test_transients'][ $name ] = array(
			'value'   => $value,
			'expires' => $expiration > 0 ? time() + (int) $expiration : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Transient stand-in.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( $GLOBALS['sitelemetry_test_transients'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Records a settings error.
	 *
	 * @return void
	 */
	function add_settings_error() {
		$GLOBALS['sitelemetry_test_settings_errors'][] = func_get_args();
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Blog info stand-in.
	 *
	 * @param string $show Field.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '6.8' : '';
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * HTTP stand-in: dispatches to the test handler.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|WP_Error
	 */
	function wp_remote_post( $url, $args = array() ) {
		return sitelemetry_test_http( 'POST', $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * HTTP stand-in: dispatches to the test handler.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|WP_Error
	 */
	function wp_remote_get( $url, $args = array() ) {
		return sitelemetry_test_http( 'GET', $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Response code.
	 *
	 * @param mixed $response Response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Response body.
	 *
	 * @param mixed $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Response header (case-insensitive).
	 *
	 * @param mixed  $response Response.
	 * @param string $header   Header.
	 * @return string
	 */
	function wp_remote_retrieve_header( $response, $header ) {
		if ( ! is_array( $response ) || empty( $response['headers'] ) ) {
			return '';
		}
		foreach ( $response['headers'] as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $header ) ) {
				return $value;
			}
		}
		return '';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Cron stand-in.
	 *
	 * @param string $hook Hook.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook ) {
		return isset( $GLOBALS['sitelemetry_test_cron'][ $hook ] ) ? $GLOBALS['sitelemetry_test_cron'][ $hook ] : false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Cron stand-in.
	 *
	 * @param int    $timestamp When.
	 * @param string $hook      Hook.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['sitelemetry_test_cron'][ $hook ] = (int) $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Cron stand-in.
	 *
	 * @param int    $timestamp  When.
	 * @param string $recurrence Recurrence.
	 * @param string $hook       Hook.
	 * @return bool
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['sitelemetry_test_cron'][ $hook ] = (int) $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Cron stand-in.
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	function wp_clear_scheduled_hook( $hook ) {
		$had = isset( $GLOBALS['sitelemetry_test_cron'][ $hook ] ) ? 1 : 0;
		unset( $GLOBALS['sitelemetry_test_cron'][ $hook ] );
		return $had;
	}
}

/**
 * Dispatches an HTTP request to the test handler.
 *
 * @param string $method Method.
 * @param string $url    URL.
 * @param array  $args   Args.
 * @return array|WP_Error
 */
function sitelemetry_test_http( $method, $url, $args ) {
	$handler = $GLOBALS['sitelemetry_test_http'];
	if ( ! is_callable( $handler ) ) {
		return new WP_Error( 'http_request_failed', 'No HTTP handler is installed in this test.' );
	}
	return call_user_func( $handler, $method, $url, $args );
}

/**
 * Resets every in-memory store.
 *
 * @return void
 */
function sitelemetry_test_reset() {
	$GLOBALS['sitelemetry_test_options']         = array();
	$GLOBALS['sitelemetry_test_transients']      = array();
	$GLOBALS['sitelemetry_test_actions']         = array();
	$GLOBALS['sitelemetry_test_cron']            = array();
	$GLOBALS['sitelemetry_test_settings_errors'] = array();
	$GLOBALS['sitelemetry_test_http']            = null;
}

/**
 * Decoded fixture.
 *
 * @param string $name File name under tests/fixtures.
 * @return array
 */
function sitelemetry_test_fixture( $name ) {
	return json_decode( file_get_contents( __DIR__ . '/fixtures/' . $name ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * A WordPress-shaped HTTP response.
 *
 * @param int    $code    Status.
 * @param string $body    Body.
 * @param array  $headers Headers.
 * @return array
 */
function sitelemetry_test_response( $code, $body, $headers = array() ) {
	return array(
		'response' => array(
			'code'    => (int) $code,
			'message' => '',
		),
		'headers'  => $headers,
		'body'     => $body,
	);
}

/**
 * In-process stand-in for the hosted service: the MCP endpoint and /api/plans.
 * Behaviour per fixture host follows the reference mock server.
 */
class Sitelemetry_Test_Mock_Service {

	const API_KEY = 'sl_test_key_123456';

	/**
	 * Accepted key.
	 *
	 * @var string
	 */
	public $api_key = self::API_KEY;

	/**
	 * Number of poll calls that answer "running" before the final result.
	 *
	 * @var int
	 */
	public $running_polls = 2;

	/**
	 * When true, the next request that carries a session id is answered with the
	 * MCP "unknown session" 404 (once), as a server that expired the session would.
	 *
	 * @var bool
	 */
	public $reject_session = false;

	/**
	 * Recorded requests: method, url, headers, body (decoded).
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Jobs by id.
	 *
	 * @var array
	 */
	private $jobs = array();

	/**
	 * busy.example counter.
	 *
	 * @var int
	 */
	private $busy_calls = 0;

	/**
	 * Installs this service as the HTTP handler.
	 *
	 * @return void
	 */
	public function install() {
		$GLOBALS['sitelemetry_test_http'] = array( $this, 'handle' );
	}

	/**
	 * The recorded tools/call messages.
	 *
	 * @return array
	 */
	public function tool_calls() {
		return array_values(
			array_filter(
				$this->calls,
				function ( $call ) {
					return is_array( $call['body'] ) && isset( $call['body']['method'] ) && 'tools/call' === $call['body']['method'];
				}
			)
		);
	}

	/**
	 * Handles one request.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array
	 */
	public function handle( $method, $url, $args ) {
		$path          = parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$body          = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		$this->calls[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'body'    => $body,
		);
		if ( '/api/plans' === $path ) {
			return self::json( 200, sitelemetry_test_fixture( 'plans.json' ) );
		}
		if ( '/mcp' === $path ) {
			return $this->mcp( $args, $body );
		}
		return self::json( 404, array( 'error' => 'not found' ) );
	}

	/**
	 * MCP endpoint.
	 *
	 * @param array      $args    Request args.
	 * @param array|null $message Decoded JSON-RPC message.
	 * @return array
	 */
	private function mcp( $args, $message ) {
		$auth = self::header( $args, 'authorization' );
		if ( 'Bearer ' . $this->api_key !== $auth ) {
			return self::json( 401, array( 'error' => 'Unauthorized. Connect with Sitelemetry OAuth or provide a Sitelemetry MCP API key as a Bearer token.' ) );
		}
		if ( $this->reject_session && '' !== self::header( $args, 'mcp-session-id' ) ) {
			$this->reject_session = false;
			return self::json(
				404,
				array(
					'error' => 'Session not found.',
					'code'  => 'SESSION_NOT_FOUND',
				)
			);
		}
		if ( ! is_array( $message ) ) {
			return self::json( 400, array( 'error' => array( 'code' => -32700, 'message' => 'Parse error' ) ) );
		}
		if ( ! isset( $message['id'] ) ) {
			return sitelemetry_test_response( 202, '' );
		}
		switch ( isset( $message['method'] ) ? $message['method'] : '' ) {
			case 'initialize':
				return $this->reply(
					$message['id'],
					array(
						'protocolVersion' => '2025-06-18',
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'mock-sitelemetry',
							'version' => '0.0.0',
						),
					),
					array( 'mcp-session-id' => 'sess-test-1' )
				);
			case 'tools/call':
				return $this->call( $message );
			default:
				return $this->rpc_error( $message['id'], -32601, 'Method not found' );
		}
	}

	/**
	 * tools/call.
	 *
	 * @param array $message Message.
	 * @return array
	 */
	private function call( $message ) {
		$id   = $message['id'];
		$name = isset( $message['params']['name'] ) ? $message['params']['name'] : '';
		$args = isset( $message['params']['arguments'] ) && is_array( $message['params']['arguments'] ) ? $message['params']['arguments'] : array();

		if ( array_key_exists( 'jobId', $args ) ) {
			$job_id = $args['jobId'];
			if ( ! isset( $this->jobs[ $job_id ] ) || $this->jobs[ $job_id ]['tool'] !== $name ) {
				return $this->reply( $id, self::action_required( 'audit_job_argument_mismatch', 'The audit job arguments do not match. Call the same tool with the returned pollArguments unchanged.' ) );
			}
			++$this->jobs[ $job_id ]['polls'];
			$job = $this->jobs[ $job_id ];
			return $this->reply( $id, $job['polls'] < $this->running_polls ? self::running( $job ) : $job['final'] );
		}

		$target = isset( $args['target'] ) ? (string) $args['target'] : '';
		$host   = parse_url( $target, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$host   = $host ? $host : $target;
		$completed = sitelemetry_test_fixture( 'security-completed.json' );
		switch ( $host ) {
			case 'ok.example':
				$job_id = 'mj_' . str_pad( (string) count( $this->jobs ), 32, '0', STR_PAD_LEFT );
				$job    = array(
					'id'            => $job_id,
					'tool'          => $name,
					'polls'         => 0,
					'final'         => $completed,
					'pollArguments' => array(
						'target' => 'https://ok.example/',
						'jobId'  => $job_id,
					),
				);
				$this->jobs[ $job_id ] = $job;
				return $this->reply( $id, self::running( $job ) );
			case 'sync.example':
				return $this->reply( $id, $completed );
			case 'sse.example':
				$payload = json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $completed ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				return sitelemetry_test_response( 200, ": keep-alive\n\nevent: message\ndata: " . $payload . "\n\n", array( 'content-type' => 'text/event-stream' ) );
			case 'free.example':
				return $this->reply( $id, sitelemetry_test_fixture( 'security-partial-free.json' ) );
			case 'full.example':
				return $this->reply( $id, sitelemetry_test_fixture( 'full-partial.json' ) );
			case 'plan.example':
				return self::json(
					402,
					array(
						'error'   => 'This feature is not included in Free. Upgrade your plan to continue.',
						'code'    => 'PLAN_UPGRADE_REQUIRED',
						'feature' => 'seo',
					)
				);
			case 'plan-result.example':
				return $this->reply( $id, self::action_required( 'entitlement_required', 'Audit not started. No audit quota was used. The requested audit requires Starter or higher access and is not included in the connected Free account.' ) );
			case 'quota.example':
				return $this->reply( $id, self::action_required( 'usage_limit_reached', 'Audit not started. No audit quota was used. The current audit allowance is exhausted. Retry after the allowance resets.' ) );
			case 'quota-rpc.example':
				return $this->rpc_error( $id, -32603, 'Monthly security scan limit reached for this account (monthly allowance: 10).' );
			case 'verify.example':
				return $this->reply( $id, self::action_required( 'target_verification_required', 'Audit not started. No audit quota was used. Verify ownership of this target in Sitelemetry or Google Search Console before retrying. No plan change is required.' ) );
			case 'consent.example':
				return $this->reply( $id, self::action_required( 'authorization_consent_required', 'Audit not started. No audit quota was used. Review and accept the current audit authorization terms in your account before retrying.' ) );
			case 'busy.example':
				if ( 0 === $this->busy_calls++ ) {
					return self::json( 429, array( 'error' => 'Another audit is already running for this account. Please retry shortly.' ), array( 'retry-after' => '0' ) );
				}
				return $this->reply( $id, $completed );
			case 'error.example':
				return $this->reply(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Error: audit failed',
							),
						),
						'isError' => true,
					)
				);
			default:
				return $this->reply(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Error: unknown fixture host ' . $host,
							),
						),
						'isError' => true,
					)
				);
		}
	}

	/**
	 * One request header, matched without case.
	 *
	 * @param array  $args Request args.
	 * @param string $name Header name.
	 * @return string
	 */
	private static function header( $args, $name ) {
		foreach ( isset( $args['headers'] ) ? $args['headers'] : array() as $key => $value ) {
			if ( strtolower( $name ) === strtolower( $key ) ) {
				return is_string( $value ) ? $value : '';
			}
		}
		return '';
	}

	/**
	 * JSON response.
	 *
	 * @param int   $code    Status.
	 * @param mixed $payload Payload.
	 * @param array $headers Extra headers.
	 * @return array
	 */
	public static function json( $code, $payload, $headers = array() ) {
		return sitelemetry_test_response(
			$code,
			json_encode( $payload ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array_merge( array( 'content-type' => 'application/json; charset=utf-8' ), $headers )
		);
	}

	/**
	 * JSON-RPC result.
	 *
	 * @param mixed $id      Id.
	 * @param mixed $result  Result.
	 * @param array $headers Headers.
	 * @return array
	 */
	private function reply( $id, $result, $headers = array() ) {
		return self::json(
			200,
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			$headers
		);
	}

	/**
	 * JSON-RPC error.
	 *
	 * @param mixed  $id      Id.
	 * @param int    $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private function rpc_error( $id, $code, $message ) {
		return self::json(
			200,
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			)
		);
	}

	/**
	 * action_required result.
	 *
	 * @param string $reason Reason.
	 * @param string $text   Text.
	 * @return array
	 */
	public static function action_required( $reason, $text ) {
		return array(
			'isError'           => false,
			'content'           => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'structuredContent' => array(
				'status'         => 'action_required',
				'reason'         => $reason,
				'auditExecuted'  => false,
				'usageConsumed'  => false,
			),
		);
	}

	/**
	 * running result.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function running( $job ) {
		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => "The audit is still running. Call the same tool with the returned pollArguments unchanged.\n\npollArguments:\n" . json_encode( $job['pollArguments'] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				),
			),
			'structuredContent' => array(
				'status'            => 'running',
				'jobId'             => $job['id'],
				'executionComplete' => false,
				'nextAction'        => 'poll_same_tool',
				'pollArguments'     => $job['pollArguments'],
				'retryAfterMs'      => 10,
			),
		);
	}
}

require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-labels.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-settings.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-client.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-outcome.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-plans.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-links.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-results.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-runner.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-cron.php';

if ( ! class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	require_once __DIR__ . '/phpunit-shim.php';
}
