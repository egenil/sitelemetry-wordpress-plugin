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
	global $wpdb;
	delete_option( 'sitelemetry_audit_settings' );
	delete_option( 'sitelemetry_audit_results' );
	delete_option( 'sitelemetry_audit_job' );
	delete_option( 'sitelemetry_audit_verification' );
	delete_option( 'sitelemetry_audit_verification_api' );
	delete_transient( 'sitelemetry_audit_plans' );
	delete_transient( 'sitelemetry_audit_step_lock' );
	delete_transient( 'sitelemetry_audit_verification_probe' );
	wp_clear_scheduled_hook( 'sitelemetry_audit_weekly' );
	wp_clear_scheduled_hook( 'sitelemetry_audit_poll_job' );
	wp_clear_scheduled_hook( 'sitelemetry_audit_verification_renew' );
	// The dismissed notices are per-site user options (update_user_option()).
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'sitelemetry_audit_dismissed_notice', '', true );
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'sitelemetry_audit_dismissed_renewal', '', true );
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
