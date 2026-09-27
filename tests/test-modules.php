<?php
/**
 * Passing checks and "what was not measured" tests.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-admin.php';

/**
 * Sitelemetry_Audit_Modules, Sitelemetry_Audit_Outcome::passing_entries() and the
 * not-measured views.
 */
class Sitelemetry_Audit_Modules_Test extends TestCase {

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * Result model from a fixture.
	 *
	 * @param string $fixture Fixture.
	 * @param string $kind    Kind.
	 * @return array
	 */
	private function model( $fixture, $kind = 'security' ) {
		return Sitelemetry_Audit_Outcome::interpret(
			array(
				'outcome' => 'result',
				'tool'    => Sitelemetry_Audit_Labels::tool_for( $kind ),
				'job_id'  => null,
				'result'  => sitelemetry_test_fixture( $fixture ),
			),
			$kind,
			'https://ok.example'
		);
	}

	/**
	 * Check ids map to the module that ran them.
	 *
	 * @return void
	 */
	public function test_check_module() {
		$map = array(
			'ok.dns.resolve'                        => 'dns',
			'ok.dns.email.spf'                      => 'dns-email',
			'ok.dns.caa'                            => 'dns-email',
			'ok.rdap.lookup'                        => 'rdap',
			'ok.tls.protocol.modern'                => 'tls',
			'ok.http.header.hsts'                   => 'http-headers',
			'ok.http.cors.no-wildcard'              => 'http-headers',
			'ok.http.cors.no-reflection'            => 'http-methods',
			'ok.http.trace.disabled'                => 'http-methods',
			'ok.mitm.no-mixed-content'              => 'mitm-posture',
			'ok.exposure.env'                       => 'exposure',
			'ok.api.swagger-json'                   => 'api-exposure',
			'ok.auth.wp-admin'                      => 'auth-surface',
			'ok.wordpress.xmlrpc'                   => 'wordpress',
			'ok.ports.risky-closed'                 => 'ports',
			'ok.port-intel.summary'                 => 'port-intel',
			'ok.secret-exposure.clean'              => 'secret-exposure',
			'delivery.compression.ok'               => 'transport-delivery',
			'ok.engine.wpscan.core'                 => 'engine-wpscan',
			'ok.engine.unknown.x'                   => null,
			'ok.seo.unique-titles'                  => null,
			''                                      => null,
		);
		foreach ( $map as $id => $module ) {
			$this->assertSame( $module, Sitelemetry_Audit_Modules::check_module( $id ), (string) $id );
		}
		$this->assertSame( 'WordPress posture', Sitelemetry_Audit_Modules::label( 'wordpress' ) );
		$this->assertSame( 'custom-module', Sitelemetry_Audit_Modules::label( 'custom-module' ) );
		$this->assertSame( 'not measured', Sitelemetry_Audit_Modules::status_label( 'unavailable' ) );
		$this->assertSame( 'partly measured', Sitelemetry_Audit_Modules::status_label( 'partial' ) );
	}

	/**
	 * Passing checks are grouped by module in the order sitelemetry.com lists the
	 * modules; the section is closed by default in the view.
	 *
	 * @return void
	 */
	public function test_passing_section_groups() {
		$section = Sitelemetry_Audit_Modules::passing_section( $this->model( 'security-partial-redirects.json' ) );
		$this->assertSame( 'Passing checks (30)', $section['title'] );
		$this->assertSame( '', $section['note'] );
		$titles = array_column( $section['groups'], 'title' );
		$this->assertSame(
			array( 'DNS posture (1)', 'Email DNS (3)', 'WHOIS / RDAP (1)', 'TLS / certificate (3)', 'HTTP security headers (5)', 'MITM / HTTPS posture (1)', 'HTTP methods / CORS (3)', 'API / GraphQL exposure (5)', 'Admin / login surface (4)', 'Port scan (2)', 'Leaked secrets (1)', 'Compression / CDN (1)' ),
			$titles
		);
		$this->assertSame(
			array(
				'title'    => 'Modern TLS protocol in use',
				'evidence' => 'TLSv1.3',
			),
			$section['groups'][3]['items'][2]
		);
		// The positive observation moved to passingFindings is listed with its module.
		$this->assertSame( 'Compression is enabled', $section['groups'][11]['items'][0]['title'] );
	}

