<?php
/**
 * Verification helper tests: the served file, the token, the self-test and the
 * call to action.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_Verification.
 */
class Sitelemetry_Audit_Verification_Test extends TestCase {

	/**
	 * A valid token (the service's format: sitelemetry- and 32 base64url characters).
	 */
	const TOKEN = 'sitelemetry-Ab3_-9xYz0123456789ABCDEFGHIJKLM';

	/**
	 * Reset stores; this site is https://www.example.org.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
		$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';
	}

	/**
	 * Installs an HTTP handler that answers by URL.
	 *
	 * @param array $responses URL => response.
	 * @return array Recorded requests (by reference through the returned object).
	 */
	private function serve( array $responses ) {
		$log                              = new ArrayObject();
		$GLOBALS['sitelemetry_test_http'] = function ( $method, $url, $args ) use ( $responses, $log ) {
			$log[] = array( $method, $url, $args );
			return isset( $responses[ $url ] ) ? $responses[ $url ] : new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		};
		return $log;
	}

	/**
	 * The token pattern is the service's: sitelemetry- and 32 base64url characters.
	 *
	 * @return void
	 */
	public function test_token_pattern() {
		$this->assertFalse( Sitelemetry_Audit_Verification::is_token( self::TOKEN . "\n" ), 'no trailing newline' );
		$this->assertSame( 44, strlen( self::TOKEN ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::is_token( self::TOKEN ) );
		foreach ( array( '', 'sitelemetry-short', self::TOKEN . 'x', substr( self::TOKEN, 0, -1 ) . '=', substr( self::TOKEN, 0, -1 ) . '/', 'Sitelemetry-' . substr( self::TOKEN, 12 ), ' ' . self::TOKEN, self::TOKEN . "\n<script>", null, 42 ) as $bad ) {
			$this->assertFalse( Sitelemetry_Audit_Verification::is_token( $bad ), var_export( $bad, true ) );
		}
	}

	/**
	 * Exactly one path is served, for this site's host only.
	 *
	 * @return void
	 */
	public function test_is_file_request() {
		$home = 'https://www.example.org/';
		$this->assertTrue( Sitelemetry_Audit_Verification::is_file_request( '/.well-known/sitelemetry-verification.txt', 'www.example.org', $home ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::is_file_request( '/.well-known/sitelemetry-verification.txt?cache=1', 'WWW.Example.org:443', $home ) );
		$this->assertTrue( Sitelemetry_Audit_Verification::is_file_request( '/.well-known/sitelemetry-verification.txt', 'www.example.org.', $home ) );
		$this->assertFalse( Sitelemetry_Audit_Verification::is_file_request( '/.well-known/sitelemetry-verification.txt', 'example.org', $home ), 'www and non-www are different hosts' );
		$this->assertFalse( Sitelemetry_Audit_Verification::is_file_request( '/.well-known/sitelemetry-verification.txt', 'evil.example', $home ) );
		$this->assertFalse( Sitelemetry_Audit_Verification::is_file_request( '/.well-known/sitelemetry-verification.txt', '', $home ) );
		foreach ( array( '/.well-known/security.txt', '/.well-known/acme-challenge/abc', '/.well-known/', '/.well-known/sitelemetry-verification.txt/', '/blog/.well-known/sitelemetry-verification.txt', '/.well-known/SITELEMETRY-verification.txt', '/.well-known/sitelemetry-verification.txt.bak', '/' ) as $path ) {
			$this->assertFalse( Sitelemetry_Audit_Verification::is_file_request( $path, 'www.example.org', $home ), $path );
		}
		$this->assertSame( 'https://www.example.org/.well-known/sitelemetry-verification.txt', Sitelemetry_Audit_Verification::file_url() );
		$this->assertSame( 'http://plain.example/.well-known/sitelemetry-verification.txt', Sitelemetry_Audit_Verification::file_url( 'http://plain.example/' ) );
		$this->assertSame( '[2001:db8::1]', Sitelemetry_Audit_Verification::normalize_host( '[2001:DB8::1]:8443' ) );
	}

	/**
	 * 200 text/plain no-store with the token; 404 without a token; HEAD has no body.
	 *
	 * @return void
	 */
	public function test_response() {
		$found = Sitelemetry_Audit_Verification::response( 'get', self::TOKEN );
		$this->assertSame( 200, $found['status'] );
		$this->assertSame( self::TOKEN, $found['body'] );
		$this->assertSame( 'text/plain; charset=utf-8', $found['headers']['Content-Type'] );
		$this->assertSame( 'no-store, max-age=0', $found['headers']['Cache-Control'] );
		$this->assertSame( 'nosniff', $found['headers']['X-Content-Type-Options'] );

		$head = Sitelemetry_Audit_Verification::response( 'head', self::TOKEN );
		$this->assertSame( 200, $head['status'] );
		$this->assertSame( '', $head['body'] );

		$missing = Sitelemetry_Audit_Verification::response( 'get', '' );
		$this->assertSame( 404, $missing['status'] );
		$this->assertSame( 'no-store, max-age=0', $missing['headers']['Cache-Control'] );
		$this->assertStringNotContainsString( 'sitelemetry-', $missing['body'] );
		$this->assertSame( 404, Sitelemetry_Audit_Verification::response( 'get', 'not-a-token' )['status'] );
	}

	/**
	 * The token is stored for this site's host and served only while the site has
	 * that host; nothing is served before a token is saved.
	 *
	 * @return void
	 */
	public function test_token_storage() {
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );
		$error = Sitelemetry_Audit_Verification::save_token( 'sitelemetry-nope' );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 'invalid_token', $error->get_error_code() );
		$this->assertFalse( get_option( Sitelemetry_Audit_Verification::OPTION, false ) );

		$this->assertTrue( Sitelemetry_Audit_Verification::save_token( '  ' . self::TOKEN . "\n" ) );
		$stored = Sitelemetry_Audit_Verification::stored();
		$this->assertSame( self::TOKEN, $stored['token'] );
		$this->assertSame( 'www.example.org', $stored['host'] );
		$this->assertSame( self::TOKEN, Sitelemetry_Audit_Verification::served_token() );

		// The site moved to another address: the old token is not served there.
		$GLOBALS['sitelemetry_test_home'] = 'https://example.org';
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );
		$GLOBALS['sitelemetry_test_home'] = 'https://www.example.org';

