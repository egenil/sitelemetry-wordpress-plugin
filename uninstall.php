<?php
/**
 * Uninstall: remove every option, transient and cron event of the plugin.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cleans one site.
 *
 * @return void
 */
function sitelemetry_audit_uninstall_site() {
	delete_option( 'sitelemetry_audit_settings' );
	delete_option( 'sitelemetry_audit_results' );
	delete_option( 'sitelemetry_audit_job' );
	delete_transient( 'sitelemetry_audit_plans' );
	delete_transient( 'sitelemetry_audit_step_lock' );
	wp_clear_scheduled_hook( 'sitelemetry_audit_weekly' );
	wp_clear_scheduled_hook( 'sitelemetry_audit_poll_job' );
}

if ( is_multisite() ) {
	$sitelemetry_audit_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sitelemetry_audit_site_ids as $sitelemetry_audit_site_id ) {
		switch_to_blog( $sitelemetry_audit_site_id );
		sitelemetry_audit_uninstall_site();
		restore_current_blog();
	}
} else {
	sitelemetry_audit_uninstall_site();
}
