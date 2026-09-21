<?php
/**
 * Plugin bootstrap.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the runner, cron, admin pages and dashboard widget together.
 */
class Sitelemetry_Audit_Plugin {

	/**
	 * Singleton.
	 *
	 * @var Sitelemetry_Audit_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Runner.
	 *
	 * @var Sitelemetry_Audit_Runner
	 */
	public $runner;

	/**
	 * Cron.
	 *
	 * @var Sitelemetry_Audit_Cron
	 */
	public $cron;

	/**
	 * Admin.
	 *
	 * @var Sitelemetry_Audit_Admin
	 */
	public $admin;

	/**
	 * Dashboard widget.
	 *
	 * @var Sitelemetry_Audit_Dashboard
	 */
	public $dashboard;

	/**
	 * Returns the instance, creating it on first use.
	 *
	 * @return Sitelemetry_Audit_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		$this->runner    = new Sitelemetry_Audit_Runner();
		$this->cron      = new Sitelemetry_Audit_Cron( $this->runner );
		$this->admin     = new Sitelemetry_Audit_Admin( $this->runner );
		$this->dashboard = new Sitelemetry_Audit_Dashboard( $this->admin );

		$this->cron->register();
		if ( is_admin() ) {
			$this->admin->register();
			$this->dashboard->register();
		}
	}

	/**
	 * Activation: schedule the weekly audit when it was enabled before.
	 *
	 * @return void
	 */
	public static function activate() {
		Sitelemetry_Audit_Cron::sync_weekly_schedule();
	}

	/**
	 * Deactivation: remove the cron events and forget a running job. Settings and
	 * results stay until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Sitelemetry_Audit_Cron::clear_all();
		Sitelemetry_Audit_Runner::clear_job();
		delete_transient( Sitelemetry_Audit_Settings::LOCK_TRANSIENT );
	}
}