	/**
	 * Full audit: the security pillar is grouped by module, the other pillars by pillar.
	 *
	 * @return void
	 */
	public function test_passing_section_full_audit() {
		$section = Sitelemetry_Audit_Modules::passing_section( $this->model( 'full-partial.json', 'full' ) );
		$this->assertSame( 'Passing checks (4)', $section['title'] );
		$this->assertSame( array( 'DNS posture (1)', 'TLS / certificate (1)', 'SEO (2)' ), array_column( $section['groups'], 'title' ) );

		$seo           = Sitelemetry_Audit_Outcome::empty_model( 'seo', 'https://ok.example' );
		$seo['status'] = 'completed';
		$seo['passing_items'] = array(
			array( 'id' => 'ok.seo.unique-titles', 'title' => 'Page titles are unique', 'evidence' => '', 'pillar' => '' ),
			array( 'id' => 'ok.tls.x', 'title' => 'Not a security audit', 'evidence' => '', 'pillar' => '' ),
		);
		$seo['passing_checks'] = 2;
		$this->assertSame( array( 'Technical SEO (2)' ), array_column( Sitelemetry_Audit_Modules::passing_section( $seo )['groups'], 'title' ) );
	}

	/**
	 * Counts without a list: a result stored by 0.1.2, a list the service did not
	 * send, and more checks counted than kept.
	 *
	 * @return void
	 */
	public function test_passing_section_notes() {
		$legacy = $this->model( 'security-completed.json' );
		unset( $legacy['passing_items'] );
		$section = Sitelemetry_Audit_Modules::passing_section( $legacy );
		$this->assertSame( 'Passing checks (12)', $section['title'] );
		$this->assertSame( array(), $section['groups'] );
		$this->assertStringContainsString( 'earlier version of the plugin', $section['note'] );
		$this->assertStringContainsString( 'The list appears here after the next audit.', $section['note'] );

		$unsent                  = $this->model( 'security-completed.json' );
		$unsent['passing_items'] = array();
		$this->assertSame( 'Sitelemetry counted these passing checks but did not send the list of them with this result.', Sitelemetry_Audit_Modules::passing_section( $unsent )['note'] );

		$more                   = $this->model( 'security-completed.json' );
		$more['passing_checks'] = 342;
		$section                = Sitelemetry_Audit_Modules::passing_section( $more );
		$this->assertSame( 'Passing checks (12 of 342)', $section['title'] );
		$this->assertSame( '330 more passing checks were counted but not kept with this result, so they are not listed here.', $section['note'] );

		$none                   = $this->model( 'security-completed.json' );
		$none['passing_items']  = array();
		$none['passing_checks'] = 0;
		$this->assertNull( Sitelemetry_Audit_Modules::passing_section( $none ) );
	}

