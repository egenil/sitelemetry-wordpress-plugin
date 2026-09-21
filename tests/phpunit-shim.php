<?php
/**
 * A minimal PHPUnit\Framework\TestCase so the suite runs on plain PHP when PHPUnit
 * is not installed (tests/run.php). Only the assertions the suite uses.
 *
 * @package Sitelemetry_Audit
 */

namespace PHPUnit\Framework;

/**
 * Raised by a failed assertion.
 */
class AssertionFailedError extends \Exception {}

/**
 * Base test case.
 */
abstract class TestCase {

	/**
	 * Runs before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {}

	/**
	 * Fails.
	 *
	 * @param string $message Message.
	 * @return void
	 * @throws AssertionFailedError Always.
	 */
	public static function fail( $message = '' ) {
		throw new AssertionFailedError( '' !== $message ? $message : 'Failed.' );
	}

	/**
	 * Asserts a condition.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Message.
	 * @return void
	 */
	public static function assertTrue( $condition, $message = '' ) {
		if ( true !== $condition ) {
			self::fail( '' !== $message ? $message : 'Expected true, got ' . var_export( $condition, true ) );
		}
	}

	/**
	 * Asserts a condition is false.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Message.
	 * @return void
	 */
	public static function assertFalse( $condition, $message = '' ) {
		if ( false !== $condition ) {
			self::fail( '' !== $message ? $message : 'Expected false, got ' . var_export( $condition, true ) );
		}
	}

	/**
	 * Strict equality.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertSame( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			self::fail( ( '' !== $message ? $message . ' ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	/**
	 * Loose equality.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertEquals( $expected, $actual, $message = '' ) {
		if ( $expected != $actual ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			self::fail( ( '' !== $message ? $message . ' ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}

	/**
	 * Null.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public static function assertNull( $actual, $message = '' ) {
		self::assertSame( null, $actual, $message );
	}

	/**
	 * Not null.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public static function assertNotNull( $actual, $message = '' ) {
		if ( null === $actual ) {
			self::fail( '' !== $message ? $message : 'Expected a non-null value.' );
		}
	}

	/**
	 * Count.
	 *
	 * @param int    $expected Expected.
	 * @param mixed  $haystack Countable.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertCount( $expected, $haystack, $message = '' ) {
		self::assertSame( (int) $expected, count( $haystack ), $message );
	}

	/**
	 * Array key.
	 *
	 * @param mixed  $key     Key.
	 * @param array  $array   Array.
	 * @param string $message Message.
	 * @return void
	 */
	public static function assertArrayHasKey( $key, $array, $message = '' ) {
		if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
			self::fail( '' !== $message ? $message : 'Missing key ' . var_export( $key, true ) );
		}
	}

	/**
	 * Array key absent.
	 *
	 * @param mixed  $key     Key.
	 * @param array  $array   Array.
	 * @param string $message Message.
	 * @return void
	 */
	public static function assertArrayNotHasKey( $key, $array, $message = '' ) {
		if ( is_array( $array ) && array_key_exists( $key, $array ) ) {
			self::fail( '' !== $message ? $message : 'Unexpected key ' . var_export( $key, true ) );
		}
	}

	/**
	 * Substring.
	 *
	 * @param string $needle   Needle.
	 * @param string $haystack Haystack.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertStringContainsString( $needle, $haystack, $message = '' ) {
		if ( false === strpos( (string) $haystack, (string) $needle ) ) {
			self::fail( ( '' !== $message ? $message . ' ' : '' ) . 'Expected to find ' . var_export( $needle, true ) . ' in ' . var_export( $haystack, true ) );
		}
	}

	/**
	 * Substring absent.
	 *
	 * @param string $needle   Needle.
	 * @param string $haystack Haystack.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertStringNotContainsString( $needle, $haystack, $message = '' ) {
		if ( false !== strpos( (string) $haystack, (string) $needle ) ) {
			self::fail( ( '' !== $message ? $message . ' ' : '' ) . 'Did not expect ' . var_export( $needle, true ) . ' in ' . var_export( $haystack, true ) );
		}
	}

	/**
	 * Regular expression.
	 *
	 * @param string $pattern Pattern.
	 * @param string $string  Subject.
	 * @param string $message Message.
	 * @return void
	 */
	public static function assertMatchesRegularExpression( $pattern, $string, $message = '' ) {
		if ( ! preg_match( $pattern, (string) $string ) ) {
			self::fail( ( '' !== $message ? $message . ' ' : '' ) . 'Expected ' . var_export( $string, true ) . ' to match ' . $pattern );
		}
	}

	/**
	 * Instance.
	 *
	 * @param string $class   Class.
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public static function assertInstanceOf( $class, $actual, $message = '' ) {
		if ( ! ( $actual instanceof $class ) ) {
			self::fail( '' !== $message ? $message : 'Expected an instance of ' . $class );
		}
	}

	/**
	 * Greater than.
	 *
	 * @param mixed  $expected Lower bound.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertGreaterThan( $expected, $actual, $message = '' ) {
		if ( ! ( $actual > $expected ) ) {
			self::fail( '' !== $message ? $message : 'Expected ' . var_export( $actual, true ) . ' > ' . var_export( $expected, true ) );
		}
	}

	/**
	 * Contains (arrays).
	 *
	 * @param mixed  $needle   Needle.
	 * @param array  $haystack Haystack.
	 * @param string $message  Message.
	 * @return void
	 */
	public static function assertContains( $needle, $haystack, $message = '' ) {
		if ( ! in_array( $needle, $haystack, true ) ) {
			self::fail( '' !== $message ? $message : 'Expected ' . var_export( $needle, true ) . ' in the array.' );
		}
	}
}
