<?php
/**
 * Bundled translations as a fallback for WordPress.org language packs.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress loads the translations of a WordPress.org plugin just in time from
 * the language packs in wp-content/languages/plugins, so the plugin does not call
 * load_plugin_textdomain(). The plugin also ships its own translations in
 * languages/; WordPress uses them only for a locale without an installed
 * language pack: through the lang_dir_for_domain filter on WordPress 6.6 and
 * later, and on older versions, which have no such filter, by loading the
 * bundled file on init (load_bundled_legacy()). An installed language pack always
 * takes precedence.
 */
class Sitelemetry_Audit_I18n {

	/**
	 * Text domain.
	 */
	const DOMAIN = 'sitelemetry-audit';

	/**
	 * First WordPress version with the lang_dir_for_domain filter.
	 */
	const FILTER_VERSION = '6.6';

	/**
	 * Registers the fallback.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'lang_dir_for_domain', array( __CLASS__, 'bundled_language_dir' ), 10, 3 );
		// No arguments: do_action( 'init' ) passes an empty string to its callbacks.
		add_action( 'init', array( __CLASS__, 'load_bundled_legacy' ), 10, 0 );
	}

	/**
	 * WordPress 6.0 to 6.5: loads the bundled translation of the current locale
	 * when no language pack is installed for it, so the plugin's screens are in
	 * the same language as the audit text Sitelemetry sends for that locale. Only
	 * in the admin, in WP-Cron and in AJAX requests, where the plugin shows or
	 * stores text. Does nothing on WordPress 6.6 and later.
	 *
	 * @param string|null $wp_version WordPress version (default, or '', the running one).
	 * @return bool Whether a bundled file was loaded.
	 */
	public static function load_bundled_legacy( $wp_version = null ) {
		$wp_version = is_string( $wp_version ) && '' !== $wp_version ? $wp_version : (string) get_bloginfo( 'version' );
		if ( '' === $wp_version || version_compare( $wp_version, self::FILTER_VERSION, '>=' ) ) {
			return false;
		}
		if ( ! ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) || is_textdomain_loaded( self::DOMAIN ) ) {
			return false;
		}
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( ! is_string( $locale ) || ! preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}$/', $locale ) ) {
			return false;
		}
		$file = self::DOMAIN . '-' . $locale . '.mo';
		// A language pack is loaded by WordPress itself, just in time.
		if ( defined( 'WP_LANG_DIR' ) && is_readable( WP_LANG_DIR . '/plugins/' . $file ) ) {
			return false;
		}
		if ( ! is_readable( self::bundled_dir() . $file ) ) {
			return false;
		}
		return (bool) load_textdomain( self::DOMAIN, self::bundled_dir() . $file );
	}

	/**
	 * The plugin's own languages/ directory when WordPress found no translation
	 * file for this text domain and locale and the plugin ships one; otherwise the
	 * path WordPress found, unchanged.
	 *
	 * @param string|false $path   Directory WordPress found, or false when there is none.
	 * @param string       $domain Text domain.
	 * @param string       $locale Locale.
	 * @return string|false
	 */
	public static function bundled_language_dir( $path, $domain, $locale ) {
		if ( ! empty( $path ) || self::DOMAIN !== $domain ) {
			return $path;
		}
		if ( ! is_string( $locale ) || ! preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}$/', $locale ) ) {
			return $path;
		}
		$dir = self::bundled_dir();
		return is_readable( $dir . self::DOMAIN . '-' . $locale . '.mo' ) ? $dir : $path;
	}

	/**
	 * The plugin's languages/ directory, with a trailing slash.
	 *
	 * @return string
	 */
	public static function bundled_dir() {
		return SITELEMETRY_AUDIT_DIR . 'languages/';
	}
}