		Sitelemetry_Audit_Verification::remove_token();
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );
	}

	/**
	 * The self-test fetches the site's own file (no redirects followed by the HTTP
	 * API) and reports success.
	 *
	 * @return void
	 */
	public function test_self_test_success() {
		Sitelemetry_Audit_Verification::save_token( self::TOKEN );
		$url = 'https://www.example.org/.well-known/sitelemetry-verification.txt';
		$log = $this->serve( array( $url => sitelemetry_test_response( 200, self::TOKEN . "\n", array( 'content-type' => 'text/plain', 'x-sitelemetry-verification' => 'token' ) ) ) );
		$result = Sitelemetry_Audit_Verification::test_file();
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ok', $result['code'] );
		$this->assertStringContainsString( 'click Verify HTTP', $result['message'] );
		$this->assertCount( 1, $log );
		$this->assertSame( array( 'GET', $url ), array( $log[0][0], $log[0][1] ) );
		$this->assertSame( 0, $log[0][2]['redirection'] );
		$this->assertStringNotContainsString( 'sl_', wp_json_encode( $log[0][2] ) );

		Sitelemetry_Audit_Verification::remember_test( $result );
		$stored = Sitelemetry_Audit_Verification::stored();
		$this->assertTrue( $stored['last_test']['ok'] );
		$this->assertSame( self::TOKEN, $stored['token'] );
	}

	/**
	 * The self-test names the exact problem.
	 *
	 * @return void
	 */
	public function test_self_test_problems() {
		Sitelemetry_Audit_Verification::save_token( self::TOKEN );
		$url  = 'https://www.example.org/.well-known/sitelemetry-verification.txt';
		$case = function ( $response, $extra = array() ) use ( $url ) {
			$this->serve( array_merge( array( $url => $response ), $extra ) );
			return Sitelemetry_Audit_Verification::test_file();
		};

		$result = $case( sitelemetry_test_response( 404, '<html>nginx</html>', array( 'content-type' => 'text/html' ) ) );
		$this->assertSame( array( false, 'not_found', 404 ), array( $result['ok'], $result['code'], $result['status'] ) );
		$this->assertStringContainsString( 'without passing the request to WordPress', $result['message'] );

		$result = $case( sitelemetry_test_response( 404, 'Not found', array( 'x-sitelemetry-verification' => 'none' ) ) );
		$this->assertSame( 'plugin_no_token', $result['code'] );
		$this->assertStringContainsString( 'The plugin answered, but no token is stored', $result['message'] );

		$result = $case( sitelemetry_test_response( 403, 'Forbidden' ) );
		$this->assertSame( 'forbidden', $result['code'] );
		$this->assertStringContainsString( 'firewall', $result['message'] );

		$result = $case( sitelemetry_test_response( 301, '', array( 'location' => 'https://example.org/.well-known/sitelemetry-verification.txt' ) ) );
		$this->assertSame( 'redirect_host', $result['code'] );
		$this->assertStringContainsString( 'to another host, example.org', $result['message'] );

		$result = $case( sitelemetry_test_response( 302, '', array( 'location' => 'http://www.example.org/.well-known/sitelemetry-verification.txt' ) ) );
		$this->assertSame( 'redirect_downgrade', $result['code'] );

		// An upper-case scheme is still https: no downgrade.
		$result = $case(
			sitelemetry_test_response( 301, '', array( 'location' => 'HTTPS://www.example.org/.well-known/sitelemetry-verification.txt?v=3' ) ),
			array( 'HTTPS://www.example.org/.well-known/sitelemetry-verification.txt?v=3' => sitelemetry_test_response( 200, self::TOKEN ) )
		);
		$this->assertTrue( $result['ok'], 'HTTPS:// in upper case is not a downgrade' );

		$result = $case(
			sitelemetry_test_response( 301, '', array( 'location' => '/.well-known/sitelemetry-verification.txt?v=2' ) ),
			array( $url . '?v=2' => sitelemetry_test_response( 200, self::TOKEN ) )
		);
		$this->assertTrue( $result['ok'], 'a same-host redirect is followed, as the verifier does' );

		$result = $case( sitelemetry_test_response( 301, '', array( 'location' => $url ) ) );
		$this->assertSame( 'too_many_redirects', $result['code'] );

		$result = $case( sitelemetry_test_response( 200, '<!doctype html><title>Maintenance</title>', array( 'content-type' => 'text/html; charset=UTF-8' ) ) );
		$this->assertSame( 'not_plugin', $result['code'] );

		$result = $case( sitelemetry_test_response( 200, 'sitelemetry-oldtokenoldtokenoldtokenoldtoken12', array( 'content-type' => 'text/plain' ) ) );
		$this->assertSame( 'wrong_body', $result['code'] );

		$result = $case( sitelemetry_test_response( 503, 'Maintenance' ) );
		$this->assertSame( 'unavailable', $result['code'] );

		$result = $case( sitelemetry_test_response( 401, '' ) );
		$this->assertSame( 'auth', $result['code'] );

		$result = $case( sitelemetry_test_response( 502, '' ) );
		$this->assertSame( array( 'server_error', 502 ), array( $result['code'], $result['status'] ) );

		$this->serve( array() );
		$result = Sitelemetry_Audit_Verification::test_file();
		$this->assertSame( 'unreachable', $result['code'] );
		$this->assertStringContainsString( 'cURL error 7', $result['message'] );

		Sitelemetry_Audit_Verification::remove_token();
		$this->assertSame( 'no_token', Sitelemetry_Audit_Verification::test_file()['code'] );
	}

	/**
	 * Visible reasons the file cannot verify the site.
	 *
	 * @return void
	 */
	public function test_warnings() {
		$this->assertSame( array(), Sitelemetry_Audit_Verification::warnings( 'https://www.example.org/' ) );
		$this->assertCount( 1, Sitelemetry_Audit_Verification::warnings( 'https://localhost/' ) );
		$this->assertCount( 1, Sitelemetry_Audit_Verification::warnings( 'https://mysite.local/' ) );
		$this->assertCount( 1, Sitelemetry_Audit_Verification::warnings( 'https://192.168.1.20/' ) );
		$this->assertStringContainsString( 'subdirectory', implode( ' ', Sitelemetry_Audit_Verification::warnings( 'https://example.org/blog/' ) ) );
		$this->assertStringContainsString( 'uses http://', implode( ' ', Sitelemetry_Audit_Verification::warnings( 'http://example.org/' ) ) );

		// A non-default port: Sitelemetry fetches the default port, the site's own link keeps the port.
		$this->assertSame( 'This site address uses port 8443. Sitelemetry fetches the file from the default port of the host (443 for https://, 80 for http://), so the file must also be reachable there; Test the file checks that address.', implode( ' ', Sitelemetry_Audit_Verification::warnings( 'https://www.example.org:8443/' ) ) );
		$this->assertSame( array(), Sitelemetry_Audit_Verification::warnings( 'https://www.example.org:443/' ) );
		$this->assertSame( 'https://www.example.org/.well-known/sitelemetry-verification.txt', Sitelemetry_Audit_Verification::file_url( 'https://www.example.org:8443/' ) );
		$this->assertSame( 'https://www.example.org:8443/.well-known/sitelemetry-verification.txt', Sitelemetry_Audit_Verification::site_file_url( 'https://www.example.org:8443/' ) );
		$this->assertSame( 'http://www.example.org/.well-known/sitelemetry-verification.txt', Sitelemetry_Audit_Verification::site_file_url( 'http://www.example.org:80/' ) );
		$this->assertNull( Sitelemetry_Audit_Verification::custom_port( 'http://www.example.org/' ) );

		// Hosts without a www variant or DNS records.
		$this->assertTrue( Sitelemetry_Audit_Verification::is_domain_host( 'www.example.org' ) );
		foreach ( array( '127.0.0.1', '[::1]', 'localhost', '' ) as $host ) {
			$this->assertFalse( Sitelemetry_Audit_Verification::is_domain_host( $host ), $host );
		}
	}

	/**
	 * The last self-test is stored as a code with its values and written in the
	 * language of whoever views it, not of whoever ran it.
	 *
	 * @return void
	 */
	public function test_self_test_message_in_the_viewers_language() {
		Sitelemetry_Audit_Verification::save_token( self::TOKEN );
		$this->serve( array( 'https://www.example.org/.well-known/sitelemetry-verification.txt' => sitelemetry_test_response( 301, '', array( 'location' => 'https://example.org/x' ) ) ) );
		Sitelemetry_Audit_Verification::remember_test( Sitelemetry_Audit_Verification::test_file() );
		$stored = Sitelemetry_Audit_Verification::stored()['last_test'];
		$this->assertSame( array( 'redirect_host', 301, 'example.org' ), array( $stored['code'], $stored['status'], $stored['detail'] ) );
		$this->assertArrayNotHasKey( 'message', $stored );

		$english = 'The file address redirects (HTTP %1$d) to another host, %2$s. Sitelemetry does not follow redirects to another host, so verification fails. Add and verify that host in the app instead, or stop the redirect for this path.';
		$GLOBALS['sitelemetry_test_translations'] = array( $english => 'Dosya adresi (HTTP %1$d) başka bir alan adına yönlendiriyor: %2$s.' );
		$this->assertSame( 'Dosya adresi (HTTP 301) başka bir alan adına yönlendiriyor: example.org.', Sitelemetry_Audit_Verification::test_message( $stored ) );

		// An unknown code keeps the message stored with it.
		$this->assertSame( 'Old text', Sitelemetry_Audit_Verification::test_message( array( 'code' => 'future', 'message' => 'Old text' ) ) );
		$this->assertSame( '', Sitelemetry_Audit_Verification::test_message( array() ) );
	}

	/**
	 * On a multisite network a site administrator cannot publish the file: the
	 * server behind the host is the network's. A network administrator can, and
	 * a token stored without that capability is never served.
	 *
	 * @return void
	 */
	public function test_multisite_needs_a_network_administrator() {
		$this->assertSame( 'manage_options', Sitelemetry_Audit_Verification::capability() );
		$this->assertTrue( Sitelemetry_Audit_Verification::current_user_can_publish() );

		$GLOBALS['sitelemetry_test_multisite'] = true;
		$GLOBALS['sitelemetry_test_home']      = 'https://site2.network.example';
		$this->assertSame( 'manage_network_options', Sitelemetry_Audit_Verification::capability() );
		$this->assertFalse( Sitelemetry_Audit_Verification::current_user_can_publish(), 'a site administrator' );

		// Even if a token reached the option without the capability, it is not served.
		Sitelemetry_Audit_Verification::save_token( self::TOKEN );
		$this->assertFalse( Sitelemetry_Audit_Verification::stored()['authorized'] );
		$this->assertSame( '', Sitelemetry_Audit_Verification::served_token() );
		$this->assertSame( 404, Sitelemetry_Audit_Verification::response( 'GET', Sitelemetry_Audit_Verification::served_token() )['status'] );

		$verify           = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://site2.network.example/' );
		$verify['status'] = 'verification_required';
		$verify['reason'] = 'target_verification_required';
		$cta              = Sitelemetry_Audit_Verification::call_to_action( $verify );
		$this->assertSame( array( false, true ), array( $cta['helper'], $cta['restricted'] ) );

		// A network administrator.
		$GLOBALS['sitelemetry_test_caps'] = array( 'manage_options', 'manage_network_options' );
		$this->assertTrue( Sitelemetry_Audit_Verification::current_user_can_publish() );
		Sitelemetry_Audit_Verification::save_token( self::TOKEN );
		$this->assertTrue( Sitelemetry_Audit_Verification::stored()['authorized'] );
		$this->assertSame( self::TOKEN, Sitelemetry_Audit_Verification::served_token() );
		$cta = Sitelemetry_Audit_Verification::call_to_action( $verify );
		$this->assertSame( array( true, false ), array( $cta['helper'], $cta['restricted'] ) );

		// On a single site the site administrator publishes, as before.
		$GLOBALS['sitelemetry_test_multisite'] = false;
		$GLOBALS['sitelemetry_test_caps']      = array( 'manage_options' );
		$this->assertTrue( Sitelemetry_Audit_Verification::current_user_can_publish() );
	}

	/**
	 * The call to action follows what the service reported, with the renewal wording
	 * for target_reverification_required and the helper only for this site's host.
	 *
	 * @return void
	 */
	public function test_call_to_action() {
		$base = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://www.example.org/' );

		$verify           = $base;
		$verify['status'] = 'verification_required';
		$verify['reason'] = 'target_verification_required';
		$cta              = Sitelemetry_Audit_Verification::call_to_action( $verify );
		$this->assertSame( array( false, true, 'www.example.org' ), array( $cta['renew'], $cta['helper'], $cta['host'] ) );
		$this->assertSame( 'This audit runs only after ownership of www.example.org is verified.', $cta['text'] );

		$renew           = $verify;
		$renew['reason'] = 'target_reverification_required';
		$cta             = Sitelemetry_Audit_Verification::call_to_action( $renew );
		$this->assertTrue( $cta['renew'] );
		$this->assertStringContainsString( 'has expired. Verification lasts 30 days', $cta['text'] );

		$scope           = $verify;
		$scope['reason'] = 'verification_scope_required';
		$this->assertStringContainsString( 'covers only part of the host', Sitelemetry_Audit_Verification::call_to_action( $scope )['text'] );

		$partial                 = $base;
		$partial['status']       = 'partial';
		$partial['not_measured'] = array(
			array(
				'type'    => 'verification_modules',
				'modules' => array( 'exposure' ),
			),
		);
		$cta                     = Sitelemetry_Audit_Verification::call_to_action( $partial );
		$this->assertSame( 'Some checks run only after ownership of www.example.org is verified.', $cta['text'] );

		$other           = $verify;
		$other['target'] = 'https://example.org/';
		$this->assertFalse( Sitelemetry_Audit_Verification::call_to_action( $other )['helper'], 'www and non-www are different hosts' );

		foreach ( array( 'authorization_consent_required', 'usage_limit_reached' ) as $reason ) {
			$gate           = $base;
			$gate['status'] = 'verification_required';
			$gate['reason'] = $reason;
			$this->assertNull( Sitelemetry_Audit_Verification::call_to_action( $gate ), $reason );
		}
	}

	/**
	 * Without a stored API key nothing is requested: one-click verification is
	 * offered disabled, and a click only records that the key is missing.
	 *
	 * @return void
	 */
	public function test_no_request_without_a_key() {
		$log = $this->serve( array() );
		$this->assertSame( 'no_key', Sitelemetry_Audit_Verification::one_click_state() );
		$this->assertFalse( Sitelemetry_Audit_Verification::one_click_available() );
		$result = Sitelemetry_Audit_Verification::request_one_click();
		$this->assertSame( array( false, 'no_api_key' ), array( $result['ok'], $result['code'] ) );
		$this->assertSame( 'Save an API key first.', Sitelemetry_Audit_Verification::one_click_message( Sitelemetry_Audit_Verification::api_state()['last'] ) );
		$this->assertFalse( Sitelemetry_Audit_Verification::auto_renew_allowed() );
		$this->assertSame( 'skipped', Sitelemetry_Audit_Verification::renew() );
		$this->assertCount( 0, $log );
	}
}
