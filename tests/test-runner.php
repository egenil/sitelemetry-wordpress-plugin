<?php
/**
 * Runner tests: the poll state machine (pure) and end-to-end steps against the mock service.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_Runner.
 */
class Sitelemetry_Audit_Runner_Test extends TestCase {

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * Installs settings with the test key.
	 *
	 * @param string $target Target.
	 * @param string $kind   Kind.
	 * @return void
	 */
	private function configure( $target = 'https://ok.example/', $kind = 'security' ) {
		update_option(
			Sitelemetry_Audit_Settings::OPTION,
			array(
				'api_key'        => Sitelemetry_Test_Mock_Service::API_KEY,
				'target'         => $target,
				'kind'           => $kind,
				'weekly_enabled' => false,
			)
		);
	}

	/**
	 * Names of the recorded actions.
	 *
	 * @return string[]
	 */
	private function action_names() {
		return array_map(
			function ( $call ) {
				return $call[0];
			},
			$GLOBALS['sitelemetry_test_actions']
		);
	}

	/**
	 * running -> running -> completed with the server's pollArguments re-sent unchanged.
	 *
	 * @return void
	 */
	public function test_advance_running_then_completed() {
		$job     = Sitelemetry_Audit_Runner::new_job( 'security', 'https://ok.example/', 'manual', 1000 );
		$running = Sitelemetry_Test_Mock_Service::running(
			array(
				'id'            => 'mj_1',
				'pollArguments' => array(
					'target' => 'https://ok.example/',
					'jobId'  => 'mj_1',
				),
			)
		);
		$step = Sitelemetry_Audit_Runner::advance( $job, $running, 1001 );
		$this->assertFalse( $step['done'] );
		$this->assertSame( 'mj_1', $step['job']['job_id'] );
		$this->assertSame( array( 'target' => 'https://ok.example/', 'jobId' => 'mj_1' ), $step['job']['args'] );
		$this->assertSame( 10, $step['job']['retry_after_ms'] );
		$this->assertSame( 1, $step['job']['polls'] );
		$this->assertSame( 'The audit is still running. Call the same tool with the returned pollArguments unchanged.', $step['job']['phase'] );

		$final = Sitelemetry_Audit_Runner::advance( $step['job'], sitelemetry_test_fixture( 'security-completed.json' ), 1002 );
		$this->assertTrue( $final['done'] );
		$this->assertSame( 'result', $final['run']['outcome'] );
		$this->assertSame( 'mj_1', $final['run']['job_id'] );
		$this->assertSame( 'audit_security', $final['run']['tool'] );

		// Without pollArguments the client falls back to target + jobId; a missing retryAfterMs uses the default.
		$bare = Sitelemetry_Audit_Runner::advance( $job, array( 'structuredContent' => array( 'status' => 'running', 'jobId' => 'mj_2' ) ), 1003 );
		$this->assertSame( array( 'target' => 'https://ok.example/', 'jobId' => 'mj_2' ), $bare['job']['args'] );
		$this->assertSame( Sitelemetry_Audit_Runner::DEFAULT_RETRY_MS, $bare['job']['retry_after_ms'] );
	}

