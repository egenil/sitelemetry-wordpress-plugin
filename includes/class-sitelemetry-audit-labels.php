<?php
/**
 * Translatable labels for audit kinds, severities, statuses and coverage notes.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All user-facing vocabulary lives here so the views and the dashboard widget share it.
 */
class Sitelemetry_Audit_Labels {

	/**
	 * Audit kind => MCP tool name.
	 *
	 * @return array
	 */
	public static function tools() {
		return array(
			'security'      => 'audit_security',
			'seo'           => 'audit_seo',
			'ai_visibility' => 'audit_ai_visibility',
			'integrations'  => 'audit_integrations',
			'accessibility' => 'audit_accessibility',
			'performance'   => 'audit_performance',
			'full'          => 'audit_full',
		);
	}

	/**
	 * Audit kind => plan catalogue id (the public /api/plans uses "ai" for AI visibility).
	 *
	 * @return array
	 */
	public static function plan_kind_ids() {
		return array(
			'security'      => 'security',
			'seo'           => 'seo',
			'ai_visibility' => 'ai',
			'integrations'  => 'integrations',
			'accessibility' => 'accessibility',
			'performance'   => 'performance',
			'full'          => 'full',
		);
	}

	/**
	 * Audit kind => label.
	 *
	 * @return array
	 */
	public static function kinds() {
		return array(
			'security'      => __( 'Security', 'sitelemetry-audit' ),
			'seo'           => __( 'Technical SEO', 'sitelemetry-audit' ),
			'ai_visibility' => __( 'AI visibility', 'sitelemetry-audit' ),
			'integrations'  => __( 'Integrations', 'sitelemetry-audit' ),
			'accessibility' => __( 'Accessibility', 'sitelemetry-audit' ),
			'performance'   => __( 'Performance', 'sitelemetry-audit' ),
			'full'          => __( 'Full (all pillars)', 'sitelemetry-audit' ),
		);
	}

	/**
	 * Whether a value is a supported audit kind.
	 *
	 * @param mixed $kind Candidate.
	 * @return bool
	 */
	public static function is_kind( $kind ) {
		return is_string( $kind ) && array_key_exists( $kind, self::tools() );
	}

	/**
	 * Label of an audit kind.
	 *
	 * @param string $kind Audit kind.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		$kinds = self::kinds();
		return isset( $kinds[ $kind ] ) ? $kinds[ $kind ] : (string) $kind;
	}

	/**
	 * MCP tool of an audit kind.
	 *
	 * @param string $kind Audit kind.
	 * @return string|null
	 */
	public static function tool_for( $kind ) {
		$tools = self::tools();
		return isset( $tools[ $kind ] ) ? $tools[ $kind ] : null;
	}

	/**
	 * Severities, most severe first.
	 *
	 * @return string[]
	 */
	public static function severities() {
		return array( 'critical', 'high', 'medium', 'low', 'info' );
	}

	/**
	 * Severity => label.
	 *
	 * @return array
	 */
	public static function severity_labels() {
		return array(
			'critical' => __( 'Critical', 'sitelemetry-audit' ),
			'high'     => __( 'High', 'sitelemetry-audit' ),
			'medium'   => __( 'Medium', 'sitelemetry-audit' ),
			'low'      => __( 'Low', 'sitelemetry-audit' ),
			'info'     => __( 'Info', 'sitelemetry-audit' ),
		);
	}

	/**
	 * Label of a severity.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	public static function severity_label( $severity ) {
		$labels = self::severity_labels();
		return isset( $labels[ $severity ] ) ? $labels[ $severity ] : (string) $severity;
	}

	/**
	 * Result statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'completed', 'partial', 'blocked', 'quota_exhausted', 'plan_required', 'verification_required' );
	}

	/**
	 * Heading of the status banner.
	 *
	 * @param array $model Result model.
	 * @return string
	 */
	public static function status_heading( array $model ) {
		$status = isset( $model['status'] ) ? $model['status'] : 'blocked';
		$reason = isset( $model['reason'] ) ? $model['reason'] : '';
		if ( 'verification_required' === $status ) {
			if ( 'authorization_consent_required' === $reason ) {
				return __( 'Not run: the connected account must accept the current audit authorization terms', 'sitelemetry-audit' );
			}
			if ( 'target_reverification_required' === $reason ) {
				return __( 'Not run: ownership verification of the target must be renewed', 'sitelemetry-audit' );
			}
			if ( 'verification_scope_required' === $reason ) {
				return __( 'Not run: the current verification of the target does not cover the requested host-level checks', 'sitelemetry-audit' );
			}
			return __( 'Not run: ownership verification of the target is required', 'sitelemetry-audit' );
		}
		switch ( $status ) {
			case 'completed':
				return __( 'Completed', 'sitelemetry-audit' );
			case 'partial':
				return __( 'Completed with partial coverage', 'sitelemetry-audit' );
			case 'quota_exhausted':
				return __( 'Not run: the monthly audit allowance of the connected account is exhausted', 'sitelemetry-audit' );
			case 'plan_required':
				return __( 'Not run: this audit kind is not included in the connected plan', 'sitelemetry-audit' );
			default:
				return __( 'Not completed', 'sitelemetry-audit' );
		}
	}

