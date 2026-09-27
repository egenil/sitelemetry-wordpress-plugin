<?php
/**
 * Site ownership verification helper: serves the Sitelemetry HTTP verification file.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sitelemetry proves ownership of a host by fetching
 * https://<host>/.well-known/sitelemetry-verification.txt and comparing the body
 * with a token it generated for the connected account. The plugin answers exactly
 * that path, for this site's own host only, with the token as plain text. Nothing
 * else is ever served from /.well-known/, and without a stored token the path
 * answers 404.
 *
 * The token comes from one of two places:
 *
 * - One click (request_one_click()): the plugin asks the service's verification
 *   API for the challenge of this site's host with the stored API key, checks
 *   that the challenge is for exactly this host and path, stores and serves the
 *   token, tests the file and asks the service to verify it. The same flow renews
 *   an expired verification in WP-Cron (renew()); a fresh verification is never
 *   replaced. When the service answers like an unknown path (the API is switched
 *   off or not deployed), one_click_state() is "off" and the panel keeps the
 *   manual helper only.
 * - By hand: the admin copies the token from the Verified Domains section of the
 *   Sitelemetry app and pastes it (save_token()), then verifies in the app.
 *
 * Publishing the file proves control of the whole host to Sitelemetry, which
 * then runs host-level probes (exposed files, HTTP methods, ports) against the
 * server behind it. On a multisite network that server belongs to the network,
 * not to one site, so there only a network administrator may publish the file
 * (see capability()), and a background renewal publishes a new token only where
 * a network administrator set up the verification before.
 */
class Sitelemetry_Audit_Verification {

	const OPTION        = 'sitelemetry_audit_verification';
	const PATH          = '/.well-known/sitelemetry-verification.txt';
	const TOKEN_PATTERN = '/^sitelemetry-[A-Za-z0-9_-]{32}$/D';
	const RENEWAL_DAYS  = 30;
	const MARKER_HEADER = 'X-Sitelemetry-Verification';
	const MAX_REDIRECTS = 2;

	/**
	 * What the plugin last learned from the verification API about this site's
	 * host, and the outcome of the last one-click verification or renewal.
	 */
	const API_OPTION = 'sitelemetry_audit_verification_api';

	/**
	 * Whether the service has the verification API ("on", "off" or "unknown"),
	 * cached per key and service so the settings page does not ask every time.
	 */
	const PROBE_TRANSIENT = 'sitelemetry_audit_verification_probe';

	/** Seconds a cached answer is kept: API present, absent, not reachable. */
	const PROBE_ON_TTL      = 604800;
	const PROBE_OFF_TTL     = 3600;
	const PROBE_UNKNOWN_TTL = 300;

	/**
	 * WP-Cron event that renews the verification (at expiry, or when an audit or
	 * the status reports that it expired).
	 */
	const RENEW_HOOK = 'sitelemetry_audit_verification_renew';

	/** Seconds between the expiry and the renewal, and before a retry. */
	const RENEW_AFTER_EXPIRY = 60;
	const RETRY_DELAY        = 21600;
	const QUICK_RETRY_DELAY  = 900;
	const MAX_RENEWAL_TRIES  = 5;

	/**
	 * The capability that may publish, test and remove the verification file. On
	 * a single site it is manage_options, like the rest of the plugin. On a
	 * multisite network it is manage_network_options (network administrators): a
	 * site administrator manages one site but not the web server that answers for
	 * its host, and a published token would let Sitelemetry probe that shared
	 * server. Filter: sitelemetry_audit_verification_capability.
	 *
	 * @return string
	 */
	public static function capability() {
		$default    = function_exists( 'is_multisite' ) && is_multisite() ? 'manage_network_options' : 'manage_options';
		$capability = apply_filters( 'sitelemetry_audit_verification_capability', $default );
		return is_string( $capability ) && '' !== $capability ? $capability : $default;
	}

	/**
	 * Whether the current user may publish, test and remove the verification file.
	 *
	 * @return bool
	 */
	public static function current_user_can_publish() {
		return current_user_can( 'manage_options' ) && current_user_can( self::capability() );
	}

	/**
	 * Hooks. The file is answered on parse_request, before template_redirect,
	 * where redirect_canonical() and most maintenance-mode and login-wall plugins
	 * act, and before any template or theme code runs.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( self::RENEW_HOOK, array( __CLASS__, 'run_scheduled_renewal' ) );
		add_action( 'sitelemetry_audit_job_finished', array( __CLASS__, 'on_audit_finished' ), 10, 1 );
		add_action( 'update_option_' . Sitelemetry_Audit_Settings::OPTION, array( __CLASS__, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * update_option of the settings: when the API key changes (another account, or
	 * none), what the plugin learned about the old account's verification no
	 * longer applies. The published token stays until it is replaced or removed.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public static function on_settings_updated( $old_value, $value ) {
		$old = is_array( $old_value ) && isset( $old_value['api_key'] ) ? (string) $old_value['api_key'] : '';
		$new = is_array( $value ) && isset( $value['api_key'] ) ? (string) $value['api_key'] : '';
		if ( $old === $new ) {
			return;
		}
		delete_option( self::API_OPTION );
		delete_transient( self::PROBE_TRANSIENT );
		wp_clear_scheduled_hook( self::RENEW_HOOK );
	}

	/**
	 * Whether a value is a Sitelemetry verification token.
	 *
	 * @param mixed $token Candidate.
	 * @return bool
	 */
	public static function is_token( $token ) {
		return is_string( $token ) && 1 === preg_match( self::TOKEN_PATTERN, $token );
	}

	/**
	 * A host name without port, trailing dot or case (IPv6 brackets are kept).
	 *
	 * @param mixed $host Host, optionally with a port.
	 * @return string
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( is_string( $host ) ? $host : '' ) );
		if ( '' === $host ) {
			return '';
		}
		if ( '[' === $host[0] ) {
			$end = strpos( $host, ']' );
			return false === $end ? '' : substr( $host, 0, $end + 1 );
		}
		$host = preg_replace( '/:\d+$/', '', $host );
		return rtrim( (string) $host, '.' );
	}

	/**
	 * The host of this site (home_url()).
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return string
	 */
	public static function home_host( $home_url = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$host     = wp_parse_url( (string) $home_url, PHP_URL_HOST );
		return self::normalize_host( is_string( $host ) ? $host : '' );
	}

	/**
	 * The URL Sitelemetry fetches for this site. Sitelemetry uses https unless the
	 * domain was added in the app with an http:// address.
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return string
	 */
	public static function file_url( $home_url = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$scheme   = wp_parse_url( (string) $home_url, PHP_URL_SCHEME );
		$host     = self::home_host( $home_url );
		return '' === $host ? '' : ( 'http' === strtolower( (string) $scheme ) ? 'http' : 'https' ) . '://' . $host . self::PATH;
	}

	/**
	 * The address at which this site itself answers the file: the scheme, host and
	 * port of home_url(), for the "Open the file" link. It differs from file_url()
	 * only when the site runs on a non-default port (or on http:// while
	 * Sitelemetry fetches https://).
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return string
	 */
	public static function site_file_url( $home_url = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$scheme   = strtolower( (string) wp_parse_url( (string) $home_url, PHP_URL_SCHEME ) );
		$host     = self::home_host( $home_url );
		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$port = self::custom_port( $home_url );
		return $scheme . '://' . $host . ( null === $port ? '' : ':' . $port ) . self::PATH;
	}

	/**
	 * The port of home_url() when it is not the default port of its scheme, or null.
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return int|null
	 */
	public static function custom_port( $home_url = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$port     = wp_parse_url( (string) $home_url, PHP_URL_PORT );
		$scheme   = strtolower( (string) wp_parse_url( (string) $home_url, PHP_URL_SCHEME ) );
		if ( ! is_int( $port ) || ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			return null;
		}
		return $port;
	}

	/**
	 * Whether a host is an IP address (IPv4, or IPv6 in brackets).
	 *
	 * @param string $host Normalized host.
	 * @return bool
	 */
	public static function is_ip_host( $host ) {
		return false !== filter_var( trim( (string) $host, '[]' ), FILTER_VALIDATE_IP );
	}

	/**
	 * Whether a host is a domain name with at least two labels, the kind of host
	 * that has a www variant and DNS records (not an IP address or "localhost").
	 *
	 * @param string $host Normalized host.
	 * @return bool
	 */
	public static function is_domain_host( $host ) {
		return '' !== (string) $host && ! self::is_ip_host( $host ) && false !== strpos( trim( (string) $host, '.' ), '.' );
	}

