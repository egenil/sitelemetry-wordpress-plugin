<?php
/**
 * Bundled translations: the language-pack fallback and the shipped .mo files.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_I18n and languages/*.mo.
 */
class Sitelemetry_Audit_I18n_Test extends TestCase {

	/**
	 * Locales the plugin ships.
	 */
	const LOCALES = array( 'tr_TR', 'es_ES', 'de_DE', 'de_DE_formal', 'fr_FR', 'pt_BR', 'it_IT', 'ja', 'zh_CN' );

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * The bundled directory is offered only for this text domain, only when
	 * WordPress found no language pack, and only for a locale the plugin ships.
	 *
	 * @return void
	 */
	public function test_bundled_language_dir_is_a_fallback_only() {
		$dir = Sitelemetry_Audit_I18n::bundled_dir();
		$this->assertSame( SITELEMETRY_AUDIT_DIR . 'languages/', $dir );

		foreach ( self::LOCALES as $locale ) {
			$this->assertSame( $dir, Sitelemetry_Audit_I18n::bundled_language_dir( false, 'sitelemetry-audit', $locale ), $locale );
		}

		// An installed WordPress.org language pack always wins.
		$pack = '/var/www/wp-content/languages/plugins/';
		$this->assertSame( $pack, Sitelemetry_Audit_I18n::bundled_language_dir( $pack, 'sitelemetry-audit', 'tr_TR' ) );

		// Other text domains, locales without a bundled file and malformed locales are left alone.
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'default', 'tr_TR' ) );
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'other-plugin', 'de_DE' ) );
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'sitelemetry-audit', 'en_US' ) );
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'sitelemetry-audit', 'nl_NL' ) );
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'sitelemetry-audit', '../../../tr_TR' ) );
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'sitelemetry-audit', 'tr_TR.mo' ) );
		$this->assertFalse( Sitelemetry_Audit_I18n::bundled_language_dir( false, 'sitelemetry-audit', null ) );
	}

	/**
	 * WordPress 6.0 to 6.5 have no lang_dir_for_domain filter: the bundled file of
	 * the user's locale is loaded on init instead, in the admin, WP-Cron and AJAX
	 * only, and never when a language pack is installed or on WordPress 6.6+.
	 *
	 * @return void
	 */
	public function test_bundled_file_on_older_wordpress() {
		$GLOBALS['sitelemetry_test_locale'] = 'tr_TR';
		foreach ( array( '6.6', '6.6.2', '7.1.2' ) as $version ) {
			$this->assertFalse( Sitelemetry_Audit_I18n::load_bundled_legacy( $version ), $version );
		}
		$this->assertSame( array(), $GLOBALS['sitelemetry_test_textdomains'] );

		// do_action( 'init' ) passes an empty string: the running version is used.
		$this->assertFalse( Sitelemetry_Audit_I18n::load_bundled_legacy( '' ), 'running 6.8' );
		$GLOBALS['sitelemetry_test_wp_version'] = '6.0.16';
		$this->assertTrue( Sitelemetry_Audit_I18n::load_bundled_legacy( '' ), 'running 6.0.16' );
		$GLOBALS['sitelemetry_test_textdomains'] = array();
		$this->assertTrue( Sitelemetry_Audit_I18n::load_bundled_legacy( '6.0' ) );
		$this->assertSame( Sitelemetry_Audit_I18n::bundled_dir() . 'sitelemetry-audit-tr_TR.mo', $GLOBALS['sitelemetry_test_textdomains']['sitelemetry-audit'] );
		$this->assertFalse( Sitelemetry_Audit_I18n::load_bundled_legacy( '6.5.5' ), 'already loaded' );

		foreach ( array( 'front' => false, 'cron' => true, 'ajax' => true ) as $context => $expected ) {
			$GLOBALS['sitelemetry_test_textdomains'] = array();
			$GLOBALS['sitelemetry_test_context']     = $context;
			$this->assertSame( $expected, Sitelemetry_Audit_I18n::load_bundled_legacy( '6.4' ), $context );
		}

		$GLOBALS['sitelemetry_test_context'] = 'admin';
		foreach ( array( 'en_US', 'nl_NL', '../tr_TR', '' ) as $locale ) {
			$GLOBALS['sitelemetry_test_textdomains'] = array();
			$GLOBALS['sitelemetry_test_locale']      = $locale;
			$this->assertFalse( Sitelemetry_Audit_I18n::load_bundled_legacy( '6.2' ), $locale );
		}

		// An installed language pack wins: WordPress loads it just in time.
		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', sys_get_temp_dir() . '/sitelemetry-test-languages' );
		}
		$pack = WP_LANG_DIR . '/plugins/sitelemetry-audit-de_DE.mo';
		if ( ! is_dir( dirname( $pack ) ) ) {
			mkdir( dirname( $pack ), 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		file_put_contents( $pack, 'pack' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$GLOBALS['sitelemetry_test_locale'] = 'de_DE';
		$this->assertFalse( Sitelemetry_Audit_I18n::load_bundled_legacy( '6.1' ) );
		unlink( $pack ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertTrue( Sitelemetry_Audit_I18n::load_bundled_legacy( '6.1' ) );
	}

	/**
	 * Every shipped .mo file parses as a GNU gettext catalogue, names its locale,
	 * translates every string of the template and keeps every printf placeholder.
	 *
	 * @return void
	 */
	public function test_shipped_catalogues_are_complete_and_keep_placeholders() {
		$template = $this->template_keys();
		$this->assertGreaterThan( 200, count( $template ) );

		foreach ( self::LOCALES as $locale ) {
			$file = SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit-' . $locale . '.mo';
			$this->assertTrue( is_readable( $file ), $file );
			$this->assertTrue( is_readable( SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit-' . $locale . '.po' ), $locale . '.po' );
			$messages = $this->read_mo( $file );

			$this->assertArrayHasKey( '', $messages, $locale );
			$this->assertStringContainsString( 'Language: ' . $locale . "\n", $messages[''], $locale );
			$this->assertStringContainsString( 'charset=UTF-8', $messages[''], $locale );

			foreach ( $template as $key => $plural ) {
				$mo_key = null === $plural ? $key : $key . "\0" . $plural;
				$this->assertArrayHasKey( $mo_key, $messages, $locale . ': ' . $key );
				$source = $this->placeholders( $this->msgid_of( $key ) );
				foreach ( explode( "\0", $messages[ $mo_key ] ) as $form ) {
					$this->assertTrue( '' !== $form, $locale . ': empty translation of ' . $key );
					$this->assertSame( $source, $this->placeholders( $form ), $locale . ': ' . $key );
				}
			}
			$this->assertCount( count( $template ) + 1, $messages, $locale );
		}
	}

	/**
	 * Unmeasured states read as neutral facts in Turkish: "ölçülmedi", never a
	 * failure form, and the product terms match sitelemetry.com and the browser
	 * extension.
	 *
	 * @return void
	 */
	public function test_turkish_terms() {
		$tr = $this->read_mo( SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit-tr_TR.mo' );
		$this->assertSame( 'ölçülmedi', $tr['not measured'] );
		$this->assertSame( 'Ölçülmedi', $tr['Not measured'] );
		$this->assertSame( 'Neler ölçülmedi', $tr['What was not measured'] );
		$this->assertSame( 'Başarılı kontroller', $tr['Passing checks'] );
		$this->assertSame( 'AI düzeltme promptunu kopyala', $tr['Copy AI fix prompt'] );
		foreach ( $tr as $translation ) {
			$this->assertDoesNotMatchRegularExpression( '/ölçülemedi|ölçülemiyor/u', $translation );
		}
		$de = $this->read_mo( SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit-de_DE.mo' );
		$this->assertDoesNotMatchRegularExpression( '/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihnen)\b/u', implode( "\n", array_slice( $de, 1 ) ) );
		$de_formal = $this->read_mo( SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit-de_DE_formal.mo' );
		$this->assertSame( 'KI-Korrektur-Prompt kopieren', $de_formal['Copy AI fix prompt'] );
		// "Eigentum an der Website", as in every other German string and the extension.
		foreach ( array( $de, $de_formal ) as $german ) {
			$this->assertSame( 'Eigentum an der Website verifizieren', $german['Verify ownership of the site'] );
			$this->assertDoesNotMatchRegularExpression( '/Eigentum der Website/u', implode( "\n", $german ) );
		}
	}

	/**
	 * The onboarding button uses the words of the app's own sign-in screen in
	 * every locale (the labels of its "Sign in" and "Create account" tabs), and
	 * the intro says that Sitelemetry has free and paid plans. The old free-only
	 * sign-up wording is gone from every catalogue.
	 *
	 * @return void
	 */
	public function test_onboarding_uses_the_apps_sign_in_terms() {
		$button = 'Sign in or create an account';
		$intro  = 'Sign in to Sitelemetry, or create an account on the same page: Sitelemetry has free and paid plans, and the Free plan includes the security audit. Then copy your API key and paste it below.';
		// Locale => app's sign-in tab, app's create-account tab, "free and paid" in the intro.
		$terms = array(
			'tr_TR'        => array( 'Giriş yap', 'Hesap oluştur', 'ücretsiz ve ücretli' ),
			'es_ES'        => array( 'Iniciar sesión', 'Crear cuenta', 'gratuitos y de pago' ),
			'de_DE'        => array( 'Anmelden', 'Konto erstellen', 'kostenlose und kostenpflichtige' ),
			'de_DE_formal' => array( 'Anmelden', 'Konto erstellen', 'kostenlose und kostenpflichtige' ),
			'fr_FR'        => array( 'Se connecter', 'Créer un compte', 'gratuites et payantes' ),
			'pt_BR'        => array( 'Entrar', 'Criar conta', 'gratuitos e pagos' ),
			'it_IT'        => array( 'Accedi', 'Crea account', 'gratuiti e a pagamento' ),
			'ja'           => array( 'サインイン', 'アカウント作成', '無料プランと有料プラン' ),
			'zh_CN'        => array( '登录', '创建账户', '免费和付费' ),
		);
		$this->assertSame( self::LOCALES, array_keys( $terms ) );
		foreach ( $terms as $locale => $words ) {
			$messages = $this->read_mo( SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit-' . $locale . '.mo' );
			$this->assertArrayHasKey( $button, $messages, $locale );
			$this->assertArrayHasKey( $intro, $messages, $locale );
			$this->assertTrue( false !== mb_stripos( $messages[ $button ], $words[0], 0, 'UTF-8' ), $locale . ': sign-in term in ' . $messages[ $button ] );
			$this->assertTrue( false !== mb_stripos( $messages[ $button ], $words[1], 0, 'UTF-8' ), $locale . ': create-account term in ' . $messages[ $button ] );
			$this->assertStringContainsString( $words[2], $messages[ $intro ], $locale );
			$this->assertStringContainsString( 'Free', $messages[ $intro ], $locale . ': the plan name stays Free' );
			$this->assertArrayNotHasKey( 'Create a free account', $messages, $locale );
		}
	}

	/**
	 * Template keys ("msgctxt\4msgid" for strings with a context) and their plurals.
	 *
	 * @return array<string, string|null>
	 */
	private function template_keys() {
		$pot = (string) file_get_contents( SITELEMETRY_AUDIT_DIR . 'languages/sitelemetry-audit.pot' );
		$pot = str_replace( "\r\n", "\n", $pot );
		$out = array();
		foreach ( explode( "\n\n", $pot ) as $block ) {
			if ( ! preg_match( '/^msgid "(.*)"$/m', $block, $id ) ) {
				continue;
			}
			$msgid = $this->unquote( $id[1] );
			if ( '' === $msgid ) {
				continue;
			}
			$key = preg_match( '/^msgctxt "(.*)"$/m', $block, $ctx ) ? $this->unquote( $ctx[1] ) . "\4" . $msgid : $msgid;
			$out[ $key ] = preg_match( '/^msgid_plural "(.*)"$/m', $block, $plural ) ? $this->unquote( $plural[1] ) : null;
		}
		return $out;
	}

	/**
	 * The msgid part of a template key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function msgid_of( $key ) {
		$at = strpos( $key, "\4" );
		return false === $at ? $key : substr( $key, $at + 1 );
	}

	/**
	 * A .pot string literal as text.
	 *
	 * @param string $text Escaped text.
	 * @return string
	 */
	private function unquote( $text ) {
		return strtr(
			$text,
			array(
				'\\\\' => '\\',
				'\\"'  => '"',
				'\\n'  => "\n",
				'\\t'  => "\t",
			)
		);
	}

	/**
	 * The printf conversions of a string, position-normalized and sorted.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function placeholders( $text ) {
		preg_match_all( '/%(?:(\d+)\$)?[-+ 0#]*\d*(?:\.\d+)?([bcdeEfFgGosuxX%])/', $text, $matches, PREG_SET_ORDER );
		$found = array();
		$next  = 1;
		foreach ( $matches as $match ) {
			if ( '%' === $match[2] ) {
				continue;
			}
			$position = '' !== $match[1] ? (int) $match[1] : $next++;
			$found[]  = $position . ':' . $match[2];
		}
		sort( $found );
		return implode( ' ', $found );
	}

	/**
	 * Reads a GNU .mo file (little-endian, revision 0) into original => translation.
	 *
	 * @param string $file Path.
	 * @return array<string, string>
	 */
	private function read_mo( $file ) {
		$data = (string) file_get_contents( $file );
		$this->assertSame( "\xde\x12\x04\x95", substr( $data, 0, 4 ), 'magic of ' . basename( $file ) );
		$header = unpack( 'Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 4, 16 ) );
		$this->assertSame( 0, $header['revision'] );
		$out = array();
		for ( $i = 0; $i < $header['count']; $i++ ) {
			$original    = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + 8 * $i, 8 ) );
			$translation = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + 8 * $i, 8 ) );
			$this->assertSame( "\0", substr( $data, $original['offset'] + $original['length'], 1 ) );
			$this->assertSame( "\0", substr( $data, $translation['offset'] + $translation['length'], 1 ) );
			$out[ substr( $data, $original['offset'], $original['length'] ) ] = substr( $data, $translation['offset'], $translation['length'] );
		}
		return $out;
	}
}