	/**
	 * A short translated explanation for outcomes decided locally. The server
	 * message, when there is one, is shown after it.
	 *
	 * @param array $model Result model.
	 * @return string
	 */
	public static function reason_lead( array $model ) {
		$reason = isset( $model['reason'] ) ? (string) $model['reason'] : '';
		switch ( $reason ) {
			case 'timeout':
				if ( ! empty( $model['job_id'] ) ) {
					/* translators: %s: audit job id. */
					return sprintf( __( 'The audit did not finish within the time budget. Sitelemetry keeps running job %s in the background; run the audit again later or open the report in the app.', 'sitelemetry-audit' ), $model['job_id'] );
				}
				return __( 'The audit did not finish within the time budget. Run it again later.', 'sitelemetry-audit' );
			case 'unauthorized':
				return __( 'Sitelemetry rejected the API key. Check the key in the plugin settings; you can create a new one in the app.', 'sitelemetry-audit' );
			case 'transport':
				return __( 'The site could not reach Sitelemetry. Check outgoing HTTP connections from the server and try again.', 'sitelemetry-audit' );
			case 'no_api_key':
				return __( 'No API key is configured.', 'sitelemetry-audit' );
			case 'invalid_target':
				return __( 'The target is not a valid http or https URL.', 'sitelemetry-audit' );
			case 'incomplete':
				return __( 'The audit is still running.', 'sitelemetry-audit' );
			default:
				return '';
		}
	}

	/**
	 * Renders one "what was not measured" entry.
	 *
	 * @param array $entry Structured entry (see Sitelemetry_Audit_Outcome::not_measured()).
	 * @return string
	 */
	public static function not_measured_line( array $entry ) {
		$type = isset( $entry['type'] ) ? $entry['type'] : '';
		switch ( $type ) {
			case 'failed_pillar':
				$error = '' !== $entry['error'] ? $entry['error'] : __( 'failed', 'sitelemetry-audit' );
				/* translators: 1: pillar name, 2: error message. */
				return sprintf( __( '%1$s: %2$s', 'sitelemetry-audit' ), $entry['pillar'], $error );
			case 'skipped_pillar':
				if ( 'not_in_plan' === $entry['reason'] ) {
					/* translators: %s: pillar name. */
					return sprintf( __( '%s: not included in the connected plan', 'sitelemetry-audit' ), $entry['pillar'] );
				}
				/* translators: %s: pillar name. */
				return sprintf( __( '%s: outside the connected account scope', 'sitelemetry-audit' ), $entry['pillar'] );
			case 'skipped_modules':
				/* translators: %s: comma-separated module names. */
				return sprintf( __( 'Security modules outside the connected plan: %s', 'sitelemetry-audit' ), implode( ', ', $entry['modules'] ) );
			case 'pillar_unavailable':
				/* translators: %s: pillar name. */
				return sprintf( __( '%s: unavailable (no measurement for this target)', 'sitelemetry-audit' ), $entry['pillar'] );
			case 'verification_modules':
				/* translators: %s: comma-separated module names. */
				return sprintf( __( 'Security modules that require ownership verification of the target: %s', 'sitelemetry-audit' ), implode( ', ', $entry['modules'] ) );
			case 'module':
				$reasons = ! empty( $entry['reasons'] ) ? ' (' . implode( '; ', $entry['reasons'] ) . ')' : '';
				/* translators: 1: module name, 2: module status, 3: reasons in parentheses or empty. */
				return sprintf( __( 'Module %1$s: %2$s%3$s', 'sitelemetry-audit' ), $entry['module'], $entry['status'], $reasons );
			default:
				return '';
		}
	}
}
