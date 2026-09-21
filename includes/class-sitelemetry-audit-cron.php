<?php
/**
 * WP-Cron: the optional weekly audit and the background poll of a running job.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual audits are polled by the admin AJAX loop; WP-Cron drives the scheduled
 * weekly audit (there is no browser) and acts as a safety net that finishes a
 * manual job whose browser tab was closed. Both paths call the same
 * Sitelemetry_Audit_Runner::step().
 */
class Sitelemetry_Audit_Cron {

	const WEEKLY_HOOK    = 'sitelemetry_audit_weekly';
	const POLL_HOOK      = 'sitelemetry_audit_poll_job';
	const MIN_POLL_DELAY = 60;
	const SAFETY_DELAY   = 120;

	/**
	 * Runner.
	 *
	 * @var Sitelemetry_Audit_Runner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param Sitelemetry_Audit_Runner $runner Runner.
	 */
	public function __construct( Sitelemetry_Audit_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::WEEKLY_HOOK, array( $this, 'run_weekly' ) );
		add_action( self::POLL_HOOK, array( $this, 'poll_job' ) );
		add_action( 'sitelemetry_audit_job_started', array( $this, 'on_job_started' ) );
		add_action( 'sitelemetry_audit_job_finished', array( $this, 'on_job_finished' ) );
		add_action( 'update_option_' . Sitelemetry_Audit_Settings::OPTION, array( __CLASS__, 'sync_weekly_schedule' ) );
		add_action( 'add_option_' . Sitelemetry_Audit_Settings::OPTION, array( __CLASS__, 'sync_weekly_schedule' ) );
	}

	/**
	 * Schedules or unschedules the weekly event according to the settings.
	 *
	 * @return void
	 */
	public static function sync_weekly_schedule() {
		$settings  = Sitelemetry_Audit_Settings::get();
		$scheduled = wp_next_scheduled( self::WEEKLY_HOOK );
		if ( $settings['weekly_enabled'] && ! $scheduled ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', self::WEEKLY_HOOK );
		} elseif ( ! $settings['weekly_enabled'] && $scheduled ) {
			wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		}
	}

	/**
	 * Next weekly run, or false.
	 *
	 * @return int|false
	 */
	public static function next_weekly() {
		return wp_next_scheduled( self::WEEKLY_HOOK );
	}

	/**
	 * Schedules one background poll.
	 *
	 * @param int $delay Seconds (raised to the WP-Cron granularity).
	 * @return void
	 */
	public static function schedule_poll( $delay ) {
		wp_clear_scheduled_hook( self::POLL_HOOK );
		wp_schedule_single_event( time() + max( self::MIN_POLL_DELAY, (int) $delay ), self::POLL_HOOK );
	}

	/**
	 * Removes every event of this plugin.
	 *
	 * @return void
	 */
	public static function clear_all() {
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}

	/**
	 * Weekly event: start the configured audit and poll it in the background.
	 * A completed audit consumes one unit of the account's monthly allowance.
	 *
	 * @return void
	 */
	public function run_weekly() {
		$settings = Sitelemetry_Audit_Settings::get();
		if ( ! $settings['weekly_enabled'] || '' === $settings['api_key'] ) {
			return;
		}
		$job = $this->runner->start( $settings['kind'], $settings['target'], 'scheduled' );
		if ( is_wp_error( $job ) ) {
			return;
		}
		$this->poll_job();
	}

	/**
	 * Background poll: one step, then reschedule while the job is running.
	 *
	 * @return void
	 */
	public function poll_job() {
		$state = $this->runner->step();
		if ( 'running' === $state['state'] ) {
			$job = isset( $state['job'] ) ? $state['job'] : null;
			$ms  = is_array( $job ) && isset( $job['retry_after_ms'] ) ? (int) $job['retry_after_ms'] : 0;
			self::schedule_poll( (int) ceil( $ms / 1000 ) );
		}
	}

	/**
	 * Safety net for manual jobs: if the browser stops polling, WP-Cron finishes the job.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public function on_job_started( $job ) {
		if ( is_array( $job ) && 'manual' === $job['origin'] ) {
			self::schedule_poll( self::SAFETY_DELAY );
		}
	}

	/**
	 * The job is finished or abandoned: no background poll is needed.
	 *
	 * @return void
	 */
	public function on_job_finished() {
		wp_clear_scheduled_hook( self::POLL_HOOK );
	}
}
