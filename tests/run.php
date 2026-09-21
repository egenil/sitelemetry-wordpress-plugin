<?php
/**
 * Runs the suite without PHPUnit:  php tests/run.php
 *
 * Discovers every class extending PHPUnit\Framework\TestCase in tests/test-*.php
 * and runs its public test* methods. Exit code 1 when any test fails.
 *
 * @package Sitelemetry_Audit
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $sitelemetry_test_file ) {
	require_once $sitelemetry_test_file;
}

$sitelemetry_test_passed = 0;
$sitelemetry_test_failed = 0;
foreach ( get_declared_classes() as $sitelemetry_test_class ) {
	if ( ! is_subclass_of( $sitelemetry_test_class, 'PHPUnit\Framework\TestCase' ) ) {
		continue;
	}
	$sitelemetry_test_ref = new ReflectionClass( $sitelemetry_test_class );
	if ( $sitelemetry_test_ref->isAbstract() ) {
		continue;
	}
	foreach ( $sitelemetry_test_ref->getMethods( ReflectionMethod::IS_PUBLIC ) as $sitelemetry_test_method ) {
		$sitelemetry_test_name = $sitelemetry_test_method->getName();
		if ( 0 !== strpos( $sitelemetry_test_name, 'test' ) ) {
			continue;
		}
		$sitelemetry_test_instance = new $sitelemetry_test_class();
		try {
			$sitelemetry_test_setup = new ReflectionMethod( $sitelemetry_test_instance, 'setUp' );
			$sitelemetry_test_setup->setAccessible( true );
			$sitelemetry_test_setup->invoke( $sitelemetry_test_instance );
			$sitelemetry_test_instance->{$sitelemetry_test_name}();
			++$sitelemetry_test_passed;
			echo "ok   {$sitelemetry_test_class}::{$sitelemetry_test_name}\n";
		} catch ( Throwable $sitelemetry_test_error ) {
			++$sitelemetry_test_failed;
			echo "FAIL {$sitelemetry_test_class}::{$sitelemetry_test_name}\n     " . get_class( $sitelemetry_test_error ) . ': ' . $sitelemetry_test_error->getMessage() . "\n     at " . $sitelemetry_test_error->getFile() . ':' . $sitelemetry_test_error->getLine() . "\n";
		}
	}
}

echo "\n{$sitelemetry_test_passed} passed, {$sitelemetry_test_failed} failed (PHP " . PHP_VERSION . ")\n";
exit( $sitelemetry_test_failed > 0 ? 1 : 0 );
