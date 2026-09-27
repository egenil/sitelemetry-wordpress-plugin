<?php
/**
 * Outcome tests: status classification, findings normalization, coverage notes.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_Outcome and the labels that render its entries.
 */
class Sitelemetry_Audit_Outcome_Test extends TestCase {

	/**
	 * Result run helper.
	 *
	 * @param array $result Tool result.
	 * @return array
	 */
	private function result_run( array $result ) {
		return array(
			'outcome' => 'result',
			'tool'    => 'audit_security',
			'job_id'  => null,
			'result'  => $result,
		);
	}

	/**
	 * Gate run helper.
	 *
	 * @param string $reason Reason.
	 * @return string Status.
	 */
	private function gate( $reason ) {
		$model = Sitelemetry_Audit_Outcome::interpret( $this->result_run( Sitelemetry_Test_Mock_Service::action_required( $reason, 'Audit not started.' ) ), 'security', 'https://ok.example' );
		return $model['status'];
	}

	/**
	 * Pre-execution gates map to statuses.
	 *
	 * @return void
	 */
	public function test_action_required_gates() {
		$this->assertSame( 'plan_required', $this->gate( 'entitlement_required' ) );
		$this->assertSame( 'quota_exhausted', $this->gate( 'usage_limit_reached' ) );
		$this->assertSame( 'verification_required', $this->gate( 'target_verification_required' ) );
		$this->assertSame( 'verification_required', $this->gate( 'target_reverification_required' ) );
		$this->assertSame( 'verification_required', $this->gate( 'verification_scope_required' ) );
		$this->assertSame( 'verification_required', $this->gate( 'authorization_consent_required' ) );
		$this->assertSame( 'blocked', $this->gate( 'audit_job_unavailable' ) );

		$model = Sitelemetry_Audit_Outcome::interpret( $this->result_run( Sitelemetry_Test_Mock_Service::action_required( 'usage_limit_reached', 'Audit not started. Allowance exhausted.' ) ), 'security', 'https://ok.example' );
		$this->assertSame( 'usage_limit_reached', $model['reason'] );
		$this->assertSame( 'Audit not started. Allowance exhausted.', $model['message'] );
	}