	/**
	 * Rate limits and transient failures are retried with the server's delay; a
	 * commercial usage limit and non-transient errors end the job.
	 *
	 * @return void
	 */
	public function test_advance_retries_and_errors() {
		$job = Sitelemetry_Audit_Runner::new_job( 'security', 'https://ok.example/', 'manual', 1000 );

		$busy = new WP_Error( 'sitelemetry_http', 'Another audit is already running.', array( 'status' => 429, 'code' => null, 'retry_after_ms' => 7000 ) );
		$step = Sitelemetry_Audit_Runner::advance( $job, $busy, 1001 );
		$this->assertFalse( $step['done'] );
		$this->assertSame( 7000, $step['job']['retry_after_ms'] );
		$this->assertSame( 1, $step['job']['rate_limit_retries'] );

		$quota = new WP_Error( 'sitelemetry_http', 'Monthly limit reached.', array( 'status' => 429, 'code' => 'COMMERCIAL_USAGE_LIMIT_REACHED', 'retry_after_ms' => null ) );
		$step  = Sitelemetry_Audit_Runner::advance( $job, $quota, 1001 );
		$this->assertTrue( $step['done'] );
		$this->assertSame( 'error', $step['run']['outcome'] );

		$outage = new WP_Error( 'sitelemetry_http', 'Bad gateway', array( 'status' => 502, 'code' => null, 'retry_after_ms' => null ) );
		$first  = Sitelemetry_Audit_Runner::advance( $job, $outage, 1001 );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 4000, $first['job']['retry_after_ms'] );
		$second = Sitelemetry_Audit_Runner::advance( $first['job'], $outage, 1002 );
		$this->assertSame( 8000, $second['job']['retry_after_ms'] );
		$third = Sitelemetry_Audit_Runner::advance( $second['job'], $outage, 1003 );
		$this->assertFalse( $third['done'] );
		$fourth = Sitelemetry_Audit_Runner::advance( $third['job'], $outage, 1004 );
		$this->assertTrue( $fourth['done'] );

		$rpc  = new WP_Error( 'sitelemetry_rpc', 'Unknown tool', array( 'code' => -32602 ) );
		$step = Sitelemetry_Audit_Runner::advance( $job, $rpc, 1001 );
		$this->assertTrue( $step['done'] );

		$job_busy = Sitelemetry_Test_Mock_Service::action_required( 'audit_job_busy', 'Another audit is running.' );
		$step     = Sitelemetry_Audit_Runner::advance( $job, $job_busy, 1001 );
		$this->assertFalse( $step['done'] );
		$this->assertSame( 15000, $step['job']['retry_after_ms'] );
		$exhausted = $step['job'];
		$exhausted['job_busy_retries'] = Sitelemetry_Audit_Runner::MAX_JOB_BUSY_RETRIES;
		$step = Sitelemetry_Audit_Runner::advance( $exhausted, $job_busy, 1002 );
		$this->assertTrue( $step['done'] );

