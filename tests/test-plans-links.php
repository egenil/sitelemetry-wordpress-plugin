<?php
/**
 * Plans and upgrade-hook rendering helpers.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_Plans and Sitelemetry_Audit_Links.
 */
class Sitelemetry_Audit_Plans_Links_Test extends TestCase {

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * Normalized plans.
	 *
	 * @return array
	 */
	private function plans() {
		return Sitelemetry_Audit_Plans::normalize( sitelemetry_test_fixture( 'plans.json' )['plans'] );
	}

	/**
	 * Catalogue normalization and price formatting.
	 *
	 * @return void
	 */
	public function test_normalize_and_format() {
		$plans = $this->plans();
		$this->assertCount( 4, $plans );
		$free = Sitelemetry_Audit_Plans::find( $plans, 'free' );
		$this->assertSame( array( 10, 10, array( 'security' ), 0 ), array( $free['module_count'], $free['security_scans'], $free['audit_kinds'], $free['monthly'] ) );
		$this->assertSame( 'Free', Sitelemetry_Audit_Plans::format_price( $free ) );

		$paid = Sitelemetry_Audit_Plans::paid( $plans );
		$this->assertSame( array( 'starter', 'professional', 'enterprise' ), array_column( $paid, 'id' ) );
		$this->assertSame( '$49/month', Sitelemetry_Audit_Plans::format_price( $paid[0] ) );
		$this->assertSame( 2500, $paid[1]['security_scans'] );
		$this->assertSame( 'see pricing', Sitelemetry_Audit_Plans::format_price( array( 'monthly' => null, 'currency' => 'USD' ) ) );
		$this->assertSame( 'EUR 9/month', Sitelemetry_Audit_Plans::format_price( array( 'monthly' => 9, 'currency' => 'EUR' ) ) );

		$this->assertNull( Sitelemetry_Audit_Plans::normalize( 'nope' ) );
		$this->assertNull( Sitelemetry_Audit_Plans::normalize( array( array( 'label' => 'no id' ) ) ) );
		$this->assertNull( Sitelemetry_Audit_Plans::find( null, 'free' ) );
		$this->assertSame( array(), Sitelemetry_Audit_Plans::paid( null ) );
	}

	/**
	 * The plan an audit kind requires, from the catalogue or the static fallback.
	 *
	 * @return void
	 */
	public function test_required_plan_label() {
		$plans = $this->plans();
		$this->assertSame( 'Free', Sitelemetry_Audit_Plans::required_plan_label( $plans, 'security' ) );
		$this->assertSame( 'Starter', Sitelemetry_Audit_Plans::required_plan_label( $plans, 'seo' ) );
		$this->assertSame( 'Starter', Sitelemetry_Audit_Plans::required_plan_label( $plans, 'ai_visibility' ) );
		$this->assertSame( 'Starter', Sitelemetry_Audit_Plans::required_plan_label( $plans, 'full' ) );
		$this->assertNull( Sitelemetry_Audit_Plans::required_plan_label( null, 'seo' ) );
		$this->assertNull( Sitelemetry_Audit_Plans::required_plan_label( $plans, 'bogus' ) );
		$this->assertSame( 'Free', Sitelemetry_Audit_Plans::fallback_required_plan_label( 'security' ) );
		$this->assertSame( 'Starter', Sitelemetry_Audit_Plans::fallback_required_plan_label( 'performance' ) );
	}

	/**
	 * The catalogue is fetched once and then served from the transient.
	 *
	 * @return void
	 */
	public function test_get_caches() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$plans = Sitelemetry_Audit_Plans::get( 'https://sitelemetry.com' );
		$this->assertCount( 4, $plans );
		$this->assertSame( 'https://sitelemetry.com/api/plans', $service->calls[0]['url'] );
		$this->assertSame( 'GET', $service->calls[0]['method'] );
		Sitelemetry_Audit_Plans::get( 'https://sitelemetry.com' );
		$this->assertCount( 1, $service->calls );

