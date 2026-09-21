<?php
/**
 * Audit job state machine: start, one poll step at a time, finish.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One job at a time is stored in an option. Every call to step() sends exactly
 * one tools/call to the service: the first with { target }, the following ones
 * with the server's pollArguments unchanged, until the result is final, an error
 * is not retryable, or the time budget is spent. Who calls step() and how often
 * (the admin AJAX loop or a WP-Cron single event) is decided by the caller from
 * the retry_after_ms the job carries.
 */
class Sitelemetry_Audit_Runner {

	const JOB_OPTION            = 'sitelemetry_audit_job';
	const DEFAULT_RETRY_MS      = 2000;
	const MAX_RATE_LIMIT_RETRIES = 40;
	const MAX_JOB_BUSY_RETRIES  = 10;
	const MAX_TRANSIENT_RETRIES = 3;

	/**
	 * The stored job, or null.
	 *
	 * @return array|null
	 */
	public static function get_job() {
		$job = get_option( self::JOB_OPTION, null );
		return is_array( $job ) && isset( $job['kind'], $job['args'] ) ? $job : null;
	}

	/**
	 * Persists the job (autoload off).
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function save_job( array $job ) {
		if ( false === get_option( self::JOB_OPTION, false ) ) {
			add_option( self::JOB_OPTION, $job, '', 'no' );
			return;
		}
		update_option( self::JOB_OPTION, $job, 'no' );
	}

	/**
	 * Removes the job.
	 *
	 * @return void
	 */
	public static function clear_job() {
		delete_option( self::JOB_OPTION );
	}

	/**
	 * A fresh job (pure).
	 *
	 * @param string $kind   Audit kind.
	 * @param string $target Target URL.
	 * @param string $origin manual | scheduled.
	 * @param int    $now    Unix timestamp.
	 * @return array
	 */
	public static function new_job( $kind, $target, $origin, $now ) {
		return array(
			'kind'             => $kind,
			'target'           => $target,
			'tool'             => Sitelemetry_Audit_Labels::tool_for( $kind ),
			'origin'           => 'scheduled' === $origin ? 'scheduled' : 'manual',
			'args'             => array( 'target' => $target ),
			'job_id'           => null,
			'started_at'       => (int) $now,
			'updated_at'       => (int) $now,
			'calls'            => 0,
			'polls'            => 0,
			'rate_limit_retries' => 0,
			'job_busy_retries' => 0,
			'transient_errors' => 0,
			'retry_after_ms'   => self::DEFAULT_RETRY_MS,
			'phase'            => '',
			// MCP session of the first step, reused by the following ones so a
			// poll is one request instead of a new handshake.
			'session_id'       => '',
			'protocol_version' => '',
		);
	}

	/**
	 * Whether the job has exceeded the time budget (pure).
	 *
	 * @param array $job    Job.
	 * @param int   $now    Unix timestamp.
	 * @param int   $budget Budget in seconds.
	 * @return bool
	 */
	public static function is_expired( array $job, $now, $budget ) {
		return ( (int) $now - (int) $job['started_at'] ) >= (int) $budget;
	}

