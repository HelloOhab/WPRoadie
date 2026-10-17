<?php
/**
 * Integration test runner. Runs inside a real WordPress site:
 *
 * Usage: wp eval-file tests/integration/run.php [group ...] --user=<admin>
 *
 * Each tests/integration/<group>.php file registers tests with
 * wproadie_test(). Tests call abilities through the Abilities API exactly
 * as the MCP Adapter does, so permission checks, schema validation, undo
 * capture and logging all run.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.EscapeOutput.ExceptionNotEscaped, Squiz.Commenting.FunctionCommentThrowTag.Missing, Universal.NamingConventions.NoReservedKeywordParameterNames -- Test runner: exceptions carry plain-text test messages to the CLI.

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run with: wp eval-file tests/integration/run.php\n" );
}

$GLOBALS['wproadie_tests'] = array();

/**
 * Appends a line to the trace file named by WPROADIE_TEST_TRACE (CI prints
 * it when the run dies), and sends PHP's own error log there too.
 */
function wproadie_trace( string $message ): void {
	$file = getenv( 'WPROADIE_TEST_TRACE' );
	if ( $file ) {
		file_put_contents( $file, gmdate( 'H:i:s' ) . ' ' . $message . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
if ( getenv( 'WPROADIE_TEST_TRACE' ) ) {
	ini_set( 'log_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	ini_set( 'error_log', (string) getenv( 'WPROADIE_TEST_TRACE' ) ); // phpcs:ignore WordPress.PHP.IniSet.Risky
}

// A fatal error inside a test is otherwise silent when it happens while
// output is buffered (the upgrader runs that way): say what and where.
register_shutdown_function(
	static function () {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			fwrite( STDERR, sprintf( "\nFATAL: %s in %s:%d\n", $error['message'], $error['file'], $error['line'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
	}
);

/**
 * Registers a test.
 *
 * @param string   $name Test name.
 * @param callable $fn   Test body; throw (or fail an assertion) to fail.
 */
function wproadie_test( string $name, callable $fn ): void {
	$GLOBALS['wproadie_tests'][] = array( $name, $fn );
}

/**
 * Executes an ability by short or full name and returns its result.
 *
 * @param string $name  e.g. "create-post" or "wproadie/create-post".
 * @param array  $input Input arguments.
 * @return mixed|WP_Error
 */
function wproadie_call( string $name, array $input = array() ) {
	$full    = false === strpos( $name, '/' ) ? 'wproadie/' . $name : $name;
	$ability = wp_get_ability( $full );
	if ( ! $ability ) {
		throw new RuntimeException( "Ability {$full} is not registered (group off, mode, or unavailable?)" );
	}
	return $ability->execute( $input );
}

/**
 * @param mixed  $condition Truthy to pass.
 * @param string $message   Shown on failure.
 */
function wproadie_assert( $condition, string $message = 'Assertion failed' ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Shown on failure.
 */
function wproadie_assert_same( $expected, $actual, string $message = '' ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( trim( $message . ' Expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) ) );
	}
}

/**
 * Asserts a result is not a WP_Error and returns it.
 *
 * @param mixed $result Ability result.
 * @return mixed
 */
function wproadie_ok( $result ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'Unexpected error: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
	}
	return $result;
}

/**
 * Asserts a result is a WP_Error, optionally with a given code.
 *
 * @param mixed       $result Ability result.
 * @param string|null $code   Expected error code.
 */
function wproadie_assert_error( $result, ?string $code = null ): void {
	if ( ! is_wp_error( $result ) ) {
		throw new RuntimeException( 'Expected an error, got ' . substr( (string) wp_json_encode( $result ), 0, 300 ) );
	}
	if ( null !== $code && $result->get_error_code() !== $code ) {
		throw new RuntimeException( 'Expected error ' . $code . ', got ' . $result->get_error_code() . ': ' . $result->get_error_message() );
	}
}

// Test runs make many calls in a minute; the rate-limit test opts back in.
$GLOBALS['wproadie_test_rate_limit'] = false;
add_filter(
	'wproadie_rate_limit',
	static function ( $limit ) {
		return $GLOBALS['wproadie_test_rate_limit'] ? $limit : 0;
	},
	1
);

if ( ! WPRoadie\Server\Permission::can_connect() ) {
	WP_CLI::error( 'Run as an administrator with WPRoadie access, e.g. --user=admin (and `wp wproadie grant admin`).' );
}

$groups = isset( $args ) && $args ? $args : array_map(
	static function ( $file ) {
		return basename( $file, '.php' );
	},
	array_filter(
		glob( __DIR__ . '/*.php' ),
		static function ( $file ) {
			return 'run.php' !== basename( $file );
		}
	)
);

$passed = 0;
$failed = 0;
foreach ( $groups as $group ) {
	$file = __DIR__ . '/' . $group . '.php';
	if ( ! is_readable( $file ) ) {
		WP_CLI::warning( "No test file for {$group}" );
		continue;
	}
	$GLOBALS['wproadie_tests'] = array();
	require $file;
	WP_CLI::log( "== {$group}" );
	foreach ( $GLOBALS['wproadie_tests'] as $test ) {
		wproadie_trace( 'START ' . $test[0] );
		list( $name, $fn ) = $test;
		try {
			$fn();
			++$passed;
			WP_CLI::log( "  ok   {$name}" );
		} catch ( Throwable $e ) {
			++$failed;
			WP_CLI::log( "  FAIL {$name}: " . $e->getMessage() );
		}
	}
}

WP_CLI::log( "{$passed} passed, {$failed} failed" );
if ( $failed ) {
	WP_CLI::halt( 1 );
}