		$gate = Sitelemetry_Audit_Runner::advance( $job, Sitelemetry_Test_Mock_Service::action_required( 'usage_limit_reached', 'x' ), 1001 );
		$this->assertTrue( $gate['done'] );
	}

	/**
	 * start() validates the key, the target and the single-job rule.
	 *
	 * @return void
	 */
	public function test_start_validation() {
		$runner = new Sitelemetry_Audit_Runner();
		$this->assertSame( 'no_api_key', $runner->start( 'security', 'https://ok.example/' )->get_error_code() );
		$this->configure();
		$this->assertSame( 'invalid_target', $runner->start( 'security', 'not a url' )->get_error_code() );
		$this->assertSame( 'invalid_kind', $runner->start( 'bogus', 'https://ok.example/' )->get_error_code() );

		$job = $runner->start( 'security', 'ok.example' );
		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'https://ok.example', $job['target'] );
		$this->assertSame( 'audit_security', $job['tool'] );
		$this->assertSame( array( 'target' => 'https://ok.example' ), $job['args'] );
		$this->assertNotNull( Sitelemetry_Audit_Runner::get_job() );
		$this->assertSame( array( 'sitelemetry_audit_job_started' ), $this->action_names() );

		$this->assertSame( 'sitelemetry_busy', $runner->start( 'security', 'https://ok.example/' )->get_error_code() );
	}

	/**
	 * Manual run end to end: start, three steps (running, running, completed),
	 * result stored per kind, job cleared, pollArguments re-sent unchanged.
	 *
	 * @return void
	 */
	public function test_step_end_to_end() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure();
		$runner = new Sitelemetry_Audit_Runner();
		$runner->start( 'security', 'https://ok.example/', 'manual' );

		$first = $runner->step();
		$this->assertSame( 'running', $first['state'] );
		$this->assertSame( 'mj_00000000000000000000000000000000', $first['job']['job_id'] );
		$this->assertSame( 1, $first['job']['polls'] );

		$second = $runner->step();
		$this->assertSame( 'running', $second['state'] );
		$this->assertSame( 2, $second['job']['polls'] );

		$third = $runner->step();
		$this->assertSame( 'done', $third['state'] );
		$this->assertSame( 'completed', $third['model']['status'] );
		$this->assertSame( 82, $third['model']['score'] );
		$this->assertSame( 'manual', $third['model']['origin'] );
		$this->assertSame( 2, $third['model']['polls'] );
		$this->assertGreaterThan( 0, $third['model']['finished_at'] );

		$this->assertNull( Sitelemetry_Audit_Runner::get_job() );
		$stored = Sitelemetry_Audit_Results::get( 'security' );
		$this->assertSame( 'mj_00000000000000000000000000000000', $stored['job_id'] );
		$this->assertSame( $stored, Sitelemetry_Audit_Results::latest() );
		$this->assertSame( array( 'idle' ), array( $runner->step()['state'] ) );

		$calls = $service->tool_calls();
		$this->assertCount( 3, $calls );
		$this->assertSame( array( 'target' => 'https://ok.example/' ), $calls[0]['body']['params']['arguments'] );
		$expected_poll = array( 'target' => 'https://ok.example/', 'jobId' => 'mj_00000000000000000000000000000000' );
		$this->assertSame( $expected_poll, $calls[1]['body']['params']['arguments'] );
		$this->assertSame( $expected_poll, $calls[2]['body']['params']['arguments'] );

		// One handshake for the whole job: the session negotiated by the first
		// step is reused, so every later step is a single request in the session
		// the job was issued in.
		$methods = array_map(
			function ( $call ) {
				return $call['body']['method'];
			},
			$service->calls
		);
		$this->assertSame( array( 'initialize', 'notifications/initialized', 'tools/call', 'tools/call', 'tools/call' ), $methods );
		$this->assertSame( 'sess-test-1', $calls[1]['headers']['Mcp-Session-Id'] );
		$this->assertSame( 'sess-test-1', $calls[2]['headers']['Mcp-Session-Id'] );
		$this->assertSame( array( 'sitelemetry_audit_job_started', 'sitelemetry_audit_job_finished' ), $this->action_names() );
		$this->assertFalse( get_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT ) );
	}

	/**
	 * A session the service no longer knows costs one fresh handshake on that
	 * step; the job keeps polling with the new session.
	 *
	 * @return void
	 */
	public function test_step_recovers_from_an_expired_session() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure();
		$runner = new Sitelemetry_Audit_Runner();
		$runner->start( 'security', 'https://ok.example/' );

		$first = $runner->step();
		$this->assertSame( 'sess-test-1', $first['job']['session_id'] );
		$this->assertSame( '2025-06-18', $first['job']['protocol_version'] );
		$before = count( $service->calls );

		$service->reject_session = true;
		$second                  = $runner->step();
		$this->assertSame( 'running', $second['state'] );
		$this->assertSame( 2, $second['job']['polls'] );
		$this->assertSame( 'sess-test-1', $second['job']['session_id'] );
		$methods = array_map(
			function ( $call ) {
				return $call['body']['method'];
			},
			array_slice( $service->calls, $before )
		);
		$this->assertSame( array( 'tools/call', 'initialize', 'notifications/initialized', 'tools/call' ), $methods );

		$third = $runner->step();
		$this->assertSame( 'done', $third['state'] );
		$this->assertSame( 'completed', $third['model']['status'] );
	}

	/**
	 * Gates reported by the service end the job on the first step without a poll.
	 *
	 * @return void
	 */
	public function test_step_gates() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$runner = new Sitelemetry_Audit_Runner();
		$cases  = array(
			'https://quota.example/'       => array( 'quota_exhausted', 'usage_limit_reached' ),
			'https://plan.example/'        => array( 'plan_required', 'PLAN_UPGRADE_REQUIRED' ),
			'https://plan-result.example/' => array( 'plan_required', 'entitlement_required' ),
			'https://quota-rpc.example/'   => array( 'quota_exhausted', 'rpc_-32603' ),
			'https://verify.example/'      => array( 'verification_required', 'target_verification_required' ),
			'https://consent.example/'     => array( 'verification_required', 'authorization_consent_required' ),
			'https://error.example/'       => array( 'blocked', 'tool_error' ),
			'https://free.example/'        => array( 'partial', null ),
		);
		foreach ( $cases as $target => $expected ) {
			$this->configure( $target );
			$this->assertFalse( is_wp_error( $runner->start( 'security', $target ) ), $target );
			$state = $runner->step();
			$this->assertSame( 'done', $state['state'], $target );
			$this->assertSame( $expected[0], $state['model']['status'], $target );
			$this->assertSame( $expected[1], $state['model']['reason'], $target );
		}
		$this->assertSame( 'Not run: the connected account must accept the current audit authorization terms', Sitelemetry_Audit_Labels::status_heading( array( 'status' => 'verification_required', 'reason' => 'authorization_consent_required' ) ) );
	}

	/**
	 * A 429 with Retry-After is retried on the next step and then completes.
	 *
	 * @return void
	 */
	public function test_step_rate_limited_then_completed() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure( 'https://busy.example/' );
		$runner = new Sitelemetry_Audit_Runner();
		$runner->start( 'security', 'https://busy.example/' );
		$first = $runner->step();
		$this->assertSame( 'running', $first['state'] );
		$this->assertSame( 0, $first['job']['retry_after_ms'] );
		$this->assertSame( 1, $first['job']['rate_limit_retries'] );
		$second = $runner->step();
		$this->assertSame( 'done', $second['state'] );
		$this->assertSame( 'completed', $second['model']['status'] );
	}

	/**
	 * A rejected key ends the job as blocked/unauthorized without leaking the key.
	 *
	 * @return void
	 */
	public function test_step_unauthorized() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure();
		update_option( Sitelemetry_Audit_Settings::OPTION, array_merge( Sitelemetry_Audit_Settings::get(), array( 'api_key' => 'sl_wrong_key_999' ) ) );
		$runner = new Sitelemetry_Audit_Runner();
		$runner->start( 'security', 'https://ok.example/' );
		$state = $runner->step();
		$this->assertSame( 'done', $state['state'] );
		$this->assertSame( array( 'blocked', 'unauthorized' ), array( $state['model']['status'], $state['model']['reason'] ) );
		$this->assertStringNotContainsString( 'sl_wrong_key_999', wp_json_encode( $state['model'] ) );
		$this->assertStringNotContainsString( 'sl_wrong_key_999', wp_json_encode( Sitelemetry_Audit_Runner::progress( Sitelemetry_Audit_Runner::new_job( 'security', 'https://ok.example/', 'manual', time() ), time(), 1200 ) ) );
	}

	/**
	 * The time budget ends a job as a timeout that names the server job id.
	 *
	 * @return void
	 */
	public function test_step_timeout() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure();
		$job               = Sitelemetry_Audit_Runner::new_job( 'security', 'https://ok.example/', 'scheduled', time() - 2 * Sitelemetry_Audit_Settings::time_budget() );
		$job['job_id']     = 'mj_late';
		$job['args']       = array( 'target' => 'https://ok.example/', 'jobId' => 'mj_late' );
		Sitelemetry_Audit_Runner::save_job( $job );
		$runner = new Sitelemetry_Audit_Runner();
		$state  = $runner->step();
		$this->assertSame( 'done', $state['state'] );
		$this->assertSame( array( 'blocked', 'timeout', 'mj_late', 'scheduled' ), array( $state['model']['status'], $state['model']['reason'], $state['model']['job_id'], $state['model']['origin'] ) );
		$this->assertCount( 0, $service->calls );
		$this->assertNull( Sitelemetry_Audit_Runner::get_job() );

		// start() replaces an expired job instead of reporting busy.
		Sitelemetry_Audit_Runner::save_job( $job );
		$this->assertFalse( is_wp_error( $runner->start( 'security', 'https://ok.example/' ) ) );
	}

	/**
	 * The step lock prevents concurrent requests to the service.
	 *
	 * @return void
	 */
	public function test_step_lock() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure();
		$runner = new Sitelemetry_Audit_Runner();
		$runner->start( 'security', 'https://ok.example/' );
		set_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT, 1, 60 );
		$state = $runner->step();
		$this->assertSame( 'running', $state['state'] );
		$this->assertTrue( $state['locked'] );
		$this->assertCount( 0, $service->calls );
		delete_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT );
		$this->assertSame( 'running', $runner->step()['state'] );
		$this->assertCount( 3, $service->calls );
	}

	/**
	 * cancel() forgets the job and tells the cron to stop polling.
	 *
	 * @return void
	 */
	public function test_cancel() {
		$this->configure();
		$runner = new Sitelemetry_Audit_Runner();
		$runner->start( 'security', 'https://ok.example/' );
		$runner->cancel();
		$this->assertNull( Sitelemetry_Audit_Runner::get_job() );
		$this->assertSame( array( 'sitelemetry_audit_job_started', 'sitelemetry_audit_job_finished' ), $this->action_names() );
	}

	/**
	 * The progress payload exposes no arguments and no key.
	 *
	 * @return void
	 */
	public function test_progress_payload() {
		$job      = Sitelemetry_Audit_Runner::new_job( 'seo', 'https://ok.example/', 'manual', 100 );
		$progress = Sitelemetry_Audit_Runner::progress( $job, 160, 1200 );
		$this->assertSame( array( 'state', 'kind', 'origin', 'job_id', 'phase', 'polls', 'retry_after_ms', 'elapsed', 'budget' ), array_keys( $progress ) );
		$this->assertSame( 60, $progress['elapsed'] );
		$this->assertSame( array( 'state' => 'idle' ), Sitelemetry_Audit_Runner::progress( null, 160, 1200 ) );
	}

	/**
	 * Cron: the weekly event starts a scheduled job and reschedules the poll while running.
	 *
	 * @return void
	 */
	public function test_cron_weekly_and_poll() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$this->configure();
		update_option( Sitelemetry_Audit_Settings::OPTION, array_merge( Sitelemetry_Audit_Settings::get(), array( 'weekly_enabled' => true ) ) );
		$runner = new Sitelemetry_Audit_Runner();
		$cron   = new Sitelemetry_Audit_Cron( $runner );

		Sitelemetry_Audit_Cron::sync_weekly_schedule();
		$this->assertNotNull( wp_next_scheduled( Sitelemetry_Audit_Cron::WEEKLY_HOOK ) );

		$cron->run_weekly();
		$job = Sitelemetry_Audit_Runner::get_job();
		$this->assertSame( 'scheduled', $job['origin'] );
		$this->assertSame( 1, $job['polls'] );
		$next = wp_next_scheduled( Sitelemetry_Audit_Cron::POLL_HOOK );
		$this->assertGreaterThan( time() + Sitelemetry_Audit_Cron::MIN_POLL_DELAY - 2, $next );

		$cron->poll_job();
		$cron->poll_job();
		$this->assertNull( Sitelemetry_Audit_Runner::get_job() );
		$this->assertSame( 'scheduled', Sitelemetry_Audit_Results::get( 'security' )['origin'] );

		// on_job_finished runs from the finished action in WordPress; here we call it directly.
		$cron->on_job_finished();
		$this->assertFalse( wp_next_scheduled( Sitelemetry_Audit_Cron::POLL_HOOK ) );

		update_option( Sitelemetry_Audit_Settings::OPTION, array_merge( Sitelemetry_Audit_Settings::get(), array( 'weekly_enabled' => false ) ) );
		Sitelemetry_Audit_Cron::sync_weekly_schedule();
		$this->assertFalse( wp_next_scheduled( Sitelemetry_Audit_Cron::WEEKLY_HOOK ) );
	}
}
