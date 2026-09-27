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
					return sprintf( __( 'The audit did not finish within the time budget. Sitelemetry may still be working on job %s; run the audit again later to get a result.', 'sitelemetry-audit' ), $model['job_id'] );
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
	 * One "what was not measured" entry as the results page shows it: the item, the
	 * explanation of its reason when the reason is recognised, and then the
	 * service's own reason in smaller text. Entries that need ownership
	 * verification are flagged so the page can link to the verification helper.
	 *
	 * @param array $entry Structured entry (see Sitelemetry_Audit_Outcome::not_measured()).
	 * @return array { text: string, explanation: string, detail: string, verification: bool }
	 */
	public static function not_measured_view( array $entry ) {
		$view    = array(
			'text'         => '',
			'explanation'  => '',
			'detail'       => '',
			'verification' => false,
		);
		$text    = function ( $key ) use ( $entry ) {
			return isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';
		};
		$list    = function ( $key ) use ( $entry ) {
			return isset( $entry[ $key ] ) && is_array( $entry[ $key ] ) ? $entry[ $key ] : array();
		};
		$modules = function () use ( $list ) {
			return Sitelemetry_Audit_Modules::list_label( array_filter( $list( 'modules' ), 'is_scalar' ) );
		};
		switch ( $text( 'type' ) ) {
			case 'failed_pillar':
				$error = '' !== $text( 'error' ) ? $text( 'error' ) : __( 'failed', 'sitelemetry-audit' );
				/* translators: 1: pillar name, 2: error message. */
				$view['text'] = sprintf( __( '%1$s: %2$s', 'sitelemetry-audit' ), $text( 'pillar' ), $error );
				break;
			case 'skipped_pillar':
				if ( 'not_in_plan' === $text( 'reason' ) ) {
					/* translators: %s: pillar name. */
					$view['text'] = sprintf( __( '%s: not included in the connected plan', 'sitelemetry-audit' ), $text( 'pillar' ) );
				} else {
					/* translators: %s: pillar name. */
					$view['text'] = sprintf( __( '%s: outside the connected account scope', 'sitelemetry-audit' ), $text( 'pillar' ) );
				}
				break;
			case 'skipped_modules':
				/* translators: %s: comma-separated module names. */
				$view['text'] = sprintf( __( 'Security modules outside the connected plan: %s', 'sitelemetry-audit' ), $modules() );
				break;
			case 'pillar_unavailable':
				/* translators: %s: pillar name. */
				$view['text'] = sprintf( __( '%s: unavailable (no measurement for this target)', 'sitelemetry-audit' ), $text( 'pillar' ) );
				break;
			case 'kind_unavailable':
				/* translators: %s: pillar name. */
				$view['text'] = sprintf( __( '%s: unavailable (no measurement for this target)', 'sitelemetry-audit' ), self::kind_label( $text( 'kind' ) ) );
				break;
			case 'pages':
				$count = (int) $text( 'count' );
				$urls  = array_filter( array_map( 'strval', array_filter( $list( 'urls' ), 'is_scalar' ) ), 'strlen' );
				$shown = implode( ', ', $urls ) . ( $count > count( $urls ) ? ', …' : '' );
				if ( '1' === $text( 'robots' ) ) {
					/* translators: 1: number of pages, 2: comma-separated page addresses. */
					$line = sprintf( __( 'Pages not assessed because robots.txt disallows them (%1$d): %2$s', 'sitelemetry-audit' ), $count, $shown );
				} else {
					/* translators: 1: number of pages, 2: comma-separated page addresses. */
					$line = sprintf( __( 'Pages found but not assessed (%1$d): %2$s', 'sitelemetry-audit' ), $count, $shown );
				}
				$view['text'] = self::in_pillar( $text( 'pillar' ), $line );
				break;
			case 'metrics':
				$names = array_filter( array_map( 'strval', array_filter( $list( 'metrics' ), 'is_scalar' ) ), 'strlen' );
				/* translators: %s: comma-separated metric names such as LCP, INP, CLS. */
				$view['text'] = self::in_pillar( $text( 'pillar' ), sprintf( __( 'Metrics not measured: %s', 'sitelemetry-audit' ), implode( ', ', $names ) ) );
				break;
			case 'verification_modules':
				/* translators: %s: comma-separated module names. */
				$view['text']         = sprintf( __( 'Security modules that require ownership verification of the target: %s', 'sitelemetry-audit' ), $modules() );
				$view['verification'] = true;
				break;
			case 'module':
				$reasons     = implode( '; ', array_filter( array_map( 'strval', array_filter( $list( 'reasons' ), 'is_scalar' ) ), 'strlen' ) );
				$module      = Sitelemetry_Audit_Modules::label( $text( 'module' ) );
				$status      = Sitelemetry_Audit_Modules::status_label( $text( 'status' ) );
				$explanation = Sitelemetry_Audit_Modules::explain( $text( 'module' ), $reasons );
				$shown       = Sitelemetry_Audit_Outcome::clip( $reasons, Sitelemetry_Audit_Modules::SHOWN_REASON_CHARS );
				if ( null !== $explanation ) {
					/* translators: 1: module name, 2: module status such as "not measured". */
					$view['text']        = sprintf( __( '%1$s: %2$s', 'sitelemetry-audit' ), $module, $status );
					$view['explanation'] = $explanation;
					/* translators: %s: the reason as the audit reported it. */
					$view['detail'] = sprintf( __( 'Reported by the audit: %s', 'sitelemetry-audit' ), $shown );
				} elseif ( '' !== $shown ) {
					/* translators: 1: module name, 2: module status such as "not measured", 3: the reasons reported by the audit. */
					$view['text'] = sprintf( __( '%1$s: %2$s (%3$s)', 'sitelemetry-audit' ), $module, $status, $shown );
				} else {
					/* translators: 1: module name, 2: module status such as "not measured". */
					$view['text'] = sprintf( __( '%1$s: %2$s', 'sitelemetry-audit' ), $module, $status );
				}
				break;
		}
		return $view;
	}

	/**
	 * A "not measured" line of one pillar of a full audit, prefixed with the
	 * pillar name ("SEO: ..."); a single-kind audit has no pillar name.
	 *
	 * @param string $pillar Pillar name or ''.
	 * @param string $line   Line.
	 * @return string
	 */
	private static function in_pillar( $pillar, $line ) {
		/* translators: 1: pillar name, 2: what was not measured in that pillar. */
		return '' === $pillar ? $line : sprintf( __( '%1$s: %2$s', 'sitelemetry-audit' ), $pillar, $line );
	}

	/**
	 * One "what was not measured" entry as a single line of plain text (the AI fix
	 * prompt): the item, its explanation and the service's reason.
	 *
	 * @param array $entry       Structured entry.
	 * @param bool  $with_detail Whether to keep the service's reason after an explanation.
	 * @return string
	 */
	public static function not_measured_line( array $entry, $with_detail = true ) {
		$view = self::not_measured_view( $entry );
		if ( '' === $view['explanation'] ) {
			return $view['text'];
		}
		/* translators: 1: what was not measured, 2: explanation, 3: the reason as the audit reported it. */
		return trim( sprintf( __( '%1$s. %2$s %3$s', 'sitelemetry-audit' ), $view['text'], $view['explanation'], $with_detail ? $view['detail'] : '' ) );
	}

	/**
	 * Heading and explanation of each check status that is neither a pass nor a
	 * fail (Sitelemetry_Audit_Outcome::unmeasured_statuses()).
	 *
	 * @return array status => { title: string, explanation: string }
	 */
	public static function unmeasured_check_groups() {
		return array(
			'error'          => array(
				'title'       => __( 'Checks that ended with an error', 'sitelemetry-audit' ),
				'explanation' => __( 'These checks started but ended with an error (for example a timeout), so they have no result.', 'sitelemetry-audit' ),
			),
			'skipped'        => array(
				'title'       => __( 'Checks without a result', 'sitelemetry-audit' ),
				'explanation' => __( 'Sitelemetry could not determine a result for these checks, for example because the site did not answer the request or the data the check needs was not available.', 'sitelemetry-audit' ),
			),
			'manual_review'  => array(
				'title'       => __( 'Checks to review yourself', 'sitelemetry-audit' ),
				'explanation' => __( 'An automated audit cannot decide these checks; review them yourself.', 'sitelemetry-audit' ),
			),
			'not_applicable' => array(
				'title'       => __( 'Checks that do not apply', 'sitelemetry-audit' ),
				'explanation' => __( 'These checks do not apply to this site, for example because it does not use the feature they test.', 'sitelemetry-audit' ),
			),
		);
	}

	/**
	 * The "What was not measured" section: the gaps a partial result reports
	 * (pillars, modules, verification) and, for any measured result, the checks
	 * that have neither a pass nor a fail result, grouped by status.
	 *
	 * @param array $model Result model.
	 * @return array { title: string, note: string, items: array, checks: array, checks_note: string }
	 */
	public static function not_measured_section( array $model ) {
		$items   = array();
		$entries = isset( $model['not_measured'] ) && is_array( $model['not_measured'] ) ? $model['not_measured'] : array();
		foreach ( $entries as $entry ) {
			$view = is_array( $entry ) ? self::not_measured_view( $entry ) : null;
			if ( $view && '' !== $view['text'] ) {
				$items[] = $view;
			}
		}

		$checks = array();
		$listed = 0;
		$stored = isset( $model['unmeasured_checks'] ) && is_array( $model['unmeasured_checks'] ) ? $model['unmeasured_checks'] : array();
		foreach ( self::unmeasured_check_groups() as $status => $group ) {
			$rows = array();
			foreach ( $stored as $check ) {
				if ( ! is_array( $check ) || ! isset( $check['status'] ) || $status !== $check['status'] ) {
					continue;
				}
				$title  = isset( $check['title'] ) && '' !== (string) $check['title'] ? (string) $check['title'] : ( isset( $check['id'] ) ? (string) $check['id'] : '' );
				$rows[] = array(
					'title'    => $title,
					'evidence' => isset( $check['evidence'] ) ? (string) $check['evidence'] : '',
				);
			}
			if ( $rows ) {
				$listed  += count( $rows );
				$checks[] = array(
					'title'       => Sitelemetry_Audit_Modules::count_title( $group['title'], count( $rows ) ),
					'explanation' => $group['explanation'],
					'items'       => $rows,
				);
			}
		}
		$counted = isset( $model['unmeasured_total'] ) && is_numeric( $model['unmeasured_total'] ) ? max( $listed, (int) $model['unmeasured_total'] ) : $listed;
		$more    = $counted - $listed;

		if ( ! $items && ! $checks ) {
			$items[] = array(
				'text'         => __( 'Some requested measurements were unavailable; this result does not say which.', 'sitelemetry-audit' ),
				'explanation'  => '',
				'detail'       => '',
				'verification' => false,
			);
		}
		$explained = false;
		foreach ( $items as $item ) {
			$explained = $explained || '' !== $item['explanation'];
		}
		return array(
			'title'       => Sitelemetry_Audit_Modules::count_title( __( 'What was not measured', 'sitelemetry-audit' ), count( $items ) + $counted ),
			'note'        => $explained
				? __( 'Explained items come from how the site responded (such as a redirect or a firewall) or from a deliberate scan limit, not from an error in the audit. Unmeasured checks are not counted as passes.', 'sitelemetry-audit' )
				: __( 'Unmeasured checks are not counted as passes.', 'sitelemetry-audit' ),
			'items'       => $items,
			'checks'      => $checks,
			/* translators: %d: number of checks without a result that are not listed. */
			'checks_note' => $more > 0 ? sprintf( _n( '%d more check without a result was counted but not kept with this result, so it is not listed here.', '%d more checks without a result were counted but not kept with this result, so they are not listed here.', $more, 'sitelemetry-audit' ), $more ) : '',
		);
	}
}