	/**
	 * The stored verification state.
	 *
	 * @return array { token: string, host: string, saved_at: int, authorized: bool, source: string, last_test: array|null, renew: bool }
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'token'      => isset( $stored['token'] ) && self::is_token( $stored['token'] ) ? $stored['token'] : '',
			'host'       => isset( $stored['host'] ) && is_string( $stored['host'] ) ? $stored['host'] : '',
			'saved_at'   => isset( $stored['saved_at'] ) ? (int) $stored['saved_at'] : 0,
			'authorized' => ! empty( $stored['authorized'] ),
			// "api" when the plugin obtained the token itself, "manual" when it was pasted.
			'source'     => isset( $stored['source'] ) && 'api' === $stored['source'] ? 'api' : 'manual',
			'last_test'  => isset( $stored['last_test'] ) && is_array( $stored['last_test'] ) ? $stored['last_test'] : null,
			// False after the admin removed the token (remove_token()): the plugin
			// then never publishes a new one by itself.
			'renew'      => ! ( isset( $stored['renew'] ) && false === $stored['renew'] ),
		);
	}

	/**
	 * Persists the state (autoload off: it is read only for the verification path
	 * and on the settings page).
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function persist( array $state ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $state, '', 'no' );
			return;
		}
		update_option( self::OPTION, $state, 'no' );
	}

	/**
	 * Stores a pasted token for this site's host.
	 *
	 * @param mixed $raw Pasted value.
	 * @return true|WP_Error
	 */
	public static function save_token( $raw ) {
		$token = trim( is_string( $raw ) ? $raw : '' );
		if ( ! self::is_token( $token ) ) {
			return new WP_Error( 'invalid_token', __( 'That is not a Sitelemetry verification token. Copy the whole token shown under HTTP verification file in the Verified Domains section of the app; it starts with sitelemetry- and has 32 more characters.', 'sitelemetry-audit' ) );
		}
		if ( '' === self::home_host() ) {
			return new WP_Error( 'no_host', __( 'The site address has no host name.', 'sitelemetry-audit' ) );
		}
		// Whether a user with the verification capability saved it; on a
		// multisite network only such a token is served (served_token()).
		self::store_token( $token, self::current_user_can_publish(), 'manual' );
		return true;
	}

	/**
	 * Stores a valid token for this site's host. The state is replaced as a whole,
	 * so a stop set by remove_token() ends: the admin publishes a token again.
	 *
	 * @param string $token      Token (already validated).
	 * @param bool   $authorized Whether it may be served on a multisite network.
	 * @param string $source     "manual" or "api".
	 * @return void
	 */
	private static function store_token( $token, $authorized, $source ) {
		self::persist(
			array(
				'token'      => $token,
				'host'       => self::home_host(),
				'saved_at'   => time(),
				'authorized' => (bool) $authorized,
				'source'     => 'api' === $source ? 'api' : 'manual',
				'last_test'  => null,
			)
		);
	}

	/**
	 * Removes the token: the path answers 404 again. The admin stopped publishing
	 * the file, so the automatic renewal stops as well; otherwise WP-Cron would
	 * publish a new token and verify the host again at the next expiry, and
	 * Sitelemetry's host-level probes would resume with no admin action. The
	 * scheduled renewal is removed and the stop is recorded ('renew' => false), so
	 * auto_renew_allowed() refuses every later trigger (the expiry, an audit that
	 * reports the verification expired, the settings page probe, activation). It
	 * ends when the admin verifies with one click (request_one_click()) or saves a
	 * token again (store_token()).
	 *
	 * @return void
	 */
	public static function remove_token() {
		wp_clear_scheduled_hook( self::RENEW_HOOK );
		self::persist( array( 'renew' => false ) );
	}

