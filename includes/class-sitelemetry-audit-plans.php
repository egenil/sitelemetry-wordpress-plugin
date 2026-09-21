<?php
/**
 * Public plan catalogue (GET {base}/api/plans) for the neutral plan and usage box.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches, caches and normalizes the plan catalogue. Nothing here is authenticated.
 */
class Sitelemetry_Audit_Plans {

	const CACHE_TTL         = 12 * HOUR_IN_SECONDS;
	const CACHE_TTL_FAILURE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Catalogue audit-kind id => label.
	 *
	 * @return array
	 */
	public static function kind_labels() {
		return array(
			'full'           => __( 'Full', 'sitelemetry-audit' ),
			'security'       => __( 'Security', 'sitelemetry-audit' ),
			'seo'            => __( 'SEO', 'sitelemetry-audit' ),
			'ai'             => __( 'AI visibility', 'sitelemetry-audit' ),
			'accessibility'  => __( 'Accessibility', 'sitelemetry-audit' ),
			'performance'    => __( 'Performance', 'sitelemetry-audit' ),
			'integrations'   => __( 'Integrations', 'sitelemetry-audit' ),
			'search-console' => __( 'Search Console', 'sitelemetry-audit' ),
		);
	}

	/**
	 * Label of a catalogue audit kind.
	 *
	 * @param string $id Catalogue id.
	 * @return string
	 */
	public static function kind_label( $id ) {
		$labels = self::kind_labels();
		return isset( $labels[ $id ] ) ? $labels[ $id ] : (string) $id;
	}

	/**
	 * Normalizes the "plans" list of the catalogue.
	 *
	 * @param mixed $list Decoded list.
	 * @return array|null Normalized plans, or null when empty or malformed.
	 */
	public static function normalize( $list ) {
		if ( ! is_array( $list ) ) {
			return null;
		}
		$plans = array();
		foreach ( $list as $plan ) {
			if ( ! is_array( $plan ) || ! isset( $plan['id'] ) || ! is_string( $plan['id'] ) ) {
				continue;
			}
			$price      = isset( $plan['price'] ) && is_array( $plan['price'] ) ? $plan['price'] : array();
			$commercial = isset( $plan['commercial'] ) && is_array( $plan['commercial'] ) ? $plan['commercial'] : array();
			$monthly    = isset( $price['monthly'] ) ? Sitelemetry_Audit_Outcome::number_or_null( $price['monthly'] ) : null;
			$modules    = null;
			if ( isset( $plan['moduleCount'] ) && null !== Sitelemetry_Audit_Outcome::number_or_null( $plan['moduleCount'] ) ) {
				$modules = (int) $plan['moduleCount'];
			} elseif ( isset( $plan['modules'] ) && is_array( $plan['modules'] ) ) {
				$modules = count( $plan['modules'] );
			}
			$scans   = isset( $commercial['securityScans'] ) ? Sitelemetry_Audit_Outcome::number_or_null( $commercial['securityScans'] ) : null;
			$kinds   = array();
			if ( isset( $plan['auditKinds'] ) && is_array( $plan['auditKinds'] ) ) {
				foreach ( $plan['auditKinds'] as $kind ) {
					if ( is_scalar( $kind ) ) {
						$kinds[] = (string) $kind;
					}
				}
			}
			$plans[] = array(
				'id'             => $plan['id'],
				'label'          => isset( $plan['label'] ) && is_string( $plan['label'] ) ? $plan['label'] : $plan['id'],
				'monthly'        => $monthly,
				'currency'       => isset( $price['currency'] ) && is_string( $price['currency'] ) ? $price['currency'] : 'USD',
				'audit_kinds'    => $kinds,
				'module_count'   => $modules,
				'security_scans' => null === $scans ? null : (int) $scans,
			);
		}
		return count( $plans ) > 0 ? $plans : null;
	}

