<?php
/**
 * Turns a raw audit outcome into one normalized result model.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions: status classification, findings normalization, coverage notes.
 *
 * A "run" is an array with outcome (result | error | timeout), tool, job_id and
 * either result (the tools/call result) or error (a WP_Error from the client).
 */
class Sitelemetry_Audit_Outcome {

	/**
	 * Pre-execution gates the server reports as status "action_required".
	 *
	 * @return array
	 */
	public static function reason_statuses() {
		return array(
			'entitlement_required'           => 'plan_required',
			'usage_limit_reached'            => 'quota_exhausted',
			'target_verification_required'   => 'verification_required',
			'target_reverification_required' => 'verification_required',
			'verification_scope_required'    => 'verification_required',
			'authorization_consent_required' => 'verification_required',
		);
	}

	/**
	 * Classifies an error message (and optional server error code) into a status.
	 *
	 * @param string      $text Message.
	 * @param string|null $code Server error code.
	 * @return string plan_required | quota_exhausted | verification_required | blocked
	 */
	public static function status_from_text( $text, $code ) {
		$text = (string) $text;
		if ( 'PLAN_UPGRADE_REQUIRED' === $code || preg_match( '/requires (?:starter|professional|enterprise)(?: or higher)? access|not included in (?:the connected|free|the current plan|your plan)|upgrade your plan/i', $text ) ) {
			return 'plan_required';
		}
		if ( 'COMMERCIAL_USAGE_LIMIT_REACHED' === $code || preg_match( '/monthly .*limit reached|allowance is exhausted|usage limit reached/i', $text ) ) {
			return 'quota_exhausted';
		}
		if ( preg_match( '/ownership|verif(?:y|ied|ication)|authorization terms/i', $text ) ) {
			return 'verification_required';
		}
		return 'blocked';
	}

	/**
	 * Collapses whitespace and limits length.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum length in characters.
	 * @return string
	 */
	public static function clip( $value, $max = 400 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text      = (string) $value;
		$collapsed = preg_replace( '/\s+/u', ' ', $text );
		$text      = trim( null === $collapsed ? $text : $collapsed );
		if ( mb_strlen( $text ) > $max ) {
			return mb_substr( $text, 0, $max - 1 ) . '…';
		}
		return $text;
	}

