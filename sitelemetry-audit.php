<?php
/**
 * Plugin Name:       Sitelemetry Audit
 * Plugin URI:        https://sitelemetry.com
 * Description:       Run Sitelemetry website audits (security first; SEO, performance, accessibility, AI visibility and integrations on paid plans) from the WordPress dashboard and review the findings with fixes.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sitelemetry
 * Author URI:        https://sitelemetry.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitelemetry-audit
 * Domain Path:       /languages
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SITELEMETRY_AUDIT_VERSION', '0.1.1' );
define( 'SITELEMETRY_AUDIT_FILE', __FILE__ );
define( 'SITELEMETRY_AUDIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITELEMETRY_AUDIT_URL', plugin_dir_url( __FILE__ ) );

require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-labels.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-settings.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-client.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-outcome.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-plans.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-links.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-results.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-runner.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-cron.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-admin.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-dashboard.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-plugin.php';

register_activation_hook( __FILE__, array( 'Sitelemetry_Audit_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sitelemetry_Audit_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Sitelemetry_Audit_Plugin', 'instance' ) );
