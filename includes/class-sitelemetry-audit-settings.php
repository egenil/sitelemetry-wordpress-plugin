<?php
/**
 * Options, defaults and sanitization.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes the plugin settings (WordPress options API).
 */
class Sitelemetry_Audit_Settings {

	const OPTION          = 'sitelemetry_audit_settings';
	const OPTION_GROUP    = 'sitelemetry_audit';
	const DEFAULT_BASE    = 'https://sitelemetry.com';
	const PLANS_TRANSIENT = 'sitelemetry_audit_plans';
	const LOCK_TRANSIENT  = 'sitelemetry_audit_step_lock';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'        => '',
			'target'         => '',
			'kind'           => 'security',
			'weekly_enabled' => false,
		);
	}

	/**
	 * Current settings merged with the defaults. The target falls back to home_url().
	 *
	 * @return array
	 */
	public static function get() {
		$stored   = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		if ( ! is_string( $settings['api_key'] ) ) {
			$settings['api_key'] = '';
		}
		if ( ! is_string( $settings['target'] ) || '' === $settings['target'] ) {
			$settings['target'] = home_url( '/' );
		}
		if ( ! Sitelemetry_Audit_Labels::is_kind( $settings['kind'] ) ) {
			$settings['kind'] = 'security';
		}
		$settings['weekly_enabled'] = (bool) $settings['weekly_enabled'];
		return $settings;
	}

	/**
	 * The stored API key ('' when none).
	 *
	 * @return string
	 */
	public static function api_key() {
		$settings = self::get();
		return $settings['api_key'];
	}

	/**
	 * Base URL of the hosted service. Overridable with the SITELEMETRY_AUDIT_BASE_URL
	 * constant (wp-config.php) or the sitelemetry_audit_base_url filter, for staging.
	 *
	 * @return string
	 */
	public static function base_url() {
		$base = defined( 'SITELEMETRY_AUDIT_BASE_URL' ) ? SITELEMETRY_AUDIT_BASE_URL : self::DEFAULT_BASE;
		$base = apply_filters( 'sitelemetry_audit_base_url', $base );
		return rtrim( (string) $base, '/' );
	}

	/**
	 * Total time budget for starting and polling one audit, in seconds.
	 *
	 * @return int
	 */
	public static function time_budget() {
		return max( 60, (int) apply_filters( 'sitelemetry_audit_time_budget', 20 * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Timeout of one HTTP request to the service, in seconds.
	 *
	 * @return int
	 */
	public static function request_timeout() {
		return max( 10, (int) apply_filters( 'sitelemetry_audit_request_timeout', 60 ) );
	}

	/**
	 * Masked representation of the key for display (never the key itself).
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		$tail = strlen( $key ) > 8 ? substr( $key, -4 ) : '';
		return str_repeat( '*', 12 ) . $tail;
	}

	/**
	 * Validates a target URL (http or https, with a host).
	 *
	 * @param string $url Candidate URL.
	 * @return string Normalized URL or '' when invalid.
	 */
	public static function normalize_target( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^([a-z][a-z0-9+.-]*)://#i', $url, $scheme ) ) {
			if ( ! in_array( strtolower( $scheme[1] ), array( 'http', 'https' ), true ) ) {
				return '';
			}
		} else {
			$url = 'https://' . $url;
		}
		if ( preg_match( '/\s/', $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $parts['host'] ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();
		$clean   = self::defaults();

		$new_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( (string) $input['api_key'] ) ) : '';
		if ( ! empty( $input['remove_api_key'] ) ) {
			$clean['api_key'] = '';
		} elseif ( '' !== $new_key ) {
			$clean['api_key'] = $new_key;
		} else {
			$clean['api_key'] = $current['api_key'];
		}

		$raw_target = isset( $input['target'] ) ? trim( (string) $input['target'] ) : '';
		$target     = self::normalize_target( $raw_target );
		if ( '' === $target && '' !== $raw_target ) {
			add_settings_error( self::OPTION, 'sitelemetry_audit_target', __( 'The target must be a valid http or https URL. The previous value was kept.', 'sitelemetry-audit' ) );
			$target = $current['target'];
		}
		$clean['target'] = $target;

		$kind          = isset( $input['kind'] ) ? sanitize_key( (string) $input['kind'] ) : 'security';
		$clean['kind'] = Sitelemetry_Audit_Labels::is_kind( $kind ) ? $kind : 'security';

		$clean['weekly_enabled'] = ! empty( $input['weekly_enabled'] );

		return $clean;
	}
}
