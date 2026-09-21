<?php
/**
 * Links to the Sitelemetry website and the neutral "Plan and usage" content.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upgrade hooks are factual and calm: the remaining allowance when known, what paid
 * plans add (from the public catalogue) and links to pricing and to the app.
 */
class Sitelemetry_Audit_Links {

	const SITE       = 'https://sitelemetry.com';
	const UTM_SOURCE = 'wordpress-plugin';
	const UTM_MEDIUM = 'plugin';

	/**
	 * Pricing page with the plugin's UTM parameters.
	 *
	 * @return string
	 */
	public static function pricing_url() {
		return self::SITE . '/pricing?utm_source=' . self::UTM_SOURCE . '&utm_medium=' . self::UTM_MEDIUM;
	}

	/**
	 * The app: API keys, ownership verification, full reports.
	 *
	 * @return string
	 */
	public static function app_url() {
		return self::SITE . '/app';
	}

	/**
	 * The next step when a result requires something in the app, or null.
	 *
	 * @param array $model Result model.
	 * @return string|null
	 */
	public static function verification_step( array $model ) {
		$status = isset( $model['status'] ) ? $model['status'] : '';
		$reason = isset( $model['reason'] ) ? $model['reason'] : '';
		if ( 'verification_required' === $status && 'authorization_consent_required' === $reason ) {
			return __( 'Review and accept the current audit authorization terms for the connected account in the app, then run the audit again.', 'sitelemetry-audit' );
		}
		$needs_verification = 'verification_required' === $status;
		if ( ! $needs_verification && isset( $model['not_measured'] ) && is_array( $model['not_measured'] ) ) {
			foreach ( $model['not_measured'] as $entry ) {
				if ( isset( $entry['type'] ) && 'verification_modules' === $entry['type'] ) {
					$needs_verification = true;
					break;
				}
			}
		}
		if ( $needs_verification ) {
			return __( 'Verify ownership of the target in the app (DNS or HTTP challenge) to include the protected checks.', 'sitelemetry-audit' );
		}
		return null;
	}

	/**
	 * Data for the "Plan and usage" box.
	 *
	 * @param array      $model Result model (may be an empty model when nothing ran yet).
	 * @param array|null $plans Normalized plan catalogue or null when unavailable.
	 * @return array {
	 *     @type string      $lead           One factual sentence about the connected plan or the gate.
	 *     @type string      $remaining      Sentence with the remaining scans, or ''.
	 *     @type array       $paid           Rows for the paid plans table (label, price, kinds, modules, scans).
	 *     @type string      $paid_intro     Intro sentence of the table, or ''.
	 *     @type string      $pricing_url    Pricing link with UTM parameters.
	 *     @type string      $app_url        App link.
	 *     @type string|null $verification   Next step in the app, or null.
	 * }
	 */
	public static function plan_box( array $model, $plans ) {
		$status = isset( $model['status'] ) ? $model['status'] : '';
		$plan   = isset( $model['plan'] ) ? $model['plan'] : null;
		$free   = Sitelemetry_Audit_Plans::find( $plans, 'free' );

		if ( 'quota_exhausted' === $status ) {
			$lead = __( 'The connected account has used its monthly audit allowance. It resets at the start of the next billing period. This run did not start an audit and did not consume allowance.', 'sitelemetry-audit' );
		} elseif ( 'plan_required' === $status ) {
			/* translators: %s: audit kind label. */
			$lead = sprintf( __( 'The %s audit is not included in the connected account\'s plan. This run did not start an audit and did not consume allowance.', 'sitelemetry-audit' ), Sitelemetry_Audit_Labels::kind_label( isset( $model['kind'] ) ? $model['kind'] : '' ) );
		} elseif ( 'free' === $plan ) {
			if ( $free && null !== $free['module_count'] && null !== $free['security_scans'] ) {
				/* translators: 1: number of security modules, 2: number of security scans per month. */
				$lead = sprintf( __( 'The connected account is on the Free plan: %1$d public security modules and %2$d security scans per month.', 'sitelemetry-audit' ), $free['module_count'], $free['security_scans'] );
			} else {
				$lead = __( 'The connected account is on the Free plan.', 'sitelemetry-audit' );
			}
		} elseif ( is_string( $plan ) && '' !== $plan ) {
			$known = Sitelemetry_Audit_Plans::find( $plans, $plan );
			/* translators: %s: plan name. */
			$lead = sprintf( __( 'The connected account is on the %s plan.', 'sitelemetry-audit' ), $known ? $known['label'] : ucfirst( $plan ) );
		} else {
			$lead = __( 'Each completed audit uses one unit of the monthly allowance of the connected account; polling a running audit does not use more. The current plan and the remaining allowance are shown in the app.', 'sitelemetry-audit' );
		}

		$remaining = '';
		if ( isset( $model['remaining_scans'] ) && null !== $model['remaining_scans'] ) {
			/* translators: %d: number of remaining security scans. */
			$remaining = sprintf( __( 'Remaining security scans in the current period: %d.', 'sitelemetry-audit' ), (int) $model['remaining_scans'] );
		}

		$rows = array();
		foreach ( Sitelemetry_Audit_Plans::paid( $plans ) as $paid ) {
			$kinds  = array_map( array( 'Sitelemetry_Audit_Plans', 'kind_label' ), $paid['audit_kinds'] );
			$rows[] = array(
				'label'   => $paid['label'],
				'price'   => Sitelemetry_Audit_Plans::format_price( $paid ),
				'kinds'   => count( $kinds ) > 0 ? implode( ', ', $kinds ) : __( 'see pricing', 'sitelemetry-audit' ),
				'modules' => null === $paid['module_count'] ? __( 'see pricing', 'sitelemetry-audit' ) : (string) $paid['module_count'],
				'scans'   => null === $paid['security_scans'] ? __( 'see pricing', 'sitelemetry-audit' ) : (string) $paid['security_scans'],
			);
		}

		return array(
			'lead'         => $lead,
			'remaining'    => $remaining,
			'paid'         => $rows,
			'paid_intro'   => count( $rows ) > 0 ? __( 'Paid plans include these audit kinds and security module counts:', 'sitelemetry-audit' ) : '',
			'pricing_url'  => self::pricing_url(),
			'app_url'      => self::app_url(),
			'verification' => self::verification_step( $model ),
		);
	}
}
