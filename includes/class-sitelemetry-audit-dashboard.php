<?php
/**
 * Dashboard widget with the last score, the findings by severity and, when an
 * audit needs it, the ownership verification call to action.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shown to users who can manage options.
 */
class Sitelemetry_Audit_Dashboard {

	const WIDGET_ID = 'sitelemetry_audit_widget';

	/**
	 * Admin (for URLs).
	 *
	 * @var Sitelemetry_Audit_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 *
	 * @param Sitelemetry_Audit_Admin $admin Admin.
	 */
	public function __construct( Sitelemetry_Audit_Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Registers the widget.
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! current_user_can( Sitelemetry_Audit_Admin::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget( self::WIDGET_ID, __( 'Sitelemetry audit', 'sitelemetry-audit' ), array( $this, 'render' ) );
	}

	/**
	 * Widget body.
	 *
	 * @return void
	 */
	public function render() {
		$settings = Sitelemetry_Audit_Settings::get();
		$model    = Sitelemetry_Audit_Results::get( $settings['kind'] );
		if ( ! $model ) {
			$model = Sitelemetry_Audit_Results::latest();
		}
		$view = array(
			'model'            => $model,
			'job'              => Sitelemetry_Audit_Runner::get_job(),
			'has_key'          => '' !== $settings['api_key'],
			'results_url'      => Sitelemetry_Audit_Admin::results_url( $model ? $model['kind'] : '' ),
			'settings_url'     => Sitelemetry_Audit_Admin::page_url(),
			'severities'       => Sitelemetry_Audit_Labels::severity_labels(),
			'status_heading'   => $model ? Sitelemetry_Audit_Labels::status_heading( $model ) : '',
			'verification'     => $model ? Sitelemetry_Audit_Verification::call_to_action( $model ) : null,
			'verification_url' => Sitelemetry_Audit_Admin::verification_url(),
			'app_url'          => Sitelemetry_Audit_Links::verified_domains_url(),
		);
		require SITELEMETRY_AUDIT_DIR . 'admin/views/dashboard-widget.php';
	}
}