	/**
	 * Ends the stop set by remove_token(): the admin asked the plugin to verify
	 * this site again, so it may renew the verification by itself again.
	 *
	 * @return void
	 */
	private static function resume_renewal() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || ! isset( $state['renew'] ) || false !== $state['renew'] ) {
			return;
		}
		unset( $state['renew'] );
		if ( array() === $state ) {
			delete_option( self::OPTION );
			return;
		}
		self::persist( $state );
	}

	/**
	 * The token served for this site, or '' (none stored, or stored for another
	 * host, for example before the site moved to a new address). On a multisite
	 * network a token is served only when a user with the verification capability
	 * saved it.
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return string
	 */
	public static function served_token( $home_url = null ) {
		$stored = self::stored();
		$host   = self::home_host( $home_url );
		if ( function_exists( 'is_multisite' ) && is_multisite() && ! $stored['authorized'] ) {
			return '';
		}
		return '' !== $stored['token'] && '' !== $host && $stored['host'] === $host ? $stored['token'] : '';
	}

	/**
	 * Whether a request is for the verification file of this site: exactly the
	 * path (no other /.well-known/ path) on this site's own host.
	 *
	 * @param string $request_uri  Request URI.
	 * @param string $request_host Host header.
	 * @param string $home_url     Home URL.
	 * @return bool
	 */
	public static function is_file_request( $request_uri, $request_host, $home_url ) {
		$path = wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		$host = self::normalize_host( $request_host );
		return self::PATH === $path && '' !== $host && self::home_host( $home_url ) === $host;
	}

	/**
	 * The response for the verification path (pure): 200 with the token as plain
	 * text, or 404 when no token is stored for this host. Never cached.
	 *
	 * @param string $method   Request method.
	 * @param string $token    Token served for this host, or ''.
	 * @return array { status: int, headers: array, body: string }
	 */
	public static function response( $method, $token ) {
		$found = self::is_token( $token );
		return array(
			'status'  => $found ? 200 : 404,
			'headers' => array(
				'Content-Type'           => 'text/plain; charset=utf-8',
				'Cache-Control'          => 'no-store, max-age=0',
				'X-Content-Type-Options' => 'nosniff',
				'X-Robots-Tag'           => 'noindex, nofollow',
				self::MARKER_HEADER      => $found ? 'token' : 'none',
			),
			'body'    => 'HEAD' === strtoupper( (string) $method ) ? '' : ( $found ? $token : 'Not found' ),
		);
	}

	/**
	 * parse_request: answers the verification path and stops, or does nothing.
	 *
	 * @return void
	 */
	public static function maybe_serve() {
		// Compared with one fixed path and this site's host, never stored or echoed.
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		if ( ! self::is_file_request( $uri, $host, home_url( '/' ) ) ) {
			return;
		}
		$response = self::response( $method, self::served_token() );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// Page cache plugins never keep this answer. The constant is theirs, so it keeps its name.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared page-cache constant read by caching plugins.
		}
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo esc_html( $response['body'] );
		exit;
	}

	/**
	 * The address sent to the verification API: the scheme and host of home_url()
	 * (https unless the site runs on http://), without port or path. The service
	 * verifies the exact host name and keeps the scheme for the challenge URL.
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return string
	 */
	public static function target_url( $home_url = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$scheme   = strtolower( (string) wp_parse_url( (string) $home_url, PHP_URL_SCHEME ) );
		$host     = self::home_host( $home_url );
		return '' === $host ? '' : ( 'http' === $scheme ? 'http' : 'https' ) . '://' . $host . '/';
	}

	/**
	 * The client for the stored key, unless one is given (tests inject one with a
	 * mocked HTTP layer).
	 *
	 * @param Sitelemetry_Audit_Verification_Api|null $api Client.
	 * @return Sitelemetry_Audit_Verification_Api
	 */
	private static function api( $api ) {
		return $api instanceof Sitelemetry_Audit_Verification_Api ? $api : Sitelemetry_Audit_Verification_Api::from_settings();
	}

	/**
	 * The stored API state, with every field present.
	 *
	 * @return array {
	 *     @type string     $host        Host the state is about.
	 *     @type string     $key         Fingerprint of the key and service that asked (not the key).
	 *     @type string     $status      verified | pending | stale | unverified | '' (last answer of the service).
	 *     @type string     $source      sitelemetry | google-search-console | ''.
	 *     @type int        $expires_at  When the verification expires (0: unknown).
	 *     @type int        $verified_at When it was verified (0: unknown).
	 *     @type int        $checked_at  When the plugin last asked.
	 *     @type bool       $renewing    A renewal replaced the expired proof and is not finished.
	 *     @type int        $attempts    Failed renewals in a row.
	 *     @type array|null $last        Outcome of the last one-click verification or renewal.
	 *     @type bool       $recheck     An audit contradicted the recorded status: the settings page asks again.
	 * }
	 */
	public static function api_state() {
		$state = get_option( self::API_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'host'        => isset( $state['host'] ) && is_string( $state['host'] ) ? $state['host'] : '',
			'key'         => isset( $state['key'] ) && is_string( $state['key'] ) ? $state['key'] : '',
			'status'      => isset( $state['status'] ) && in_array( $state['status'], array( 'verified', 'pending', 'stale', 'unverified' ), true ) ? $state['status'] : '',
			'source'      => isset( $state['source'] ) && is_string( $state['source'] ) ? $state['source'] : '',
			'expires_at'  => isset( $state['expires_at'] ) ? (int) $state['expires_at'] : 0,
			'verified_at' => isset( $state['verified_at'] ) ? (int) $state['verified_at'] : 0,
			'checked_at'  => isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0,
			'renewing'    => ! empty( $state['renewing'] ),
			'attempts'    => isset( $state['attempts'] ) ? (int) $state['attempts'] : 0,
			'last'        => isset( $state['last'] ) && is_array( $state['last'] ) ? $state['last'] : null,
			'recheck'     => ! empty( $state['recheck'] ),
		);
	}

	/**
	 * Persists the API state (autoload off).
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function save_api_state( array $state ) {
		if ( false === get_option( self::API_OPTION, false ) ) {
			add_option( self::API_OPTION, $state, '', 'no' );
			return;
		}
		update_option( self::API_OPTION, $state, 'no' );
	}

	/**
	 * A Unix timestamp from an ISO 8601 date of the service, or 0.
	 *
	 * @param mixed $value Date.
	 * @return int
	 */
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}
		$time = strtotime( $value );
		return false === $time ? 0 : (int) $time;
	}

	/**
	 * Records what the service said about this site's host (status, challenge or
	 * verify answer). Tokens are not part of it.
	 *
	 * @param array                              $answer Decoded answer.
	 * @param Sitelemetry_Audit_Verification_Api $api    Client that asked (the state belongs to its key).
	 * @return void
	 */
	private static function record_status( array $answer, $api ) {
		$state                = self::api_state();
		$status               = isset( $answer['status'] ) ? $answer['status'] : '';
		$state['host']        = self::home_host();
		$state['key']         = $api->fingerprint();
		$state['status']      = in_array( $status, array( 'verified', 'pending', 'stale', 'unverified' ), true ) ? $status : '';
		$state['source']      = isset( $answer['source'] ) && is_string( $answer['source'] ) ? sanitize_key( $answer['source'] ) : '';
		$state['expires_at']  = isset( $answer['expiresAt'] ) ? self::timestamp( $answer['expiresAt'] ) : 0;
		$state['verified_at'] = isset( $answer['verifiedAt'] ) ? self::timestamp( $answer['verifiedAt'] ) : 0;
		$state['checked_at']  = time();
		$state['recheck']     = false;
		if ( 'verified' === $state['status'] ) {
			$state['renewing'] = false;
		}
		self::save_api_state( $state );
	}

	/**
	 * The verification of this site's host as the plugin last saw it, while it is
	 * valid: { expires_at: int (0 when the service gave no date), verified_at: int,
	 * source: string }, or null. It belongs to the account of the stored key: after
	 * the key changes it no longer counts.
	 *
	 * @return array|null
	 */
	public static function verified_state() {
		$state = self::api_state();
		if ( 'verified' !== $state['status'] || '' === $state['host'] || self::home_host() !== $state['host'] ) {
			return null;
		}
		if ( Sitelemetry_Audit_Verification_Api::from_settings()->fingerprint() !== $state['key'] ) {
			return null;
		}
		if ( $state['expires_at'] > 0 && $state['expires_at'] <= time() ) {
			return null;
		}
		return array(
			'expires_at'  => $state['expires_at'],
			'verified_at' => $state['verified_at'],
			'source'      => $state['source'],
		);
	}

	/**
	 * Remembers whether the service has the verification API.
	 *
	 * @param Sitelemetry_Audit_Verification_Api $api   Client.
	 * @param string                             $state on | off | unknown.
	 * @return void
	 */
	private static function remember_probe( $api, $state ) {
		$ttl = array(
			'on'      => self::PROBE_ON_TTL,
			'off'     => self::PROBE_OFF_TTL,
			'unknown' => self::PROBE_UNKNOWN_TTL,
		);
		set_transient(
			self::PROBE_TRANSIENT,
			array(
				'state' => $state,
				'key'   => $api->fingerprint(),
			),
			$ttl[ $state ]
		);
	}

	/**
	 * The probe state an answer implies: off when the service answered like an
	 * unknown path, unknown when it could not be reached, on for any answer of the
	 * API itself (including its errors).
	 *
	 * @param array|WP_Error $answer Answer.
	 * @return string
	 */
	private static function probe_state_of( $answer ) {
		if ( ! is_wp_error( $answer ) ) {
			return 'on';
		}
		if ( Sitelemetry_Audit_Verification_Api::ERROR_OFF === $answer->get_error_code() ) {
			return 'off';
		}
		if ( Sitelemetry_Audit_Verification_Api::ERROR_TRANSPORT === $answer->get_error_code() ) {
			return 'unknown';
		}
		$data = Sitelemetry_Audit_Verification_Api::error_data( $answer );
		return 0 === strpos( $data['code'], 'http_' ) ? 'unknown' : 'on';
	}

	/**
	 * Whether one-click verification can be offered:
	 *
	 * - "no_key"  no API key is stored (the button is shown disabled);
	 * - "on"      the service has the verification API;
	 * - "off"     the service answered like an unknown path: the API is switched
	 *             off or not deployed, so only the manual helper is shown;
	 * - "unknown" the service could not be reached, or $probe is false and nothing
	 *             is cached (the button is offered; a click finds out).
	 *
	 * With $probe, a missing or expired cache is refreshed with one status request
	 * (read only), which also records this host's verification state and schedules
	 * its renewal (schedule_renewal_for()). The same request is made once while
	 * the API is known to be there when an audit contradicted the recorded status
	 * (api_state()['recheck'], see on_audit_finished()). Only the settings page
	 * probes; notices and the dashboard widget read the cache.
	 *
	 * @param Sitelemetry_Audit_Verification_Api|null $api   Client (default: the stored key).
	 * @param bool                                    $probe Whether to ask the service.
	 * @return string
	 */
	public static function one_click_state( $api = null, $probe = true ) {
		$api = self::api( $api );
		if ( ! $api->has_key() ) {
			return 'no_key';
		}
		$cached = get_transient( self::PROBE_TRANSIENT );
		$known  = is_array( $cached ) && isset( $cached['state'], $cached['key'] ) && $cached['key'] === $api->fingerprint() && in_array( $cached['state'], array( 'on', 'off', 'unknown' ), true );
		if ( $known && ( ! $probe || 'on' !== $cached['state'] || ! self::api_state()['recheck'] ) ) {
			return $cached['state'];
		}
		if ( ! $probe ) {
			return 'unknown';
		}
		$answer = $api->status( self::target_url(), 5 );
		$state  = self::probe_state_of( $answer );
		self::remember_probe( $api, $state );
		if ( ! is_wp_error( $answer ) ) {
			self::record_status( $answer, $api );
			self::schedule_renewal_for( isset( $answer['status'] ) ? $answer['status'] : '' );
		} else {
			// Asked once: an error answer does not make every page load ask again.
			self::set_recheck( false );
		}
		return $state;
	}

	/**
	 * Sets or clears api_state()['recheck'] (nothing is saved when it is unchanged).
	 *
	 * @param bool $recheck Whether the settings page asks the service again.
	 * @return void
	 */
	private static function set_recheck( $recheck ) {
		$state = self::api_state();
		if ( $state['recheck'] === (bool) $recheck ) {
			return;
		}
		$state['recheck'] = (bool) $recheck;
		self::save_api_state( $state );
	}

	/**
	 * After a status probe: renews an expired verification in the background now,
	 * and makes sure a valid one is renewed when it expires. A verification made in
	 * the app, or before the API key was changed (which clears the schedule), has no
	 * renewal scheduled yet; an earlier pending renewal is kept.
	 *
	 * @param string $status Status the service reported.
	 * @return void
	 */
	private static function schedule_renewal_for( $status ) {
		if ( ! self::auto_renew_allowed() ) {
			return;
		}
		if ( 'stale' === $status ) {
			self::schedule_renewal( time() );
			return;
		}
		$state = self::api_state();
		if ( 'verified' !== $status || $state['expires_at'] <= 0 ) {
			return;
		}
		$at   = self::renewal_time( $state['expires_at'] );
		$next = wp_next_scheduled( self::RENEW_HOOK );
		if ( false === $next || $next > $at ) {
			self::schedule_renewal( $at );
		}
	}

	/**
	 * Whether the settings page offers one-click verification (see
	 * one_click_state(); an unreachable service still gets the button).
	 *
	 * @param Sitelemetry_Audit_Verification_Api|null $api Client (default: the stored key).
	 * @return bool
	 */
	public static function one_click_available( $api = null ) {
		return in_array( self::one_click_state( $api ), array( 'on', 'unknown' ), true );
	}

	/**
	 * One-click verification of this site (the admin clicked "Verify this site";
	 * the caller checked the capability and the nonce):
	 *
	 * 1. status: when the host is already verified and fresh, nothing else happens;
	 * 2. challenge: the token for exactly this host and path is stored and served;
	 * 3. the self-test loads the file from this site's own address;
	 * 4. verify: Sitelemetry loads the file and confirms the token.
	 *
	 * The outcome is stored (api_state()['last']) so the settings page explains it
	 * in the viewer's language. "off" means the service has no verification API;
	 * nothing is recorded as a failure then. The click ends a stop of the automatic
	 * renewal set by "Remove token" (remove_token()).
	 *
	 * @param Sitelemetry_Audit_Verification_Api|null $api Client (default: the stored key).
	 * @return array { ok: bool, code: string, ... } See one_click_message().
	 */
	public static function request_one_click( $api = null ) {
		self::resume_renewal();
		$api = self::api( $api );
		if ( ! $api->has_key() ) {
			return self::finish_attempt( array( 'ok' => false, 'code' => 'no_api_key' ), 'manual' );
		}
		if ( ! self::is_domain_host( self::home_host() ) ) {
			return self::finish_attempt( array( 'ok' => false, 'code' => 'invalid_target' ), 'manual' );
		}
		$target = self::target_url();
		$status = $api->status( $target );
		self::remember_probe( $api, self::probe_state_of( $status ) );
		if ( is_wp_error( $status ) ) {
			return self::failure_or_off( $status, 'manual' );
		}
		self::record_status( $status, $api );
		if ( isset( $status['status'] ) && 'verified' === $status['status'] ) {
			return self::finish_attempt( array( 'ok' => true, 'code' => 'already_verified' ), 'manual' );
		}
		$result = self::complete_challenge( $api, $target, self::current_user_can_publish() );
		return 'off' === $result['code'] ? $result : self::finish_attempt( $result, 'manual' );
	}

	/**
	 * Challenge, publish, self-test, verify (shared by the click and the renewal).
	 *
	 * @param Sitelemetry_Audit_Verification_Api $api        Client.
	 * @param string                             $target     Target URL.
	 * @param bool                               $authorized Whether the token may be served on a multisite network.
	 * @return array Outcome.
	 */
	private static function complete_challenge( $api, $target, $authorized ) {
		$challenge = $api->challenge( $target );
		if ( is_wp_error( $challenge ) ) {
			return self::failure_of( $challenge );
		}
		if ( isset( $challenge['status'] ) && 'verified' === $challenge['status'] ) {
			// Verified in the meantime (for example in the app): nothing to publish.
			self::record_status( $challenge, $api );
			return array(
				'ok'   => true,
				'code' => 'already_verified',
			);
		}
		$details = isset( $challenge['challenge'] ) && is_array( $challenge['challenge'] ) ? $challenge['challenge'] : array();
		$token   = isset( $details['token'] ) ? $details['token'] : '';
		$url     = isset( $details['url'] ) && is_string( $details['url'] ) ? $details['url'] : '';
		$host    = self::normalize_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		// Only a token for exactly this site's host and the verification path is
		// ever published, whatever the answer says.
		if ( ! self::is_token( $token ) || self::PATH !== wp_parse_url( $url, PHP_URL_PATH ) || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || self::home_host() !== $host ) {
			return array(
				'ok'     => false,
				'code'   => 'challenge_mismatch',
				'detail' => $host,
			);
		}
		self::record_status( $challenge, $api );
		self::store_token( $token, $authorized, 'api' );
		self::remember_test( self::test_file(), true );
		$verified = $api->verify( $target );
		if ( is_wp_error( $verified ) ) {
			return self::failure_of( $verified );
		}
		self::record_status( $verified, $api );
		if ( ! isset( $verified['status'] ) || 'verified' !== $verified['status'] ) {
			return array(
				'ok'     => false,
				'code'   => 'unexpected',
				'detail' => isset( $verified['status'] ) && is_string( $verified['status'] ) ? sanitize_key( $verified['status'] ) : '',
			);
		}
		return array(
			'ok'   => true,
			'code' => 'verified',
		);
	}

	/**
	 * The outcome of an error answer: "off" for an unknown path, otherwise the
	 * service's code and the values its message needs.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private static function failure_of( $error ) {
		$code = $error->get_error_code();
		if ( Sitelemetry_Audit_Verification_Api::ERROR_OFF === $code ) {
			return array(
				'ok'   => false,
				'code' => 'off',
			);
		}
		if ( Sitelemetry_Audit_Verification_Api::ERROR_TRANSPORT === $code ) {
			return array(
				'ok'     => false,
				'code'   => 'transport',
				'detail' => Sitelemetry_Audit_Outcome::clip( $error->get_error_message(), 200 ),
			);
		}
		$data = Sitelemetry_Audit_Verification_Api::error_data( $error );
		return array(
			'ok'          => false,
			'code'        => '' !== $data['code'] ? $data['code'] : 'unexpected',
			'reason'      => $data['reason'],
			'http_status' => $data['http_status'],
			'retry_after' => $data['retry_after'],
			'limit'       => $data['limit'],
			'detail'      => 0 === strpos( $data['code'], 'http_' ) ? (string) $data['http'] : '',
		);
	}

	/**
	 * An error answer of the first request: "off" is returned as is (the panel
	 * falls back to the manual helper with a neutral note), anything else is
	 * recorded as the outcome.
	 *
	 * @param WP_Error $error  Error.
	 * @param string   $origin manual | renewal.
	 * @return array
	 */
	private static function failure_or_off( $error, $origin ) {
		$result = self::failure_of( $error );
		return 'off' === $result['code'] ? $result : self::finish_attempt( $result, $origin );
	}

	/**
	 * Records the outcome of a one-click verification or a renewal and, after a
	 * success, schedules the next renewal for the moment the verification expires.
	 *
	 * @param array  $result Outcome.
	 * @param string $origin manual | renewal.
	 * @return array The outcome.
	 */
	private static function finish_attempt( array $result, $origin ) {
		$state         = self::api_state();
		$ok            = ! empty( $result['ok'] );
		$state['last'] = array(
			'ok'          => $ok,
			'code'        => isset( $result['code'] ) ? (string) $result['code'] : '',
			'reason'      => isset( $result['reason'] ) ? (string) $result['reason'] : '',
			'http_status' => isset( $result['http_status'] ) ? (int) $result['http_status'] : null,
			'retry_after' => isset( $result['retry_after'] ) ? (int) $result['retry_after'] : null,
			'limit'       => isset( $result['limit'] ) ? (int) $result['limit'] : null,
			'detail'      => isset( $result['detail'] ) ? Sitelemetry_Audit_Outcome::clip( (string) $result['detail'], 200 ) : '',
			'origin'      => 'renewal' === $origin ? 'renewal' : 'manual',
			'at'          => time(),
		);
		if ( $ok || 'manual' === $origin ) {
			$state['attempts'] = 0;
		} else {
			++$state['attempts'];
		}
		if ( $ok ) {
			$state['renewing'] = false;
		}
		self::save_api_state( $state );
		if ( $ok && 'verified' === $state['status'] && $state['expires_at'] > 0 ) {
			self::schedule_renewal( self::renewal_time( $state['expires_at'] ) );
		}
		return $result;
	}

	/**
	 * When to renew a verification that expires at $expires_at: just after the
	 * expiry. When this server's clock is already past it but the service still
	 * reports the verification as fresh (the two clocks differ), not before
	 * QUICK_RETRY_DELAY, so WP-Cron does not ask the service on every run.
	 *
	 * @param int $expires_at Unix timestamp.
	 * @return int
	 */
	public static function renewal_time( $expires_at ) {
		$at = (int) $expires_at + self::RENEW_AFTER_EXPIRY;
		return $at > time() ? $at : time() + self::QUICK_RETRY_DELAY;
	}

	/**
	 * Whether the plugin may renew the verification by itself (in WP-Cron, with
	 * no user present): an API key is stored, the admin did not stop it with
	 * "Remove token" (remove_token()), this site has a public-looking host name at
	 * the root of the host, and, on a multisite network, a network administrator
	 * set up the verification of this host before. Every trigger of a renewal
	 * checks it. Filter: sitelemetry_audit_auto_renew (false turns automatic
	 * renewal off).
	 *
	 * @return bool
	 */
	public static function auto_renew_allowed() {
		$host = self::home_host();
		if ( '' === Sitelemetry_Audit_Settings::api_key() || ! self::is_domain_host( $host ) ) {
			return false;
		}
		if ( ! self::stored()['renew'] ) {
			return false;
		}
		// A subdirectory install does not answer the root of the host.
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( is_string( $path ) && '' !== trim( $path, '/' ) ) {
			return false;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$stored = self::stored();
			if ( ! $stored['authorized'] || $stored['host'] !== $host ) {
				return false;
			}
		}
		return (bool) apply_filters( 'sitelemetry_audit_auto_renew', true );
	}

	/**
	 * Schedules the renewal the plugin knows is due, from the stored API state
	 * alone (no request): at plugin activation, after deactivation removed the
	 * event. A valid verification is renewed just after its expiry; one that
	 * expired while the plugin was inactive (still recorded as verified, or
	 * reported stale), or a renewal that was not finished and has retries left,
	 * soon (renewal_time() maps a past or unknown expiry to QUICK_RETRY_DELAY from
	 * now). Only a state recorded for this host with the stored key counts.
	 *
	 * @return void
	 */
	public static function schedule_known_renewal() {
		if ( ! self::auto_renew_allowed() ) {
			return;
		}
		$state = self::api_state();
		if ( '' === $state['host'] || self::home_host() !== $state['host'] || Sitelemetry_Audit_Verification_Api::from_settings()->fingerprint() !== $state['key'] ) {
			return;
		}
		$due = ( 'verified' === $state['status'] && $state['expires_at'] > 0 )
			|| 'stale' === $state['status']
			|| ( 'pending' === $state['status'] && $state['renewing'] && $state['attempts'] < self::MAX_RENEWAL_TRIES );
		if ( $due ) {
			self::schedule_renewal( self::renewal_time( $state['expires_at'] ) );
		}
	}

	/**
	 * Schedules the renewal (one pending event at a time).
	 *
	 * @param int $timestamp When.
	 * @return void
	 */
	public static function schedule_renewal( $timestamp ) {
		wp_clear_scheduled_hook( self::RENEW_HOOK );
		wp_schedule_single_event( max( time(), (int) $timestamp ), self::RENEW_HOOK );
	}

	/**
	 * Automatic renewal (WP-Cron): asks for the status and renews only a
	 * verification the service reports as expired ("stale"), or finishes a renewal
	 * this plugin started. A fresh verification is never touched: then only the
	 * next renewal is scheduled. A failure is recorded for the admin notice and
	 * retried later.
	 *
	 * @param Sitelemetry_Audit_Verification_Api|null $api Client (default: the stored key).
	 * @return string skipped | off | fresh | renewed | failed
	 */
	public static function renew( $api = null ) {
		if ( ! self::auto_renew_allowed() ) {
			return 'skipped';
		}
		$api    = self::api( $api );
		$target = self::target_url();
		$status = $api->status( $target );
		self::remember_probe( $api, self::probe_state_of( $status ) );
		if ( is_wp_error( $status ) ) {
			$result = self::failure_or_off( $status, 'renewal' );
			return 'off' === $result['code'] ? 'off' : self::after_failed_renewal( $result );
		}
		self::record_status( $status, $api );
		$state   = self::api_state();
		$current = isset( $status['status'] ) ? $status['status'] : '';
		if ( 'verified' === $current ) {
			// Renewed elsewhere (or still fresh): clear a failed renewal and wait for the expiry.
			if ( null !== $state['last'] && 'renewal' === $state['last']['origin'] && empty( $state['last']['ok'] ) ) {
				self::finish_attempt(
					array(
						'ok'   => true,
						'code' => 'already_verified',
					),
					'renewal'
				);
			} elseif ( $state['expires_at'] > 0 ) {
				self::schedule_renewal( self::renewal_time( $state['expires_at'] ) );
			}
			return 'fresh';
		}
		if ( 'stale' !== $current && ! ( 'pending' === $current && $state['renewing'] ) ) {
			// Never verified, or a pending verification someone else started: the
			// admin verifies with a click.
			return 'skipped';
		}
		$state['renewing'] = true;
		self::save_api_state( $state );
		$stored     = self::stored();
		$authorized = function_exists( 'is_multisite' ) && is_multisite() ? $stored['authorized'] : true;
		$result     = self::complete_challenge( $api, $target, $authorized );
		if ( 'off' === $result['code'] ) {
			return 'off';
		}
		self::finish_attempt( $result, 'renewal' );
		return $result['ok'] ? 'renewed' : self::after_failed_renewal( $result );
	}

	/**
	 * Schedules the retry of a failed renewal: soon for a passing problem (the
	 * service or the network), later otherwise, and not at all after
	 * MAX_RENEWAL_TRIES failures in a row.
	 *
	 * @param array $result Outcome.
	 * @return string "failed".
	 */
	private static function after_failed_renewal( array $result ) {
		$state = self::api_state();
		if ( $state['attempts'] < self::MAX_RENEWAL_TRIES ) {
			$delay = self::is_passing_failure( $result ) ? self::QUICK_RETRY_DELAY : self::RETRY_DELAY;
			if ( isset( $result['retry_after'] ) && (int) $result['retry_after'] > $delay ) {
				$delay = (int) $result['retry_after'];
			}
			self::schedule_renewal( time() + $delay );
		}
		return 'failed';
	}

	/**
	 * Whether a failure is probably passing (the network, the service, a rate
	 * limit or no answer from the site in time) rather than something to fix.
	 *
	 * @param array $result Outcome.
	 * @return bool
	 */
	private static function is_passing_failure( array $result ) {
		$code   = isset( $result['code'] ) ? (string) $result['code'] : '';
		$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
		if ( in_array( $code, array( 'transport', 'rate_limited', 'verification_in_progress', 'persistence_unavailable', 'internal_error', 'deployment_maintenance' ), true ) || 0 === strpos( $code, 'http_' ) ) {
			return true;
		}
		return 'verification_failed' === $code && in_array( $reason, array( 'timeout', 'connection' ), true );
	}

	/**
	 * WP-Cron callback of RENEW_HOOK.
	 *
	 * @return void
	 */
	public static function run_scheduled_renewal() {
		self::renew();
	}

	/**
	 * sitelemetry_audit_job_finished: when an audit of this site reports that the
	 * verification must be renewed (target_reverification_required), renew it in
	 * the background, unless the service is known to have no verification API.
	 *
	 * When it reports that this site is not verified, or not for the whole host
	 * (target_verification_required, verification_scope_required; for example the
	 * domain was removed under Verified Domains in the app), the status the plugin
	 * recorded is no longer shown: the settings panel stops saying "Verification
	 * complete" and asks the service once more on its next load (recheck). If the
	 * service still reports the host as verified, the notice comes back. The cached
	 * answer about the API is kept, so the call to action of that result still
	 * offers one-click verification.
	 *
	 * @param mixed $model Result model (null when a job was abandoned).
	 * @return void
	 */
	public static function on_audit_finished( $model ) {
		if ( ! is_array( $model ) || ! isset( $model['status'], $model['reason'], $model['target'] ) ) {
			return;
		}
		if ( 'verification_required' !== $model['status'] || ! self::target_is_this_site( $model['target'] ) ) {
			return;
		}
		if ( in_array( $model['reason'], array( 'target_verification_required', 'verification_scope_required' ), true ) ) {
			$state = self::api_state();
			if ( '' !== $state['status'] ) {
				$state['status']  = '';
				$state['recheck'] = true;
				self::save_api_state( $state );
			}
			return;
		}
		if ( 'target_reverification_required' !== $model['reason'] ) {
			return;
		}
		if ( self::auto_renew_allowed() && 'off' !== self::one_click_state( null, false ) ) {
			self::schedule_renewal( time() );
		}
	}

	/**
	 * Before the weekly audit of this site: when the plugin knows that the
	 * verification has expired, renew it first so the audit is not refused.
	 *
	 * @param string $target Audit target.
	 * @return void
	 */
	public static function renew_before_audit( $target ) {
		if ( ! self::target_is_this_site( $target ) ) {
			return;
		}
		$state   = self::api_state();
		$expired = 'stale' === $state['status'] || ( 'verified' === $state['status'] && $state['expires_at'] > 0 && $state['expires_at'] <= time() );
		if ( self::home_host() !== $state['host'] || ! $expired || 'off' === self::one_click_state( null, false ) ) {
			return;
		}
		self::renew();
	}

	/**
	 * The failed renewal the admin should hear about, or null: the last outcome of
	 * an automatic renewal when it failed, unless it looks passing and has not
	 * failed twice in a row yet (a retry is scheduled then), or the admin has
	 * stopped the automatic renewal since (remove_token()).
	 *
	 * @return array|null The outcome (see api_state()['last']).
	 */
	public static function renewal_failure() {
		$state = self::api_state();
		$last  = $state['last'];
		if ( null === $last || ! empty( $last['ok'] ) || ! isset( $last['origin'] ) || 'renewal' !== $last['origin'] || ! self::stored()['renew'] ) {
			return null;
		}
		if ( self::is_passing_failure( $last ) && $state['attempts'] < 2 ) {
			return null;
		}
		return $last;
	}

	/**
	 * Formats a timestamp in the site's date and time format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}

	/**
	 * "Verification complete ✓ valid until <date>" for a verification that expires
	 * at $expires_at (without the date when the service gave none).
	 *
	 * @param int $expires_at Unix timestamp or 0.
	 * @return string
	 */
	public static function complete_message( $expires_at ) {
		if ( (int) $expires_at <= 0 ) {
			return __( 'Verification complete ✓', 'sitelemetry-audit' );
		}
		/* translators: %s: date and time the verification expires. */
		return sprintf( __( 'Verification complete ✓ valid until %s', 'sitelemetry-audit' ), self::format_time( $expires_at ) );
	}

	/**
	 * The message of a one-click outcome in the current user's language: what
	 * happened and how to fix it. Built from the stored code and values, so each
	 * viewer reads it in their own language.
	 *
	 * @param array $last Outcome (see api_state()['last']).
	 * @return string
	 */
	public static function one_click_message( array $last ) {
		$code   = isset( $last['code'] ) && is_string( $last['code'] ) ? $last['code'] : '';
		$reason = isset( $last['reason'] ) && is_string( $last['reason'] ) ? $last['reason'] : '';
		$detail = isset( $last['detail'] ) && is_scalar( $last['detail'] ) ? (string) $last['detail'] : '';
		$status = isset( $last['http_status'] ) && is_numeric( $last['http_status'] ) ? (int) $last['http_status'] : 0;
		if ( ! empty( $last['ok'] ) ) {
			$verified = self::verified_state();
			return self::complete_message( null === $verified ? 0 : $verified['expires_at'] );
		}
		if ( 'verification_failed' === $code ) {
			return self::verification_failed_message( $reason, $status );
		}
		switch ( $code ) {
			case 'no_api_key':
				return __( 'Save an API key first.', 'sitelemetry-audit' );
			case 'unauthorized':
				return __( 'Sitelemetry did not accept the API key. It may be mistyped or revoked (for example after a password change), or the account cannot use API access. Get your API key again from the app, save it in the plugin settings and try again.', 'sitelemetry-audit' );
			case 'invalid_target':
				return __( 'Sitelemetry verifies only public host names, and the address of this site is not one (for example an IP address, localhost or a name without a dot). Verify the public address of the site instead.', 'sitelemetry-audit' );
			case 'pending_limit':
				/* translators: %d: maximum number of unfinished verifications. */
				return sprintf( __( 'Your Sitelemetry workspace already has the maximum number of unfinished verifications (%d). Finish or remove some under Verified Domains in the app, then try again.', 'sitelemetry-audit' ), isset( $last['limit'] ) ? (int) $last['limit'] : 10 );
			case 'rate_limited':
				$seconds = isset( $last['retry_after'] ) ? max( 1, (int) $last['retry_after'] ) : 60;
				/* translators: %s: time to wait, for example "5 mins". */
				return sprintf( __( 'Too many verification attempts. Try again in %s.', 'sitelemetry-audit' ), human_time_diff( 0, $seconds ) );
			case 'verification_in_progress':
				return __( 'A verification for this account is already running. Wait a few seconds, then try again.', 'sitelemetry-audit' );
			case 'verifier_unavailable':
			case 'api_key_unavailable':
				return __( 'One-click verification is not available on Sitelemetry right now. Verify with a token from the app as described under Verify this site in the plugin settings, or use a DNS record or Google Search Console.', 'sitelemetry-audit' );
			case 'challenge_mismatch':
				/* translators: %s: host name in the answer of Sitelemetry. */
				return sprintf( __( 'Sitelemetry answered with a verification file for another host (%s), so the plugin did not publish it. Check the site address in Settings > General, then try again.', 'sitelemetry-audit' ), '' !== $detail ? $detail : '-' );
			case 'transport':
				/* translators: %s: error message. */
				return sprintf( __( 'WordPress could not reach Sitelemetry (%s). Check that this server can make outgoing HTTPS requests to sitelemetry.com, then try again.', 'sitelemetry-audit' ), $detail );
		}
		// The detail is an HTTP status only for an answer without the service's
		// envelope (code http_NNN); otherwise it is a word such as a status of the
		// service, and the code is shown instead.
		/* translators: %s: error code. */
		return sprintf( __( 'Sitelemetry could not complete the verification (%s). Try again in a few minutes.', 'sitelemetry-audit' ), 1 === preg_match( '/^[0-9]+$/D', $detail ) ? 'HTTP ' . $detail : ( '' !== $code ? $code : '-' ) );
	}

	/**
	 * The message of a failed check: Sitelemetry loaded (or tried to load) the file
	 * and it did not prove ownership.
	 *
	 * @param string $reason      Reason code of the service.
	 * @param int    $http_status HTTP status the site answered, or 0.
	 * @return string
	 */
	private static function verification_failed_message( $reason, $http_status ) {
		switch ( $reason ) {
			case 'http_status':
				if ( 404 === $http_status ) {
					return __( 'Sitelemetry could not load the verification file: the site answered HTTP 404. The web server most likely handles /.well-known/ itself (for example an nginx rule for hidden paths) and does not pass the request to WordPress. Ask your host to pass /.well-known/sitelemetry-verification.txt to WordPress, or use a DNS record.', 'sitelemetry-audit' );
				}
				if ( 401 === $http_status || 403 === $http_status ) {
					/* translators: %d: HTTP status code. */
					return sprintf( __( 'Sitelemetry could not load the verification file: the site answered HTTP %d. A firewall, a security plugin or password protection blocks the request (Sitelemetry uses the user agent Sitelemetry-Ownership-Verifier/1.0). Allow /.well-known/sitelemetry-verification.txt, then try again.', 'sitelemetry-audit' ), $http_status );
				}
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Sitelemetry could not load the verification file: the site answered HTTP %d instead of the file. Check that the site is up and not in maintenance mode, then try again.', 'sitelemetry-audit' ), $http_status );
			case 'redirect':
				return __( 'The verification file address redirects to another host, protocol or port, or redirects more than twice, and Sitelemetry does not follow such redirects. The usual cause is a redirect between www and non-www: Sitelemetry verifies one exact host name, so set the site address in Settings > General to the host your server redirects to, or stop the redirect for /.well-known/sitelemetry-verification.txt.', 'sitelemetry-audit' );
			case 'body_mismatch':
				return __( 'Sitelemetry loaded the verification file, but it did not contain exactly the token. A cached copy, another file at that path or a page from a cache, maintenance or security plugin answered instead. Purge the cache, remove the other file or exclude /.well-known/sitelemetry-verification.txt, then try again.', 'sitelemetry-audit' );
			case 'timeout':
				return __( 'The site did not answer Sitelemetry in time (10 seconds). Check that the site is up and reachable from the internet, then try again.', 'sitelemetry-audit' );
			case 'not_public':
				return __( 'The host name of this site does not resolve to a public address, so Sitelemetry cannot load the file from the internet. Check the DNS records of the domain, or verify the public address of the site instead.', 'sitelemetry-audit' );
			case 'dns':
				return __( 'Sitelemetry could not resolve the host name of this site. Check the DNS records of the domain, then try again.', 'sitelemetry-audit' );
			case 'tls':
				return __( 'Sitelemetry could not validate the TLS certificate of this site. Install a valid certificate for this exact host name, then try again.', 'sitelemetry-audit' );
			case 'body_too_large':
				return __( 'The verification file address answered with more than 2 KB, so something other than the plugin answers there. Remove that file or exclude /.well-known/sitelemetry-verification.txt from it, then try again.', 'sitelemetry-audit' );
			case 'challenge_changed':
				return __( 'The verification code changed while Sitelemetry was checking it (for example because it was renewed in the app at the same time). Click Verify this site again.', 'sitelemetry-audit' );
		}
		return __( 'Sitelemetry could not connect to this site. Check that it is reachable from the internet and that no firewall blocks the user agent Sitelemetry-Ownership-Verifier/1.0, then try again.', 'sitelemetry-audit' );
	}

	/**
	 * Why Sitelemetry probably cannot verify this host through the file, if a
	 * reason is visible from WordPress.
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @return string[] Warnings.
	 */
	public static function warnings( $home_url = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$host     = self::home_host( $home_url );
		$path     = wp_parse_url( (string) $home_url, PHP_URL_PATH );
		$warnings = array();
		$bare     = trim( $host, '[]' );
		$private  = false !== filter_var( $bare, FILTER_VALIDATE_IP ) && false === filter_var( $bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( 'localhost' === $host || $private || preg_match( '/\.(?:localhost|local|test|invalid|example|internal|lan|home\.arpa)$/', $host ) ) {
			$warnings[] = __( 'This site address is not reachable from the internet (a local, private or test host). Sitelemetry verifies only public hosts, so the file cannot verify it.', 'sitelemetry-audit' );
		}
		if ( is_string( $path ) && '' !== trim( $path, '/' ) ) {
			$warnings[] = __( 'WordPress runs in a subdirectory of this host. Sitelemetry fetches the file from the root of the host, which usually does not reach WordPress here; use the DNS method instead, or place the file at the root of the host.', 'sitelemetry-audit' );
		}
		if ( 'http' === strtolower( (string) wp_parse_url( (string) $home_url, PHP_URL_SCHEME ) ) ) {
			$warnings[] = __( 'This site address uses http://. Sitelemetry fetches the file over https:// unless the domain was added in the app with an http:// address.', 'sitelemetry-audit' );
		}
		$port = self::custom_port( $home_url );
		if ( null !== $port ) {
			/* translators: %d: port number of the site address. */
			$warnings[] = sprintf( __( 'This site address uses port %d. Sitelemetry fetches the file from the default port of the host (443 for https://, 80 for http://), so the file must also be reachable there; Test the file checks that address.', 'sitelemetry-audit' ), $port );
		}
		return $warnings;
	}

	/**
	 * Fetches this site's own verification file and reports what Sitelemetry would
	 * probably see. Follows at most two redirects on the same host, as the
	 * verifier does; a redirect to another host or from https to http fails there.
	 *
	 * The result carries a code and the values its message needs: remember_test()
	 * stores those, and test_message() writes the message in the language of
	 * whoever views the settings page.
	 *
	 * @param string|null $home_url Home URL (default home_url('/')).
	 * @param string|null $token    Expected token (default the served token).
	 * @return array { ok: bool, code: string, message: string, url: string, status: int|null, detail: string }
	 */
	public static function test_file( $home_url = null, $token = null ) {
		$home_url = null === $home_url ? home_url( '/' ) : $home_url;
		$token    = null === $token ? self::served_token( $home_url ) : $token;
		$url      = self::file_url( $home_url );
		$host     = self::home_host( $home_url );
		$result   = function ( $ok, $code, $status = null, $detail = '' ) use ( &$url ) {
			$test            = array(
				'ok'     => $ok,
				'code'   => $code,
				'url'    => $url,
				'status' => $status,
				'detail' => (string) $detail,
			);
			$test['message'] = Sitelemetry_Audit_Verification::test_message( $test );
			return $test;
		};
		if ( '' === $token ) {
			return $result( false, 'no_token' );
		}
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 4096,
					'user-agent'          => Sitelemetry_Audit_Client::user_agent(),
					'headers'             => array(
						'Accept'        => 'text/plain',
						'Cache-Control' => 'no-cache',
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return $result( false, 'unreachable', null, Sitelemetry_Audit_Outcome::clip( $response->get_error_message(), 200 ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 300 && $status < 400 ) {
				$location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
				$next     = self::resolve_location( $url, $location );
				if ( '' === $next ) {
					return $result( false, 'redirect', $status );
				}
				$next_host = self::home_host( $next );
				if ( $next_host !== $host ) {
					return $result( false, 'redirect_host', $status, $next_host );
				}
				// Schemes compare case-insensitively: "Location: HTTPS://..." is no downgrade.
				if ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && 'https' !== strtolower( (string) wp_parse_url( $next, PHP_URL_SCHEME ) ) ) {
					return $result( false, 'redirect_downgrade', $status );
				}
				if ( $hop >= self::MAX_REDIRECTS ) {
					return $result( false, 'too_many_redirects', $status );
				}
				$url = $next;
				continue;
			}
			return self::judge( $response, $status, $token, $result );
		}
		return $result( false, 'too_many_redirects' );
	}

	/**
	 * Interprets the final (non-redirect) response of the self-test.
	 *
	 * @param array    $response HTTP response.
	 * @param int      $status   Status code.
	 * @param string   $token    Expected token.
	 * @param callable $result   Result builder.
	 * @return array
	 */
	private static function judge( $response, $status, $token, $result ) {
		$marker = strtolower( trim( (string) wp_remote_retrieve_header( $response, self::MARKER_HEADER ) ) );
		$type   = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( 200 === $status ) {
			if ( trim( (string) wp_remote_retrieve_body( $response ) ) === $token ) {
				return $result( true, 'ok', $status );
			}
			if ( '' === $marker && false !== strpos( $type, 'html' ) ) {
				return $result( false, 'not_plugin', $status );
			}
			return $result( false, 'wrong_body', $status );
		}
		if ( 404 === $status && '' !== $marker ) {
			return $result( false, 'plugin_no_token', $status );
		}
		$codes = array(
			401 => 'auth',
			403 => 'forbidden',
			404 => 'not_found',
			429 => 'rate_limited',
			503 => 'unavailable',
		);
		if ( isset( $codes[ $status ] ) ) {
			return $result( false, $codes[ $status ], $status );
		}
		return $result( false, $status >= 500 ? 'server_error' : 'unexpected', $status );
	}

	/**
	 * The message of a self-test result in the current user's language, built from
	 * its code, HTTP status and detail (an error message or a host name). A stored
	 * result with an unknown code keeps the message stored with it, if any.
	 *
	 * @param array $test Result of test_file(), or the stored last test.
	 * @return string
	 */
	public static function test_message( array $test ) {
		$code   = isset( $test['code'] ) && is_string( $test['code'] ) ? $test['code'] : '';
		$status = isset( $test['status'] ) && is_numeric( $test['status'] ) ? (int) $test['status'] : 0;
		$detail = isset( $test['detail'] ) && is_scalar( $test['detail'] ) ? (string) $test['detail'] : '';
		switch ( $code ) {
			case 'ok':
				return __( 'The file works: WordPress loaded it from its own address with the stored token. Now click Verify HTTP for this domain in the app. Sitelemetry loads the file from the internet with its own user agent (Sitelemetry-Ownership-Verifier/1.0), so a firewall rule that blocks unknown bots can still make verification fail.', 'sitelemetry-audit' );
			case 'no_token':
				return __( 'No token is stored for this site yet. Paste the token from the app first.', 'sitelemetry-audit' );
			case 'unreachable':
				/* translators: %s: error message. */
				return sprintf( __( 'WordPress could not load the file from its own address (%s). Some hosts block requests from a site to itself, so this does not prove that Sitelemetry cannot load it; open the file address in a browser to check.', 'sitelemetry-audit' ), $detail );
			case 'redirect':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'The file address answers with a redirect (HTTP %d) without a usable destination.', 'sitelemetry-audit' ), $status );
			case 'redirect_host':
				/* translators: 1: HTTP status code, 2: host the redirect points to. */
				return sprintf( __( 'The file address redirects (HTTP %1$d) to another host, %2$s. Sitelemetry does not follow redirects to another host, so verification fails. Add and verify that host in the app instead, or stop the redirect for this path.', 'sitelemetry-audit' ), $status, $detail );
			case 'redirect_downgrade':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'The file address redirects (HTTP %d) from https:// to http://. Sitelemetry does not follow that redirect, so verification fails.', 'sitelemetry-audit' ), $status );
			case 'too_many_redirects':
				return __( 'The file address redirects more than twice. Sitelemetry follows at most two redirects, so verification fails.', 'sitelemetry-audit' );
			case 'not_plugin':
				return __( 'A web page answered instead of the file (for example a maintenance, login, cache or firewall page), so the request did not reach this plugin. Exclude /.well-known/sitelemetry-verification.txt from that page or plugin, or use the DNS method.', 'sitelemetry-audit' );
			case 'wrong_body':
				return __( 'The file address answers, but not with the stored token. Another file at that path (for example one uploaded earlier) or a cached copy takes precedence: remove it, purge the cache, or put the current token in it.', 'sitelemetry-audit' );
			case 'plugin_no_token':
				return __( 'The plugin answered, but no token is stored for this site address. Save the token again.', 'sitelemetry-audit' );
			case 'not_found':
				return __( 'The web server answered 404 without passing the request to WordPress. Many server setups handle /.well-known/ themselves (for example an nginx rule for hidden paths), and with plain permalinks the web server does not pass unknown paths to WordPress. Ask your host to pass /.well-known/sitelemetry-verification.txt to WordPress, upload a plain text file with the token at that path, or use the DNS method.', 'sitelemetry-audit' );
			case 'auth':
				return __( 'The site asks for a password (HTTP 401), so Sitelemetry cannot read the file. Remove the password protection for this path while you verify, or use the DNS method.', 'sitelemetry-audit' );
			case 'forbidden':
				return __( 'The request was refused (HTTP 403), usually by the web server configuration or a firewall or security plugin (for example Cloudflare, Wordfence or ModSecurity rules). Allow this path, or use the DNS method.', 'sitelemetry-audit' );
			case 'rate_limited':
				return __( 'The site rate-limited the request (HTTP 429). Try again in a minute; if it persists, allow this path in the firewall or security plugin.', 'sitelemetry-audit' );
			case 'unavailable':
				return __( 'The site answered 503 (temporarily unavailable), often a maintenance mode. Verify when the site is back, or exclude this path from maintenance mode.', 'sitelemetry-audit' );
			case 'server_error':
			case 'unexpected':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'The file address answered with HTTP %d instead of the file.', 'sitelemetry-audit' ), $status );
		}
		return isset( $test['message'] ) && is_string( $test['message'] ) ? $test['message'] : '';
	}

	/**
	 * An absolute URL for a Location header, or ''.
	 *
	 * @param string $base     URL that was requested.
	 * @param string $location Location header.
	 * @return string
	 */
	private static function resolve_location( $base, $location ) {
		if ( '' === $location ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
		$host   = wp_parse_url( $base, PHP_URL_HOST );
		if ( 0 === strpos( $location, '//' ) ) {
			return $scheme . ':' . $location;
		}
		if ( '/' === $location[0] ) {
			return $scheme . '://' . $host . $location;
		}
		return '';
	}

	/**
	 * Records the last self-test of the stored token: its code, HTTP status and
	 * detail, not the message, so the settings page writes the message in each
	 * viewer's language (test_message()), and whether it ran inside a one-click
	 * verification or a renewal (the panel explains a failed one through the
	 * outcome of that attempt instead).
	 *
	 * @param array $test    Result of test_file().
	 * @param bool  $attempt Whether the test ran inside a one-click verification or a renewal.
	 * @return void
	 */
	public static function remember_test( array $test, $attempt = false ) {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['token'] ) ) {
			return;
		}
		$state['last_test'] = array(
			'ok'      => ! empty( $test['ok'] ),
			'code'    => isset( $test['code'] ) ? (string) $test['code'] : '',
			'status'  => isset( $test['status'] ) && is_numeric( $test['status'] ) ? (int) $test['status'] : null,
			'detail'  => isset( $test['detail'] ) && is_scalar( $test['detail'] ) ? Sitelemetry_Audit_Outcome::clip( $test['detail'], 200 ) : '',
			'at'      => time(),
			'attempt' => (bool) $attempt,
		);
		self::persist( $state );
	}

	/**
	 * Whether the audited target is this site's host (the helper serves only that).
	 *
	 * @param string $target Target URL.
	 * @return bool
	 */
	public static function target_is_this_site( $target ) {
		$host = wp_parse_url( (string) $target, PHP_URL_HOST );
		return is_string( $host ) && '' !== self::home_host() && self::normalize_host( $host ) === self::home_host();
	}

	/**
	 * The verification call to action for a result, or null: when the service
	 * reported that verification is required or must be renewed, or listed
	 * security modules that run only on a verified site.
	 *
	 * helper is true when the plugin can publish the file for the target (it is
	 * this site's host) and the current user may do it; restricted is true when
	 * the target is this site's host but only a network administrator may.
	 * one_click is true when the helper can verify with one click (the service is
	 * known to have the verification API). renews is true when the plugin then
	 * renews the verification by itself: after a click, or for a verification it
	 * already manages, unless the admin stopped that with "Remove token"
	 * (remove_token()). verified is true when this site's host was verified after
	 * the result was produced: the call to action then only asks to run the audit
	 * again.
	 *
	 * Reads only stored state: no request leaves the site.
	 *
	 * @param array $model Result model.
	 * @return array|null { renew: bool, text: string, helper: bool, restricted: bool, host: string, one_click: bool, renews: bool, verified: bool }
	 */
	public static function call_to_action( array $model ) {
		$reason = isset( $model['reason'] ) ? $model['reason'] : '';
		$status = isset( $model['status'] ) ? $model['status'] : '';
		$gated  = 'verification_required' === $status && in_array( $reason, array( 'target_verification_required', 'target_reverification_required', 'verification_scope_required' ), true );
		$needs  = $gated;
		if ( ! $needs && isset( $model['not_measured'] ) && is_array( $model['not_measured'] ) ) {
			foreach ( $model['not_measured'] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['type'] ) && 'verification_modules' === $entry['type'] ) {
					$needs = true;
					break;
				}
			}
		}
		if ( ! $needs ) {
			return null;
		}
		$target    = isset( $model['target'] ) ? (string) $model['target'] : '';
		$own       = self::target_is_this_site( $target );
		$helper    = $own && self::current_user_can_publish();
		$host      = self::normalize_host( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		$renew     = 'target_reverification_required' === $reason;
		$one_click = $helper && 'on' === self::one_click_state( null, false );
		$stopped   = ! self::stored()['renew'];
		$verified  = $own ? self::verified_state() : null;
		$finished  = isset( $model['finished_at'] ) ? (int) $model['finished_at'] : 0;
		if ( null !== $verified && $verified['verified_at'] > 0 && $verified['verified_at'] >= $finished ) {
			$text = $verified['expires_at'] > 0
				/* translators: 1: host name, 2: date and time the verification expires. */
				? sprintf( __( 'Ownership of %1$s is verified (valid until %2$s). Run the audit again to include the checks that need verification.', 'sitelemetry-audit' ), $host, self::format_time( $verified['expires_at'] ) )
				/* translators: %s: host name. */
				: sprintf( __( 'Ownership of %s is verified. Run the audit again to include the checks that need verification.', 'sitelemetry-audit' ), $host );
			return array(
				'renew'      => false,
				'text'       => $text,
				'helper'     => $helper,
				'restricted' => $own && ! $helper,
				'host'       => $host,
				'one_click'  => $one_click,
				'renews'     => $one_click && ! $stopped,
				'verified'   => true,
			);
		}
		// A click on Verify this site ends a stop, so a first verification by one
		// click is renewed; an expired one is renewed by itself only without a stop.
		$renews = $one_click && ! ( $renew && $stopped );
		if ( $renew && $renews ) {
			/* translators: %s: host name. */
			$text = sprintf( __( 'The ownership verification of %s has expired. The plugin renews it automatically in the background; to renew it now, open Verify this site.', 'sitelemetry-audit' ), $host );
		} elseif ( $renew ) {
			/* translators: %s: host name. */
			$text = sprintf( __( 'The ownership verification of %s has expired. Verification lasts 30 days and is not renewed automatically: click Reverify in the app, then publish the new token and verify again.', 'sitelemetry-audit' ), $host );
		} elseif ( 'verification_scope_required' === $reason ) {
			/* translators: %s: host name. */
			$text = sprintf( __( 'The current verification of %s covers only part of the host. Verify the whole host (HTTP file or DNS record) to run the host-level checks.', 'sitelemetry-audit' ), $host );
		} elseif ( $gated ) {
			/* translators: %s: host name. */
			$text = sprintf( __( 'This audit runs only after ownership of %s is verified.', 'sitelemetry-audit' ), $host );
		} else {
			/* translators: %s: host name. */
			$text = sprintf( __( 'Some checks run only after ownership of %s is verified.', 'sitelemetry-audit' ), $host );
		}
		return array(
			'renew'      => $renew,
			'text'       => $text,
			'helper'     => $helper,
			'restricted' => $own && ! $helper,
			'host'       => $host,
			'one_click'  => $one_click,
			'renews'     => $renews,
			'verified'   => false,
		);
	}
}