	/**
	 * Fetches the catalogue from the service (no cache).
	 *
	 * @param string $base_url Service base URL.
	 * @return array|null
	 */
	public static function fetch( $base_url ) {
		$response = wp_remote_get(
			rtrim( (string) $base_url, '/' ) . '/api/plans',
			array(
				'timeout'    => 15,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => Sitelemetry_Audit_Client::user_agent(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) && isset( $decoded['plans'] ) ? self::normalize( $decoded['plans'] ) : null;
	}

	/**
	 * Cached catalogue (12 hours; a failed fetch is remembered for 10 minutes).
	 *
	 * @param string $base_url Service base URL.
	 * @return array|null
	 */
	public static function get( $base_url ) {
		$cached = get_transient( Sitelemetry_Audit_Settings::PLANS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['plans'] ) && is_array( $cached['plans'] ) ? $cached['plans'] : null;
		}
		$plans = self::fetch( $base_url );
		set_transient(
			Sitelemetry_Audit_Settings::PLANS_TRANSIENT,
			array( 'plans' => $plans ),
			null === $plans ? self::CACHE_TTL_FAILURE : self::CACHE_TTL
		);
		return $plans;
	}

	/**
	 * Plan by id.
	 *
	 * @param array|null $plans Normalized plans.
	 * @param string     $id    Plan id.
	 * @return array|null
	 */
	public static function find( $plans, $id ) {
		if ( ! is_array( $plans ) ) {
			return null;
		}
		foreach ( $plans as $plan ) {
			if ( $plan['id'] === $id ) {
				return $plan;
			}
		}
		return null;
	}

	/**
	 * Plans other than Free, cheapest first.
	 *
	 * @param array|null $plans Normalized plans.
	 * @return array
	 */
	public static function paid( $plans ) {
		if ( ! is_array( $plans ) ) {
			return array();
		}
		$paid = array_values(
			array_filter(
				$plans,
				function ( $plan ) {
					return 'free' !== $plan['id'];
				}
			)
		);
		usort(
			$paid,
			function ( $a, $b ) {
				$left  = null === $a['monthly'] ? PHP_INT_MAX : $a['monthly'];
				$right = null === $b['monthly'] ? PHP_INT_MAX : $b['monthly'];
				if ( $left === $right ) {
					return 0;
				}
				return $left < $right ? -1 : 1;
			}
		);
		return $paid;
	}

	/**
	 * Price for display.
	 *
	 * @param array $plan Normalized plan.
	 * @return string
	 */
	public static function format_price( array $plan ) {
		if ( null === $plan['monthly'] ) {
			return __( 'see pricing', 'sitelemetry-audit' );
		}
		if ( 0 === (int) $plan['monthly'] && 0.0 === (float) $plan['monthly'] ) {
			return __( 'Free', 'sitelemetry-audit' );
		}
		$amount = 'USD' === $plan['currency'] ? '$' . $plan['monthly'] : $plan['currency'] . ' ' . $plan['monthly'];
		/* translators: %s: price with currency, for example $49. */
		return sprintf( __( '%s/month', 'sitelemetry-audit' ), $amount );
	}

	/**
	 * Label of the cheapest plan that includes an audit kind ("Starter"), or null when
	 * the catalogue is unavailable or lists no such plan.
	 *
	 * @param array|null $plans Normalized plans.
	 * @param string     $kind  Plugin audit kind.
	 * @return string|null
	 */
	public static function required_plan_label( $plans, $kind ) {
		$ids = Sitelemetry_Audit_Labels::plan_kind_ids();
		if ( ! is_array( $plans ) || ! isset( $ids[ $kind ] ) ) {
			return null;
		}
		$catalogue_id = $ids[ $kind ];
		$free         = self::find( $plans, 'free' );
		if ( $free && in_array( $catalogue_id, $free['audit_kinds'], true ) ) {
			return $free['label'];
		}
		foreach ( self::paid( $plans ) as $plan ) {
			if ( in_array( $catalogue_id, $plan['audit_kinds'], true ) ) {
				return $plan['label'];
			}
		}
		return null;
	}

	/**
	 * Required plan when the catalogue is unavailable, from the public plan facts:
	 * Free includes the security audit; every other kind needs Starter or higher.
	 *
	 * @param string $kind Plugin audit kind.
	 * @return string
	 */
	public static function fallback_required_plan_label( $kind ) {
		return 'security' === $kind ? __( 'Free', 'sitelemetry-audit' ) : __( 'Starter', 'sitelemetry-audit' );
	}
}