	/**
	 * Applies one server response to the job (pure).
	 *
	 * @param array          $job      Job.
	 * @param array|WP_Error $response tools/call result or client error.
	 * @param int            $now      Unix timestamp.
	 * @return array { job: array, done: bool, run: array|null }
	 */
	public static function advance( array $job, $response, $now ) {
		$job['updated_at'] = (int) $now;
		++$job['calls'];
		$running = array(
			'job'  => $job,
			'done' => false,
			'run'  => null,
		);
		$done    = function ( array $run ) use ( $job ) {
			return array(
				'job'  => $job,
				'done' => true,
				'run'  => array_merge(
					array(
						'tool'   => $job['tool'],
						'job_id' => $job['job_id'],
					),
					$run
				),
			);
		};

		if ( is_wp_error( $response ) ) {
			$status       = Sitelemetry_Audit_Client::error_status( $response );
			$rate_limited = 'sitelemetry_http' === $response->get_error_code() && 429 === $status && 'COMMERCIAL_USAGE_LIMIT_REACHED' !== Sitelemetry_Audit_Client::error_server_code( $response );
			if ( $rate_limited && $job['rate_limit_retries'] < self::MAX_RATE_LIMIT_RETRIES ) {
				++$job['rate_limit_retries'];
				$retry                 = Sitelemetry_Audit_Client::error_retry_after_ms( $response );
				$job['retry_after_ms'] = null === $retry ? 5000 : (int) $retry;
				$job['phase']          = Sitelemetry_Audit_Outcome::clip( $response->get_error_message(), 200 );
				$running['job']        = $job;
				return $running;
			}
			if ( Sitelemetry_Audit_Client::is_transient_error( $response ) && $job['transient_errors'] < self::MAX_TRANSIENT_RETRIES ) {
				++$job['transient_errors'];
				$job['retry_after_ms'] = 2000 * (int) pow( 2, $job['transient_errors'] );
				$job['phase']          = Sitelemetry_Audit_Outcome::clip( $response->get_error_message(), 200 );
				$running['job']        = $job;
				return $running;
			}
			return $done(
				array(
					'outcome' => 'error',
					'error'   => $response,
				)
			);
		}

		$result     = is_array( $response ) ? $response : array();
		$structured = isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ? $result['structuredContent'] : array();
		$status     = isset( $structured['status'] ) ? $structured['status'] : '';

		if ( 'running' === $status && isset( $structured['jobId'] ) && is_string( $structured['jobId'] ) ) {
			$job['job_id'] = $structured['jobId'];
			++$job['polls'];
			// The server's pollArguments are re-sent unchanged; no scan options are added.
			if ( isset( $structured['pollArguments'] ) && is_array( $structured['pollArguments'] ) && count( $structured['pollArguments'] ) > 0 ) {
				$job['args'] = $structured['pollArguments'];
			} else {
				$job['args'] = array(
					'target' => $job['target'],
					'jobId'  => $job['job_id'],
				);
			}
			$retry                 = isset( $structured['retryAfterMs'] ) ? Sitelemetry_Audit_Outcome::number_or_null( $structured['retryAfterMs'] ) : null;
			$job['retry_after_ms'] = null === $retry ? self::DEFAULT_RETRY_MS : max( 0, (int) $retry );
			// The first text line says whether the job is queued or executing.
			$text  = Sitelemetry_Audit_Outcome::text_content( $result );
			$lines = preg_split( '/\r?\n/', $text );
			$line  = is_array( $lines ) && isset( $lines[0] ) ? trim( $lines[0] ) : '';
			if ( '' !== $line ) {
				$job['phase'] = Sitelemetry_Audit_Outcome::clip( $line, 200 );
			}
			$running['job'] = $job;
			return $running;
		}

		if ( 'action_required' === $status && isset( $structured['reason'] ) && 'audit_job_busy' === $structured['reason'] && $job['job_busy_retries'] < self::MAX_JOB_BUSY_RETRIES ) {
			++$job['job_busy_retries'];
			$job['retry_after_ms'] = 15000;
			$job['phase']          = __( 'Another audit is already running for this account; retrying shortly.', 'sitelemetry-audit' );
			$running['job']        = $job;
			return $running;
		}

		return $done(
			array(
				'outcome' => 'result',
				'result'  => $result,
			)
		);
	}

	/**
	 * Registers a new job. The first request to the service happens in step().
	 *
	 * @param string $kind   Audit kind.
	 * @param string $target Target URL.
	 * @param string $origin manual | scheduled.
	 * @return array|WP_Error The job.
	 */
	public function start( $kind, $target, $origin = 'manual' ) {
		if ( ! Sitelemetry_Audit_Labels::is_kind( $kind ) ) {
			return new WP_Error( 'invalid_kind', __( 'Unsupported audit kind.', 'sitelemetry-audit' ) );
		}
		if ( '' === Sitelemetry_Audit_Settings::api_key() ) {
			return new WP_Error( 'no_api_key', __( 'Add your Sitelemetry API key in the settings before running an audit.', 'sitelemetry-audit' ) );
		}
		$target = Sitelemetry_Audit_Settings::normalize_target( $target );
		if ( '' === $target ) {
			return new WP_Error( 'invalid_target', __( 'The target must be a valid http or https URL.', 'sitelemetry-audit' ) );
		}
		$existing = self::get_job();
		if ( $existing ) {
			if ( ! self::is_expired( $existing, time(), Sitelemetry_Audit_Settings::time_budget() ) ) {
				return new WP_Error( 'sitelemetry_busy', __( 'An audit is already running. Wait for it to finish or stop waiting for it on the results page.', 'sitelemetry-audit' ) );
			}
			$this->finish( $existing, array( 'outcome' => 'timeout' ) );
		}
		$job = self::new_job( $kind, $target, $origin, time() );
		self::save_job( $job );
		do_action( 'sitelemetry_audit_job_started', $job );
		return $job;
	}

