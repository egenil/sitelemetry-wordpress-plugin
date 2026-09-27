<?php
/**
 * Settings and results pages, the Run audit action and the AJAX poll endpoint.
 *
 * @package Sitelemetry_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > Sitelemetry with two tabs: Settings and Results.
 */
class Sitelemetry_Audit_Admin {

	const PAGE        = 'sitelemetry-audit';
	const HOOK_SUFFIX = 'settings_page_sitelemetry-audit';
	const CAPABILITY  = 'manage_options';

	/**
	 * User option (per site) holding the result whose weekly-audit notice the
	 * user dismissed.
	 */
	const DISMISSED_OPTION = 'sitelemetry_audit_dismissed_notice';

	/**
	 * User option (per site) holding the failed automatic renewal whose notice
	 * the user dismissed.
	 */
	const DISMISSED_RENEWAL_OPTION = 'sitelemetry_audit_dismissed_renewal';

	/**
	 * Runner.
	 *
	 * @var Sitelemetry_Audit_Runner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param Sitelemetry_Audit_Runner $runner Runner.
	 */
	public function __construct( Sitelemetry_Audit_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_sitelemetry_audit_run', array( $this, 'handle_run' ) );
		add_action( 'admin_post_sitelemetry_audit_poll', array( $this, 'handle_poll' ) );
		add_action( 'admin_post_sitelemetry_audit_cancel', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_sitelemetry_audit_verification_save', array( $this, 'handle_verification_save' ) );
		add_action( 'admin_post_sitelemetry_audit_verification_test', array( $this, 'handle_verification_test' ) );
		add_action( 'admin_post_sitelemetry_audit_verification_one_click', array( $this, 'handle_verification_one_click' ) );
		add_action( 'admin_post_sitelemetry_audit_dismiss_notice', array( $this, 'handle_dismiss_notice' ) );
		add_action( 'admin_post_sitelemetry_audit_dismiss_renewal', array( $this, 'handle_dismiss_renewal' ) );
		add_action( 'admin_notices', array( $this, 'render_weekly_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_renewal_notice' ) );
		add_action( 'wp_ajax_sitelemetry_audit_poll', array( $this, 'ajax_poll' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITELEMETRY_AUDIT_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Settings > Sitelemetry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Sitelemetry Audit', 'sitelemetry-audit' ),
			__( 'Sitelemetry', 'sitelemetry-audit' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Options API registration.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			Sitelemetry_Audit_Settings::OPTION_GROUP,
			Sitelemetry_Audit_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Sitelemetry_Audit_Settings', 'sanitize' ),
				'default'           => Sitelemetry_Audit_Settings::defaults(),
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'sitelemetry-audit' ) . '</a>' );
		return $links;
	}

	/**
	 * URL of the plugin page.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * URL of the "Verify this site" panel of the settings tab.
	 *
	 * @return string
	 */
	public static function verification_url() {
		return self::page_url() . '#sitelemetry-audit-verify';
	}

	/**
	 * URL of the results tab.
	 *
	 * @param string $kind Audit kind to show ('' for the default).
	 * @return string
	 */
	public static function results_url( $kind = '' ) {
		$args = array( 'tab' => 'results' );
		if ( Sitelemetry_Audit_Labels::is_kind( $kind ) ) {
			$args['kind'] = $kind;
		}
		return self::page_url( $args );
	}

	/**
	 * Formats a timestamp in the site's date and time format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function format_time( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Admin assets, only on this page and on the dashboard (widget styles).
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( self::HOOK_SUFFIX !== $hook_suffix && 'index.php' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'sitelemetry-audit-admin', SITELEMETRY_AUDIT_URL . 'admin/css/sitelemetry-audit-admin.css', array(), SITELEMETRY_AUDIT_VERSION );
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'sitelemetry-audit-admin', SITELEMETRY_AUDIT_URL . 'admin/js/sitelemetry-audit-admin.js', array(), SITELEMETRY_AUDIT_VERSION, true );
		wp_localize_script(
			'sitelemetry-audit-admin',
			'sitelemetryAuditData',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'sitelemetry_audit_poll' ),
				// Floor for every poll; the ceiling bounds only the delays the
				// script picks itself. A delay Sitelemetry asked for is waited in full.
				'minIntervalMs' => 2000,
				'maxIntervalMs' => 30000,
				'i18n'          => array(
					'waiting'   => __( 'Waiting for Sitelemetry…', 'sitelemetry-audit' ),
					/* translators: %s: elapsed time, for example 1:05. */
					'elapsed'   => __( 'Elapsed: %s', 'sitelemetry-audit' ),
					'finished'  => __( 'The audit finished. Loading the results…', 'sitelemetry-audit' ),
					'error'     => __( 'The progress check failed. The audit keeps running; reload this page to check again.', 'sitelemetry-audit' ),
					'running'   => __( 'Running…', 'sitelemetry-audit' ),
					'showAll'   => __( 'All severities', 'sitelemetry-audit' ),
					'noMatches' => __( 'No findings match this filter.', 'sitelemetry-audit' ),
					'copied'    => __( 'Prompt copied. Paste it into ChatGPT, Claude or your coding assistant.', 'sitelemetry-audit' ),
					'copyFail'  => __( 'The prompt could not be copied automatically. Select the text below and copy it.', 'sitelemetry-audit' ),
					'verifying' => __( 'Verifying…', 'sitelemetry-audit' ),
				),
			)
		);
	}

	/**
	 * Renders the page (settings or results tab).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sitelemetry-audit' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tab = 'results' === $tab ? 'results' : 'settings';
		$job = Sitelemetry_Audit_Runner::get_job();

		echo '<div class="wrap sitelemetry-audit-wrap">';
		echo '<h1>' . esc_html__( 'Sitelemetry Audit', 'sitelemetry-audit' ) . '</h1>';
		$this->render_notice();
		echo '<nav class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( self::page_url() ) . '" class="nav-tab' . ( 'settings' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Settings', 'sitelemetry-audit' ) . '</a>';
		echo '<a href="' . esc_url( self::results_url() ) . '" class="nav-tab' . ( 'results' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Results', 'sitelemetry-audit' ) . '</a>';
		echo '</nav>';

		if ( 'results' === $tab ) {
			$this->render_results( $job );
		} else {
			$this->render_settings( $job );
		}
		echo '</div>';
	}

	/**
	 * One-line notice after an action redirect.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice code from our own redirects.
		$code = isset( $_GET['sitelemetry_notice'] ) ? sanitize_key( wp_unslash( $_GET['sitelemetry_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$notices = array(
			'started'        => array( 'info', __( 'The audit was started. This page updates while Sitelemetry works.', 'sitelemetry-audit' ) ),
			'busy'           => array( 'warning', __( 'An audit is already running. Wait for it to finish or stop waiting for it below.', 'sitelemetry-audit' ) ),
			'no_api_key'     => array( 'error', __( 'Add your Sitelemetry API key before running an audit.', 'sitelemetry-audit' ) ),
			'invalid_target' => array( 'error', __( 'The target must be a valid http or https URL.', 'sitelemetry-audit' ) ),
			'invalid_kind'   => array( 'error', __( 'Unsupported audit kind.', 'sitelemetry-audit' ) ),
			'cancelled'      => array( 'info', __( 'Stopped waiting. The result of this audit will not appear here; run the audit again when you want a result.', 'sitelemetry-audit' ) ),
			'token_saved'    => array( 'success', __( 'The verification token is saved and the verification file is published. Test the file, then click Verify HTTP in the app.', 'sitelemetry-audit' ) ),
			'token_removed'  => array( 'info', __( 'The verification token was removed; the verification file is no longer published.', 'sitelemetry-audit' ) ),
			'tested'         => array( 'info', __( 'The verification file was tested; the result is shown under Verify this site.', 'sitelemetry-audit' ) ),
			'verify_no_key'  => array( 'error', __( 'Save an API key first.', 'sitelemetry-audit' ) ),
			'verify_failed'  => array( 'error', __( 'The verification did not complete. The reason and how to fix it are shown under Verify this site.', 'sitelemetry-audit' ) ),
			'verify_off'     => array( 'info', __( 'One-click verification is not available yet. Verify with a token from the app as described under Verify this site.', 'sitelemetry-audit' ) ),
		);
		if ( 'verified' === $code ) {
			$verified         = Sitelemetry_Audit_Verification::verified_state();
			$notices[ $code ] = array( 'success', Sitelemetry_Audit_Verification::complete_message( null === $verified ? 0 : $verified['expires_at'] ) );
		}
		if ( ! isset( $notices[ $code ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $notices[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $notices[ $code ][1] ) . '</p></div>';
	}

	/**
	 * Settings tab.
	 *
	 * @param array|null $job Running job.
	 * @return void
	 */
	private function render_settings( $job ) {
		$settings = Sitelemetry_Audit_Settings::get();
		// No request leaves the site before the admin has connected an account.
		$plans = '' !== $settings['api_key'] ? Sitelemetry_Audit_Plans::get( Sitelemetry_Audit_Settings::base_url() ) : null;
		$kinds = array();
		foreach ( Sitelemetry_Audit_Labels::kinds() as $kind => $label ) {
			$required = Sitelemetry_Audit_Plans::required_plan_label( $plans, $kind );
			if ( null === $required ) {
				$required = Sitelemetry_Audit_Plans::fallback_required_plan_label( $kind );
			}
			$kinds[ $kind ] = 'security' === $kind
				/* translators: 1: audit kind label, 2: plan name. */
				? sprintf( __( '%1$s (included in %2$s)', 'sitelemetry-audit' ), $label, $required )
				/* translators: 1: audit kind label, 2: plan name. */
				: sprintf( __( '%1$s (requires %2$s or higher)', 'sitelemetry-audit' ), $label, $required );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice code from our own redirect.
		$notice = isset( $_GET['sitelemetry_notice'] ) ? sanitize_key( wp_unslash( $_GET['sitelemetry_notice'] ) ) : '';
		$view   = array(
			'settings'     => $settings,
			'has_key'      => '' !== $settings['api_key'],
			'masked_key'   => Sitelemetry_Audit_Settings::mask_key( $settings['api_key'] ),
			'kinds'        => $kinds,
			'weekly_next'  => Sitelemetry_Audit_Cron::next_weekly(),
			'job'          => $job,
			'run_action'   => admin_url( 'admin-post.php' ),
			'results_url'  => self::results_url(),
			'app_url'      => Sitelemetry_Audit_Links::app_url(),
			'sign_in_url'  => Sitelemetry_Audit_Links::sign_in_url(),
			'api_key_url'  => Sitelemetry_Audit_Links::api_key_url(),
			'pricing_url'  => Sitelemetry_Audit_Links::pricing_url(),
			'option_name'  => Sitelemetry_Audit_Settings::OPTION,
			'option_group' => Sitelemetry_Audit_Settings::OPTION_GROUP,
			// A rejected token is reported next to the field, where the redirect lands.
			'verification' => self::verification_view( $settings, 'invalid_token' === $notice ),
		);
		require SITELEMETRY_AUDIT_DIR . 'admin/views/settings.php';
	}

	/**
	 * Data of the "Verify this site" panel.
	 *
	 * The one-click part: one_click is "no_key" (button disabled until a key is
	 * saved), "on" or "unknown" (button enabled), or "off" (the service has no
	 * verification API: no button, a neutral note and the manual helper as
	 * before). Finding that out may cost one read-only status request, cached
	 * (Sitelemetry_Audit_Verification::one_click_state()); it is made only for a
	 * user who may publish the file.
	 *
	 * @param array                                   $settings    Settings.
	 * @param bool                                    $token_error Whether the last pasted token was rejected (the
	 *                                                             panel shows the error next to the field).
	 * @param Sitelemetry_Audit_Verification_Api|null $api         Verification API client (tests inject one).
	 * @return array
	 */
	public static function verification_view( array $settings, $token_error = false, $api = null ) {
		$stored      = Sitelemetry_Audit_Verification::stored();
		$host        = Sitelemetry_Audit_Verification::home_host();
		$target      = Sitelemetry_Audit_Verification::normalize_host( (string) wp_parse_url( $settings['target'], PHP_URL_HOST ) );
		$domain      = Sitelemetry_Audit_Verification::is_domain_host( $host );
		$can_publish = Sitelemetry_Audit_Verification::current_user_can_publish();
		$one_click   = $can_publish ? Sitelemetry_Audit_Verification::one_click_state( $api ) : 'off';
		$api_state   = Sitelemetry_Audit_Verification::api_state();
		$last        = $api_state['last'];
		$last_failed = is_array( $last ) && empty( $last['ok'] );
		// A failure recorded after the service's last answer (the status request
		// itself failed, for example because the key was revoked or Sitelemetry
		// could not be reached) means the verification the plugin saw before is no
		// longer confirmed: the failure is shown instead of it, as the notice after
		// the click promises.
		$unconfirmed = $last_failed && isset( $last['at'] ) && (int) $last['at'] >= $api_state['checked_at'];
		$verified    = 'off' === $one_click || $unconfirmed ? null : Sitelemetry_Audit_Verification::verified_state();
		// The outcome of the last click or renewal is shown while it is a failure
		// and the host is not verified (a later verification replaces it). A failed
		// renewal no longer matters once the admin stopped the automatic renewal
		// with Remove token.
		$renewal_stopped = ! $stored['renew'] && is_array( $last ) && isset( $last['origin'] ) && 'renewal' === $last['origin'];
		$failure = 'off' !== $one_click && null === $verified && $last_failed && ! $renewal_stopped ? $last : null;
		// A failed file test that ran inside a one-click verification or a renewal
		// is part of that attempt: its outcome already says what Sitelemetry saw and
		// how to fix it, and the manual helper's advice for the test (add the other
		// host in the app, upload a file with the token) would contradict it or
		// break the next automatic renewal (a static file keeps the old token). A
		// test the admin runs with Test the file, and a passing test, are shown.
		$internal_test = is_array( $stored['last_test'] ) && empty( $stored['last_test']['ok'] ) && ! empty( $stored['last_test']['attempt'] );
		return array(
			'one_click'        => $one_click,
			'one_click_action' => 'sitelemetry_audit_verification_one_click',
			'verified_text'    => null === $verified ? '' : Sitelemetry_Audit_Verification::complete_message( $verified['expires_at'] ),
			// Expired: reported stale, or past the expiry the service gave while the
			// scheduled renewal has not run yet (WP-Cron waits for a visit).
			'stale'            => 'off' !== $one_click && 'no_key' !== $one_click && $api_state['host'] === $host && ( 'stale' === $api_state['status'] || ( 'verified' === $api_state['status'] && $api_state['expires_at'] > 0 && $api_state['expires_at'] <= time() ) ),
			'failure_text'     => null === $failure ? '' : Sitelemetry_Audit_Verification::one_click_message( $failure ),
			'failure_renewal'  => null !== $failure && isset( $failure['origin'] ) && 'renewal' === $failure['origin'],
			'failure_key'      => null !== $failure && isset( $failure['code'] ) && 'unauthorized' === $failure['code'],
			// The admin removed the token: the plugin does not renew by itself until
			// the next click on Verify this site or a saved token.
			'renewal_off'      => ! $stored['renew'],
			// With one click on offer the manual steps are collapsed, and open when
			// they are in use (a pasted token, or a rejected one).
			'manual_open'      => $token_error || ( '' !== $stored['token'] && 'manual' === $stored['source'] ),
			'api_key_url'      => Sitelemetry_Audit_Links::api_key_url(),
			'host'          => $host,
			// An IP address or a single-label host has no www variant and no DNS records.
			'www_host'      => ! $domain ? '' : ( 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host ),
			'file_url'      => Sitelemetry_Audit_Verification::file_url(),
			'site_file_url' => Sitelemetry_Audit_Verification::site_file_url(),
			'token'         => $stored['token'],
			'served'        => '' !== Sitelemetry_Audit_Verification::served_token(),
			'stored_host'   => $stored['host'],
			'saved_at'      => $stored['saved_at'],
			'last_test'     => $internal_test ? null : $stored['last_test'],
			'last_message'  => $internal_test ? '' : self::last_test_message( $stored ),
			'target_host'   => $target,
			'warnings'      => Sitelemetry_Audit_Verification::warnings(),
			'dns_name'      => $domain ? '_sitelemetry-challenge.' . $host : '',
			'can_publish'   => $can_publish,
			'token_error'   => (bool) $token_error,
			'action'        => admin_url( 'admin-post.php' ),
			'app_url'       => Sitelemetry_Audit_Links::verified_domains_url(),
			'help_url'      => Sitelemetry_Audit_Links::verification_help_url(),
			'renewal_days'  => Sitelemetry_Audit_Verification::RENEWAL_DAYS,
		);
	}

	/**
	 * The message of the last file test. A passing test of a token the plugin
	 * obtained itself does not send the admin to the app: the plugin asks
	 * Sitelemetry to verify it.
	 *
	 * @param array $stored Stored verification state.
	 * @return string
	 */
	private static function last_test_message( array $stored ) {
		if ( ! $stored['last_test'] ) {
			return '';
		}
		if ( 'api' === $stored['source'] && ! empty( $stored['last_test']['ok'] ) ) {
			return __( 'The file works: WordPress loaded it from its own address with the stored token.', 'sitelemetry-audit' );
		}
		return Sitelemetry_Audit_Verification::test_message( $stored['last_test'] );
	}

	/**
	 * Results tab.
	 *
	 * @param array|null $job Running job.
	 * @return void
	 */
	private function render_results( $job ) {
		$settings = Sitelemetry_Audit_Settings::get();
		$results  = Sitelemetry_Audit_Results::all();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection of the stored result to show.
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		if ( ! Sitelemetry_Audit_Labels::is_kind( $kind ) ) {
			if ( $job ) {
				$kind = $job['kind'];
			} elseif ( isset( $results[ $settings['kind'] ] ) ) {
				$kind = $settings['kind'];
			} else {
				$latest = Sitelemetry_Audit_Results::latest();
				$kind   = $latest ? $latest['kind'] : $settings['kind'];
			}
		}
		$model = isset( $results[ $kind ] ) ? $results[ $kind ] : null;
		$plans = '' !== $settings['api_key'] ? Sitelemetry_Audit_Plans::get( Sitelemetry_Audit_Settings::base_url() ) : null;
		$view  = array(
			'kind'          => $kind,
			'results'       => $results,
			'model'         => $model,
			'job'           => $job,
			'progress'      => Sitelemetry_Audit_Runner::progress( $job, time(), Sitelemetry_Audit_Settings::time_budget() ),
			'plan_box'      => Sitelemetry_Audit_Links::plan_box( $model ? $model : Sitelemetry_Audit_Outcome::empty_model( $kind, $settings['target'] ), $plans ),
			'settings'      => $settings,
			'has_key'       => '' !== $settings['api_key'],
			'run_action'    => admin_url( 'admin-post.php' ),
			'poll_url'      => wp_nonce_url( admin_url( 'admin-post.php?action=sitelemetry_audit_poll' ), 'sitelemetry_audit_poll' ),
			'cancel_url'    => wp_nonce_url( admin_url( 'admin-post.php?action=sitelemetry_audit_cancel' ), 'sitelemetry_audit_cancel' ),
			'settings_url'  => self::page_url(),
			'results_url'   => self::results_url( $kind ),
			'severities'    => Sitelemetry_Audit_Labels::severity_labels(),
			'reason_lead'   => $model ? Sitelemetry_Audit_Labels::reason_lead( $model ) : '',
			'status_heading' => $model ? Sitelemetry_Audit_Labels::status_heading( $model ) : '',
		);
		$view  = array_merge( $view, self::result_sections( $model ) );
		require SITELEMETRY_AUDIT_DIR . 'admin/views/results.php';
	}

	/**
	 * The parts of the results tab built from a stored result: the passing checks,
	 * what was not measured, the verification call to action and the AI fix prompt
	 * (completed and partial results only).
	 *
	 * @param array|null $model Result model.
	 * @return array
	 */
	public static function result_sections( $model ) {
		$measured = is_array( $model ) && in_array( $model['status'], array( 'completed', 'partial' ), true );
		$cta      = is_array( $model ) ? Sitelemetry_Audit_Verification::call_to_action( $model ) : null;
		// "What was not measured" shows the gaps of a partial result and, for any
		// measured result, the individual checks that have no pass or fail result.
		$gaps     = $measured && ( 'partial' === $model['status'] || ! empty( $model['unmeasured_checks'] ) );
		return array(
			'passing'          => $measured ? Sitelemetry_Audit_Modules::passing_section( $model ) : null,
			'not_measured'     => $gaps ? Sitelemetry_Audit_Labels::not_measured_section( $model ) : null,
			'verification'     => $cta,
			'verification_url' => self::verification_url(),
			// A renewal needs a new token: the stored one was used up by the last
			// verification, so "already published" would send the admin to the app
			// with a token Sitelemetry no longer accepts.
			'token_published'  => null !== $cta && $cta['helper'] && ! $cta['renew'] && '' !== Sitelemetry_Audit_Verification::served_token(),
			'app_url'          => Sitelemetry_Audit_Links::verified_domains_url(),
			'fix_prompt'       => $measured ? Sitelemetry_Audit_Fix_Prompt::for_admin( $model ) : '',
		);
	}

	/**
	 * Save or remove the verification token (admin-post).
	 *
	 * @return void
	 */
	public function handle_verification_save() {
		if ( ! Sitelemetry_Audit_Verification::current_user_can_publish() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_verification_save' );
		if ( isset( $_POST['sitelemetry_audit_remove_token'] ) ) {
			Sitelemetry_Audit_Verification::remove_token();
			$this->redirect( self::verification_url(), 'token_removed' );
		}
		$token = isset( $_POST['sitelemetry_audit_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sitelemetry_audit_token'] ) ) : '';
		$saved = Sitelemetry_Audit_Verification::save_token( $token );
		$this->redirect( self::verification_url(), is_wp_error( $saved ) ? 'invalid_token' : 'token_saved' );
	}

	/**
	 * Load this site's own verification file and record what it answered (admin-post).
	 *
	 * @return void
	 */
	public function handle_verification_test() {
		if ( ! Sitelemetry_Audit_Verification::current_user_can_publish() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_verification_test' );
		Sitelemetry_Audit_Verification::remember_test( Sitelemetry_Audit_Verification::test_file() );
		$this->redirect( self::verification_url(), 'tested' );
	}

	/**
	 * One-click verification through the service (admin-post): status, challenge,
	 * publish, self-test, verify (Sitelemetry_Audit_Verification::request_one_click()).
	 * The redirect lands on the panel, which explains the outcome.
	 *
	 * @return void
	 */
	public function handle_verification_one_click() {
		if ( ! Sitelemetry_Audit_Verification::current_user_can_publish() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_verification_one_click' );
		if ( '' === Sitelemetry_Audit_Settings::api_key() ) {
			$this->redirect( self::verification_url(), 'verify_no_key' );
		}
		$result = Sitelemetry_Audit_Verification::request_one_click();
		if ( 'off' === $result['code'] ) {
			$this->redirect( self::verification_url(), 'verify_off' );
		}
		$this->redirect( self::verification_url(), $result['ok'] ? 'verified' : 'verify_failed' );
	}

	/**
	 * The result the weekly-audit notice is about, or null: the latest stored
	 * result when the weekly schedule produced it and it needs ownership
	 * verification or its renewal.
	 *
	 * @return array|null { model: array, cta: array, key: string }
	 */
	public static function weekly_notice_subject() {
		$model = Sitelemetry_Audit_Results::latest();
		if ( ! is_array( $model ) || ! isset( $model['origin'] ) || 'scheduled' !== $model['origin'] ) {
			return null;
		}
		$cta = Sitelemetry_Audit_Verification::call_to_action( $model );
		// Verified since that audit: nothing left to ask.
		if ( null === $cta || $cta['verified'] ) {
			return null;
		}
		return array(
			'model' => $model,
			'cta'   => $cta,
			'key'   => $model['kind'] . ':' . ( isset( $model['finished_at'] ) ? (int) $model['finished_at'] : 0 ),
		);
	}

	/**
	 * admin_notices: when the automatic renewal of the verification failed, a
	 * notice on the Dashboard and the Plugins screen for users who may verify the
	 * site, with the reason and the fix, until a renewal or a click succeeds or
	 * they dismiss it. The plugin's own page shows the same outcome in the panel.
	 *
	 * @return void
	 */
	public function render_renewal_notice() {
		if ( ! Sitelemetry_Audit_Verification::current_user_can_publish() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		$failure = Sitelemetry_Audit_Verification::renewal_failure();
		if ( null === $failure || get_user_option( self::DISMISSED_RENEWAL_OPTION ) === (string) $failure['at'] ) {
			return;
		}
		$view = array(
			'host'             => Sitelemetry_Audit_Verification::home_host(),
			'message'          => Sitelemetry_Audit_Verification::one_click_message( $failure ),
			'verification_url' => self::verification_url(),
			'dismiss_url'      => wp_nonce_url( admin_url( 'admin-post.php?action=sitelemetry_audit_dismiss_renewal' ), 'sitelemetry_audit_dismiss_renewal' ),
		);
		require SITELEMETRY_AUDIT_DIR . 'admin/views/renewal-notice.php';
	}

	/**
	 * Dismiss the notice about a failed automatic renewal (admin-post).
	 *
	 * @return void
	 */
	public function handle_dismiss_renewal() {
		if ( ! Sitelemetry_Audit_Verification::current_user_can_publish() ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_dismiss_renewal' );
		$failure = Sitelemetry_Audit_Verification::renewal_failure();
		if ( null !== $failure ) {
			update_user_option( get_current_user_id(), self::DISMISSED_RENEWAL_OPTION, (string) $failure['at'] );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * admin_notices: after a weekly audit that needs ownership verification, a
	 * notice on the Dashboard and the Plugins screen for users who manage the
	 * plugin, until they dismiss it for that result. The plugin's own page shows
	 * the same call to action with the result, so the notice stays off it.
	 *
	 * @return void
	 */
	public function render_weekly_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		$subject = self::weekly_notice_subject();
		if ( null === $subject || get_user_option( self::DISMISSED_OPTION ) === $subject['key'] ) {
			return;
		}
		// A failed automatic renewal has its own, more precise notice.
		if ( $subject['cta']['renew'] && $subject['cta']['one_click'] && null !== Sitelemetry_Audit_Verification::renewal_failure() ) {
			return;
		}
		$view = array(
			'cta'              => $subject['cta'],
			'kind'             => $subject['model']['kind'],
			'results_url'      => self::results_url( $subject['model']['kind'] ),
			'verification_url' => self::verification_url(),
			'dismiss_url'      => wp_nonce_url( admin_url( 'admin-post.php?action=sitelemetry_audit_dismiss_notice' ), 'sitelemetry_audit_dismiss_notice' ),
		);
		require SITELEMETRY_AUDIT_DIR . 'admin/views/weekly-notice.php';
	}

	/**
	 * Dismiss the weekly-audit notice for the result it is about (admin-post).
	 *
	 * @return void
	 */
	public function handle_dismiss_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_dismiss_notice' );
		$subject = self::weekly_notice_subject();
		if ( null !== $subject ) {
			// Per user and, on a multisite network, per site.
			update_user_option( get_current_user_id(), self::DISMISSED_OPTION, $subject['key'] );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Run audit (admin-post).
	 *
	 * @return void
	 */
	public function handle_run() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run audits.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_run' );
		$settings = Sitelemetry_Audit_Settings::get();
		$kind     = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : $settings['kind'];
		if ( ! Sitelemetry_Audit_Labels::is_kind( $kind ) ) {
			$kind = $settings['kind'];
		}
		$job = $this->runner->start( $kind, $settings['target'], 'manual' );
		if ( is_wp_error( $job ) ) {
			$code = 'sitelemetry_busy' === $job->get_error_code() ? 'busy' : $job->get_error_code();
			$this->redirect( self::results_url( $kind ), $code );
		}
		$this->redirect( self::results_url( $kind ), 'started' );
	}

	/**
	 * One poll step without JavaScript (admin-post), then back to the results.
	 *
	 * @return void
	 */
	public function handle_poll() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run audits.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_poll' );
		$state = $this->runner->step();
		$kind  = isset( $state['job']['kind'] ) ? $state['job']['kind'] : ( isset( $state['model']['kind'] ) ? $state['model']['kind'] : '' );
		$this->redirect( self::results_url( $kind ), '' );
	}

	/**
	 * Stop waiting for the running job (admin-post).
	 *
	 * @return void
	 */
	public function handle_cancel() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run audits.', 'sitelemetry-audit' ) );
		}
		check_admin_referer( 'sitelemetry_audit_cancel' );
		$this->runner->cancel();
		$this->redirect( self::results_url(), 'cancelled' );
	}

	/**
	 * AJAX poll: one step, then the progress (never the API key or the arguments).
	 *
	 * @return void
	 */
	public function ajax_poll() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run audits.', 'sitelemetry-audit' ) ), 403 );
		}
		check_ajax_referer( 'sitelemetry_audit_poll', 'nonce' );
		$state = $this->runner->step();
		if ( 'done' === $state['state'] ) {
			wp_send_json_success(
				array(
					'state'    => 'done',
					'redirect' => self::results_url( $state['model']['kind'] ),
				)
			);
		}
		if ( 'idle' === $state['state'] ) {
			wp_send_json_success(
				array(
					'state'    => 'idle',
					'redirect' => self::results_url(),
				)
			);
		}
		$progress = Sitelemetry_Audit_Runner::progress( $state['job'], time(), Sitelemetry_Audit_Settings::time_budget() );
		if ( ! empty( $state['locked'] ) ) {
			$progress['retry_after_ms'] = 3000;
		}
		wp_send_json_success( $progress );
	}

	/**
	 * Redirects with an optional notice code and exits.
	 *
	 * @param string $url  Destination.
	 * @param string $code Notice code or ''.
	 * @return void
	 */
	private function redirect( $url, $code ) {
		if ( '' !== $code ) {
			$url = add_query_arg( 'sitelemetry_notice', $code, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
