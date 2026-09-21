<?php
/**
 * View tests: the admin templates render fixture models without notices and escape output.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/view-stubs.php';
require_once SITELEMETRY_AUDIT_DIR . 'includes/class-sitelemetry-audit-admin.php';

/**
 * admin/views/*.php.
 */
class Sitelemetry_Audit_Views_Test extends TestCase {

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * Renders a view with strict error reporting; any notice fails the test.
	 *
	 * @param string $file View file name.
	 * @param array  $view View data.
	 * @return string
	 */
	private function render( $file, array $view ) {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			function ( $severity, $message, $filename, $line ) {
				throw new ErrorException( $message, 0, $severity, $filename, $line );
			}
		);
		ob_start();
		try {
			require SITELEMETRY_AUDIT_DIR . 'admin/views/' . $file;
		} finally {
			$html = ob_get_clean();
			restore_error_handler();
		}
		return $html;
	}

	/**
	 * Result model from a fixture.
	 *
	 * @param string $fixture Fixture name.
	 * @param string $kind    Audit kind.
	 * @return array
	 */
	private function model( $fixture, $kind = 'security' ) {
		$model                = Sitelemetry_Audit_Outcome::interpret(
			array(
				'outcome' => 'result',
				'tool'    => Sitelemetry_Audit_Labels::tool_for( $kind ),
				'job_id'  => 'mj_view',
				'result'  => sitelemetry_test_fixture( $fixture ),
			),
			$kind,
			'https://ok.example'
		);
		$model['started_at']  = 1700000000;
		$model['finished_at'] = 1700000300;
		$model['origin']      = 'manual';
		$model['polls']       = 3;
		return $model;
	}

	/**
	 * The $view array the admin builds for the results tab.
	 *
	 * @param array|null $model   Model.
	 * @param array|null $job     Job.
	 * @param array      $results Stored results.
	 * @return array
	 */
	private function results_view( $model, $job = null, array $results = array() ) {
		$plans = Sitelemetry_Audit_Plans::normalize( sitelemetry_test_fixture( 'plans.json' )['plans'] );
		$kind  = $model ? $model['kind'] : 'security';
		$view  = array(
			'kind'           => $kind,
			'results'        => $results,
			'model'          => $model,
			'job'            => $job,
			'progress'       => Sitelemetry_Audit_Runner::progress( $job, time(), 1200 ),
			'plan_box'       => Sitelemetry_Audit_Links::plan_box( $model ? $model : Sitelemetry_Audit_Outcome::empty_model( $kind, 'https://ok.example' ), $plans ),
			'settings'       => array( 'api_key' => 'sl_secret_key_ABCD', 'target' => 'https://ok.example', 'kind' => 'security', 'weekly_enabled' => false ),
			'has_key'        => true,
			'run_action'     => admin_url( 'admin-post.php' ),
			'poll_url'       => wp_nonce_url( admin_url( 'admin-post.php?action=sitelemetry_audit_poll' ), 'sitelemetry_audit_poll' ),
			'cancel_url'     => wp_nonce_url( admin_url( 'admin-post.php?action=sitelemetry_audit_cancel' ), 'sitelemetry_audit_cancel' ),
			'settings_url'   => Sitelemetry_Audit_Admin::page_url(),
			'results_url'    => Sitelemetry_Audit_Admin::results_url( $kind ),
			'severities'     => Sitelemetry_Audit_Labels::severity_labels(),
			'not_measured'   => array(),
			'reason_lead'    => $model ? Sitelemetry_Audit_Labels::reason_lead( $model ) : '',
			'status_heading' => $model ? Sitelemetry_Audit_Labels::status_heading( $model ) : '',
		);
		if ( $model ) {
			foreach ( $model['not_measured'] as $entry ) {
				$view['not_measured'][] = Sitelemetry_Audit_Labels::not_measured_line( $entry );
			}
		}
		return $view;
	}

	/**
	 * A completed result: banner, score, chips, findings and the plan box with UTM links.
	 *
	 * @return void
	 */
	public function test_results_completed() {
		$model = $this->model( 'security-completed.json' );
		$html  = $this->render( 'results.php', $this->results_view( $model, null, array( 'security' => $model ) ) );
		$this->assertStringContainsString( 'sitelemetry-audit-banner--completed', $html );
		$this->assertStringContainsString( '<h2>Completed</h2>', $html );
		$this->assertStringContainsString( '<span class="sitelemetry-audit-score-value">82</span>', $html );
		$this->assertStringContainsString( 'sitelemetry-audit-grade">B<', $html );
		$this->assertStringContainsString( '4 findings', $html );
		$this->assertStringContainsString( '1 High', $html );
		$this->assertStringContainsString( 'data-severity="high"', $html );
		$this->assertStringContainsString( 'HSTS header is missing', $html );
		$this->assertStringContainsString( 'Send Strict-Transport-Security', $html );
		$this->assertStringContainsString( 'id="sitelemetry-audit-severity-filter"', $html );
		$this->assertStringContainsString( 'Plan and usage', $html );
		$this->assertStringContainsString( 'utm_source=wordpress-plugin&amp;utm_medium=plugin', $html );
		$this->assertStringContainsString( 'https://sitelemetry.com/app', $html );
		$this->assertStringContainsString( 'The connected account is on the Starter plan.', $html );
		$this->assertStringContainsString( 'Run the Security audit again', $html );
		$this->assertStringContainsString( 'Job <code>mj_view</code>', $html );
		$this->assertStringNotContainsString( 'sl_secret_key_ABCD', $html );
		$this->assertStringNotContainsString( 'What was not measured', $html );
	}

	/**
	 * A partial result lists what was not measured and the verification step.
	 *
	 * @return void
	 */
	public function test_results_partial_with_coverage_notes() {
		$model = $this->model( 'security-partial-free.json' );
		$html  = $this->render( 'results.php', $this->results_view( $model ) );
		$this->assertStringContainsString( 'Completed with partial coverage', $html );
		$this->assertStringContainsString( 'What was not measured', $html );
		$this->assertStringContainsString( 'Unmeasured checks are not passes.', $html );
		$this->assertStringContainsString( 'http-methods, exposure, api-exposure', $html );
		$this->assertStringContainsString( 'Module rdap: unavailable (registry_timeout)', $html );
		$this->assertStringContainsString( 'Verify ownership of the target in the app', $html );
		$this->assertStringContainsString( 'Free plan: 10 public security modules and 10 security scans per month', $html );
		$this->assertStringContainsString( '<td>Starter</td>', $html );
		$this->assertStringContainsString( '$49/month', $html );
	}

	/**
	 * A full audit renders the pillar table.
	 *
	 * @return void
	 */
	public function test_results_full_pillars() {
		$model = $this->model( 'full-partial.json', 'full' );
		$html  = $this->render( 'results.php', $this->results_view( $model ) );
		$this->assertStringContainsString( 'sitelemetry-audit-pillars', $html );
		$this->assertStringContainsString( '<td>SEO</td>', $html );
		$this->assertStringContainsString( 'PageSpeed Insights was unavailable', $html );
		$this->assertStringContainsString( '<span class="sitelemetry-audit-score-value">71</span>', $html );
	}

	/**
	 * Gates render their headings, the server message and the plan box lead.
	 *
	 * @return void
	 */
	public function test_results_gates() {
		$quota            = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://ok.example' );
		$quota['status']  = 'quota_exhausted';
		$quota['reason']  = 'usage_limit_reached';
		$quota['message'] = 'Audit not started. No audit quota was used.';
		$html             = $this->render( 'results.php', $this->results_view( $quota ) );
		$this->assertStringContainsString( 'monthly audit allowance of the connected account is exhausted', $html );
		$this->assertStringContainsString( 'Audit not started. No audit quota was used.', $html );
		$this->assertStringContainsString( 'has used its monthly audit allowance', $html );

		$unauthorized           = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://ok.example' );
		$unauthorized['reason'] = 'unauthorized';
		$html                   = $this->render( 'results.php', $this->results_view( $unauthorized ) );
		$this->assertStringContainsString( 'Not completed', $html );
		$this->assertStringContainsString( 'rejected the API key', $html );

		$consent           = Sitelemetry_Audit_Outcome::empty_model( 'seo', 'https://ok.example' );
		$consent['status'] = 'verification_required';
		$consent['reason'] = 'authorization_consent_required';
		$html              = $this->render( 'results.php', $this->results_view( $consent ) );
		$this->assertStringContainsString( 'accept the current audit authorization terms', $html );
	}

	/**
	 * A running job renders the progress box with the poll attributes; an empty
	 * state offers the first run.
	 *
	 * @return void
	 */
	public function test_results_running_and_empty() {
		$job          = Sitelemetry_Audit_Runner::new_job( 'security', 'https://ok.example', 'manual', time() - 65 );
		$job['phase'] = 'Queued for capacity.';
		$html         = $this->render( 'results.php', $this->results_view( null, $job ) );
		$this->assertStringContainsString( 'id="sitelemetry-audit-progress"', $html );
		$this->assertStringContainsString( 'data-state="running"', $html );
		$this->assertStringContainsString( 'data-retry-after="2000"', $html );
		$this->assertStringContainsString( 'Queued for capacity.', $html );
		$this->assertStringContainsString( 'Elapsed: 1:05', $html );
		$this->assertStringContainsString( 'Stop waiting', $html );
		$this->assertStringContainsString( 'action=sitelemetry_audit_cancel', $html );
		$this->assertStringNotContainsString( 'No audit has run yet', $html );

		$html = $this->render( 'results.php', $this->results_view( null ) );
		$this->assertStringContainsString( 'No audit has run yet', $html );
		$this->assertStringContainsString( 'value="sitelemetry_audit_run"', $html );
	}

	/**
	 * Server-provided text is escaped on output.
	 *
	 * @return void
	 */
	public function test_results_escapes_server_text() {
		$model                          = $this->model( 'security-completed.json' );
		$model['findings'][0]['title']    = '<script>alert(1)</script>';
		$model['findings'][0]['location'] = 'https://ok.example/?q="><img src=x>';
		$model['findings'][0]['fix']      = 'Use <strong>HSTS</strong>';
		$model['message']                 = '<b>bold</b>';
		$html                             = $this->render( 'results.php', $this->results_view( $model ) );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<img src=x>', $html );
		$this->assertStringContainsString( 'Use &lt;strong&gt;HSTS&lt;/strong&gt;', $html );
	}

	/**
	 * The settings view shows the masked key only and labels kinds with their plan.
	 *
	 * @return void
	 */
	public function test_settings_view() {
		$view = array(
			'settings'     => array( 'api_key' => 'sl_secret_key_ABCD', 'target' => 'https://ok.example/', 'kind' => 'seo', 'weekly_enabled' => true ),
			'has_key'      => true,
			'masked_key'   => Sitelemetry_Audit_Settings::mask_key( 'sl_secret_key_ABCD' ),
			'kinds'        => array( 'security' => 'Security (included in Free)', 'seo' => 'Technical SEO (requires Starter or higher)' ),
			'weekly_next'  => time() + 3600,
			'job'          => null,
			'run_action'   => admin_url( 'admin-post.php' ),
			'results_url'  => Sitelemetry_Audit_Admin::results_url(),
			'app_url'      => Sitelemetry_Audit_Links::app_url(),
			'pricing_url'  => Sitelemetry_Audit_Links::pricing_url(),
			'option_name'  => Sitelemetry_Audit_Settings::OPTION,
			'option_group' => Sitelemetry_Audit_Settings::OPTION_GROUP,
		);
		$html = $this->render( 'settings.php', $view );
		$this->assertStringNotContainsString( 'sl_secret_key_ABCD', $html );
		$this->assertStringContainsString( '************ABCD', $html );
		$this->assertStringContainsString( 'name="sitelemetry_audit_settings[remove_api_key]"', $html );
		$this->assertMatchesRegularExpression( '/<option value="seo"\s+selected="selected">Technical SEO \(requires Starter or higher\)<\/option>/', $html );
		$this->assertMatchesRegularExpression( '/name="sitelemetry_audit_settings\[weekly_enabled\]" value="1"\s+checked="checked"/', $html );
		$this->assertStringContainsString( 'Next run:', $html );
		$this->assertStringContainsString( 'uses one unit of the monthly allowance', $html );
		$this->assertStringContainsString( 'value="sitelemetry_audit_run"', $html );
		$this->assertStringContainsString( 'utm_source=wordpress-plugin&amp;utm_medium=plugin', $html );

		$view['has_key']    = false;
		$view['masked_key'] = '';
		$html               = $this->render( 'settings.php', $view );
		$this->assertStringContainsString( 'disabled="disabled"', $html );
		$this->assertStringContainsString( 'Save an API key first.', $html );
	}

	/**
	 * The dashboard widget shows the score, chips and the remaining scans.
	 *
	 * @return void
	 */
	public function test_dashboard_widget_view() {
		$model                    = $this->model( 'security-completed.json' );
		$model['remaining_scans'] = 6;
		$view                     = array(
			'model'          => $model,
			'job'            => null,
			'has_key'        => true,
			'results_url'    => Sitelemetry_Audit_Admin::results_url( 'security' ),
			'settings_url'   => Sitelemetry_Audit_Admin::page_url(),
			'severities'     => Sitelemetry_Audit_Labels::severity_labels(),
			'status_heading' => Sitelemetry_Audit_Labels::status_heading( $model ),
		);
		$html = $this->render( 'dashboard-widget.php', $view );
		$this->assertStringContainsString( '<span class="sitelemetry-audit-score-value">82</span>', $html );
		$this->assertStringContainsString( '1 High', $html );
		$this->assertStringContainsString( 'Remaining security scans this period: 6', $html );
		$this->assertStringContainsString( 'View results', $html );
		$this->assertStringContainsString( 'tab=results&amp;kind=security', $html );

		$view['model']   = null;
		$view['has_key'] = false;
		$html            = $this->render( 'dashboard-widget.php', $view );
		$this->assertStringContainsString( 'Add API key', $html );
	}
}
