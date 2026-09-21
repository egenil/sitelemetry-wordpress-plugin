<?php
/**
 * Settings sanitization tests.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_Settings.
 */
class Sitelemetry_Audit_Settings_Test extends TestCase {

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * Defaults: target falls back to the site address, kind to security.
	 *
	 * @return void
	 */
	public function test_defaults() {
		$settings = Sitelemetry_Audit_Settings::get();
		$this->assertSame( '', $settings['api_key'] );
		$this->assertSame( 'https://example.test/', $settings['target'] );
		$this->assertSame( 'security', $settings['kind'] );
		$this->assertFalse( $settings['weekly_enabled'] );
		$this->assertSame( 'https://sitelemetry.com', Sitelemetry_Audit_Settings::base_url() );
		$this->assertSame( 1200, Sitelemetry_Audit_Settings::time_budget() );

		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'kind' => 'bogus', 'api_key' => 42, 'target' => '' ) );
		$settings = Sitelemetry_Audit_Settings::get();
		$this->assertSame( 'security', $settings['kind'] );
		$this->assertSame( '', $settings['api_key'] );
		$this->assertSame( 'https://example.test/', $settings['target'] );
	}

	/**
	 * The key is kept when the field is empty, replaced when filled, removed on request.
	 *
	 * @return void
	 */
	public function test_sanitize_api_key() {
		$clean = Sitelemetry_Audit_Settings::sanitize( array( 'api_key' => '  sl_key_1  ', 'target' => 'https://ok.example/', 'kind' => 'security' ) );
		$this->assertSame( 'sl_key_1', $clean['api_key'] );
		update_option( Sitelemetry_Audit_Settings::OPTION, $clean );

		$kept = Sitelemetry_Audit_Settings::sanitize( array( 'api_key' => '', 'target' => 'https://ok.example/', 'kind' => 'seo' ) );
		$this->assertSame( 'sl_key_1', $kept['api_key'] );
		$this->assertSame( 'seo', $kept['kind'] );

		$replaced = Sitelemetry_Audit_Settings::sanitize( array( 'api_key' => 'sl_key_2', 'target' => 'https://ok.example/' ) );
		$this->assertSame( 'sl_key_2', $replaced['api_key'] );

		$removed = Sitelemetry_Audit_Settings::sanitize( array( 'api_key' => 'sl_key_3', 'remove_api_key' => '1', 'target' => 'https://ok.example/' ) );
		$this->assertSame( '', $removed['api_key'] );

		$this->assertSame( '************ey_1', Sitelemetry_Audit_Settings::mask_key( 'sl_test_key_1' ) );
		$this->assertSame( '************', Sitelemetry_Audit_Settings::mask_key( 'short' ) );
		$this->assertSame( '', Sitelemetry_Audit_Settings::mask_key( '' ) );
	}

	/**
	 * Targets are normalized; an invalid one keeps the previous value and records an error.
	 *
	 * @return void
	 */
	public function test_sanitize_target_and_flags() {
		$this->assertSame( 'https://ok.example', Sitelemetry_Audit_Settings::normalize_target( 'ok.example' ) );
		$this->assertSame( 'http://ok.example/path', Sitelemetry_Audit_Settings::normalize_target( ' http://ok.example/path ' ) );
		$this->assertSame( '', Sitelemetry_Audit_Settings::normalize_target( 'ftp://ok.example' ) );
		$this->assertSame( '', Sitelemetry_Audit_Settings::normalize_target( 'not a url' ) );
		$this->assertSame( '', Sitelemetry_Audit_Settings::normalize_target( '' ) );

		update_option( Sitelemetry_Audit_Settings::OPTION, array( 'api_key' => 'k', 'target' => 'https://previous.example/', 'kind' => 'security', 'weekly_enabled' => false ) );
		$clean = Sitelemetry_Audit_Settings::sanitize( array( 'target' => 'not a url', 'kind' => 'performance', 'weekly_enabled' => '1' ) );
		$this->assertSame( 'https://previous.example/', $clean['target'] );
		$this->assertCount( 1, $GLOBALS['sitelemetry_test_settings_errors'] );
		$this->assertSame( 'performance', $clean['kind'] );
		$this->assertTrue( $clean['weekly_enabled'] );

		$empty = Sitelemetry_Audit_Settings::sanitize( array( 'target' => '', 'kind' => 'nope' ) );
		$this->assertSame( '', $empty['target'] );
		$this->assertSame( 'security', $empty['kind'] );
		$this->assertFalse( $empty['weekly_enabled'] );
		update_option( Sitelemetry_Audit_Settings::OPTION, $empty );
		$this->assertSame( 'https://example.test/', Sitelemetry_Audit_Settings::get()['target'] );

		// Non-array input keeps the stored key and falls back to the defaults.
		$garbage = Sitelemetry_Audit_Settings::sanitize( 'garbage' );
		$this->assertSame( 'k', $garbage['api_key'] );
		$this->assertSame( 'security', $garbage['kind'] );

		$this->assertSame( 'https://xn--bcher-kva.example/', Sitelemetry_Audit_Settings::normalize_target( 'https://xn--bcher-kva.example/' ) );
		$this->assertSame( 'http://localhost:8080/', Sitelemetry_Audit_Settings::normalize_target( 'http://localhost:8080/' ) );
		$this->assertSame( '', Sitelemetry_Audit_Settings::normalize_target( 'https://bad host.example/' ) );
		$this->assertSame( '', Sitelemetry_Audit_Settings::normalize_target( 'javascript:alert(1)' ) );
	}
}