	/**
	 * The stored list is bounded: 300 items, clipped fields, a character budget;
	 * the count covers every passing check.
	 *
	 * @return void
	 */
	public function test_passing_entries_are_bounded() {
		$items = array();
		for ( $index = 0; $index < 400; $index++ ) {
			$items[] = array(
				'id'       => 'ok.tls.check-' . $index,
				'status'   => 'ok',
				'title'    => str_repeat( 'T', 400 ),
				'evidence' => str_repeat( 'E', 900 ),
			);
		}
		$items[] = array( 'id' => 'skip.tls.x', 'status' => 'skipped', 'title' => 'Skipped', 'evidence' => '' );
		$items[] = array( 'id' => 'x', 'status' => 'fail', 'title' => 'Failed', 'evidence' => '' );
		$items[] = 'not a row';
		$entries = Sitelemetry_Audit_Outcome::passing_entries( array( 'auditDetails' => array( 'checks' => array( 'items' => $items ) ) ) );
		$this->assertSame( 400, $entries['count'] );
		$this->assertGreaterThan( 0, count( $entries['items'] ) );
		$this->assertLessThanOrEqual( Sitelemetry_Audit_Outcome::MAX_PASSING_ITEMS, count( $entries['items'] ) );
		$this->assertSame( 180, mb_strlen( $entries['items'][0]['title'] ) );
		$this->assertSame( 300, mb_strlen( $entries['items'][0]['evidence'] ) );
		$chars = 0;
		foreach ( $entries['items'] as $item ) {
			$chars += mb_strlen( $item['id'] ) + mb_strlen( $item['title'] ) + mb_strlen( $item['evidence'] );
		}
		$this->assertLessThanOrEqual( Sitelemetry_Audit_Outcome::MAX_PASSING_CHARS, $chars );
		$this->assertLessThanOrEqual( 120000, strlen( serialize( $entries['items'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$small = array();
		for ( $index = 0; $index < 350; $index++ ) {
			$small[] = array( 'id' => 'ok.dns.' . $index, 'status' => 'ok', 'title' => 'T' . $index, 'evidence' => '' );
		}
		$entries = Sitelemetry_Audit_Outcome::passing_entries( array( 'auditDetails' => array( 'checks' => array( 'items' => $small ) ) ) );
		$this->assertCount( 300, $entries['items'] );
		$this->assertSame( 350, $entries['count'] );

		// A model keeps the server's count; the view says how many were not kept.
		$model = Sitelemetry_Audit_Outcome::interpret(
			array(
				'outcome' => 'result',
				'tool'    => 'audit_security',
				'job_id'  => null,
				'result'  => array(
					'structuredContent' => array(
						'status'        => 'completed',
						'findings'      => array(),
						'passingChecks' => 350,
						'auditDetails'  => array( 'checks' => array( 'items' => $small ) ),
					),
				),
			),
			'security',
			'https://ok.example'
		);
		$this->assertSame( 350, $model['passing_checks'] );
		$this->assertSame( 'Passing checks (300 of 350)', Sitelemetry_Audit_Modules::passing_section( $model )['title'] );
	}

	/**
	 * The causes the extension explains, in the service's English and Turkish wording.
	 *
	 * @return void
	 */
	public function test_explanations() {
		foreach ( array( 'security-partial-redirects.json', 'security-partial-redirects-tr.json' ) as $fixture ) {
			$section = Sitelemetry_Audit_Labels::not_measured_section( $this->model( $fixture ) );
			$views   = array();
			foreach ( $section['items'] as $item ) {
				$views[ $item['text'] ] = $item;
			}
			// 7 module-level entries and the 12 checks the fixture reports as skipped.
			$this->assertSame( 'What was not measured (19)', $section['title'], $fixture );
			$this->assertCount( 1, $section['checks'], $fixture );
			$this->assertSame( 'Checks without a result (12)', $section['checks'][0]['title'], $fixture );
			$this->assertStringContainsString( 'could not determine a result', $section['checks'][0]['explanation'] );
			$this->assertSame( '', $section['checks_note'] );
			$this->assertStringContainsString( 'Explained items come from how the site responded', $section['note'] );
			$this->assertSame( 'The site answers these paths with a redirect (HTTP 307), for example to its sign-in page. Sitelemetry does not follow redirects for these checks, so they were not measured.', $views['Sensitive file exposure: not measured']['explanation'], $fixture );
			$this->assertStringContainsString( '(HTTP 307/308)', $views['API / GraphQL exposure: partly measured']['explanation'] );
			$this->assertStringStartsWithReported( $views['Sensitive file exposure: not measured']['detail'] );
			$this->assertStringContainsString( '12 ports gave no reply: a firewall, usually the site\'s own, silently drops these connection attempts.', $views['Port scan: partly measured']['explanation'] );
			$this->assertSame( 'Only a limited number of scripts is scanned per audit; the remaining scripts were not checked and are not counted as clean.', $views['Leaked secrets: partly measured']['explanation'] );
		}

		$this->assertSame( 'The site rate-limited these requests (its own protection). The site returned a server error for these requests. The site did not answer within the time limit.', Sitelemetry_Audit_Modules::explain( 'exposure', 'HTTP 429; HTTP 503; No response/timeout' ) );
		$this->assertSame( 'The site did not answer within the time limit.', Sitelemetry_Audit_Modules::explain( 'supply-chain', '応答なし/タイムアウト' ) );
		$this->assertSame( '1 port gave no reply: a firewall, usually the site\'s own, silently drops these connection attempts. This is normal hardening; silent ports are counted neither as open nor as closed.', Sitelemetry_Audit_Modules::explain( 'ports', '1 timeout/filtered; 0 network error' ) );
		// Unrecognised causes are not explained; the service's reason stays visible.
		$this->assertNull( Sitelemetry_Audit_Modules::explain( 'exposure', 'HTTP 404; HTTP 301' ) );
		$this->assertNull( Sitelemetry_Audit_Modules::explain( 'ports', '12 timeout/filtered; 2 network error' ) );
		$this->assertNull( Sitelemetry_Audit_Modules::explain( 'secret-exposure', '0/7 resource(s) could not be assessed; https://x.example/a.js HTTP 500' ) );
		$this->assertNull( Sitelemetry_Audit_Modules::explain( 'tls', 'HTTP 307' ) );
		$this->assertNull( Sitelemetry_Audit_Modules::explain( 'exposure', '' ) );
		$plain = Sitelemetry_Audit_Labels::not_measured_view(
			array(
				'type'    => 'module',
				'module'  => 'exposure',
				'status'  => 'unavailable',
				'reasons' => array( 'HTTP 404; HTTP 301' ),
			)
		);
		$this->assertSame( 'Sensitive file exposure: not measured (HTTP 404; HTTP 301)', $plain['text'] );
		$this->assertSame( '', $plain['explanation'] );
		$this->assertSame( '', $plain['detail'] );

		// A long reason is explained from its end and shown shortened.
		$long = str_repeat( 'https://x.example/script.js failed; ', 60 );
		$view = Sitelemetry_Audit_Labels::not_measured_view(
			array(
				'type'    => 'module',
				'module'  => 'secret-exposure',
				'status'  => 'partial',
				'reasons' => array( '0/70 resource(s) could not be assessed; ' . $long . '7 script(s) remained outside the bounded scan.' ),
			)
		);
		$this->assertStringContainsString( 'Only a limited number of scripts', $view['explanation'] );
		$this->assertLessThanOrEqual( Sitelemetry_Audit_Modules::SHOWN_REASON_CHARS + 40, mb_strlen( $view['detail'] ) );
	}

	/**
	 * The detail line names the service's reason.
	 *
	 * @param string $detail Detail line.
	 * @return void
	 */
	private function assertStringStartsWithReported( $detail ) {
		$this->assertSame( 0, strpos( $detail, 'Reported by the audit: ' ) );
	}

	/**
	 * Verification-gated modules are flagged for the verification link, and a
	 * partial result without entries still says something truthful.
	 *
	 * @return void
	 */
	public function test_verification_entry_and_unknown() {
		$section = Sitelemetry_Audit_Labels::not_measured_section( $this->model( 'security-partial-free.json' ) );
		$this->assertTrue( $section['items'][0]['verification'] );
		$this->assertSame( 'Unmeasured checks are not counted as passes.', $section['note'] );
		$empty = Sitelemetry_Audit_Labels::not_measured_section( array( 'not_measured' => array() ) );
		$this->assertSame( 'What was not measured (1)', $empty['title'] );
		$this->assertSame( 'Some requested measurements were unavailable; this result does not say which.', $empty['items'][0]['text'] );
		$this->assertSame( array(), $empty['checks'] );
	}

	/**
	 * Checks that are neither a pass nor a fail (error, skipped, manual review,
	 * not applicable) are kept with their status and listed by status, in that
	 * order, with a count of those not kept; "observed" is not among them.
	 *
	 * @return void
	 */
	public function test_checks_without_a_result() {
		$items = array(
			array( 'id' => 'tls.ocsp', 'status' => 'error', 'title' => 'OCSP stapling', 'evidence' => 'Timeout <script>x</script>' ),
			array( 'id' => 'dns.dnssec', 'status' => 'not_applicable', 'title' => 'DNSSEC', 'evidence' => '' ),
			array( 'id' => 'http.header.permissions-policy', 'status' => 'manual_review', 'title' => 'Permissions-Policy', 'evidence' => 'Review the allowed features.' ),
			array( 'id' => 'rdap.expiry', 'status' => 'skipped', 'title' => '', 'evidence' => 'Registry timeout' ),
			array( 'id' => 'tech.server', 'status' => 'observed', 'title' => 'Server banner', 'evidence' => 'nginx' ),
			array( 'id' => 'tls.protocol', 'status' => 'ok', 'title' => 'Modern TLS', 'evidence' => 'TLS 1.3' ),
			array( 'id' => 'http.header.csp', 'status' => 'fail', 'title' => 'CSP', 'evidence' => 'Missing' ),
		);
		$model = Sitelemetry_Audit_Outcome::interpret(
			array(
				'outcome' => 'result',
				'tool'    => 'audit_security',
				'job_id'  => null,
				'result'  => array(
					'structuredContent' => array(
						'status'       => 'completed',
						'findings'     => array(),
						'auditDetails' => array( 'checks' => array( 'items' => $items ) ),
					),
				),
			),
			'security',
			'https://ok.example'
		);
		$this->assertSame( 'completed', $model['status'] );
		$this->assertSame( 4, $model['unmeasured_total'] );
		$this->assertSame( array( 'error', 'not_applicable', 'manual_review', 'skipped' ), array_column( $model['unmeasured_checks'], 'status' ) );

		$section = Sitelemetry_Audit_Labels::not_measured_section( $model );
		$this->assertSame( 'What was not measured (4)', $section['title'] );
		$this->assertSame( array(), $section['items'], 'no "does not say which" line when checks are listed' );
		$this->assertSame(
			array( 'Checks that ended with an error (1)', 'Checks without a result (1)', 'Checks to review yourself (1)', 'Checks that do not apply (1)' ),
			array_column( $section['checks'], 'title' )
		);
		$this->assertSame( 'OCSP stapling', $section['checks'][0]['items'][0]['title'] );
		$this->assertSame( 'Timeout <script>x</script>', $section['checks'][0]['items'][0]['evidence'], 'kept as text; the view escapes it' );
		$this->assertSame( 'rdap.expiry', $section['checks'][1]['items'][0]['title'], 'a check without a title shows its id' );

		// A completed result with such checks gets the section; the prompt counts them.
		$sections = Sitelemetry_Audit_Admin::result_sections( $model );
		$this->assertNotNull( $sections['not_measured'] );
		$this->assertStringContainsString( '- Checks without a pass or fail result: 1 error, 1 skipped, 1 manual review, 1 not applicable', $sections['fix_prompt'] );
		$this->assertStringContainsString( 'some individual checks have no pass or fail result', $sections['fix_prompt'] );
		$this->assertStringNotContainsString( 'Every requested check was measured', $sections['fix_prompt'] );

		// Bounded like the passing checks; the note counts the checks not kept.
		$many = array();
		for ( $i = 0; $i < Sitelemetry_Audit_Outcome::MAX_UNMEASURED_ITEMS + 5; $i++ ) {
			$many[] = array( 'id' => 'engine.x.' . $i, 'status' => 'skipped', 'title' => 'Check ' . $i, 'evidence' => '' );
		}
		$entries = Sitelemetry_Audit_Outcome::unmeasured_entries( array( 'auditDetails' => array( 'checks' => array( 'items' => $many ) ) ) );
		$this->assertCount( Sitelemetry_Audit_Outcome::MAX_UNMEASURED_ITEMS, $entries['items'] );
		$this->assertSame( Sitelemetry_Audit_Outcome::MAX_UNMEASURED_ITEMS + 5, $entries['count'] );
		$section = Sitelemetry_Audit_Labels::not_measured_section(
			array(
				'status'            => 'completed',
				'not_measured'      => array(),
				'unmeasured_checks' => $entries['items'],
				'unmeasured_total'  => $entries['count'],
			)
		);
		$this->assertSame( 'What was not measured (205)', $section['title'] );
		$this->assertSame( '5 more checks without a result were counted but not kept with this result, so they are not listed here.', $section['checks_note'] );

		// A completed result without such checks has no section.
		$this->assertNull( Sitelemetry_Audit_Admin::result_sections( $this->model( 'security-completed.json' ) )['not_measured'] );
	}
}
