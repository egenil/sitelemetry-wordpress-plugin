<?php
/**
 * One-click verification tests: the verification API client, the click flow,
 * the fallback when the service has no such API, automatic renewal and the
 * settings panel, against an in-process mock of the service.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/view-stubs.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-admin.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-plugin.php';

/**
 * In-process stand-in for the verification API of the service (the contract of
 * GET /api/v1/verification/status, POST challenge and POST verify), and for this
 * WordPress site answering its own verification file. Verify "fetches" the file
 * through the same handler, so the served token is what gets compared.
 */
class Sitelemetry_Test_Verification_Service {

	/**
	 * Whether the API is switched on (AUDIT_VERIFICATION_API_ENABLED).
	 *
	 * @var bool
	 */
	public $enabled = true;

	/**
	 * Accepted key.
	 *
	 * @var string
	 */
	public $key = Sitelemetry_Test_Mock_Service::API_KEY;

	/**
	 * This site's host.
	 *
	 * @var string
	 */
	public $host = 'www.example.org';

	/**
	 * The Sitelemetry record of the host: null, or { status: pending|verified,
	 * token, verified_at, expires_at }.
	 *
	 * @var array|null
	 */
	public $record = null;

	/**
	 * Forced error answers per endpoint: endpoint => array( http, payload, headers ).
	 *
	 * @var array
	 */
	public $fail = array();

	/**
	 * A forced failure of the file check: array( reason, httpStatus|null ).
	 *
	 * @var array|null
	 */
	public $verify_failure = null;

	/**
	 * A different challenge URL to answer with (the plugin must refuse it).
	 *
	 * @var string|null
	 */
	public $challenge_url = null;

	/**
	 * Seconds the service's clock is behind this site's clock.
	 *
	 * @var int
	 */
	public $clock_behind = 0;

	/**
	 * Recorded requests: method, url, args.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Tokens issued so far.
	 *
	 * @var int
	 */
	private $issued = 0;

	/**
	 * Installs the service as the global HTTP handler (the plugin's own default
	 * transport and the self-test go through it).
	 *
	 * @return Sitelemetry_Test_Verification_Service
	 */
	public function install() {
		$GLOBALS['sitelemetry_test_http'] = array( $this, 'handle' );
		return $this;
	}

	/**
	 * A client of this service with the injected HTTP layer.
	 *
	 * @param string|null $key Key (default: the stored one).
	 * @return Sitelemetry_Audit_Verification_Api
	 */
	public function api( $key = null ) {
		return new Sitelemetry_Audit_Verification_Api( null === $key ? Sitelemetry_Audit_Settings::api_key() : $key, Sitelemetry_Audit_Settings::base_url(), array( $this, 'handle' ) );
	}

	/**
	 * A verified record (fresh unless $expires_at is in the past).
	 *
	 * @param int $expires_at Expiry.
	 * @param int $verified_at Verification time.
	 * @return void
	 */
	public function verified( $expires_at, $verified_at = null ) {
		$this->record = array(
			'status'      => 'verified',
			'token'       => null,
			'verified_at' => null === $verified_at ? $expires_at - 30 * 86400 : $verified_at,
			'expires_at'  => $expires_at,
		);
	}

