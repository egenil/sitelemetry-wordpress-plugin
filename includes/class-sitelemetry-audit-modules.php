<?php
/**
 * Security modules: readable names, the module of a check, explanations of why a
 * module was not measured, and the "Passing checks" section.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions over stored result models. The module ids, the check id prefixes
 * and the explanations follow the Sitelemetry service and the browser extension,
 * so the three clients describe a result the same way. The service reports module
 * reasons in the report language it was asked for; the explanations are chosen
 * structurally (module id, HTTP status codes, numbers), so they work whatever
 * language the service used, and the service's own reason is always kept next to
 * them.
 */
class Sitelemetry_Audit_Modules {

	/**
	 * Characters of a service reason shown on the page and in the AI fix prompt.
	 */
	const SHOWN_REASON_CHARS = 300;

	/**
	 * Characters of a module's reasons kept with a result: an explanation can
	 * depend on the end of a long reason.
	 */
	const MAX_REASON_CHARS = 2000;

	/**
	 * Modules that probe well-known paths (/.env, /graphql, /admin, /package.json...).
	 */
	const PATH_MODULES = array( 'exposure', 'api-exposure', 'auth-surface', 'supply-chain' );

	/**
	 * Port scanning modules.
	 */
	const PORT_MODULES = array( 'ports', 'port-intel' );

