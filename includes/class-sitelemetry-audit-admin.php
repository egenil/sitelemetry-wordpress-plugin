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
			'cancelled'      => array( 'info', __( 'Stopped waiting. If Sitelemetry was still running the audit, its result stays available in the app.', 'sitelemetry-audit' ) ),
		);
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
		$view = array(
			'settings'     => $settings,
			'has_key'      => '' !== $settings['api_key'],
			'masked_key'   => Sitelemetry_Audit_Settings::mask_key( $settings['api_key'] ),
			'kinds'        => $kinds,
			'weekly_next'  => Sitelemetry_Audit_Cron::next_weekly(),
			'job'          => $job,
			'run_action'   => admin_url( 'admin-post.php' ),
			'results_url'  => self::results_url(),
			'app_url'      => Sitelemetry_Audit_Links::app_url(),
			'pricing_url'  => Sitelemetry_Audit_Links::pricing_url(),
			'option_name'  => Sitelemetry_Audit_Settings::OPTION,
			'option_group' => Sitelemetry_Audit_Settings::OPTION_GROUP,
		);
		require SITELEMETRY_AUDIT_DIR . 'admin/views/settings.php';
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
			'not_measured'  => array(),
			'reason_lead'   => $model ? Sitelemetry_Audit_Labels::reason_lead( $model ) : '',
			'status_heading' => $model ? Sitelemetry_Audit_Labels::status_heading( $model ) : '',
		);
		if ( $model && ! empty( $model['not_measured'] ) ) {
			foreach ( $model['not_measured'] as $entry ) {
				$line = Sitelemetry_Audit_Labels::not_measured_line( $entry );
				if ( '' !== $line ) {
					$view['not_measured'][] = $line;
				}
			}
		}
		require SITELEMETRY_AUDIT_DIR . 'admin/views/results.php';
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