	/**
	 * The API endpoints this test's requests reached, in order (status, challenge, verify).
	 *
	 * @return string[]
	 */
	public function endpoints() {
		$out = array();
		foreach ( $this->calls as $call ) {
			$path = (string) parse_url( $call['url'], PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( 0 === strpos( $path, '/api/v1/verification/' ) ) {
				$out[] = substr( $path, strlen( '/api/v1/verification/' ) );
			} elseif ( Sitelemetry_Audit_Verification::PATH === $path ) {
				$out[] = 'file';
			}
		}
		return $out;
	}

	/**
	 * Handles one request.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array|WP_Error
	 */
	public function handle( $method, $url, $args ) {
		$this->calls[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( $this->host === $host && Sitelemetry_Audit_Verification::PATH === $path ) {
			$file = Sitelemetry_Audit_Verification::response( $method, Sitelemetry_Audit_Verification::served_token() );
			return sitelemetry_test_response( $file['status'], $file['body'], $file['headers'] );
		}
		if ( 'sitelemetry.com' !== $host || 0 !== strpos( $path, '/api/v1/verification/' ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: ' . $host );
		}
		if ( ! $this->enabled ) {
			// As an unknown path of the service: the HTML 404 page, or a bare 405.
			return 'GET' === $method
				? sitelemetry_test_response( 404, '<!doctype html><title>Page not found</title>', array( 'content-type' => 'text/html; charset=utf-8' ) )
				: Sitelemetry_Test_Mock_Service::json( 405, array( 'error' => 'Method not allowed.' ) );
		}
		$headers = array_change_key_case( isset( $args['headers'] ) ? $args['headers'] : array(), CASE_LOWER );
		if ( isset( $headers['origin'] ) || isset( $headers['sec-fetch-site'] ) ) {
			return $this->error( 403, 'browser_origin_rejected' );
		}
		if ( ! isset( $headers['authorization'] ) || 'Bearer ' . $this->key !== $headers['authorization'] ) {
			return $this->error( 401, 'unauthorized', array(), array( 'www-authenticate' => 'Bearer realm="sitelemetry-verification"' ) );
		}
		$endpoint = substr( $path, strlen( '/api/v1/verification/' ) );
		if ( isset( $this->fail[ $endpoint ] ) ) {
			list( $http, $payload, $extra_headers ) = array_pad( $this->fail[ $endpoint ], 3, array() );
			return Sitelemetry_Test_Mock_Service::json( $http, $payload, $extra_headers );
		}
		switch ( $endpoint ) {
			case 'status':
				return 'GET' === $method ? Sitelemetry_Test_Mock_Service::json( 200, $this->view( false ) ) : $this->error( 405, 'method_not_allowed' );
			case 'challenge':
				return 'POST' === $method ? $this->challenge() : $this->error( 405, 'method_not_allowed' );
			case 'verify':
				return 'POST' === $method ? $this->verify() : $this->error( 405, 'method_not_allowed' );
		}
		return $this->error( 404, 'not_found' );
	}

	/**
	 * The state of the record.
	 *
	 * @return string
	 */
	private function state() {
		if ( null === $this->record ) {
			return 'unverified';
		}
		if ( 'pending' === $this->record['status'] ) {
			return 'pending';
		}
		return $this->record['expires_at'] > time() - $this->clock_behind ? 'verified' : 'stale';
	}

	/**
	 * An ISO 8601 date as the service writes it.
	 *
	 * @param int|null $time Timestamp.
	 * @return string|null
	 */
	private static function iso( $time ) {
		return null === $time ? null : gmdate( 'Y-m-d\TH:i:s.000\Z', (int) $time );
	}

	/**
	 * The view of the record.
	 *
	 * @param bool $with_challenge Whether to include the challenge.
	 * @return array
	 */
	private function view( $with_challenge ) {
		$state = $this->state();
		$view  = array(
			'ok'               => true,
			'hostname'         => $this->host,
			'status'           => $state,
			'source'           => null === $this->record ? null : 'sitelemetry',
			'method'           => 'verified' === $state || 'stale' === $state ? 'http' : null,
			'verifiedDomainId' => null === $this->record ? null : 'vdom_test',
			'verifiedAt'       => null !== $this->record && 'verified' === $this->record['status'] ? self::iso( $this->record['verified_at'] ) : null,
			'checkedAt'        => null,
			'expiresAt'        => null !== $this->record && 'verified' === $this->record['status'] ? self::iso( $this->record['expires_at'] ) : null,
			'nextAction'       => 'verified' === $state ? 'none' : ( 'pending' === $state ? 'publish_and_verify' : 'request_challenge' ),
		);
		if ( $with_challenge ) {
			$view['challenge'] = 'pending' === $state
				? array(
					'method'    => 'http',
					'url'       => null === $this->challenge_url ? 'https://' . $this->host . '/.well-known/sitelemetry-verification.txt' : $this->challenge_url,
					'token'     => $this->record['token'],
					'createdAt' => self::iso( time() ),
				)
				: null;
		}
		return $view;
	}

	/**
	 * A new token.
	 *
	 * @return string
	 */
	private function token() {
		return 'sitelemetry-' . md5( 'token-' . ( ++$this->issued ) );
	}

	/**
	 * POST challenge.
	 *
	 * @return array
	 */
	private function challenge() {
		$state   = $this->state();
		$created = false;
		$renewed = false;
		if ( 'unverified' === $state ) {
			$this->record = array(
				'status'      => 'pending',
				'token'       => $this->token(),
				'verified_at' => null,
				'expires_at'  => null,
			);
			$created      = true;
		} elseif ( 'stale' === $state ) {
			$this->record = array(
				'status'      => 'pending',
				'token'       => $this->token(),
				'verified_at' => null,
				'expires_at'  => null,
			);
			$renewed      = true;
		}
		return Sitelemetry_Test_Mock_Service::json(
			200,
			array_merge(
				$this->view( true ),
				array(
					'created' => $created,
					'renewed' => $renewed,
				)
			)
		);
	}

	/**
	 * POST verify: fetches the site's file through the same handler.
	 *
	 * @return array
	 */
	private function verify() {
		$state = $this->state();
		if ( 'verified' === $state ) {
			return Sitelemetry_Test_Mock_Service::json( 200, array_merge( $this->view( true ), array( 'alreadyVerified' => true ) ) );
		}
		if ( 'unverified' === $state ) {
			return $this->error( 404, 'challenge_not_found' );
		}
		if ( 'stale' === $state ) {
			return $this->error( 409, 'challenge_required', array( 'status' => 'stale' ) );
		}
		$failure = function ( $reason, $http_status = null ) {
			$extra = array(
				'reason'   => $reason,
				'hostname' => $this->host,
				'status'   => 'pending',
				'url'      => 'https://' . $this->host . '/.well-known/sitelemetry-verification.txt',
			);
			if ( null !== $http_status ) {
				$extra['httpStatus'] = $http_status;
			}
			return $this->error( 409, 'verification_failed', $extra );
		};
		if ( null !== $this->verify_failure ) {
			return $failure( $this->verify_failure[0], isset( $this->verify_failure[1] ) ? $this->verify_failure[1] : null );
		}
		$file = $this->handle( 'GET', 'https://' . $this->host . Sitelemetry_Audit_Verification::PATH, array() );
		$code = (int) wp_remote_retrieve_response_code( $file );
		if ( 200 !== $code ) {
			return $failure( 'http_status', $code );
		}
		if ( trim( (string) wp_remote_retrieve_body( $file ) ) !== $this->record['token'] ) {
			return $failure( 'body_mismatch' );
		}
		$this->record = array(
			'status'      => 'verified',
			'token'       => null,
			'verified_at' => time(),
			'expires_at'  => time() + 30 * 86400,
		);
		return Sitelemetry_Test_Mock_Service::json( 200, array_merge( $this->view( true ), array( 'alreadyVerified' => false ) ) );
	}

	/**
	 * An error answer in the service's envelope.
	 *
	 * @param int    $http    Status.
	 * @param string $code    Code.
	 * @param array  $extra   Extra fields.
	 * @param array  $headers Headers.
	 * @return array
	 */
	public function error( $http, $code, array $extra = array(), array $headers = array() ) {
		return Sitelemetry_Test_Mock_Service::json(
			$http,
			array_merge(
				array(
					'ok'    => false,
					'error' => 'Error ' . $code . '.',
					'code'  => $code,
				),
				$extra
			),
			$headers
		);
	}
}

/**
 * Sitelemetry_Audit_Verification_Api and the one-click parts of Sitelemetry_Audit_Verification.
 */
class Sitelemetry_Audit_One_Click_Test extends TestCase {

	/**
	 * This site is https://www.example.org with a stored key.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
		$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';
		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ) );
	}

	/**
	 * A mock service installed as the HTTP handler.
	 *
	 * @return Sitelemetry_Test_Verification_Service
	 */
	private function service() {
		return ( new Sitelemetry_Test_Verification_Service() )->install();
	}

	/**
	 * Renders a view; any notice fails the test.
	 *
	 * @param string $file View file.
	 * @param array  $view View data.
	 * @return string
	 */
	private function render( $file, array $view ) {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			function ( $severity, $message, $filename, $line ) {
				throw new ErrorException( $message, 0, $severity, $filename, $line );
			}
		);
		ob_start();
		try {
			require SITELEMETRY_AUDIT_DIR . 'admin/views/' . $file;
		} finally {
			$html = ob_get_clean();
			restore_error_handler();
		}
		return $html;
	}

	/**
	 * The settings page as the admin builds it.
	 *
	 * @param Sitelemetry_Audit_Verification_Api|null $api         Client.
	 * @param bool                                    $token_error Rejected token.
	 * @return string
	 */
	private function settings_page( $api = null, $token_error = false ) {
		$settings = Sitelemetry_Audit_Settings::get();
		return $this->render(
			'settings.php',
			array(
				'settings'     => $settings,
				'has_key'      => '' !== $settings['api_key'],
				'masked_key'   => Sitelemetry_Audit_Settings::mask_key( $settings['api_key'] ),
				'kinds'        => array( 'security' => 'Security (included in Free)' ),
				'weekly_next'  => false,
				'job'          => null,
				'run_action'   => admin_url( 'admin-post.php' ),
				'results_url'  => Sitelemetry_Audit_Admin::results_url(),
				'app_url'      => Sitelemetry_Audit_Links::app_url(),
				'sign_in_url'  => Sitelemetry_Audit_Links::sign_in_url(),
				'api_key_url'  => Sitelemetry_Audit_Links::api_key_url(),
				'pricing_url'  => Sitelemetry_Audit_Links::pricing_url(),
				'option_name'  => Sitelemetry_Audit_Settings::OPTION,
				'option_group' => Sitelemetry_Audit_Settings::OPTION_GROUP,
				'verification' => Sitelemetry_Audit_Admin::verification_view( $settings, $token_error, $api ),
			)
		);
	}

	/**
	 * A result of this site that needs the verification renewed.
	 *
	 * @param string $reason   Reason.
	 * @param int    $finished When it finished.
	 * @return array
	 */
	private function gated_model( $reason, $finished = 1700000000 ) {
		$model                = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://www.example.org/' );
		$model['status']      = 'verification_required';
		$model['reason']      = $reason;
		$model['finished_at'] = $finished;
		return $model;
	}

	/**
	 * The HTTP layer is interpreted without a network: JSON answers, error codes
	 * with Retry-After, an unknown path (HTML 404, bare 405) and failures.
	 *
	 * @return void
	 */
	public function test_api_interprets_answers() {
		$ok = Sitelemetry_Audit_Verification_Api::interpret( Sitelemetry_Test_Mock_Service::json( 200, array( 'ok' => true, 'status' => 'verified' ) ) );
		$this->assertSame( 'verified', $ok['status'] );

		$limited = Sitelemetry_Audit_Verification_Api::interpret( Sitelemetry_Test_Mock_Service::json( 429, array( 'ok' => false, 'code' => 'rate_limited', 'error' => 'Too many.' ), array( 'retry-after' => '42' ) ) );
		$this->assertSame( Sitelemetry_Audit_Verification_Api::ERROR_API, $limited->get_error_code() );
		$data = Sitelemetry_Audit_Verification_Api::error_data( $limited );
		$this->assertSame( array( 429, 'rate_limited', 42 ), array( $data['http'], $data['code'], $data['retry_after'] ) );

		$failed = Sitelemetry_Audit_Verification_Api::interpret( Sitelemetry_Test_Mock_Service::json( 409, array( 'ok' => false, 'code' => 'verification_failed', 'reason' => 'http_status', 'httpStatus' => 404, 'error' => 'x' ) ) );
		$data   = Sitelemetry_Audit_Verification_Api::error_data( $failed );
		$this->assertSame( array( 'verification_failed', 'http_status', 404 ), array( $data['code'], $data['reason'], $data['http_status'] ) );

		// The service's maintenance guard keeps its upper-case code; it is normalized.
		$maintenance = Sitelemetry_Audit_Verification_Api::interpret( Sitelemetry_Test_Mock_Service::json( 503, array( 'code' => 'DEPLOYMENT_MAINTENANCE', 'error' => 'Maintenance.' ) ) );
		$this->assertSame( 'deployment_maintenance', Sitelemetry_Audit_Verification_Api::error_data( $maintenance )['code'] );

		// Answers like an unknown path: the API is not there.
		foreach ( array(
			sitelemetry_test_response( 404, '<!doctype html><title>Not found</title>', array( 'content-type' => 'text/html' ) ),
			Sitelemetry_Test_Mock_Service::json( 405, array( 'error' => 'Method not allowed.' ) ),
			sitelemetry_test_response( 200, '<!doctype html><title>App</title>', array( 'content-type' => 'text/html' ) ),
		) as $response ) {
			$this->assertSame( Sitelemetry_Audit_Verification_Api::ERROR_OFF, Sitelemetry_Audit_Verification_Api::interpret( $response )->get_error_code() );
		}

		// A proxy error without the service's envelope, and a network failure.
		$proxy = Sitelemetry_Audit_Verification_Api::interpret( sitelemetry_test_response( 502, '<html>Bad gateway</html>' ) );
		$this->assertSame( 'http_502', Sitelemetry_Audit_Verification_Api::error_data( $proxy )['code'] );
		$transport = Sitelemetry_Audit_Verification_Api::interpret( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
		$this->assertSame( Sitelemetry_Audit_Verification_Api::ERROR_TRANSPORT, $transport->get_error_code() );
	}

	/**
	 * Server-to-server requests: the three routes, the key as a Bearer token, no
	 * browser headers, no redirects followed, and only the target sent.
	 *
	 * @return void
	 */
	public function test_requests_are_server_to_server() {
		$service = $this->service();
		$api     = $service->api();
		$api->status( 'https://www.example.org/' );
		$api->challenge( 'https://www.example.org/' );
		$api->verify( 'https://www.example.org/' );
		$this->assertCount( 4, $service->calls, 'status, challenge, verify and the file the verify step fetched' );
		$this->assertSame( array( 'GET', 'https://sitelemetry.com/api/v1/verification/status?target=https%3A%2F%2Fwww.example.org%2F' ), array( $service->calls[0]['method'], $service->calls[0]['url'] ) );
		$this->assertSame( array( 'POST', 'https://sitelemetry.com/api/v1/verification/challenge' ), array( $service->calls[1]['method'], $service->calls[1]['url'] ) );
		$this->assertSame( array( 'POST', 'https://sitelemetry.com/api/v1/verification/verify' ), array( $service->calls[2]['method'], $service->calls[2]['url'] ) );
		foreach ( array( 0, 1, 2 ) as $index ) {
			$args    = $service->calls[ $index ]['args'];
			$headers = array_change_key_case( $args['headers'], CASE_LOWER );
			$this->assertSame( 'Bearer ' . Sitelemetry_Test_Mock_Service::API_KEY, $headers['authorization'] );
			$this->assertArrayNotHasKey( 'origin', $headers );
			$this->assertArrayNotHasKey( 'sec-fetch-site', $headers );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertSame( 'sitelemetry-audit-wordpress/' . SITELEMETRY_AUDIT_VERSION, $args['user-agent'] );
		}
		$this->assertSame( array( 'target' => 'https://www.example.org/' ), json_decode( $service->calls[1]['args']['body'], true ) );
		$this->assertSame( array( 'target' => 'https://www.example.org/' ), json_decode( $service->calls[2]['args']['body'], true ) );

		// The target is the scheme and host of the site address, without port or path.
		$this->assertSame( 'https://www.example.org/', Sitelemetry_Audit_Verification::target_url( 'https://WWW.example.org:8443/blog/' ) );
		$this->assertSame( 'http://plain.example/', Sitelemetry_Audit_Verification::target_url( 'http://plain.example' ) );
	}

	/**
	 * Without an API key the Verify button is shown disabled with a hint, and no
	 * request leaves the site; once a key is saved it is enabled.
	 *
	 * @return void
	 */
	public function test_button_disabled_without_key() {
		$service = $this->service();
		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => '' ) );
		$this->assertSame( 'no_key', Sitelemetry_Audit_Verification::one_click_state() );
		$html = $this->settings_page();
		$this->assertCount( 0, $service->calls, 'no request before a key is stored' );
		$this->assertMatchesRegularExpression( '/<input type="submit" name="sitelemetry_audit_one_click" class="button button-primary" value="Verify this site" aria-describedby="sitelemetry-audit-one-click-hint" disabled="disabled">/', $html );
		$this->assertStringContainsString( 'Save your API key above to verify this site with one click.', $html );
		// Onboarding: one button to the app's sign-in page (where new users switch
		// to Create account), and a link to the page with the key.
		$this->assertStringContainsString( '<a class="button button-primary" href="https://sitelemetry.com/app?utm_source=wordpress-plugin&amp;utm_medium=plugin" target="_blank" rel="noopener noreferrer">Sign in or create an account</a>', $html );
		$this->assertStringContainsString( '<a class="sitelemetry-audit-api-key-link" href="https://sitelemetry.com/app?view=account&amp;utm_source=wordpress-plugin&amp;utm_medium=plugin" target="_blank" rel="noopener noreferrer">Get your API key</a>', $html );
		$this->assertStringContainsString( 'Sitelemetry has free and paid plans', $html );
		$this->assertSame( 1, substr_count( $html, 'class="button button-primary" href="https://sitelemetry.com/' ), 'one onboarding button' );
		$this->assertStringNotContainsString( 'plan=free', $html );
		$this->assertStringNotContainsString( 'Create a free account', $html );
		// The manual helper is shown as before, not collapsed.
		$this->assertStringNotContainsString( '<details class="sitelemetry-audit-manual"', $html );
		$this->assertStringContainsString( 'value="sitelemetry_audit_verification_save"', $html );

		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ) );
		$html = $this->settings_page( $service->api() );
		$this->assertSame( array( 'status' ), $service->endpoints(), 'one read-only probe' );
		$this->assertMatchesRegularExpression( '/<input type="submit" name="sitelemetry_audit_one_click" class="button button-primary" value="Verify this site" aria-describedby="sitelemetry-audit-one-click-hint">/', $html );
		$this->assertStringContainsString( 'value="sitelemetry_audit_verification_one_click"', $html );
		$this->assertStringContainsString( 'publishes it at <code>https://www.example.org/.well-known/sitelemetry-verification.txt</code>', $html );
		$this->assertStringNotContainsString( 'Sign in or create an account', $html );
		$this->assertStringContainsString( 'href="https://sitelemetry.com/app?view=account&amp;utm_source=wordpress-plugin&amp;utm_medium=plugin"', $html );
		// The manual helper stays available, collapsed.
		$this->assertStringContainsString( '<details class="sitelemetry-audit-manual">', $html );
		$this->assertStringContainsString( 'value="sitelemetry_audit_verification_save"', $html );
		$this->assertStringContainsString( 'The plugin renews it automatically in the background (WP-Cron)', $html );
	}

	/**
	 * The onboarding links are exact. "Sign in or create an account" opens the app
	 * with the plugin's UTM parameters and no plan parameter, so the app shows its
	 * sign-in screen with the Create account tab (the ordinary sign-up with the
	 * plan choice). It never uses the app's neutral free-only entry, which is
	 * exactly /app?plan=free with no other parameter (reserved for the AI-directory
	 * sign-ups). ?view=account opens the view with the key.
	 *
	 * @return void
	 */
	public function test_onboarding_links() {
		$sign_in = Sitelemetry_Audit_Links::sign_in_url();
		$this->assertSame( 'https://sitelemetry.com/app?utm_source=wordpress-plugin&utm_medium=plugin', $sign_in );
		$this->assertSame( 'https://sitelemetry.com/app?view=account&utm_source=wordpress-plugin&utm_medium=plugin', Sitelemetry_Audit_Links::api_key_url() );
		// The same UTM parameters as the pricing link (one constant).
		$this->assertSame( 'utm_source=' . Sitelemetry_Audit_Links::UTM_SOURCE . '&utm_medium=' . Sitelemetry_Audit_Links::UTM_MEDIUM, Sitelemetry_Audit_Links::utm_query() );
		$this->assertStringEndsWith( '?' . Sitelemetry_Audit_Links::utm_query(), Sitelemetry_Audit_Links::pricing_url() );
		foreach ( array( $sign_in, Sitelemetry_Audit_Links::api_key_url() ) as $url ) {
			$query = array();
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertSame( '/app', wp_parse_url( $url, PHP_URL_PATH ) );
			$this->assertArrayNotHasKey( 'plan', $query, 'no plan parameter: the app opens its sign-in screen' );
			// The app's neutral free-only entry needs exactly one parameter, plan=free.
			$this->assertFalse( array( 'plan' => 'free' ) === $query );
		}
		$query = array();
		parse_str( (string) wp_parse_url( $sign_in, PHP_URL_QUERY ), $query );
		$this->assertSame( array( 'utm_source' => 'wordpress-plugin', 'utm_medium' => 'plugin' ), $query );
	}

	/**
	 * Already verified and fresh: only the status is asked, nothing is published,
	 * and the panel says "Verification complete ✓ valid until <date>".
	 *
	 * @return void
	 */
	public function test_status_fresh() {
		$service = $this->service();
		$expires = gmmktime( 12, 0, 0, 10, 20, 2030 );
		$service->verified( $expires, time() - 3600 );
		$result = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertSame( array( true, 'already_verified' ), array( $result['ok'], $result['code'] ) );
		$this->assertSame( array( 'status' ), $service->endpoints() );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token(), 'nothing is published' );
		$this->assertSame( 'Verification complete ✓ valid until 2030-10-20 12:00', Sitelemetry_Audit_Verification::complete_message( Sitelemetry_Audit_Verification::verified_state()['expires_at'] ) );
		$this->assertSame( $expires + 60, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ), 'renewal after the expiry' );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'notice notice-success inline sitelemetry-audit-verified" role="status"><p>Verification complete ✓ valid until 2030-10-20 12:00</p>', $html );
		$this->assertStringNotContainsString( 'The verification did not complete', $html );
	}

	/**
	 * Challenge and verify: the token of exactly this host is stored and served,
	 * the self-test runs, Sitelemetry verifies, the next renewal is scheduled and
	 * the calls to action of earlier results turn into "verified, run again".
	 *
	 * @return void
	 */
	public function test_challenge_and_verify_success() {
		$service = $this->service();
		Sitelemetry_Audit_Results::store(
			'security',
			array_merge( $this->gated_model( 'target_verification_required', time() - 60 ), array( 'origin' => 'scheduled' ) )
		);
		$result = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertSame( array( true, 'verified' ), array( $result['ok'], $result['code'] ) );
		$this->assertSame( array( 'status', 'challenge', 'file', 'verify', 'file' ), $service->endpoints(), 'status, challenge, self-test, verify (which fetches the file)' );

		$stored = Sitelemetry_Audit_Verification::stored();
		$this->assertSame( 'sitelemetry-' . md5( 'token-1' ), $stored['token'] );
		$this->assertSame( array( 'www.example.org', 'api', true ), array( $stored['host'], $stored['source'], $stored['authorized'] ) );
		$this->assertTrue( $stored['last_test']['ok'], 'the self-test passed' );
		$this->assertSame( $stored['token'], Sitelemetry_Audit_Verification::served_token() );

		$verified = Sitelemetry_Audit_Verification::verified_state();
		$this->assertGreaterThan( time() + 29 * 86400, $verified['expires_at'] );
		$this->assertSame( $verified['expires_at'] + 60, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertStringStartsWith( 'Verification complete ✓ valid until ', Sitelemetry_Audit_Verification::one_click_message( Sitelemetry_Audit_Verification::api_state()['last'] ) );

		// The call to action of the earlier result is refreshed, and the weekly notice is gone.
		$cta = Sitelemetry_Audit_Verification::call_to_action( Sitelemetry_Audit_Results::latest() );
		$this->assertTrue( $cta['verified'] );
		$this->assertStringStartsWith( 'Ownership of www.example.org is verified (valid until ', $cta['text'] );
		$this->assertNull( Sitelemetry_Audit_Admin::weekly_notice_subject() );

		// The panel: complete, and the self-test of a token the plugin got itself
		// does not send the admin to the app.
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'sitelemetry-audit-verified', $html );
		$this->assertStringContainsString( 'The file works: WordPress loaded it from its own address with the stored token.', $html );
		$this->assertStringNotContainsString( 'Now click Verify HTTP', $html );
		$this->assertStringContainsString( '<details class="sitelemetry-audit-manual">', $html, 'collapsed: the token came from the API' );
	}

	/**
	 * A challenge for another host or path is never published.
	 *
	 * @return void
	 */
	public function test_challenge_for_another_host_is_refused() {
		foreach ( array( 'https://example.org/.well-known/sitelemetry-verification.txt', 'https://www.example.org/.well-known/other.txt', 'ftp://www.example.org/.well-known/sitelemetry-verification.txt' ) as $url ) {
			sitelemetry_test_reset();
			$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';
			update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ) );
			$service                = $this->service();
			$service->challenge_url = $url;
			$result                 = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
			$this->assertSame( array( false, 'challenge_mismatch' ), array( $result['ok'], $result['code'] ), $url );
			$this->assertSame( '', Sitelemetry_Audit_Verification::served_token(), $url );
			$this->assertSame( array( 'status', 'challenge' ), $service->endpoints(), 'no verify: ' . $url );
		}
		$this->assertStringContainsString( 'for another host (example.org)', Sitelemetry_Audit_Verification::one_click_message( array( 'code' => 'challenge_mismatch', 'detail' => 'example.org' ) ) );
	}

	/**
	 * Every failure is stored as a code and explained with its fix, and the panel
	 * keeps the DNS record and Search Console as alternatives.
	 *
	 * @return void
	 */
	public function test_failure_codes() {
		$reasons = array(
			array( 'http_status', 404, 'the site answered HTTP 404. The web server most likely handles /.well-known/ itself' ),
			array( 'http_status', 403, 'the site answered HTTP 403. A firewall, a security plugin or password protection blocks the request' ),
			array( 'http_status', 500, 'the site answered HTTP 500 instead of the file' ),
			array( 'redirect', null, 'The usual cause is a redirect between www and non-www' ),
			array( 'body_mismatch', null, 'it did not contain exactly the token' ),
			array( 'timeout', null, 'did not answer Sitelemetry in time (10 seconds)' ),
			array( 'not_public', null, 'does not resolve to a public address' ),
			array( 'dns', null, 'could not resolve the host name of this site' ),
			array( 'tls', null, 'could not validate the TLS certificate' ),
			array( 'body_too_large', null, 'more than 2 KB' ),
			array( 'connection', null, 'could not connect to this site' ),
			array( 'challenge_changed', null, 'The verification code changed while Sitelemetry was checking it' ),
		);
		foreach ( $reasons as $case ) {
			list( $reason, $http_status, $expected ) = $case;
			sitelemetry_test_reset();
			$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';
			update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ) );
			$service                 = $this->service();
			$service->verify_failure = array( $reason, $http_status );
			$result                  = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
			$this->assertSame( array( false, 'verification_failed', $reason ), array( $result['ok'], $result['code'], $result['reason'] ), $reason );
			$last = Sitelemetry_Audit_Verification::api_state()['last'];
			$this->assertSame( array( 'verification_failed', $reason, 'manual' ), array( $last['code'], $last['reason'], $last['origin'] ) );
			$this->assertStringContainsString( $expected, Sitelemetry_Audit_Verification::one_click_message( $last ), $reason );
			$this->assertNull( Sitelemetry_Audit_Verification::verified_state() );
			$this->assertFalse( wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ), 'a failed click schedules nothing' );
		}

		// The panel explains the last failure, with the alternatives.
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'notice notice-error inline sitelemetry-audit-one-click-result" role="alert"', $html );
		$this->assertStringContainsString( '<strong>The verification did not complete.</strong>', $html );
		$this->assertStringContainsString( 'The verification code changed while Sitelemetry was checking it', $html );
		$this->assertStringContainsString( 'You can also verify with a DNS record or Google Search Console', $html );
		$this->assertStringContainsString( '_sitelemetry-challenge.www.example.org', $html );

		// Error codes of the API itself.
		$codes = array(
			array( 'status', 429, array( 'code' => 'rate_limited', 'retryAfter' => 120 ), 'Too many verification attempts. Try again in 2 mins.' ),
			array( 'verify', 429, array( 'code' => 'verification_in_progress', 'retryAfter' => 5 ), 'A verification for this account is already running.' ),
			array( 'status', 401, array( 'code' => 'unauthorized' ), 'Sitelemetry did not accept the API key.' ),
			array( 'challenge', 400, array( 'code' => 'invalid_target' ), 'Sitelemetry verifies only public host names' ),
			array( 'challenge', 409, array( 'code' => 'pending_limit', 'limit' => 10 ), 'the maximum number of unfinished verifications (10)' ),
			array( 'challenge', 503, array( 'code' => 'verifier_unavailable' ), 'One-click verification is not available on Sitelemetry right now.' ),
			array( 'status', 503, array( 'code' => 'api_key_unavailable' ), 'One-click verification is not available on Sitelemetry right now.' ),
			array( 'verify', 503, array( 'code' => 'persistence_unavailable' ), 'Sitelemetry could not complete the verification (persistence_unavailable).' ),
		);
		foreach ( $codes as $case ) {
			list( $endpoint, $http, $payload, $expected ) = $case;
			sitelemetry_test_reset();
			$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';
			update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ) );
			$service                    = $this->service();
			$service->fail[ $endpoint ] = array( $http, array_merge( array( 'ok' => false, 'error' => 'x' ), $payload ) );
			$result                     = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
			$this->assertSame( array( false, $payload['code'] ), array( $result['ok'], $result['code'] ), $payload['code'] );
			$this->assertStringContainsString( $expected, Sitelemetry_Audit_Verification::one_click_message( Sitelemetry_Audit_Verification::api_state()['last'] ), $payload['code'] );
		}
		// An invalid or revoked key links to the page with the key.
		$service                 = $this->service();
		$service->fail['status'] = array( 401, array( 'ok' => false, 'code' => 'unauthorized', 'error' => 'Unauthorized.' ) );
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$html = $this->settings_page( $service->api() );
		$this->assertMatchesRegularExpression( '/Sitelemetry did not accept the API key\..*<a href="https:\/\/sitelemetry\.com\/app\?view=account&amp;utm_source=wordpress-plugin&amp;utm_medium=plugin" target="_blank" rel="noopener noreferrer">Get your API key<\/a>/s', $html );

		// Transport and proxy errors.
		$api = new Sitelemetry_Audit_Verification_Api(
			Sitelemetry_Test_Mock_Service::API_KEY,
			'https://sitelemetry.com',
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
		);
		$result = Sitelemetry_Audit_Verification::request_one_click( $api );
		$this->assertSame( 'transport', $result['code'] );
		$this->assertStringContainsString( 'WordPress could not reach Sitelemetry (cURL error 28: Operation timed out).', Sitelemetry_Audit_Verification::one_click_message( Sitelemetry_Audit_Verification::api_state()['last'] ) );
		$this->assertSame( 'unknown', get_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT )['state'], 'an unreachable service is not taken for a missing API' );
		$proxy = new Sitelemetry_Audit_Verification_Api(
			Sitelemetry_Test_Mock_Service::API_KEY,
			'https://sitelemetry.com',
			function () {
				return sitelemetry_test_response( 502, '<html>Bad gateway</html>' );
			}
		);
		Sitelemetry_Audit_Verification::request_one_click( $proxy );
		$this->assertStringContainsString( 'Sitelemetry could not complete the verification (HTTP 502).', Sitelemetry_Audit_Verification::one_click_message( Sitelemetry_Audit_Verification::api_state()['last'] ) );

		// Messages are translated by code, in the viewer's language.
		$GLOBALS['sitelemetry_test_translations'] = array( 'Sitelemetry loaded the verification file, but it did not contain exactly the token. A cached copy, another file at that path or a page from a cache, maintenance or security plugin answered instead. Purge the cache, remove the other file or exclude /.well-known/sitelemetry-verification.txt, then try again.' => 'Çeviri' );
		$this->assertSame(
			'Çeviri',
			Sitelemetry_Audit_Verification::one_click_message(
				array(
					'code'   => 'verification_failed',
					'reason' => 'body_mismatch',
				)
			)
		);
	}

	/**
	 * A click on a verified site whose status request fails (here the key was
	 * revoked): the panel shows the reason and the link to the key, as the notice
	 * after the click promises, instead of the verification it saw before. A later
	 * answer of the service replaces the failure again.
	 *
	 * @return void
	 */
	public function test_failed_click_on_a_verified_site_shows_the_reason() {
		$service = $this->service();
		$service->verified( gmmktime( 12, 0, 0, 10, 20, 2030 ), time() - 3600 );
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertNotNull( Sitelemetry_Audit_Verification::verified_state() );

		// The password was changed in the app: the stored key is no longer accepted.
		$service->key = 'sl_new_key_after_password_change';
		$result       = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertSame( array( false, 'unauthorized' ), array( $result['ok'], $result['code'] ) );
		$html = $this->settings_page( $service->api() );
		$this->assertStringNotContainsString( 'sitelemetry-audit-verified', $html, 'the earlier verification is not confirmed by this key' );
		$this->assertStringContainsString( '<strong>The verification did not complete.</strong>', $html );
		$this->assertMatchesRegularExpression( '/Sitelemetry did not accept the API key\..*<a href="https:\/\/sitelemetry\.com\/app\?view=account&amp;utm_source=wordpress-plugin&amp;utm_medium=plugin" target="_blank" rel="noopener noreferrer">Get your API key<\/a>/s', $html );

		// The same for a service that cannot be reached.
		$unreachable = new Sitelemetry_Audit_Verification_Api(
			Sitelemetry_Test_Mock_Service::API_KEY,
			'https://sitelemetry.com',
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
		);
		$service->key = Sitelemetry_Test_Mock_Service::API_KEY;
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertStringContainsString( 'sitelemetry-audit-verified', $this->settings_page( $service->api() ), 'verified again with the accepted key' );
		Sitelemetry_Audit_Verification::request_one_click( $unreachable );
		$html = $this->settings_page( $service->api() );
		$this->assertStringNotContainsString( 'sitelemetry-audit-verified', $html );
		$this->assertStringContainsString( 'WordPress could not reach Sitelemetry (cURL error 7: Failed to connect).', $html );

		// A later answer of the service (the next probe) replaces the failure.
		$state               = get_option( Sitelemetry_Audit_Verification::API_OPTION );
		$state['last']['at'] = time() - 10;
		$state['checked_at'] = time() - 20;
		update_option( Sitelemetry_Audit_Verification::API_OPTION, $state );
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'Verification complete ✓ valid until 2030-10-20 12:00', $html );
		$this->assertStringNotContainsString( 'The verification did not complete.', $html );
	}

	/**
	 * A site address that is no public host name is refused before any request.
	 *
	 * @return void
	 */
	public function test_non_public_site_address() {
		$service                          = $this->service();
		$GLOBALS['sitelemetry_test_home'] = 'http://127.0.0.1:8080';
		$result                           = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertSame( 'invalid_target', $result['code'] );
		$this->assertCount( 0, $service->calls );
	}

	/**
	 * The service answers like an unknown path (API off or not deployed): the
	 * one-click path is hidden, a neutral note is shown, the manual helper stays
	 * exactly as before, and the detection is cached briefly per key.
	 *
	 * @return void
	 */
	public function test_feature_off_fallback() {
		$service          = $this->service();
		$service->enabled = false;
		$api              = $service->api();

		$this->assertSame( 'off', Sitelemetry_Audit_Verification::one_click_state( $api ) );
		$this->assertCount( 1, $service->calls );
		$this->assertSame( 'off', Sitelemetry_Audit_Verification::one_click_state( $api ) );
		$this->assertCount( 1, $service->calls, 'cached: no second probe' );
		$this->assertSame( 3600, $GLOBALS['sitelemetry_test_transients'][ Sitelemetry_Audit_Verification::PROBE_TRANSIENT ]['expires'] - time() );
		$this->assertFalse( Sitelemetry_Audit_Verification::one_click_available( $api ) );

		$html = $this->settings_page( $api );
		$this->assertStringContainsString( 'One-click verification is not available yet. Verify with a token from the app as described below.', $html );
		$this->assertStringNotContainsString( 'name="sitelemetry_audit_one_click"', $html );
		$this->assertStringNotContainsString( '<details', $html );
		$this->assertStringNotContainsString( 'notice-error', $html, 'no error beyond the neutral note' );
		$this->assertStringContainsString( 'value="sitelemetry_audit_verification_save"', $html );
		$this->assertStringContainsString( 'Sitelemetry does not re-check it by itself', $html );

		// A click (for example from a page loaded before) records no failure.
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		$result = Sitelemetry_Audit_Verification::request_one_click( $api );
		$this->assertSame( array( false, 'off' ), array( $result['ok'], $result['code'] ) );
		$this->assertNull( Sitelemetry_Audit_Verification::api_state()['last'] );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );

		// Another key is probed again; the API is back.
		$service->enabled = true;
		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => 'sl_other_key_987654' ) );
		$service->key = 'sl_other_key_987654';
		$this->assertSame( 'on', Sitelemetry_Audit_Verification::one_click_state( $service->api() ) );
		$this->assertSame( 604800, $GLOBALS['sitelemetry_test_transients'][ Sitelemetry_Audit_Verification::PROBE_TRANSIENT ]['expires'] - time() );

		// No probe outside the settings page: the cache only.
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		$before = count( $service->calls );
		$this->assertSame( 'unknown', Sitelemetry_Audit_Verification::one_click_state( $service->api(), false ) );
		$this->assertCount( $before, $service->calls );
	}

	/**
	 * Automatic renewal of an expired verification in WP-Cron: status stale,
	 * challenge (the service renews the record), publish, verify; the next
	 * renewal is scheduled and no notice is shown.
	 *
	 * @return void
	 */
	public function test_stale_auto_renew() {
		$service = $this->service();
		$service->verified( time() - 60 );
		$GLOBALS['sitelemetry_test_context'] = 'cron';
		$GLOBALS['sitelemetry_test_caps']    = array();

		// The default client goes through the WordPress HTTP API (the cron path).
		Sitelemetry_Audit_Verification::run_scheduled_renewal();
		$this->assertSame( array( 'status', 'challenge', 'file', 'verify', 'file' ), $service->endpoints() );
		$state = Sitelemetry_Audit_Verification::api_state();
		$this->assertSame( array( true, 'verified', 'renewal' ), array( $state['last']['ok'], $state['last']['code'], $state['last']['origin'] ) );
		$this->assertFalse( $state['renewing'] );
		$this->assertSame( 'verified', $service->record['status'] );
		$this->assertSame( 'sitelemetry-' . md5( 'token-1' ), Sitelemetry_Audit_Verification::served_token() );
		$this->assertSame( $state['expires_at'] + 60, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertNull( Sitelemetry_Audit_Verification::renewal_failure() );
	}

	/**
	 * A fresh verification is never rotated: the renewal only asks for the status
	 * and schedules itself for the expiry.
	 *
	 * @return void
	 */
	public function test_never_rotate_fresh() {
		$service = $this->service();
		$expires = time() + 5 * 86400;
		$service->verified( $expires );
		$this->assertSame( 'fresh', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertSame( array( 'status' ), $service->endpoints() );
		$this->assertSame( $expires + 60, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );

		// This site's clock is past the expiry, the service's is not: still fresh
		// for the service, so nothing is rotated, and the next attempt waits
		// QUICK_RETRY_DELAY instead of running on every WP-Cron call.
		$service->verified( time() - 300 );
		$service->clock_behind = 600;
		$service->calls        = array();
		$this->assertSame( 'fresh', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertSame( array( 'status' ), $service->endpoints() );
		$this->assertSame( time() + Sitelemetry_Audit_Verification::QUICK_RETRY_DELAY, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );
		$this->assertSame( $expires + 60, Sitelemetry_Audit_Verification::renewal_time( $expires ) );
		$service->clock_behind = 0;

		// Never verified, or a pending verification someone else started: the
		// admin verifies with a click; the renewal does not publish anything.
		$service->record = null;
		$service->calls  = array();
		$this->assertSame( 'skipped', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertSame( array( 'status' ), $service->endpoints() );
		$service->record = array(
			'status'      => 'pending',
			'token'       => 'sitelemetry-' . str_repeat( 'p', 32 ),
			'verified_at' => null,
			'expires_at'  => null,
		);
		$service->calls  = array();
		$this->assertSame( 'skipped', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertSame( array( 'status' ), $service->endpoints() );
	}

	/**
	 * What starts a renewal: an audit of this site reporting
	 * target_reverification_required, a status probe reporting stale, and the
	 * weekly audit when the verification is known to have expired. Not: other
	 * reasons, other hosts, a service without the API, or the filter.
	 *
	 * @return void
	 */
	public function test_renewal_triggers() {
		$hook = Sitelemetry_Audit_Verification::RENEW_HOOK;
		Sitelemetry_Audit_Verification::on_audit_finished( $this->gated_model( 'target_reverification_required' ) );
		$this->assertSame( time(), wp_next_scheduled( $hook ) );

		foreach ( array(
			$this->gated_model( 'target_verification_required' ),
			array_merge( $this->gated_model( 'target_reverification_required' ), array( 'target' => 'https://example.org/' ) ),
			null,
		) as $model ) {
			wp_clear_scheduled_hook( $hook );
			Sitelemetry_Audit_Verification::on_audit_finished( $model );
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}

		// The service is known to have no verification API.
		$service          = $this->service();
		$service->enabled = false;
		Sitelemetry_Audit_Verification::one_click_state( $service->api() );
		Sitelemetry_Audit_Verification::on_audit_finished( $this->gated_model( 'target_reverification_required' ) );
		$this->assertFalse( wp_next_scheduled( $hook ) );

		// Turned off with the filter.
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		$GLOBALS['sitelemetry_test_filters']['sitelemetry_audit_auto_renew'] = false;
		Sitelemetry_Audit_Verification::on_audit_finished( $this->gated_model( 'target_reverification_required' ) );
		$this->assertFalse( wp_next_scheduled( $hook ) );
		$GLOBALS['sitelemetry_test_filters'] = array();

		// A subdirectory install does not answer the root of the host.
		$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org/blog';
		$this->assertFalse( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';

		// The settings page probe finds the verification expired.
		$service          = $this->service();
		$service->verified( time() - 60 );
		$this->assertSame( 'on', Sitelemetry_Audit_Verification::one_click_state( $service->api() ) );
		$this->assertSame( time(), wp_next_scheduled( $hook ) );
		$this->assertSame( array( 'status' ), $service->endpoints(), 'the probe itself changes nothing' );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'The verification of this site has expired. The plugin renews it automatically', $html );

		// The weekly audit renews first when the verification is known to have expired.
		$service->calls = array();
		Sitelemetry_Audit_Verification::renew_before_audit( 'https://example.org/' );
		$this->assertSame( array(), $service->endpoints(), 'another host' );
		Sitelemetry_Audit_Verification::renew_before_audit( 'https://www.example.org/' );
		$this->assertSame( array( 'status', 'challenge', 'file', 'verify', 'file' ), $service->endpoints() );
		$service->calls = array();
		Sitelemetry_Audit_Verification::renew_before_audit( 'https://www.example.org/' );
		$this->assertSame( array(), $service->endpoints(), 'fresh again: nothing to do' );
	}

	/**
	 * A failed renewal: retried later, explained in a notice on the Dashboard and
	 * Plugins screens (a passing problem only after it failed twice), cleared by a
	 * later success.
	 *
	 * @return void
	 */
	public function test_renewal_failure_notice() {
		$service                 = $this->service();
		$service->verified( time() - 60 );
		$service->verify_failure = array( 'http_status', 404 );
		$this->assertSame( 'failed', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$state = Sitelemetry_Audit_Verification::api_state();
		$this->assertTrue( $state['renewing'] );
		$this->assertSame( 1, $state['attempts'] );
		$this->assertSame( time() + Sitelemetry_Audit_Verification::RETRY_DELAY, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertNotNull( Sitelemetry_Audit_Verification::renewal_failure() );

		$admin  = new Sitelemetry_Audit_Admin( new Sitelemetry_Audit_Runner() );
		$notice = function () use ( $admin ) {
			ob_start();
			$admin->render_renewal_notice();
			return ob_get_clean();
		};
		$this->assertSame( '', $notice(), 'no screen' );
		$GLOBALS['sitelemetry_test_screen'] = 'dashboard';
		$html                               = $notice();
		$this->assertStringContainsString( 'notice notice-error sitelemetry-audit-renewal-notice', $html );
		$this->assertStringContainsString( 'Sitelemetry could not renew the ownership verification of www.example.org automatically.', $html );
		$this->assertStringContainsString( 'the site answered HTTP 404', $html );
		$this->assertStringContainsString( 'page=sitelemetry-audit#sitelemetry-audit-verify', $html );
		$this->assertStringContainsString( 'action=sitelemetry_audit_dismiss_renewal', $html );
		$GLOBALS['sitelemetry_test_screen'] = 'settings_page_sitelemetry-audit';
		$this->assertSame( '', $notice(), 'the panel shows it on the plugin page' );
		$GLOBALS['sitelemetry_test_screen'] = 'plugins';
		$this->assertStringContainsString( 'sitelemetry-audit-renewal-notice', $notice() );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( '<strong>The automatic renewal did not complete.</strong>', $html );

		// The weekly notice about the same expiry steps aside.
		Sitelemetry_Audit_Results::store( 'security', array_merge( $this->gated_model( 'target_reverification_required', time() - 3600 ), array( 'origin' => 'scheduled' ) ) );
		$GLOBALS['sitelemetry_test_screen'] = 'dashboard';
		ob_start();
		$admin->render_weekly_notice();
		$this->assertSame( '', ob_get_clean() );

		// Dismissed (capability and nonce checked), until the next failure.
		$GLOBALS['sitelemetry_test_nonces'] = array();
		try {
			$admin->handle_dismiss_renewal();
			$this->fail( 'no redirect' );
		} catch ( Sitelemetry_Test_Exit $exit ) {
			$this->assertSame( 'redirect', $exit->kind );
		}
		$this->assertSame( array( 'sitelemetry_audit_dismiss_renewal' ), $GLOBALS['sitelemetry_test_nonces'] );
		$this->assertSame( '', $notice() );

		// The retry succeeds: the failure and its notice are gone.
		$service->verify_failure = null;
		$this->assertSame( 'renewed', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertNull( Sitelemetry_Audit_Verification::renewal_failure() );
		$this->assertSame( 0, Sitelemetry_Audit_Verification::api_state()['attempts'] );

		// A passing problem (no answer in time) is retried soon and announced only
		// when it fails again.
		$service->verified( time() - 60 );
		$service->verify_failure = array( 'timeout' );
		$this->assertSame( 'failed', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertSame( time() + Sitelemetry_Audit_Verification::QUICK_RETRY_DELAY, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertNull( Sitelemetry_Audit_Verification::renewal_failure() );
		$this->assertSame( 'failed', Sitelemetry_Audit_Verification::renew( $service->api() ), 'the pending renewal is finished by the retry' );
		$this->assertNotNull( Sitelemetry_Audit_Verification::renewal_failure() );

		// A rate limit waits as long as the service asks.
		$service->fail['verify'] = array( 429, array( 'ok' => false, 'code' => 'rate_limited', 'error' => 'x', 'retryAfter' => 7200 ) );
		Sitelemetry_Audit_Verification::renew( $service->api() );
		$this->assertSame( time() + 7200, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );

		// After MAX_RENEWAL_TRIES failures in a row no retry is scheduled.
		wp_clear_scheduled_hook( Sitelemetry_Audit_Verification::RENEW_HOOK );
		Sitelemetry_Audit_Verification::renew( $service->api() );
		Sitelemetry_Audit_Verification::renew( $service->api() );
		$this->assertSame( Sitelemetry_Audit_Verification::MAX_RENEWAL_TRIES, Sitelemetry_Audit_Verification::api_state()['attempts'] );
		wp_clear_scheduled_hook( Sitelemetry_Audit_Verification::RENEW_HOOK );
		Sitelemetry_Audit_Verification::renew( $service->api() );
		$this->assertFalse( wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );

		// The API is switched off meanwhile: the renewal stops quietly.
		$service->enabled = false;
		$this->assertSame( 'off', Sitelemetry_Audit_Verification::renew( $service->api() ) );
	}

	/**
	 * On a multisite network the background renewal publishes a token only where
	 * a network administrator set up the verification of this host.
	 *
	 * @return void
	 */
	public function test_multisite_renewal_needs_a_network_administrator() {
		$service = $this->service();
		$service->verified( time() - 60 );
		$GLOBALS['sitelemetry_test_multisite'] = true;
		$this->assertFalse( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$this->assertSame( 'skipped', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertCount( 0, $service->calls );

		// A network administrator verified with one click before.
		$GLOBALS['sitelemetry_test_caps'] = array( 'manage_options', 'manage_network_options' );
		$service->record                  = null;
		$this->assertTrue( Sitelemetry_Audit_Verification::request_one_click( $service->api() )['ok'] );
		$service->verified( time() - 60 );
		$GLOBALS['sitelemetry_test_caps']    = array();
		$GLOBALS['sitelemetry_test_context'] = 'cron';
		$this->assertSame( 'renewed', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::stored()['authorized'] );
		$this->assertNotSame( '', Sitelemetry_Audit_Verification::served_token() );
	}

	/**
	 * The admin-post handler checks the capability and the nonce and lands on the
	 * panel with the outcome.
	 *
	 * @return void
	 */
	public function test_handler_capability_nonce_and_outcomes() {
		$admin   = new Sitelemetry_Audit_Admin( new Sitelemetry_Audit_Runner() );
		$service = $this->service();
		$run     = function () use ( $admin ) {
			try {
				$admin->handle_verification_one_click();
			} catch ( Sitelemetry_Test_Exit $exit ) {
				return $exit;
			}
			return null;
		};

		$GLOBALS['sitelemetry_test_caps'] = array();
		$exit                             = $run();
		$this->assertSame( 'die', $exit->kind );
		$this->assertSame( array(), $GLOBALS['sitelemetry_test_nonces'] );
		$this->assertCount( 0, $service->calls );

		$GLOBALS['sitelemetry_test_caps']     = array( 'manage_options' );
		$GLOBALS['sitelemetry_test_nonce_ok'] = false;
		$exit                                 = $run();
		$this->assertSame( 'die', $exit->kind, 'a missing or wrong nonce stops it' );
		$this->assertSame( array( 'sitelemetry_audit_verification_one_click' ), $GLOBALS['sitelemetry_test_nonces'] );
		$this->assertCount( 0, $service->calls );
		$GLOBALS['sitelemetry_test_nonce_ok'] = true;

		$exit = $run();
		$this->assertSame( 'redirect', $exit->kind );
		$this->assertStringContainsString( 'sitelemetry_notice=verified', $exit->target );
		$this->assertStringContainsString( '#sitelemetry-audit-verify', $exit->target );

		$service->record         = null;
		$service->verify_failure = array( 'redirect' );
		$this->assertStringContainsString( 'sitelemetry_notice=verify_failed', $run()->target );

		$service->enabled = false;
		$this->assertStringContainsString( 'sitelemetry_notice=verify_off', $run()->target );

		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => '' ) );
		$this->assertStringContainsString( 'sitelemetry_notice=verify_no_key', $run()->target );
	}

	/**
	 * A new API key (another account, or none) forgets what the plugin learned
	 * about the old account; the published token stays.
	 *
	 * @return void
	 */
	public function test_key_change_forgets_the_old_account() {
		$service = $this->service();
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertNotNull( Sitelemetry_Audit_Verification::verified_state() );
		$this->assertNotSame( false, wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$token = Sitelemetry_Audit_Verification::served_token();

		Sitelemetry_Audit_Verification::on_settings_updated( array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY, 'target' => '' ), array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY, 'target' => 'https://www.example.org/' ) );
		$this->assertNotNull( Sitelemetry_Audit_Verification::verified_state(), 'same key: nothing changes' );

		Sitelemetry_Audit_Verification::on_settings_updated( array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ), array( 'api_key' => 'sl_other_key_987654' ) );
		$this->assertFalse( get_option( Sitelemetry_Audit_Verification::API_OPTION, false ) );
		$this->assertFalse( get_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT ) );
		$this->assertFalse( wp_next_scheduled( Sitelemetry_Audit_Verification::RENEW_HOOK ) );
		$this->assertSame( $token, Sitelemetry_Audit_Verification::served_token() );
	}

	/**
	 * A valid verification the settings page probe finds (made in the app, or
	 * before the key was changed, which clears the schedule) is renewed when it
	 * expires; an earlier pending renewal is kept. Past the expiry, before WP-Cron
	 * has run the renewal, the panel says it expired instead of saying nothing.
	 *
	 * @return void
	 */
	public function test_probe_schedules_the_renewal_it_finds() {
		$hook    = Sitelemetry_Audit_Verification::RENEW_HOOK;
		$service = $this->service();
		$expires = time() + 3 * 86400;
		$service->verified( $expires );
		$this->assertFalse( wp_next_scheduled( $hook ) );
		$this->assertSame( 'on', Sitelemetry_Audit_Verification::one_click_state( $service->api() ) );
		$this->assertSame( array( 'status' ), $service->endpoints(), 'the probe itself changes nothing' );
		$this->assertSame( $expires + 60, wp_next_scheduled( $hook ) );

		// An earlier renewal (for example a retry) is kept.
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		Sitelemetry_Audit_Verification::schedule_renewal( time() + 3600 );
		Sitelemetry_Audit_Verification::one_click_state( $service->api() );
		$this->assertSame( time() + 3600, wp_next_scheduled( $hook ) );

		// Automatic renewal off: nothing is scheduled.
		wp_clear_scheduled_hook( $hook );
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		$GLOBALS['sitelemetry_test_filters']['sitelemetry_audit_auto_renew'] = false;
		Sitelemetry_Audit_Verification::one_click_state( $service->api() );
		$this->assertFalse( wp_next_scheduled( $hook ) );
		$GLOBALS['sitelemetry_test_filters'] = array();

		// Past the expiry the plugin was given, before the renewal ran.
		$service->verified( time() - 30 );
		$service->clock_behind = 600;
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		Sitelemetry_Audit_Verification::one_click_state( $service->api() );
		$service->clock_behind = 0;
		$this->assertNull( Sitelemetry_Audit_Verification::verified_state() );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'The verification of this site has expired. The plugin renews it automatically', $html );
		$this->assertStringNotContainsString( 'Verification complete', $html );
	}

	/**
	 * The call to action of results, the dashboard widget and the weekly notice
	 * speak of automatic renewal when the plugin does it.
	 *
	 * @return void
	 */
	public function test_call_to_action_with_one_click() {
		$service = $this->service();
		$model   = $this->gated_model( 'target_reverification_required' );
		$cta     = Sitelemetry_Audit_Verification::call_to_action( $model );
		$this->assertFalse( $cta['one_click'], 'nothing known about the API yet' );
		$this->assertStringContainsString( 'is not renewed automatically', $cta['text'] );

		Sitelemetry_Audit_Verification::one_click_state( $service->api() );
		$cta = Sitelemetry_Audit_Verification::call_to_action( $model );
		$this->assertTrue( $cta['one_click'] );
		$this->assertSame( 'The ownership verification of www.example.org has expired. The plugin renews it automatically in the background; to renew it now, open Verify this site.', $cta['text'] );

		$widget = $this->render(
			'dashboard-widget.php',
			array(
				'model'            => $model,
				'job'              => null,
				'has_key'          => true,
				'results_url'      => Sitelemetry_Audit_Admin::results_url( 'security' ),
				'settings_url'     => Sitelemetry_Audit_Admin::page_url(),
				'severities'       => Sitelemetry_Audit_Labels::severity_labels(),
				'status_heading'   => Sitelemetry_Audit_Labels::status_heading( $model ),
				'verification'     => $cta,
				'verification_url' => Sitelemetry_Audit_Admin::verification_url(),
				'app_url'          => Sitelemetry_Audit_Links::verified_domains_url(),
			)
		);
		$this->assertStringContainsString( 'A verification lasts 30 days; the plugin renews it automatically.', $widget );
		$this->assertStringNotContainsString( 'is not renewed automatically', $widget );

		$first            = $this->gated_model( 'target_verification_required' );
		$first['message'] = 'Audit not started.';
		$results          = array_merge(
			array(
				'kind'           => 'security',
				'results'        => array(),
				'model'          => $first,
				'job'            => null,
				'progress'       => array( 'state' => 'idle' ),
				'plan_box'       => Sitelemetry_Audit_Links::plan_box( $first, null ),
				'settings'       => Sitelemetry_Audit_Settings::get(),
				'has_key'        => true,
				'run_action'     => admin_url( 'admin-post.php' ),
				'poll_url'       => '',
				'cancel_url'     => '',
				'settings_url'   => Sitelemetry_Audit_Admin::page_url(),
				'results_url'    => Sitelemetry_Audit_Admin::results_url(),
				'severities'     => Sitelemetry_Audit_Labels::severity_labels(),
				'reason_lead'    => Sitelemetry_Audit_Labels::reason_lead( $first ),
				'status_heading' => Sitelemetry_Audit_Labels::status_heading( $first ),
			),
			Sitelemetry_Audit_Admin::result_sections( $first )
		);
		$html             = $this->render( 'results.php', $results );
		$this->assertStringContainsString( 'You can verify from WordPress with one click', $html );
		$this->assertStringNotContainsString( 'paste the token from the app into the plugin settings', $html );

		// Verified afterwards: the result's call to action only asks to run it again.
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$results = array_merge( $results, Sitelemetry_Audit_Admin::result_sections( $first ) );
		$html    = $this->render( 'results.php', $results );
		$this->assertStringContainsString( '<h3>Ownership verified</h3>', $html );
		$this->assertStringContainsString( 'Run the audit again to include the checks that need verification.', $html );
		$this->assertStringNotContainsString( 'Verified Domains in the app</a>', $html );

		// A result newer than the verification still asks (for example a scope problem).
		$later = $this->gated_model( 'verification_scope_required', time() + 10 );
		$this->assertFalse( Sitelemetry_Audit_Verification::call_to_action( $later )['verified'] );

		// Another key: the stored verification belongs to the old account.
		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => 'sl_other_key_987654' ) );
		$this->assertNull( Sitelemetry_Audit_Verification::verified_state() );
	}

	/**
	 * Clicks "Remove token" through the admin-post handler (capability and nonce
	 * checked as in WordPress).
	 *
	 * @return void
	 */
	private function remove_token_as_admin() {
		$admin                                   = new Sitelemetry_Audit_Admin( new Sitelemetry_Audit_Runner() );
		$_POST['sitelemetry_audit_remove_token'] = 'Remove token';
		try {
			$admin->handle_verification_save();
			$this->fail( 'no redirect' );
		} catch ( Sitelemetry_Test_Exit $exit ) {
			$this->assertSame( 'redirect', $exit->kind );
			$this->assertStringContainsString( 'sitelemetry_notice=token_removed', $exit->target );
		} finally {
			unset( $_POST['sitelemetry_audit_remove_token'] );
		}
		$this->assertContains( 'sitelemetry_audit_verification_save', $GLOBALS['sitelemetry_test_nonces'] );
	}

	/**
	 * "Remove token" stops the automatic renewal: nothing stays scheduled, and no
	 * trigger (the expiry, an audit reporting that the verification expired, the
	 * settings page probe, the weekly audit, reactivation) publishes a new token
	 * or verifies the host again. The panel and the results no longer promise an
	 * automatic renewal. A click on Verify this site, or a saved token, ends the
	 * stop.
	 *
	 * @return void
	 */
	public function test_remove_token_stops_the_automatic_renewal() {
		$hook    = Sitelemetry_Audit_Verification::RENEW_HOOK;
		$service = $this->service();
		$this->assertTrue( Sitelemetry_Audit_Verification::request_one_click( $service->api() )['ok'] );
		$this->assertNotSame( '', Sitelemetry_Audit_Verification::served_token() );
		$this->assertNotSame( false, wp_next_scheduled( $hook ) );

		$this->remove_token_as_admin();
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token(), 'the file is no longer published' );
		$this->assertFalse( wp_next_scheduled( $hook ), 'the renewal is no longer scheduled' );
		$this->assertFalse( Sitelemetry_Audit_Verification::stored()['renew'] );
		$this->assertFalse( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$html = $this->settings_page( $service->api() );
		$this->assertStringNotContainsString( 'The plugin renews it automatically in the background (WP-Cron)', $html );
		$this->assertStringContainsString( 'Sitelemetry does not re-check it by itself', $html );
		$this->assertStringNotContainsString( 'name="sitelemetry_audit_remove_token"', $html, 'no token left to remove' );

		// The verification expires; WP-Cron runs anyway (for example an event left
		// by an older version): nothing is requested, published or verified.
		$service->verified( time() - 60 );
		$service->calls                      = array();
		$GLOBALS['sitelemetry_test_context'] = 'cron';
		$GLOBALS['sitelemetry_test_caps']    = array();
		$this->assertSame( 'skipped', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		Sitelemetry_Audit_Verification::run_scheduled_renewal();
		$this->assertSame( array(), $service->endpoints() );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );
		$this->assertSame( 'verified', $service->record['status'] );
		$this->assertGreaterThan( $service->record['expires_at'], time(), 'still the expired record: no new verification' );

		// The other triggers do not schedule it either.
		Sitelemetry_Audit_Verification::on_audit_finished( $this->gated_model( 'target_reverification_required' ) );
		$this->assertFalse( wp_next_scheduled( $hook ) );
		Sitelemetry_Audit_Verification::renew_before_audit( 'https://www.example.org/' );
		Sitelemetry_Audit_Plugin::activate();
		$this->assertFalse( wp_next_scheduled( $hook ) );
		$GLOBALS['sitelemetry_test_context'] = 'admin';
		$GLOBALS['sitelemetry_test_caps']    = array( 'manage_options' );
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		$this->assertSame( 'on', Sitelemetry_Audit_Verification::one_click_state( $service->api() ) );
		$this->assertFalse( wp_next_scheduled( $hook ), 'the settings page probe finds it stale and schedules nothing' );
		$this->assertSame( array( 'status' ), $service->endpoints(), 'only the read-only probe' );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );

		// The panel says it expired without promising an automatic renewal.
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'The ownership verification of www.example.org has expired. Verification lasts 30 days and is not renewed automatically', $html );
		$this->assertStringNotContainsString( 'The plugin renews it automatically', $html );
		$this->assertStringContainsString( 'name="sitelemetry_audit_one_click"', $html, 'one click stays available' );

		// So does the call to action of a result that reports the expiry.
		$cta = Sitelemetry_Audit_Verification::call_to_action( $this->gated_model( 'target_reverification_required' ) );
		$this->assertTrue( $cta['one_click'] );
		$this->assertFalse( $cta['renews'] );
		$this->assertStringContainsString( 'is not renewed automatically', $cta['text'] );
		// A first verification by one click would be renewed: the click ends the stop.
		$this->assertTrue( Sitelemetry_Audit_Verification::call_to_action( $this->gated_model( 'target_verification_required' ) )['renews'] );

		// A renewal that failed before the stop is no longer announced.
		$state         = get_option( Sitelemetry_Audit_Verification::API_OPTION );
		$state['last'] = array(
			'ok'     => false,
			'code'   => 'verification_failed',
			'reason' => 'http_status',
			'origin' => 'renewal',
			'at'     => time(),
		);
		update_option( Sitelemetry_Audit_Verification::API_OPTION, $state );
		$this->assertNull( Sitelemetry_Audit_Verification::renewal_failure() );
		$this->assertStringNotContainsString( 'The automatic renewal did not complete.', $this->settings_page( $service->api() ) );

		// Verify this site: renewed now, and automatically again from then on.
		$service->calls = array();
		$result         = Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertSame( array( true, 'verified' ), array( $result['ok'], $result['code'] ) );
		$this->assertSame( array( 'status', 'challenge', 'file', 'verify', 'file' ), $service->endpoints() );
		$this->assertTrue( Sitelemetry_Audit_Verification::stored()['renew'] );
		$this->assertTrue( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$this->assertSame( Sitelemetry_Audit_Verification::api_state()['expires_at'] + 60, wp_next_scheduled( $hook ) );
		$this->assertStringContainsString( 'The plugin renews it automatically in the background (WP-Cron)', $this->settings_page( $service->api() ) );

		// A click on an already verified site ends the stop as well.
		$this->remove_token_as_admin();
		$this->assertFalse( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$this->assertTrue( Sitelemetry_Audit_Verification::request_one_click( $service->api() )['ok'] );
		$this->assertSame( 'already_verified', Sitelemetry_Audit_Verification::api_state()['last']['code'] );
		$this->assertTrue( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$this->assertNotSame( false, wp_next_scheduled( $hook ) );
		$this->assertFalse( get_option( Sitelemetry_Audit_Verification::OPTION, false ), 'nothing left of the stop' );

		// So does a pasted token.
		$this->remove_token_as_admin();
		$this->assertTrue( Sitelemetry_Audit_Verification::save_token( 'sitelemetry-' . str_repeat( 'a', 32 ) ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::auto_renew_allowed() );
	}

	/**
	 * Reactivation schedules the renewal the plugin knows about, also for a
	 * verification that expired while the plugin was inactive, a stale one, and a
	 * renewal that was not finished; not for another key or host, when the
	 * renewal is turned off, or after Remove token.
	 *
	 * @return void
	 */
	public function test_reactivation_schedules_the_known_renewal() {
		$hook    = Sitelemetry_Audit_Verification::RENEW_HOOK;
		$service = $this->service();
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$expires = Sitelemetry_Audit_Verification::api_state()['expires_at'];

		// Still valid: renewed just after the expiry, as before.
		Sitelemetry_Audit_Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( $hook ) );
		Sitelemetry_Audit_Plugin::activate();
		$this->assertSame( $expires + 60, wp_next_scheduled( $hook ) );

		// Expired while the plugin was inactive: renewed soon after the activation.
		Sitelemetry_Audit_Plugin::deactivate();
		$state               = get_option( Sitelemetry_Audit_Verification::API_OPTION );
		$state['expires_at'] = time() - 3600;
		update_option( Sitelemetry_Audit_Verification::API_OPTION, $state );
		$service->verified( time() - 3600 );
		Sitelemetry_Audit_Plugin::activate();
		$this->assertSame( time() + Sitelemetry_Audit_Verification::QUICK_RETRY_DELAY, wp_next_scheduled( $hook ) );
		// The panel's "renews it automatically" holds: the scheduled event renews it.
		$GLOBALS['sitelemetry_test_context'] = 'cron';
		$service->calls                      = array();
		Sitelemetry_Audit_Verification::run_scheduled_renewal();
		$this->assertSame( array( 'status', 'challenge', 'file', 'verify', 'file' ), $service->endpoints() );
		$this->assertNotNull( Sitelemetry_Audit_Verification::verified_state() );
		$GLOBALS['sitelemetry_test_context'] = 'admin';

		// Reported stale, and a renewal that did not finish (retries left or not).
		$cases = array(
			array( array( 'status' => 'stale' ), true ),
			array(
				array(
					'status'     => 'pending',
					'renewing'   => true,
					'attempts'   => 2,
					'expires_at' => 0,
				),
				true,
			),
			array(
				array(
					'status'     => 'pending',
					'renewing'   => true,
					'attempts'   => Sitelemetry_Audit_Verification::MAX_RENEWAL_TRIES,
					'expires_at' => 0,
				),
				false,
			),
			array(
				array(
					'status'     => 'pending',
					'renewing'   => false,
					'expires_at' => 0,
				),
				false,
			),
			array(
				array(
					'status'     => 'unverified',
					'expires_at' => 0,
				),
				false,
			),
			array( array( 'key' => 'another-fingerprint' ), false ),
			array( array( 'host' => 'example.org' ), false ),
		);
		foreach ( $cases as $case ) {
			list( $changes, $scheduled ) = $case;
			Sitelemetry_Audit_Plugin::deactivate();
			$saved = get_option( Sitelemetry_Audit_Verification::API_OPTION );
			update_option( Sitelemetry_Audit_Verification::API_OPTION, array_merge( $saved, $changes ) );
			Sitelemetry_Audit_Plugin::activate();
			$this->assertSame( $scheduled, false !== wp_next_scheduled( $hook ), wp_json_encode( $changes ) );
			update_option( Sitelemetry_Audit_Verification::API_OPTION, $saved );
		}

		// Turned off with the filter, or stopped with Remove token.
		Sitelemetry_Audit_Plugin::deactivate();
		$GLOBALS['sitelemetry_test_filters']['sitelemetry_audit_auto_renew'] = false;
		Sitelemetry_Audit_Plugin::activate();
		$this->assertFalse( wp_next_scheduled( $hook ) );
		$GLOBALS['sitelemetry_test_filters'] = array();
		$this->remove_token_as_admin();
		Sitelemetry_Audit_Plugin::deactivate();
		Sitelemetry_Audit_Plugin::activate();
		$this->assertFalse( wp_next_scheduled( $hook ) );
	}

	/**
	 * Resets the site to https://www.example.org with a stored key and installs a
	 * service whose file checks and this site's own requests for the file get
	 * $file_answer.
	 *
	 * @param array|WP_Error $file_answer Answer to requests for the verification file.
	 * @param array|null     $failure     Forced failure of the service's file check, or null.
	 * @return Sitelemetry_Test_Verification_Service
	 */
	private function service_with_file_answer( $file_answer, $failure = null ) {
		sitelemetry_test_reset();
		$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';
		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => Sitelemetry_Test_Mock_Service::API_KEY ) );
		$service                          = $this->service();
		$service->verify_failure          = $failure;
		$GLOBALS['sitelemetry_test_http'] = function ( $method, $url, $args ) use ( $service, $file_answer ) {
			if ( Sitelemetry_Audit_Verification::PATH === wp_parse_url( $url, PHP_URL_PATH ) ) {
				return $file_answer;
			}
			return $service->handle( $method, $url, $args );
		};
		return $service;
	}

	/**
	 * The file test that runs inside one click or a renewal is not shown as a
	 * second notice with the manual helper's advice: the outcome of the attempt
	 * explains it. A later Test the file, and a passing test, are shown.
	 *
	 * @return void
	 */
	public function test_the_attempts_own_file_test_is_not_a_second_notice() {
		// The host blocks the site's requests to itself; Sitelemetry verifies
		// (its own check of the file goes through the mock service directly).
		$service = $this->service_with_file_answer( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::request_one_click( $service->api() )['ok'] );
		$test = Sitelemetry_Audit_Verification::stored()['last_test'];
		$this->assertSame( array( false, 'unreachable', true ), array( $test['ok'], $test['code'], $test['attempt'] ) );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'sitelemetry-audit-verified', $html );
		$this->assertStringNotContainsString( 'sitelemetry-audit-test-result', $html );
		$this->assertStringNotContainsString( 'WordPress could not load the file from its own address', $html );

		// The admin tests the file afterwards: that result is shown.
		$admin = new Sitelemetry_Audit_Admin( new Sitelemetry_Audit_Runner() );
		try {
			$admin->handle_verification_test();
			$this->fail( 'no redirect' );
		} catch ( Sitelemetry_Test_Exit $exit ) {
			$this->assertStringContainsString( 'sitelemetry_notice=tested', $exit->target );
		}
		$this->assertFalse( Sitelemetry_Audit_Verification::stored()['last_test']['attempt'] );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'notice notice-error inline sitelemetry-audit-test-result', $html );
		$this->assertStringContainsString( 'WordPress could not load the file from its own address (cURL error 28', $html );

		// The web server answers 404 to Sitelemetry and to the site itself: only the
		// one-click reason and fix, not "upload a plain text file with the token"
		// (a static file keeps the old token and breaks the next renewal).
		$service = $this->service_with_file_answer( sitelemetry_test_response( 404, '<html>nginx 404</html>', array( 'content-type' => 'text/html' ) ), array( 'http_status', 404 ) );
		$this->assertFalse( Sitelemetry_Audit_Verification::request_one_click( $service->api() )['ok'] );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'Ask your host to pass /.well-known/sitelemetry-verification.txt to WordPress, or use a DNS record.', $html );
		$this->assertStringNotContainsString( 'upload a plain text file with the token', $html );
		$this->assertStringNotContainsString( 'sitelemetry-audit-test-result', $html );

		// A redirect to the other host: no "Add and verify that host in the app".
		$service = $this->service_with_file_answer( sitelemetry_test_response( 301, '', array( 'location' => 'https://example.org/.well-known/sitelemetry-verification.txt' ) ), array( 'redirect' ) );
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( 'set the site address in Settings &gt; General to the host your server redirects to', $html );
		$this->assertStringNotContainsString( 'Add and verify that host in the app instead', $html );

		// The same for the test inside an automatic renewal.
		$service->verified( time() - 60 );
		$this->assertSame( 'failed', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::stored()['last_test']['attempt'] );
		$html = $this->settings_page( $service->api() );
		$this->assertStringContainsString( '<strong>The automatic renewal did not complete.</strong>', $html );
		$this->assertStringNotContainsString( 'sitelemetry-audit-test-result', $html );
	}

	/**
	 * An audit that reports this site as not verified (for example after the
	 * domain was removed under Verified Domains in the app) ends "Verification
	 * complete" in the panel; the next settings page load asks the service once,
	 * and the notice comes back only if the service still reports the host as
	 * verified. The result's call to action still offers one click.
	 *
	 * @return void
	 */
	public function test_an_audit_reporting_no_verification_drops_the_recorded_one() {
		$service = $this->service();
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		$this->assertStringContainsString( 'Verification complete ✓ valid until ', $this->settings_page( $service->api() ) );

		// Removed in the app; the next audit is refused.
		$service->record = null;
		$model           = $this->gated_model( 'target_verification_required', time() + 5 );
		Sitelemetry_Audit_Verification::on_audit_finished( $model );
		$this->assertNull( Sitelemetry_Audit_Verification::verified_state() );
		$this->assertTrue( Sitelemetry_Audit_Verification::api_state()['recheck'] );
		$cta = Sitelemetry_Audit_Verification::call_to_action( $model );
		$this->assertSame( array( false, true, true ), array( $cta['verified'], $cta['one_click'], $cta['renews'] ), 'still offered with one click' );
		$this->assertSame( 'This audit runs only after ownership of www.example.org is verified.', $cta['text'] );

		$service->calls = array();
		$html           = $this->settings_page( $service->api() );
		$this->assertSame( array( 'status' ), $service->endpoints(), 'asked once' );
		$this->assertStringNotContainsString( 'Verification complete', $html );
		$this->assertStringNotContainsString( 'has expired', $html );
		$this->assertSame( 'unverified', Sitelemetry_Audit_Verification::api_state()['status'] );
		$this->assertFalse( Sitelemetry_Audit_Verification::api_state()['recheck'] );
		$this->settings_page( $service->api() );
		$this->assertSame( array( 'status' ), $service->endpoints(), 'not on every load' );

		// The service still reports the host as verified (a scope problem): the
		// notice comes back after the one status request.
		Sitelemetry_Audit_Verification::request_one_click( $service->api() );
		Sitelemetry_Audit_Verification::on_audit_finished( $this->gated_model( 'verification_scope_required', time() + 5 ) );
		$this->assertNull( Sitelemetry_Audit_Verification::verified_state() );
		$service->calls = array();
		$this->assertStringContainsString( 'Verification complete ✓ valid until ', $this->settings_page( $service->api() ) );
		$this->assertSame( array( 'status' ), $service->endpoints() );

		// An error answer to that request is not asked again on every load.
		Sitelemetry_Audit_Verification::on_audit_finished( $this->gated_model( 'target_verification_required', time() + 5 ) );
		$service->key   = 'sl_revoked';
		$service->calls = array();
		$this->settings_page( $service->api() );
		$this->settings_page( $service->api() );
		$this->assertSame( array( 'status' ), $service->endpoints() );
		$this->assertFalse( Sitelemetry_Audit_Verification::api_state()['recheck'] );
		$service->key = Sitelemetry_Test_Mock_Service::API_KEY;

		// Other hosts and other results change nothing.
		delete_transient( Sitelemetry_Audit_Verification::PROBE_TRANSIENT );
		Sitelemetry_Audit_Verification::one_click_state( $service->api() );
		$this->assertNotNull( Sitelemetry_Audit_Verification::verified_state() );
		Sitelemetry_Audit_Verification::on_audit_finished( array_merge( $this->gated_model( 'target_verification_required' ), array( 'target' => 'https://example.org/' ) ) );
		$completed           = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://www.example.org/' );
		$completed['status'] = 'completed';
		$completed['reason'] = '';
		Sitelemetry_Audit_Verification::on_audit_finished( $completed );
		$this->assertNotNull( Sitelemetry_Audit_Verification::verified_state() );
		$this->assertFalse( Sitelemetry_Audit_Verification::api_state()['recheck'] );
	}

	/**
	 * Failure texts read the same on the Dashboard as in the panel, give a fix,
	 * and show an HTTP status only when there is one.
	 *
	 * @return void
	 */
	public function test_failure_texts_read_well_everywhere() {
		$unavailable = Sitelemetry_Audit_Verification::one_click_message( array( 'code' => 'verifier_unavailable' ) );
		$this->assertStringContainsString( 'as described under Verify this site in the plugin settings', $unavailable );
		$this->assertStringNotContainsString( 'below', $unavailable );
		$this->assertSame( $unavailable, Sitelemetry_Audit_Verification::one_click_message( array( 'code' => 'api_key_unavailable' ) ) );

		// The Dashboard notice after a failed renewal.
		$service = $this->service();
		$service->verified( time() - 60 );
		$service->fail['challenge'] = array(
			503,
			array(
				'ok'    => false,
				'error' => 'x',
				'code'  => 'verifier_unavailable',
			),
		);
		$this->assertSame( 'failed', Sitelemetry_Audit_Verification::renew( $service->api() ) );
		$GLOBALS['sitelemetry_test_screen'] = 'dashboard';
		$admin                              = new Sitelemetry_Audit_Admin( new Sitelemetry_Audit_Runner() );
		ob_start();
		$admin->render_renewal_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'as described under Verify this site in the plugin settings', $notice );
		$this->assertStringNotContainsString( 'described below', $notice );

		$this->assertStringEndsWith(
			'Check the DNS records of the domain, or verify the public address of the site instead.',
			Sitelemetry_Audit_Verification::one_click_message(
				array(
					'code'   => 'verification_failed',
					'reason' => 'not_public',
				)
			)
		);

		$generic = 'Sitelemetry could not complete the verification (%s). Try again in a few minutes.';
		$cases   = array(
			array( 'unexpected', 'pending', 'unexpected' ),
			array( 'unexpected', '', 'unexpected' ),
			array( 'http_502', '502', 'HTTP 502' ),
			array( '', '', '-' ),
		);
		foreach ( $cases as $case ) {
			$message = Sitelemetry_Audit_Verification::one_click_message(
				array(
					'code'   => $case[0],
					'detail' => $case[1],
				)
			);
			$this->assertSame( sprintf( $generic, $case[2] ), $message );
		}
	}
}