	/**
	 * Transport, HTTP and RPC errors map to statuses and reasons.
	 *
	 * @return void
	 */
	public function test_error_outcomes() {
		$error_run = function ( $error ) {
			return array(
				'outcome' => 'error',
				'tool'    => 'audit_security',
				'job_id'  => null,
				'error'   => $error,
			);
		};
		$timeout = Sitelemetry_Audit_Outcome::interpret( array( 'outcome' => 'timeout', 'tool' => 'audit_security', 'job_id' => 'mj_x' ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'blocked', 'timeout', 'mj_x' ), array( $timeout['status'], $timeout['reason'], $timeout['job_id'] ) );
		$this->assertStringContainsString( 'mj_x', Sitelemetry_Audit_Labels::reason_lead( $timeout ) );

		$unauthorized = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_http', 'Unauthorized.', array( 'status' => 401, 'code' => null, 'retry_after_ms' => null ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'blocked', 'unauthorized' ), array( $unauthorized['status'], $unauthorized['reason'] ) );

		$payment = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_http', 'This feature is not included in Free.', array( 'status' => 402, 'code' => 'PLAN_UPGRADE_REQUIRED', 'retry_after_ms' => null ) ) ), 'seo', 'https://ok.example' );
		$this->assertSame( array( 'plan_required', 'PLAN_UPGRADE_REQUIRED' ), array( $payment['status'], $payment['reason'] ) );

		$quota_http = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_http', 'Monthly security scan limit reached for this account (monthly allowance: 10).', array( 'status' => 429, 'code' => 'COMMERCIAL_USAGE_LIMIT_REACHED', 'retry_after_ms' => 60000 ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( 'quota_exhausted', $quota_http['status'] );

		$quota_rpc = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_rpc', 'Monthly security scan limit reached for this account (monthly allowance: 10).', array( 'code' => -32603 ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'quota_exhausted', 'rpc_-32603' ), array( $quota_rpc['status'], $quota_rpc['reason'] ) );

		$plan_rpc = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_rpc', 'The requested audit requires Starter or higher access and is not included in the connected Free account.', array( 'code' => -32603 ) ) ), 'seo', 'https://ok.example' );
		$this->assertSame( 'plan_required', $plan_rpc['status'] );

		// A verification-flavoured HTTP error is not a gate the server reported; it stays blocked.
		$verify_http = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_http', 'Verify ownership first.', array( 'status' => 400, 'code' => null, 'retry_after_ms' => null ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'blocked', 'http_400' ), array( $verify_http['status'], $verify_http['reason'] ) );

		$transport = Sitelemetry_Audit_Outcome::interpret( $error_run( new WP_Error( 'sitelemetry_transport', 'cURL error 28', array( 'wp_code' => 'http_request_failed' ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'blocked', 'transport', 'cURL error 28' ), array( $transport['status'], $transport['reason'], $transport['message'] ) );

		$tool_error = Sitelemetry_Audit_Outcome::interpret( $this->result_run( array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => 'Error: audit failed' ) ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'blocked', 'tool_error' ), array( $tool_error['status'], $tool_error['reason'] ) );

		$running = Sitelemetry_Audit_Outcome::interpret( $this->result_run( array( 'structuredContent' => array( 'status' => 'running', 'jobId' => 'mj_1' ) ) ), 'security', 'https://ok.example' );
		$this->assertSame( array( 'blocked', 'incomplete' ), array( $running['status'], $running['reason'] ) );
	}

	/**
	 * A completed security audit.
	 *
	 * @return void
	 */
	public function test_completed_fixture() {
		$model = Sitelemetry_Audit_Outcome::interpret( $this->result_run( sitelemetry_test_fixture( 'security-completed.json' ) ), 'security', 'https://ok.example' );
		$this->assertSame( 'completed', $model['status'] );
		$this->assertSame( array( 82, 'B', 4, 'starter', 12 ), array( $model['score'], $model['grade'], $model['total'], $model['plan'], $model['passing_checks'] ) );
		$this->assertSame( array( 'critical' => 0, 'high' => 1, 'medium' => 1, 'low' => 1, 'info' => 1 ), $model['counts'] );
		$this->assertCount( 4, $model['findings'] );
		$this->assertSame( 'Send Strict-Transport-Security: max-age=31536000; includeSubDomains on every HTTPS response.', $model['findings'][0]['fix'] );
		$this->assertSame( '/robots.txt', $model['findings'][2]['location'] );
		$this->assertSame( 'sf2:security-audit:id:http-headers.hsts-missing:loc:1a2b3c4d5e6f7a8b9c0d1e2f3a4b:occ:1', $model['findings'][0]['id'] );
		$this->assertSame( array(), $model['not_measured'] );
		$this->assertFalse( $model['truncated'] );
		$this->assertArrayNotHasKey( 'remaining_scans', $model );
		$this->assertArrayNotHasKey( 'report_url', $model );
		$this->assertCount( 12, $model['passing_items'] );
		$this->assertSame(
			array(
				'id'       => 'ok.dns.resolve',
				'title'    => 'DNS resolution successful',
				'evidence' => '198.51.100.7',
				'pillar'   => '',
			),
			$model['passing_items'][0]
		);
		$this->assertSame( 'Completed', Sitelemetry_Audit_Labels::status_heading( $model ) );
	}

	/**
	 * A partial Free-plan audit lists the verification-gated modules and the
	 * unavailable module with its reason.
	 *
	 * @return void
	 */
	public function test_partial_free_fixture() {
		$model = Sitelemetry_Audit_Outcome::interpret( $this->result_run( sitelemetry_test_fixture( 'security-partial-free.json' ) ), 'security', 'https://free.example' );
		$this->assertSame( 'partial', $model['status'] );
		$this->assertSame( 'free', $model['plan'] );
		$this->assertSame( 74, $model['score'] );
		$lines = array_map( array( 'Sitelemetry_Audit_Labels', 'not_measured_line' ), $model['not_measured'] );
		$this->assertContains( 'Security modules that require ownership verification of the target: HTTP methods / CORS, Sensitive file exposure, API / GraphQL exposure', $lines );
		$this->assertContains( 'WHOIS / RDAP: not measured (registry_timeout)', $lines );
		$this->assertSame( 'Completed with partial coverage', Sitelemetry_Audit_Labels::status_heading( $model ) );
		$this->assertStringContainsString( 'Some checks run only after ownership of free.example is verified.', Sitelemetry_Audit_Verification::call_to_action( $model )['text'] );
		// Only checks with status ok are passing: fail, skipped and observed are not.
		$this->assertSame( 6, $model['passing_checks'] );
		$this->assertSame( array( 'ok.dns.resolve', 'ok.dns.email.spf', 'ok.tls.certificate.trusted', 'ok.tls.protocol.modern', 'ok.http.header.nosniff', 'ok.mitm.https-redirect' ), array_column( $model['passing_items'], 'id' ) );
	}

	/**
	 * A partial full audit: blended score, pillars and the failed pillar.
	 *
	 * @return void
	 */
	public function test_full_partial_fixture() {
		$run           = $this->result_run( sitelemetry_test_fixture( 'full-partial.json' ) );
		$run['tool']   = 'audit_full';
		$model         = Sitelemetry_Audit_Outcome::interpret( $run, 'full', 'https://full.example' );
		$this->assertSame( 'partial', $model['status'] );
		$this->assertSame( 71, $model['score'] );
		$this->assertSame( array( 'Security', 'SEO' ), array_keys( $model['pillars'] ) );
		$this->assertSame( 80, $model['pillars']['Security']['score'] );
		$this->assertSame( 'professional', $model['plan'] );
		$this->assertCount( 1, $model['not_measured'] );
		$this->assertSame( 'Performance: PageSpeed Insights was unavailable for this target.', Sitelemetry_Audit_Labels::not_measured_line( $model['not_measured'][0] ) );
		$this->assertSame( 'Security', $model['findings'][0]['pillar'] );
		$this->assertSame( 3, $model['total'] );
		// A full audit sends no passingChecks: the count comes from the pillars' lists.
		$this->assertSame( 4, $model['passing_checks'] );
		$this->assertSame( array( 'Security', 'Security', 'SEO', 'SEO' ), array_column( $model['passing_items'], 'pillar' ) );
	}

	/**
	 * A pillar without measurement and a module whose skipped checks are "reasons".
	 *
	 * @return void
	 */
	public function test_unmeasured_pillar_and_module_reasons() {
		$result = array(
			'content'           => array(),
			'structuredContent' => array(
				'status'            => 'partial',
				'coverageStatus'    => 'partial',
				'executionComplete' => true,
				'complete'          => true,
				'blended'           => 70,
				'failedPillars'     => array(),
				'findings'          => array(),
				'pillars'           => array(
					'Security'    => array( 'score' => 70, 'findings' => 0 ),
					'Performance' => array( 'score' => null, 'findings' => 0 ),
				),
				'auditDetails'      => array(
					'pillars' => array(
						'Security'    => array(
							'scope' => array(
								'status'        => 'partial',
								'plan'          => 'enterprise',
								'moduleResults' => array(
									array( 'module' => 'tls', 'status' => 'unavailable', 'checkCount' => 1, 'findingCount' => 0, 'reasons' => array( 'The TLS certificate check requires an https:// target.' ) ),
								),
							),
						),
						'Performance' => array( 'scope' => array( 'status' => 'unavailable', 'method' => 'lighthouse_crux' ) ),
					),
				),
			),
		);
		$run         = $this->result_run( $result );
		$run['tool'] = 'audit_full';
		$model       = Sitelemetry_Audit_Outcome::interpret( $run, 'full', 'https://x.example' );
		$this->assertSame( 'partial', $model['status'] );
		$this->assertSame( 'enterprise', $model['plan'] );
		$this->assertNull( $model['pillars']['Performance']['score'] );
		$lines = array_map( array( 'Sitelemetry_Audit_Labels', 'not_measured_line' ), $model['not_measured'] );
		$this->assertContains( 'Performance: unavailable (no measurement for this target)', $lines );
		$this->assertContains( 'TLS / certificate: not measured (The TLS certificate check requires an https:// target.)', $lines );
		$this->assertNull( $model['passing_checks'] );
		$this->assertSame( array(), $model['passing_items'] );
	}

	/**
	 * Real general /mcp results of the other audit kinds (sitelemetry.com,
	 * 2026-09-27): what makes them partial is listed, never "this result does not
	 * say which". SEO: robots.txt keeps the crawler out of /app. Performance:
	 * PageSpeed Insights answered without data, so the scope and every metric are
	 * unavailable. The passing checks are grouped under the audit kind and the
	 * prompt speaks with the role of that kind.
	 *
	 * @return void
	 */
	public function test_single_kind_partial_results_say_what_was_not_measured() {
		$run         = $this->result_run( sitelemetry_test_fixture( 'seo-partial-robots.json' ) );
		$run['tool'] = 'audit_seo';
		$seo         = Sitelemetry_Audit_Outcome::interpret( $run, 'seo', 'https://sitelemetry.com' );
		$this->assertSame( array( 'partial', 100, 0, 59 ), array( $seo['status'], $seo['score'], $seo['total'], $seo['passing_checks'] ) );
		$this->assertSame(
			array(
				array(
					'type'   => 'pages',
					'pillar' => '',
					'count'  => 1,
					'urls'   => array( 'https://sitelemetry.com/app' ),
					'robots' => true,
				),
			),
			$seo['not_measured']
		);
		$section = Sitelemetry_Audit_Labels::not_measured_section( $seo );
		$this->assertSame( array( 'Pages not assessed because robots.txt disallows them (1): https://sitelemetry.com/app' ), array_column( $section['items'], 'text' ) );
		$this->assertSame( 'What was not measured (1)', $section['title'] );
		$passing = Sitelemetry_Audit_Modules::passing_section( $seo );
		$this->assertSame( array( 'Technical SEO (59)' ), array_column( $passing['groups'], 'title' ) );
		$prompt = Sitelemetry_Audit_Fix_Prompt::build( $seo );
		$this->assertStringStartsWith( 'Act as a senior technical SEO specialist. I ran a Sitelemetry technical SEO audit of my website https://sitelemetry.com', $prompt );
		$this->assertStringContainsString( "Not measured (unmeasured checks are not passes):\n- Pages not assessed because robots.txt disallows them (1): https://sitelemetry.com/app\n", $prompt );
		$this->assertStringNotContainsString( 'does not say which', $prompt );

		$run         = $this->result_run( sitelemetry_test_fixture( 'performance-unavailable.json' ) );
		$run['tool'] = 'audit_performance';
		$perf        = Sitelemetry_Audit_Outcome::interpret( $run, 'performance', 'https://sitelemetry.com' );
		$this->assertSame( array( 'partial', null, 1 ), array( $perf['status'], $perf['score'], $perf['total'] ) );
		$lines = array_map( array( 'Sitelemetry_Audit_Labels', 'not_measured_line' ), $perf['not_measured'] );
		$this->assertSame( array( 'Performance: unavailable (no measurement for this target)', 'Metrics not measured: LCP, INP, CLS, FCP, TTFB' ), $lines );
		$this->assertNull( Sitelemetry_Audit_Modules::passing_section( $perf ) );
		$prompt = Sitelemetry_Audit_Fix_Prompt::build( $perf );
		$this->assertStringStartsWith( 'Act as a senior web performance engineer (Core Web Vitals). I ran a Sitelemetry performance audit', $prompt );
		$this->assertStringContainsString( "- Performance: unavailable (no measurement for this target)\n- Metrics not measured: LCP, INP, CLS, FCP, TTFB\n", $prompt );

		// Pillars of a full audit are named; a measured scope lists no page, and
		// an unmeasured page without a robots.txt rule is not blamed on robots.txt.
		$result = array(
			'content'           => array(),
			'structuredContent' => array(
				'status'       => 'partial',
				'findings'     => array(),
				'auditDetails' => array(
					'pillars' => array(
						'SEO'           => array(
							'scope' => array(
								'status' => 'partial',
								'pages'  => array(
									array( 'url' => 'https://x.example/', 'status' => 'applicable' ),
									array( 'url' => 'https://x.example/a', 'status' => 'unavailable', 'robotsRule' => '/a' ),
									array( 'url' => 'https://x.example/b', 'status' => 'unavailable' ),
								),
							),
						),
						'AI visibility' => array(
							'scope' => array(
								'status' => 'measured',
								'pages'  => array( array( 'url' => 'https://x.example/c', 'status' => 'unavailable' ) ),
							),
						),
						'Performance'   => array(
							'scope'   => array( 'status' => 'measured' ),
							'metrics' => array(
								array( 'id' => 'lcp', 'label' => 'LCP', 'status' => 'measured' ),
								array( 'id' => 'inp', 'label' => 'INP', 'status' => 'unavailable' ),
							),
						),
					),
				),
			),
		);
		$run         = $this->result_run( $result );
		$run['tool'] = 'audit_full';
		$full        = Sitelemetry_Audit_Outcome::interpret( $run, 'full', 'https://x.example' );
		$lines       = array_map( array( 'Sitelemetry_Audit_Labels', 'not_measured_line' ), $full['not_measured'] );
		$this->assertSame( array( 'SEO: Pages found but not assessed (2): https://x.example/a, https://x.example/b', 'Performance: Metrics not measured: INP' ), $lines );
	}

	/**
	 * Plan coverage notes. A report link or a remaining allowance is never taken
	 * from a result: the general /mcp endpoint removes both, and nothing in the
	 * plugin may promise a report in the app.
	 *
	 * @return void
	 */
	public function test_plan_coverage_and_optional_fields() {
		$result = array(
			'content'           => array( array( 'type' => 'text', 'text' => 'ok' ) ),
			'structuredContent' => array(
				'status'            => 'completed',
				'score'             => 90,
				'findings'          => array(),
				'findingsTruncated' => true,
				'total'             => 12,
				'reportUrl'         => 'https://sitelemetry.com/app/reports/1',
				'usage'             => array( 'remaining' => array( 'securityScans' => 7 ) ),
				'auditDetails'      => array(
					'planCoverage' => array(
						'plan'                   => 'starter',
						'skippedPillars'         => array( array( 'pillar' => 'Performance', 'reason' => 'not_in_plan' ), array( 'pillar' => 'SEO', 'reason' => 'other' ) ),
						'skippedSecurityModules' => array( 'ports', 'port-intel' ),
					),
				),
			),
		);
		$model = Sitelemetry_Audit_Outcome::interpret( $this->result_run( $result ), 'full', 'https://x.example' );
		$this->assertSame( 'partial', $model['status'] );
		$this->assertArrayNotHasKey( 'remaining_scans', $model );
		$this->assertArrayNotHasKey( 'report_url', $model );
		$this->assertTrue( $model['truncated'] );
		$this->assertSame( 12, $model['total'] );
		$lines = array_map( array( 'Sitelemetry_Audit_Labels', 'not_measured_line' ), $model['not_measured'] );
		$this->assertContains( 'Performance: not included in the connected plan', $lines );
		$this->assertContains( 'SEO: outside the connected account scope', $lines );
		$this->assertContains( 'Security modules outside the connected plan: Port scan, Port risk intelligence', $lines );
	}

	/**
	 * Finding defaults and clipping.
	 *
	 * @return void
	 */
	public function test_normalize_finding() {
		$finding = Sitelemetry_Audit_Outcome::normalize_finding( array( 'title' => 'x', 'severity' => 'bogus' ), 3 );
		$this->assertSame( 'info', $finding['severity'] );
		$this->assertMatchesRegularExpression( '/^finding-3-[0-9a-f]{8}$/', $finding['id'] );

		$untitled = Sitelemetry_Audit_Outcome::normalize_finding( array(), 0 );
		$this->assertSame( 'Untitled finding', $untitled['title'] );

		$long = Sitelemetry_Audit_Outcome::normalize_finding( array( 'title' => 'T', 'severity' => 'high', 'evidence' => str_repeat( 'e ', 800 ), 'fix' => "  keep\n\n  this ", 'url' => 'https://x.example/p' ), 0 );
		$this->assertSame( 1000, mb_strlen( $long['evidence'] ) );
		$this->assertSame( '…', mb_substr( $long['evidence'], -1 ) );
		$this->assertSame( 'keep this', $long['fix'] );
		$this->assertSame( 'https://x.example/p', $long['location'] );

		$this->assertSame( 'a b', Sitelemetry_Audit_Outcome::clip( "a\n\tb" ) );
		$this->assertSame( '', Sitelemetry_Audit_Outcome::clip( array( 'not' => 'scalar' ) ) );
	}

	/**
	 * Text classification of free-form messages.
	 *
	 * @return void
	 */
	public function test_status_from_text() {
		$this->assertSame( 'plan_required', Sitelemetry_Audit_Outcome::status_from_text( 'x', 'PLAN_UPGRADE_REQUIRED' ) );
		$this->assertSame( 'plan_required', Sitelemetry_Audit_Outcome::status_from_text( 'This audit requires Professional access.', null ) );
		$this->assertSame( 'quota_exhausted', Sitelemetry_Audit_Outcome::status_from_text( 'Monthly security scan limit reached.', null ) );
		$this->assertSame( 'verification_required', Sitelemetry_Audit_Outcome::status_from_text( 'Verify ownership of this target.', null ) );
		$this->assertSame( 'blocked', Sitelemetry_Audit_Outcome::status_from_text( 'Something else.', null ) );
	}
}
