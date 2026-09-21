<?php
/**
 * Storage of the last result per audit kind.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option holds the last result model of every audit kind. Models contain the
 * target URL and the findings the service returned about it; no personal data.
 */
class Sitelemetry_Audit_Results {

	const OPTION = 'sitelemetry_audit_results';

	/**
	 * All stored results, keyed by audit kind.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$results = array();
		foreach ( $stored as $kind => $model ) {
			if ( Sitelemetry_Audit_Labels::is_kind( $kind ) && is_array( $model ) ) {
				$results[ $kind ] = $model;
			}
		}
		return $results;
	}

	/**
	 * Result of one audit kind.
	 *
	 * @param string $kind Audit kind.
	 * @return array|null
	 */
	public static function get( $kind ) {
		$all = self::all();
		return isset( $all[ $kind ] ) ? $all[ $kind ] : null;
	}

	/**
	 * Stores a result (autoload off: results can be large).
	 *
	 * @param string $kind  Audit kind.
	 * @param array  $model Result model.
	 * @return void
	 */
	public static function store( $kind, array $model ) {
		$all          = self::all();
		$all[ $kind ] = $model;
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $all, '', 'no' );
			return;
		}
		update_option( self::OPTION, $all, 'no' );
	}

	/**
	 * The most recently finished result across kinds, or null.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$latest = null;
		foreach ( self::all() as $model ) {
			$finished = isset( $model['finished_at'] ) ? (int) $model['finished_at'] : 0;
			if ( null === $latest || $finished > (int) $latest['finished_at'] ) {
				$latest = $model;
			}
		}
		return $latest;
	}

	/**
	 * Removes everything.
	 *
	 * @return void
	 */
	public static function delete_all() {
		delete_option( self::OPTION );
	}
}