		sitelemetry_test_reset();
		$GLOBALS['sitelemetry_test_http'] = function () {
			return new WP_Error( 'http_request_failed', 'offline' );
		};
		$this->assertNull( Sitelemetry_Audit_Plans::get( 'https://sitelemetry.com' ) );
		$cached = get_transient( Sitelemetry_Audit_Settings::PLANS_TRANSIENT );
		$this->assertSame( array( 'plans' => null ), $cached );
	}

	/**
	 * Links carry the plugin's UTM parameters.
	 *
	 * @return void
	 */
	public function test_urls() {
		$this->assertSame( 'https://sitelemetry.com/pricing?utm_source=wordpress-plugin&utm_medium=plugin', Sitelemetry_Audit_Links::pricing_url() );
		$this->assertSame( 'https://sitelemetry.com/app', Sitelemetry_Audit_Links::app_url() );
	}

	/**
	 * The plan box is factual for every gate and plan.
	 *
	 * @return void
	 */
	public function test_plan_box() {
		$plans = $this->plans();
		$base  = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://ok.example' );

		$quota                    = $base;
		$quota['status']          = 'quota_exhausted';
		$quota['remaining_scans'] = 0;
		$box                      = Sitelemetry_Audit_Links::plan_box( $quota, $plans );
		$this->assertStringContainsString( 'used its monthly audit allowance', $box['lead'] );
		$this->assertStringContainsString( 'did not consume allowance', $box['lead'] );
		$this->assertSame( 'Remaining security scans in the current period: 0.', $box['remaining'] );
		$this->assertCount( 3, $box['paid'] );
		$this->assertSame( array( 'Starter', '$49/month', '100', '13' ), array( $box['paid'][0]['label'], $box['paid'][0]['price'], $box['paid'][0]['scans'], $box['paid'][0]['modules'] ) );
		$this->assertStringContainsString( 'AI visibility', $box['paid'][0]['kinds'] );
		$this->assertSame( Sitelemetry_Audit_Links::pricing_url(), $box['pricing_url'] );
		$this->assertSame( Sitelemetry_Audit_Links::app_url(), $box['app_url'] );
		$this->assertNull( $box['verification'] );

		$plan           = $base;
		$plan['kind']   = 'seo';
		$plan['status'] = 'plan_required';
		$box            = Sitelemetry_Audit_Links::plan_box( $plan, $plans );
		$this->assertStringContainsString( 'The Technical SEO audit is not included', $box['lead'] );
		$this->assertSame( '', $box['remaining'] );

		$free           = $base;
		$free['status'] = 'partial';
		$free['plan']   = 'free';
		$box            = Sitelemetry_Audit_Links::plan_box( $free, $plans );
		$this->assertSame( 'The connected account is on the Free plan: 10 public security modules and 10 security scans per month.', $box['lead'] );
		$box = Sitelemetry_Audit_Links::plan_box( $free, null );
		$this->assertSame( 'The connected account is on the Free plan.', $box['lead'] );
		$this->assertSame( array(), $box['paid'] );
		$this->assertSame( '', $box['paid_intro'] );

		$starter           = $base;
		$starter['status'] = 'completed';
		$starter['plan']   = 'starter';
		$this->assertSame( 'The connected account is on the Starter plan.', Sitelemetry_Audit_Links::plan_box( $starter, $plans )['lead'] );

		$unknown = Sitelemetry_Audit_Links::plan_box( $base, $plans );
		$this->assertStringContainsString( 'one unit of the monthly allowance', $unknown['lead'] );

		foreach ( array( $quota, $plan, $free, $starter, $base ) as $model ) {
			$box = Sitelemetry_Audit_Links::plan_box( $model, $plans );
			$this->assertStringNotContainsString( '!', $box['lead'] );
			$this->assertStringNotContainsString( 'now', strtolower( $box['lead'] ) );
		}
	}

	/**
	 * The next step in the app follows the reason the server reported.
	 *
	 * @return void
	 */
	public function test_verification_step() {
		$base = Sitelemetry_Audit_Outcome::empty_model( 'security', 'https://ok.example' );

		$verify           = $base;
		$verify['status'] = 'verification_required';
		$verify['reason'] = 'target_verification_required';
		$this->assertStringContainsString( 'Verify ownership of the target', Sitelemetry_Audit_Links::verification_step( $verify ) );

		$consent           = $base;
		$consent['status'] = 'verification_required';
		$consent['reason'] = 'authorization_consent_required';
		$this->assertStringContainsString( 'authorization terms', Sitelemetry_Audit_Links::verification_step( $consent ) );

		$partial                 = $base;
		$partial['status']       = 'partial';
		$partial['not_measured'] = array( array( 'type' => 'verification_modules', 'modules' => array( 'exposure' ) ) );
		$this->assertStringContainsString( 'Verify ownership', Sitelemetry_Audit_Links::verification_step( $partial ) );

		$completed           = $base;
		$completed['status'] = 'completed';
		$this->assertNull( Sitelemetry_Audit_Links::verification_step( $completed ) );
	}

	/**
	 * Labels used by the views.
	 *
	 * @return void
	 */
	public function test_labels() {
		$this->assertTrue( Sitelemetry_Audit_Labels::is_kind( 'ai_visibility' ) );
		$this->assertFalse( Sitelemetry_Audit_Labels::is_kind( 'audit_security' ) );
		$this->assertSame( 'audit_full', Sitelemetry_Audit_Labels::tool_for( 'full' ) );
		$this->assertSame( array( 'critical', 'high', 'medium', 'low', 'info' ), Sitelemetry_Audit_Labels::severities() );
		$this->assertSame( 'High', Sitelemetry_Audit_Labels::severity_label( 'high' ) );
		$this->assertSame( 'Not run: the monthly audit allowance of the connected account is exhausted', Sitelemetry_Audit_Labels::status_heading( array( 'status' => 'quota_exhausted' ) ) );
		$this->assertSame( 'Not run: this audit kind is not included in the connected plan', Sitelemetry_Audit_Labels::status_heading( array( 'status' => 'plan_required' ) ) );
		$this->assertSame( 'Not run: ownership verification of the target must be renewed', Sitelemetry_Audit_Labels::status_heading( array( 'status' => 'verification_required', 'reason' => 'target_reverification_required' ) ) );
		$this->assertSame( 'Not completed', Sitelemetry_Audit_Labels::status_heading( array( 'status' => 'blocked' ) ) );
		$this->assertStringContainsString( 'rejected the API key', Sitelemetry_Audit_Labels::reason_lead( array( 'reason' => 'unauthorized' ) ) );
		$this->assertSame( '', Sitelemetry_Audit_Labels::reason_lead( array( 'reason' => 'usage_limit_reached' ) ) );
		$this->assertSame( '', Sitelemetry_Audit_Labels::not_measured_line( array( 'type' => 'unknown' ) ) );
	}
}