	/**
	 * Performs one request to the service for the stored job.
	 *
	 * @return array {
	 *     @type string     $state idle | running | done
	 *     @type array|null $job   Job while running.
	 *     @type array|null $model Result model when done.
	 *     @type bool       $locked True when another process was in the middle of a step.
	 * }
	 */
	public function step() {
		$job = self::get_job();
		if ( ! $job ) {
			return array( 'state' => 'idle' );
		}
		$now = time();
		if ( self::is_expired( $job, $now, Sitelemetry_Audit_Settings::time_budget() ) ) {
			return array(
				'state' => 'done',
				'model' => $this->finish( $job, array( 'outcome' => 'timeout' ) ),
			);
		}
		if ( get_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT ) ) {
			return array(
				'state'  => 'running',
				'job'    => $job,
				'locked' => true,
			);
		}
		$timeout = Sitelemetry_Audit_Settings::request_timeout();
		set_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT, 1, $timeout + 15 );
		$client = new Sitelemetry_Audit_Client( Sitelemetry_Audit_Settings::api_key(), Sitelemetry_Audit_Settings::base_url(), $timeout );
		// The session negotiated by an earlier step is reused, so this poll is
		// one request and the job stays inside the session it was issued in.
		if ( isset( $job['session_id'] ) ) {
			$client->resume_session( $job['session_id'], isset( $job['protocol_version'] ) ? $job['protocol_version'] : '' );
		}
		$response = $client->call_tool( $job['tool'], $job['args'] );
		delete_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT );
		$job['session_id']       = $client->session_id();
		$job['protocol_version'] = $client->protocol_version();

		$advanced = self::advance( $job, $response, time() );
		if ( $advanced['done'] ) {
			return array(
				'state' => 'done',
				'model' => $this->finish( $advanced['job'], $advanced['run'] ),
			);
		}
		self::save_job( $advanced['job'] );
		return array(
			'state' => 'running',
			'job'   => $advanced['job'],
		);
	}

	/**
	 * Abandons the local job. The service keeps running the audit; its result
	 * stays available in the app.
	 *
	 * @return void
	 */
	public function cancel() {
		$job = self::get_job();
		self::clear_job();
		delete_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT );
		if ( $job ) {
			do_action( 'sitelemetry_audit_job_finished', null, $job );
		}
	}

	/**
	 * Interprets the run, stores the model and clears the job.
	 *
	 * @param array $job Job.
	 * @param array $run Run (outcome, result | error).
	 * @return array Result model.
	 */
	private function finish( array $job, array $run ) {
		$run   = array_merge(
			array(
				'tool'   => $job['tool'],
				'job_id' => $job['job_id'],
			),
			$run
		);
		$model = Sitelemetry_Audit_Outcome::interpret( $run, $job['kind'], $job['target'] );

		$model['started_at']  = (int) $job['started_at'];
		$model['finished_at'] = time();
		$model['origin']      = $job['origin'];
		$model['polls']       = (int) $job['polls'];
		Sitelemetry_Audit_Results::store( $job['kind'], $model );
		self::clear_job();
		delete_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT );
		do_action( 'sitelemetry_audit_job_finished', $model, $job );
		return $model;
	}

	/**
	 * Progress information safe to send to the browser (no arguments, no key).
	 *
	 * @param array|null $job    Job.
	 * @param int        $now    Unix timestamp.
	 * @param int        $budget Budget in seconds.
	 * @return array
	 */
	public static function progress( $job, $now, $budget ) {
		if ( ! is_array( $job ) ) {
			return array( 'state' => 'idle' );
		}
		return array(
			'state'          => 'running',
			'kind'           => $job['kind'],
			'origin'         => $job['origin'],
			'job_id'         => $job['job_id'],
			'phase'          => $job['phase'],
			'polls'          => (int) $job['polls'],
			'retry_after_ms' => (int) $job['retry_after_ms'],
			'elapsed'        => max( 0, (int) $now - (int) $job['started_at'] ),
			'budget'         => (int) $budget,
		);
	}
}