	/**
	 * Module id => readable name, in the order sitelemetry.com lists the modules.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'dns'                => __( 'DNS posture', 'sitelemetry-audit' ),
			'dns-email'          => __( 'Email DNS', 'sitelemetry-audit' ),
			'rdap'               => __( 'WHOIS / RDAP', 'sitelemetry-audit' ),
			'tls'                => __( 'TLS / certificate', 'sitelemetry-audit' ),
			'http-headers'       => __( 'HTTP security headers', 'sitelemetry-audit' ),
			'mitm-posture'       => __( 'MITM / HTTPS posture', 'sitelemetry-audit' ),
			'tech-fingerprint'   => __( 'Technology fingerprint', 'sitelemetry-audit' ),
			'http-methods'       => __( 'HTTP methods / CORS', 'sitelemetry-audit' ),
			'exposure'           => __( 'Sensitive file exposure', 'sitelemetry-audit' ),
			'api-exposure'       => __( 'API / GraphQL exposure', 'sitelemetry-audit' ),
			'auth-surface'       => __( 'Admin / login surface', 'sitelemetry-audit' ),
			'supply-chain'       => __( 'Supply-chain files', 'sitelemetry-audit' ),
			'wordpress'          => __( 'WordPress posture', 'sitelemetry-audit' ),
			'ports'              => __( 'Port scan', 'sitelemetry-audit' ),
			'port-intel'         => __( 'Port risk intelligence', 'sitelemetry-audit' ),
			'sql-injection'      => __( 'SQL injection canary', 'sitelemetry-audit' ),
			'ddos-resilience'    => __( 'DDoS resilience signals', 'sitelemetry-audit' ),
			'secret-exposure'    => __( 'Leaked secrets', 'sitelemetry-audit' ),
			'subdomain-takeover' => __( 'Subdomain takeover', 'sitelemetry-audit' ),
			'cors-audit'         => __( 'Advanced CORS', 'sitelemetry-audit' ),
			'injection-canary'   => __( 'Injection canaries', 'sitelemetry-audit' ),
			'transport-delivery' => __( 'Compression / CDN', 'sitelemetry-audit' ),
			'pentest-suite'      => __( 'Pentest scope matrix', 'sitelemetry-audit' ),
			'engine-nmap'        => __( 'Nmap', 'sitelemetry-audit' ),
			'engine-nuclei'      => __( 'Nuclei', 'sitelemetry-audit' ),
			'engine-zap'         => __( 'OWASP ZAP', 'sitelemetry-audit' ),
			'engine-wpscan'      => __( 'WPScan', 'sitelemetry-audit' ),
		);
	}

	/**
	 * Module ids in display order.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::labels() );
	}

	/**
	 * Readable name of a module, or the id itself for an unknown module.
	 *
	 * @param mixed $id Module id.
	 * @return string
	 */
	public static function label( $id ) {
		$labels = self::labels();
		$id     = is_scalar( $id ) ? (string) $id : '';
		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Readable names of a list of module ids, comma-separated.
	 *
	 * @param array $ids Module ids.
	 * @return string
	 */
	public static function list_label( array $ids ) {
		$names = array();
		foreach ( $ids as $id ) {
			$name = self::label( $id );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Readable module status ("not measured", "partly measured").
	 *
	 * @param mixed $status Status reported by the service.
	 * @return string
	 */
	public static function status_label( $status ) {
		if ( 'unavailable' === $status ) {
			return __( 'not measured', 'sitelemetry-audit' );
		}
		if ( 'partial' === $status ) {
			return __( 'partly measured', 'sitelemetry-audit' );
		}
		return is_scalar( $status ) ? (string) $status : '';
	}

	/**
	 * Check id prefixes (after "ok.") of each module, as the service names its
	 * checks. The first match wins, so a more specific prefix comes first.
	 *
	 * @return array[] Pairs of prefix and module id.
	 */
	private static function prefixes() {
		return array(
			array( 'dns.email.', 'dns-email' ),
			array( 'dns.caa', 'dns-email' ),
			array( 'dns.', 'dns' ),
			array( 'rdap.', 'rdap' ),
			array( 'tls.', 'tls' ),
			array( 'http.methods.', 'http-methods' ),
			array( 'http.trace.', 'http-methods' ),
			array( 'http.cors.credentials-', 'http-methods' ),
			array( 'http.cors.origin-', 'http-methods' ),
			array( 'http.cors.no-reflection', 'http-methods' ),
			array( 'http.header.', 'http-headers' ),
			array( 'http.cookie.', 'http-headers' ),
			array( 'http.cors.wildcard', 'http-headers' ),
			array( 'http.cors.no-wildcard', 'http-headers' ),
			array( 'mitm.', 'mitm-posture' ),
			array( 'tech.', 'tech-fingerprint' ),
			array( 'exposure.', 'exposure' ),
			array( 'api.', 'api-exposure' ),
			array( 'auth.', 'auth-surface' ),
			array( 'supply-chain.', 'supply-chain' ),
			array( 'wordpress.', 'wordpress' ),
			array( 'wp.', 'wordpress' ),
			array( 'port.intel.', 'port-intel' ),
			array( 'port-intel.', 'port-intel' ),
			array( 'ports.', 'ports' ),
			array( 'port.', 'ports' ),
			array( 'sqli.', 'sql-injection' ),
			array( 'resilience.', 'ddos-resilience' ),
			array( 'secret-exposure.', 'secret-exposure' ),
			array( 'secret.', 'secret-exposure' ),
			array( 'subdomain-takeover.', 'subdomain-takeover' ),
			array( 'subdomain.', 'subdomain-takeover' ),
			array( 'cors-audit.', 'cors-audit' ),
			array( 'cors.', 'cors-audit' ),
			array( 'injection-canary.', 'injection-canary' ),
			array( 'injection.', 'injection-canary' ),
			array( 'transport-delivery.', 'transport-delivery' ),
			array( 'delivery.', 'transport-delivery' ),
			array( 'pentest.', 'pentest-suite' ),
		);
	}

	/**
	 * The security module of a check id, or null when it belongs to no known module.
	 *
	 * @param mixed $id Check id ("ok.tls.protocol.modern").
	 * @return string|null
	 */
	public static function check_module( $id ) {
		$name = preg_replace( '/^ok\./', '', is_scalar( $id ) ? (string) $id : '' );
		if ( preg_match( '/^engine\.([a-z0-9-]+)\./', $name, $engine ) ) {
			return in_array( 'engine-' . $engine[1], self::ids(), true ) ? 'engine-' . $engine[1] : null;
		}
		foreach ( self::prefixes() as $pair ) {
			if ( 0 === strpos( $name, $pair[0] ) ) {
				return $pair[1];
			}
		}
		return null;
	}

	/**
	 * The service's own "no response" wording in each report language it supports.
	 *
	 * @return string[]
	 */
	private static function no_response_phrases() {
		return array(
			'No response/timeout',
			'Yanıt yok/timeout',
			'Sin respuesta/tiempo agotado',
			'Keine Antwort/Zeitüberschreitung',
			'Aucune réponse/délai dépassé',
			'Sem resposta/tempo esgotado',
			'Nessuna risposta/timeout',
			'応答なし/タイムアウト',
			'无响应/超时',
		);
	}

	/**
	 * The explanation of a module's reasons, or null when they are not recognised.
	 *
	 * @param string $module  Module id.
	 * @param string $reasons The service's reasons, joined.
	 * @return string|null
	 */
	public static function explain( $module, $reasons ) {
		$text = is_scalar( $reasons ) ? (string) $reasons : '';
		if ( '' === trim( $text ) ) {
			return null;
		}
		if ( in_array( $module, self::PATH_MODULES, true ) ) {
			return self::explain_paths( $text );
		}
		if ( in_array( $module, self::PORT_MODULES, true ) ) {
			return self::explain_ports( $text );
		}
		if ( 'secret-exposure' === $module ) {
			return self::scripts_left_out( $text ) ? __( 'Only a limited number of scripts is scanned per audit; the remaining scripts were not checked and are not counted as clean.', 'sitelemetry-audit' ) : null;
		}
		return null;
	}

	/**
	 * Path probes answered with redirects, rate limits, server errors or silence.
	 * Any other status (an ambiguous 4xx, a 2xx soft-404) has no explanation here.
	 *
	 * @param string $text Reasons.
	 * @return string|null
	 */
	private static function explain_paths( $text ) {
		preg_match_all( '/\bHTTP\s*(\d{3})\b/u', $text, $matches );
		$codes       = array_map( 'intval', $matches[1] );
		$no_response = false;
		foreach ( self::no_response_phrases() as $phrase ) {
			if ( false !== strpos( $text, $phrase ) ) {
				$no_response = true;
				break;
			}
		}
		if ( ! $codes && ! $no_response ) {
			return null;
		}
		$redirects     = array();
		$rate_limited  = false;
		$server_errors = false;
		foreach ( $codes as $code ) {
			if ( $code >= 300 && $code < 400 ) {
				$redirects[] = $code;
			} elseif ( 429 === $code ) {
				$rate_limited = true;
			} elseif ( $code >= 500 && $code < 600 ) {
				$server_errors = true;
			} else {
				return null;
			}
		}
		$redirects = array_values( array_unique( $redirects ) );
		sort( $redirects );
		$parts = array();
		if ( $redirects ) {
			/* translators: %s: HTTP status codes separated by slashes, for example 301/302. */
			$parts[] = sprintf( __( 'The site answers these paths with a redirect (HTTP %s), for example to its sign-in page. Sitelemetry does not follow redirects for these checks, so they were not measured.', 'sitelemetry-audit' ), implode( '/', $redirects ) );
		}
		if ( $rate_limited ) {
			$parts[] = __( 'The site rate-limited these requests (its own protection).', 'sitelemetry-audit' );
		}
		if ( $server_errors ) {
			$parts[] = __( 'The site returned a server error for these requests.', 'sitelemetry-audit' );
		}
		if ( $no_response ) {
			$parts[] = __( 'The site did not answer within the time limit.', 'sitelemetry-audit' );
		}
		return implode( ' ', $parts );
	}

	/**
	 * "12 timeout/filtered; 0 network error" in every report language: only silent
	 * ports without network errors are explained.
	 *
	 * @param string $text Reasons.
	 * @return string|null
	 */
	private static function explain_ports( $text ) {
		preg_match_all( '/\d+/u', $text, $matches );
		$counts = array_map( 'intval', $matches[0] );
		if ( 2 !== count( $counts ) || $counts[0] < 1 || 0 !== $counts[1] ) {
			return null;
		}
		/* translators: %d: number of ports that gave no reply. */
		return sprintf( _n( '%d port gave no reply: a firewall, usually the site\'s own, silently drops these connection attempts. This is normal hardening; silent ports are counted neither as open nor as closed.', '%d ports gave no reply: a firewall, usually the site\'s own, silently drops these connection attempts. This is normal hardening; silent ports are counted neither as open nor as closed.', $counts[0], 'sitelemetry-audit' ), $counts[0] );
	}

	/**
	 * "0/7 resource(s) could not be assessed; 7 script(s) remained outside the
	 * bounded scan." in every report language: a resource fraction first and, as
	 * the last segment, the number of scripts left outside the scan.
	 *
	 * @param string $text Reasons.
	 * @return bool
	 */
	private static function scripts_left_out( $text ) {
		$segments = preg_split( '/[;；]/u', $text );
		if ( ! is_array( $segments ) || count( $segments ) < 2 || ! preg_match( '/\d+\s*\/\s*\d+/u', $segments[0] ) ) {
			return false;
		}
		$last = trim( (string) end( $segments ) );
		if ( preg_match( '/HTTP|:\/\/|[)）]\.?$|…$/u', $last ) ) {
			return false;
		}
		preg_match_all( '/\d+/u', $last, $matches );
		return 1 === count( $matches[0] ) && (int) $matches[0][0] > 0;
	}

	/**
	 * Server pillar names in display order.
	 *
	 * @return string[]
	 */
	private static function pillar_order() {
		return array( 'Security', 'SEO', 'AI visibility', 'Integrations', 'Accessibility', 'Performance' );
	}

	/**
	 * Group of one passing check: its security module, its pillar (full audit) or
	 * the audit kind.
	 *
	 * @param array  $item Stored passing check.
	 * @param string $kind Audit kind of the result.
	 * @return array { key: string, rank: int, label: string }
	 */
	private static function passing_group( array $item, $kind ) {
		$pillar   = isset( $item['pillar'] ) && is_string( $item['pillar'] ) ? $item['pillar'] : '';
		$security = 'Security' === $pillar || ( '' === $pillar && in_array( $kind, array( 'security', 'full' ), true ) );
		$module   = $security ? self::check_module( isset( $item['id'] ) ? $item['id'] : '' ) : null;
		if ( null !== $module ) {
			return array(
				'key'   => 'module:' . $module,
				'rank'  => (int) array_search( $module, self::ids(), true ),
				'label' => self::label( $module ),
			);
		}
		$modules = count( self::ids() );
		if ( '' !== $pillar && 'Security' !== $pillar ) {
			$position = array_search( $pillar, self::pillar_order(), true );
			return array(
				'key'   => 'pillar:' . $pillar,
				'rank'  => $modules + ( false === $position ? count( self::pillar_order() ) : (int) $position ),
				'label' => $pillar,
			);
		}
		if ( ! $security ) {
			return array(
				'key'   => 'kind:' . $kind,
				'rank'  => $modules + count( self::pillar_order() ) + 1,
				'label' => Sitelemetry_Audit_Labels::kind_label( $kind ),
			);
		}
		return array(
			'key'   => 'other',
			'rank'  => $modules + count( self::pillar_order() ) + 2,
			'label' => __( 'Other checks', 'sitelemetry-audit' ),
		);
	}

	/**
	 * The "Passing checks" section of a result, or null when it has none.
	 *
	 * The heading counts the checks listed; when the service counted more than the
	 * plugin kept, it gives both numbers and the note says how many were not kept.
	 * A result without a list (stored by 0.1.2, or sent without the checks) shows
	 * the service's count and a note instead of groups.
	 *
	 * @param array $model Result model.
	 * @return array|null { title: string, groups: array, note: string }
	 */
	public static function passing_section( array $model ) {
		$has_list = isset( $model['passing_items'] ) && is_array( $model['passing_items'] );
		$items    = $has_list ? array_values( array_filter( $model['passing_items'], 'is_array' ) ) : array();
		$listed   = count( $items );
		$total    = isset( $model['passing_checks'] ) && is_numeric( $model['passing_checks'] ) && $model['passing_checks'] > 0 ? (int) $model['passing_checks'] : 0;
		$title    = __( 'Passing checks', 'sitelemetry-audit' );
		if ( 0 === $listed && 0 === $total ) {
			return null;
		}
		if ( 0 === $listed ) {
			return array(
				'title'  => self::count_title( $title, $total ),
				'groups' => array(),
				'note'   => $has_list
					? __( 'Sitelemetry counted these passing checks but did not send the list of them with this result.', 'sitelemetry-audit' )
					: __( 'This result was stored by an earlier version of the plugin, without the list of passing checks. The list appears here after the next audit.', 'sitelemetry-audit' ),
			);
		}

		$kind   = isset( $model['kind'] ) ? (string) $model['kind'] : '';
		$groups = array();
		foreach ( $items as $item ) {
			$group = self::passing_group( $item, $kind );
			if ( ! isset( $groups[ $group['key'] ] ) ) {
				$groups[ $group['key'] ] = $group + array( 'items' => array() );
			}
			$title_text = isset( $item['title'] ) && '' !== (string) $item['title'] ? (string) $item['title'] : ( isset( $item['id'] ) ? (string) $item['id'] : '' );
			$groups[ $group['key'] ]['items'][] = array(
				'title'    => $title_text,
				'evidence' => isset( $item['evidence'] ) ? (string) $item['evidence'] : '',
			);
		}
		uasort(
			$groups,
			function ( $a, $b ) {
				return $a['rank'] - $b['rank'];
			}
		);
		$out = array();
		foreach ( $groups as $group ) {
			$out[] = array(
				'title' => self::count_title( $group['label'], count( $group['items'] ) ),
				'items' => $group['items'],
			);
		}
		$more = $total > $listed;
		return array(
			/* translators: 1: section title, 2: number of items listed, 3: number of items counted. */
			'title'  => $more ? sprintf( __( '%1$s (%2$d of %3$d)', 'sitelemetry-audit' ), $title, $listed, $total ) : self::count_title( $title, $listed ),
			'groups' => $out,
			/* translators: %d: number of passing checks that are not listed. */
			'note'   => $more ? sprintf( _n( '%d more passing check was counted but not kept with this result, so it is not listed here.', '%d more passing checks were counted but not kept with this result, so they are not listed here.', $total - $listed, 'sitelemetry-audit' ), $total - $listed ) : '',
		);
	}

	/**
	 * "Title (count)".
	 *
	 * @param string $title Title.
	 * @param int    $count Count.
	 * @return string
	 */
	public static function count_title( $title, $count ) {
		/* translators: 1: section or group title, 2: number of items. */
		return sprintf( __( '%1$s (%2$d)', 'sitelemetry-audit' ), $title, (int) $count );
	}
}