	/**
	 * Finite number or null.
	 *
	 * @param mixed $value Value.
	 * @return int|float|null
	 */
	public static function number_or_null( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return $value;
		}
		return null;
	}

	/**
	 * http(s) URL or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function url_or_null( $value ) {
		return is_string( $value ) && preg_match( '#^https?://#i', $value ) ? $value : null;
	}

	/**
	 * First non-null value found under any of the dotted paths.
	 *
	 * @param array    $source Array.
	 * @param string[] $paths  Dotted paths.
	 * @return mixed|null
	 */
	public static function pick( array $source, array $paths ) {
		foreach ( $paths as $path ) {
			$value = $source;
			foreach ( explode( '.', $path ) as $key ) {
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					$value = null;
					break;
				}
				$value = $value[ $key ];
			}
			if ( null !== $value ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * First non-empty string among the given keys.
	 *
	 * @param array    $raw  Finding.
	 * @param string[] $keys Keys in order of preference.
	 * @return string
	 */
	private static function first_text( array $raw, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				return (string) $raw[ $key ];
			}
		}
		return '';
	}

	/**
	 * Normalizes one server finding.
	 *
	 * @param mixed $raw   Finding as returned by the server.
	 * @param int   $index Position, used for the fallback id.
	 * @return array
	 */
	public static function normalize_finding( $raw, $index = 0 ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$severity = isset( $raw['severity'] ) && in_array( $raw['severity'], Sitelemetry_Audit_Labels::severities(), true ) ? $raw['severity'] : 'info';
		$title    = self::first_text( $raw, array( 'title' ) );
		$title    = self::clip( '' !== $title ? $title : __( 'Untitled finding', 'sitelemetry-audit' ), 200 );
		$id       = isset( $raw['findingKey'] ) && is_string( $raw['findingKey'] ) && '' !== $raw['findingKey']
			? $raw['findingKey']
			: 'finding-' . (int) $index . '-' . substr( hash( 'sha256', $title ), 0, 8 );
		return array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'evidence' => self::clip( self::first_text( $raw, array( 'evidence' ) ), 1000 ),
			'impact'   => self::clip( self::first_text( $raw, array( 'impact' ) ), 600 ),
			'fix'      => self::clip( self::first_text( $raw, array( 'remediation', 'fix', 'recommendation' ) ), 800 ),
			'category' => self::clip( self::first_text( $raw, array( 'category' ) ), 80 ),
			'location' => self::clip( self::first_text( $raw, array( 'location', 'url', 'path' ) ), 2048 ),
			'pillar'   => self::clip( self::first_text( $raw, array( 'pillar' ) ), 40 ),
		);
	}

	/**
	 * Zero counts per severity.
	 *
	 * @return array
	 */
	public static function zero_counts() {
		return array_fill_keys( Sitelemetry_Audit_Labels::severities(), 0 );
	}

	/**
	 * Counts normalized findings per severity.
	 *
	 * @param array $findings Normalized findings.
	 * @return array
	 */
	public static function count_by_severity( array $findings ) {
		$counts = self::zero_counts();
		foreach ( $findings as $finding ) {
			if ( isset( $finding['severity'] ) && isset( $counts[ $finding['severity'] ] ) ) {
				++$counts[ $finding['severity'] ];
			}
		}
		return $counts;
	}

	/**
	 * Everything the result says was skipped, unavailable or gated, as structured
	 * entries (rendered later by Sitelemetry_Audit_Labels::not_measured_line()).
	 * Unmeasured is not a pass.
	 *
	 * @param array $structured structuredContent of the result.
	 * @return array
	 */
	public static function not_measured( array $structured ) {
		$entries = array();
		$details = isset( $structured['auditDetails'] ) && is_array( $structured['auditDetails'] ) ? $structured['auditDetails'] : array();

		if ( isset( $structured['failedPillars'] ) && is_array( $structured['failedPillars'] ) ) {
			foreach ( $structured['failedPillars'] as $row ) {
				if ( is_array( $row ) && isset( $row['pillar'] ) ) {
					$entries[] = array(
						'type'   => 'failed_pillar',
						'pillar' => self::clip( $row['pillar'], 80 ),
						'error'  => self::clip( isset( $row['error'] ) ? $row['error'] : '', 300 ),
					);
				}
			}
		}

		$coverage = isset( $details['planCoverage'] ) && is_array( $details['planCoverage'] ) ? $details['planCoverage'] : array();
		if ( isset( $coverage['skippedPillars'] ) && is_array( $coverage['skippedPillars'] ) ) {
			foreach ( $coverage['skippedPillars'] as $row ) {
				if ( is_array( $row ) && isset( $row['pillar'] ) ) {
					$entries[] = array(
						'type'   => 'skipped_pillar',
						'pillar' => self::clip( $row['pillar'], 80 ),
						'reason' => isset( $row['reason'] ) && 'not_in_plan' === $row['reason'] ? 'not_in_plan' : 'scope',
					);
				}
			}
		}
		if ( ! empty( $coverage['skippedSecurityModules'] ) && is_array( $coverage['skippedSecurityModules'] ) ) {
			$entries[] = array(
				'type'    => 'skipped_modules',
				'modules' => self::string_list( $coverage['skippedSecurityModules'] ),
			);
		}

		$pillars = isset( $details['pillars'] ) && is_array( $details['pillars'] ) ? $details['pillars'] : array();
		$scopes  = array();
		if ( isset( $details['scope'] ) && is_array( $details['scope'] ) ) {
			$scopes[] = $details['scope'];
		}
		foreach ( $pillars as $name => $pillar ) {
			$scope = is_array( $pillar ) && isset( $pillar['scope'] ) && is_array( $pillar['scope'] ) ? $pillar['scope'] : null;
			if ( null === $scope ) {
				continue;
			}
			if ( isset( $scope['status'] ) && 'unavailable' === $scope['status'] ) {
				$entries[] = array(
					'type'   => 'pillar_unavailable',
					'pillar' => self::clip( $name, 80 ),
				);
			}
			$scopes[] = $scope;
		}

		foreach ( $scopes as $scope ) {
			if ( ! empty( $scope['verificationRequiredModules'] ) && is_array( $scope['verificationRequiredModules'] ) ) {
				$entries[] = array(
					'type'    => 'verification_modules',
					'modules' => self::string_list( $scope['verificationRequiredModules'] ),
				);
			}
			if ( isset( $scope['moduleResults'] ) && is_array( $scope['moduleResults'] ) ) {
				foreach ( $scope['moduleResults'] as $row ) {
					if ( ! is_array( $row ) || ! isset( $row['status'] ) || ! in_array( $row['status'], array( 'unavailable', 'partial' ), true ) ) {
						continue;
					}
					$reasons = array();
					if ( isset( $row['reasons'] ) && is_array( $row['reasons'] ) ) {
						$reasons = $row['reasons'];
					} elseif ( isset( $row['reason'] ) ) {
						$reasons = array( $row['reason'] );
					}
					$entries[] = array(
						'type'    => 'module',
						'module'  => self::clip( isset( $row['module'] ) ? $row['module'] : '', 80 ),
						'status'  => (string) $row['status'],
						'reasons' => array_map( array( __CLASS__, 'clip' ), self::string_list( $reasons ) ),
					);
				}
			}
		}

		$unique = array();
		foreach ( $entries as $entry ) {
			$unique[ wp_json_encode( $entry ) ] = $entry;
		}
		return array_values( $unique );
	}

	/**
	 * Keeps the non-empty strings of a list.
	 *
	 * @param array $values Values.
	 * @return string[]
	 */
	private static function string_list( array $values ) {
		$out = array();
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$out[] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Text content of a tool result.
	 *
	 * @param array $result Tool result.
	 * @return string
	 */
	public static function text_content( array $result ) {
		$parts = array();
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			foreach ( $result['content'] as $chunk ) {
				if ( is_array( $chunk ) && isset( $chunk['type'] ) && 'text' === $chunk['type'] && isset( $chunk['text'] ) && is_string( $chunk['text'] ) ) {
					$parts[] = $chunk['text'];
				}
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	/**
	 * The empty model every outcome starts from.
	 *
	 * @param string      $kind   Audit kind.
	 * @param string      $target Target URL.
	 * @param string|null $tool   Tool name.
	 * @param string|null $job_id Job id.
	 * @return array
	 */
	public static function empty_model( $kind, $target, $tool = null, $job_id = null ) {
		return array(
			'kind'              => (string) $kind,
			'target'            => (string) $target,
			'tool'              => is_string( $tool ) && '' !== $tool ? $tool : null,
			'job_id'            => is_string( $job_id ) && '' !== $job_id ? $job_id : null,
			'status'            => 'blocked',
			'reason'            => null,
			'message'           => '',
			'score'             => null,
			'grade'             => null,
			'counts'            => self::zero_counts(),
			'total'             => 0,
			'returned_findings' => 0,
			'truncated'         => false,
			'findings'          => array(),
			'pillars'           => null,
			'not_measured'      => array(),
			'plan'              => null,
			'remaining_scans'   => null,
			'report_url'        => null,
			'passing_checks'    => null,
		);
	}

	/**
	 * Interprets a finished run.
	 *
	 * @param array  $run    See the class description.
	 * @param string $kind   Audit kind.
	 * @param string $target Target URL.
	 * @return array Result model.
	 */
	public static function interpret( array $run, $kind, $target ) {
		$model   = self::empty_model( $kind, $target, isset( $run['tool'] ) ? $run['tool'] : null, isset( $run['job_id'] ) ? $run['job_id'] : null );
		$outcome = isset( $run['outcome'] ) ? $run['outcome'] : 'error';

		if ( 'timeout' === $outcome ) {
			$model['reason'] = 'timeout';
			return $model;
		}

		if ( 'error' === $outcome ) {
			return self::interpret_error( $model, isset( $run['error'] ) ? $run['error'] : null );
		}

		$result     = isset( $run['result'] ) && is_array( $run['result'] ) ? $run['result'] : array();
		$text       = self::text_content( $result );
		$structured = isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ? $result['structuredContent'] : array();
		$status     = isset( $structured['status'] ) && is_string( $structured['status'] ) ? $structured['status'] : '';

		if ( 'action_required' === $status ) {
			$reason          = isset( $structured['reason'] ) && is_string( $structured['reason'] ) ? $structured['reason'] : '';
			$map             = self::reason_statuses();
			$model['status'] = isset( $map[ $reason ] ) ? $map[ $reason ] : 'blocked';
			$model['reason'] = '' !== $reason ? $reason : 'action_required';
			$model['message'] = $text;
			return $model;
		}
		if ( ! empty( $result['isError'] ) ) {
			$model['status']  = self::status_from_text( $text, null );
			$model['reason']  = 'tool_error';
			$model['message'] = $text;
			return $model;
		}
		if ( 'running' === $status ) {
			$model['reason'] = 'incomplete';
			return $model;
		}

		$findings = array();
		if ( isset( $structured['findings'] ) && is_array( $structured['findings'] ) ) {
			foreach ( array_values( $structured['findings'] ) as $index => $raw ) {
				$findings[] = self::normalize_finding( $raw, $index );
			}
		}
		$not_measured = self::not_measured( $structured );
		$coverage     = isset( $structured['coverageStatus'] ) ? $structured['coverageStatus'] : '';
		$partial      = 'partial' === $status
			|| ( isset( $structured['complete'] ) && false === $structured['complete'] )
			|| in_array( $coverage, array( 'partial', 'unavailable' ), true )
			|| count( $not_measured ) > 0;

		$counts = self::count_by_severity( $findings );
		if ( isset( $structured['counts'] ) && is_array( $structured['counts'] ) ) {
			foreach ( Sitelemetry_Audit_Labels::severities() as $severity ) {
				$server = isset( $structured['counts'][ $severity ] ) ? self::number_or_null( $structured['counts'][ $severity ] ) : null;
				if ( null !== $server ) {
					$counts[ $severity ] = (int) $server;
				}
			}
		}
		$total = isset( $structured['total'] ) ? self::number_or_null( $structured['total'] ) : null;
		$total = null === $total ? count( $findings ) : (int) $total;

		$plan = self::pick( $structured, array( 'auditDetails.scope.plan', 'auditDetails.planCoverage.plan' ) );
		if ( ! is_string( $plan ) && isset( $structured['auditDetails']['pillars'] ) && is_array( $structured['auditDetails']['pillars'] ) ) {
			foreach ( $structured['auditDetails']['pillars'] as $pillar ) {
				$candidate = is_array( $pillar ) ? self::pick( $pillar, array( 'scope.plan' ) ) : null;
				if ( is_string( $candidate ) && '' !== $candidate ) {
					$plan = $candidate;
					break;
				}
			}
		}

		$score = isset( $structured['score'] ) ? self::number_or_null( $structured['score'] ) : null;
		if ( null === $score && isset( $structured['blended'] ) ) {
			$score = self::number_or_null( $structured['blended'] );
		}

		$model['status']            = $partial ? 'partial' : 'completed';
		$model['message']           = $text;
		$model['score']             = $score;
		$model['grade']             = isset( $structured['grade'] ) && is_string( $structured['grade'] ) ? $structured['grade'] : null;
		$model['counts']            = $counts;
		$model['total']             = $total;
		$model['returned_findings'] = count( $findings );
		$model['truncated']         = ( isset( $structured['findingsTruncated'] ) && true === $structured['findingsTruncated'] ) || count( $findings ) < $total;
		$model['findings']          = $findings;
		$model['pillars']           = isset( $structured['pillars'] ) && is_array( $structured['pillars'] ) ? self::normalize_pillars( $structured['pillars'] ) : null;
		$model['not_measured']      = $not_measured;
		$model['plan']              = is_string( $plan ) && '' !== $plan ? $plan : null;
		$model['remaining_scans']   = self::number_or_null( self::pick( $structured, array( 'usage.remaining.securityScans', 'remainingSecurityScans', 'allowance.remaining.securityScans' ) ) );
		$model['report_url']        = self::url_or_null( self::pick( $structured, array( 'reportUrl', 'report.url', 'links.report' ) ) );
		$model['passing_checks']    = isset( $structured['passingChecks'] ) ? self::number_or_null( $structured['passingChecks'] ) : null;
		return $model;
	}

	/**
	 * Interprets a client error.
	 *
	 * @param array $model Empty model.
	 * @param mixed $error WP_Error or message.
	 * @return array
	 */
	private static function interpret_error( array $model, $error ) {
		if ( ! is_wp_error( $error ) ) {
			$model['reason']  = 'transport';
			$model['message'] = is_string( $error ) ? $error : '';
			return $model;
		}
		$code    = $error->get_error_code();
		$message = $error->get_error_message();
		if ( 'sitelemetry_http' === $code ) {
			$status      = Sitelemetry_Audit_Client::error_status( $error );
			$server_code = Sitelemetry_Audit_Client::error_server_code( $error );
			if ( 401 === $status || 403 === $status ) {
				$model['reason']  = 'unauthorized';
				$model['message'] = $message;
				return $model;
			}
			$mapped           = 402 === $status ? 'plan_required' : self::status_from_text( $message, $server_code );
			$model['status']  = 'verification_required' === $mapped ? 'blocked' : $mapped;
			$model['reason']  = null !== $server_code ? $server_code : 'http_' . $status;
			$model['message'] = $message;
			return $model;
		}
		if ( 'sitelemetry_rpc' === $code ) {
			$data             = $error->get_error_data();
			$rpc_code         = is_array( $data ) && isset( $data['code'] ) && null !== $data['code'] ? (string) $data['code'] : 'error';
			$model['status']  = self::status_from_text( $message, null );
			$model['reason']  = 'rpc_' . $rpc_code;
			$model['message'] = $message;
			return $model;
		}
		if ( in_array( $code, array( 'no_api_key', 'invalid_target' ), true ) ) {
			$model['reason']  = $code;
			$model['message'] = '';
			return $model;
		}
		$model['reason']  = 'transport';
		$model['message'] = $message;
		return $model;
	}

	/**
	 * Keeps score and findings of each pillar of a full audit.
	 *
	 * @param array $pillars Server pillars map.
	 * @return array
	 */
	private static function normalize_pillars( array $pillars ) {
		$out = array();
		foreach ( $pillars as $name => $pillar ) {
			$pillar                          = is_array( $pillar ) ? $pillar : array();
			$out[ self::clip( $name, 80 ) ] = array(
				'score'    => isset( $pillar['score'] ) ? self::number_or_null( $pillar['score'] ) : null,
				'findings' => isset( $pillar['findings'] ) ? (int) self::number_or_null( $pillar['findings'] ) : 0,
			);
		}
		return $out;
	}
}
