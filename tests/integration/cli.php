<?php
/**
 * WP-CLI tool: run-wp-cli.
 *
 * Under `wp eval-file` the tool finds the running WP-CLI binary itself.
 * Tests that need a specific binary set it with the wproadie_wp_cli_path
 * filter and reset the cached detection afterwards.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test file.

use WPRoadie\Abilities\Cli\RunWpCli;

/**
 * Runs a test body with WP-CLI pinned to a path through the filter.
 *
 * @param string   $path WP-CLI path ('' to fall back to detection).
 * @param callable $body   Test body.
 */
function wproadie_test_with_wp_cli_path( string $path, callable $body ): void {
	$filter = static function () use ( $path ) {
		return $path;
	};
	add_filter( 'wproadie_wp_cli_path', $filter );
	RunWpCli::reset_detection();
	try {
		$body();
	} finally {
		remove_filter( 'wproadie_wp_cli_path', $filter );
		RunWpCli::reset_detection();
	}
}

/**
 * The WP-CLI binary running these tests.
 */
function wproadie_test_wp_cli_binary(): string {
	$phar = Phar::running( false );
	return '' !== $phar ? $phar : (string) realpath( $_SERVER['argv'][0] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

wproadie_test(
	'run-wp-cli is available under WP-CLI',
	function () {
		RunWpCli::reset_detection();
		$detected = RunWpCli::detect();
		wproadie_assert_same( '', $detected['reason'] );
		wproadie_assert_same( realpath( wproadie_test_wp_cli_binary() ), $detected['binary'] );
	}
);

wproadie_test(
	'run-wp-cli runs an allowed command and returns its output',
	function () {
		wproadie_test_with_wp_cli_path(
			wproadie_test_wp_cli_binary(),
			function () {
				$result = wproadie_ok( wproadie_call( 'run-wp-cli', array( 'command' => 'wp option get blogname' ) ) );
				wproadie_assert_same( 0, $result['exit_code'], $result['stderr'] );
				wproadie_assert_same( true, $result['success'] );
				wproadie_assert_same( get_option( 'blogname' ), trim( $result['stdout'] ) );
				wproadie_assert_same( 'wp option get blogname', $result['command'] );
				wproadie_assert_same( false, $result['timed_out'] );
			}
		);
	}
);

wproadie_test(
	'run-wp-cli runs as the connected user',
	function () {
		// eval is not allowed for the AI; allow it here only to look inside.
		$allow = static function ( $commands ) {
			$commands[] = 'eval';
			return $commands;
		};
		add_filter( 'wproadie_cli_allowlist', $allow );
		try {
			$result = wproadie_ok( wproadie_call( 'run-wp-cli', array( 'command' => 'eval "echo get_current_user_id();"' ) ) );
		} finally {
			remove_filter( 'wproadie_cli_allowlist', $allow );
		}
		wproadie_assert_same( 0, $result['exit_code'], $result['stderr'] );
		wproadie_assert_same( (string) get_current_user_id(), trim( $result['stdout'] ) );
	}
);

wproadie_test(
	'run-wp-cli returns JSON output and non-zero exit codes',
	function () {
		$result = wproadie_ok( wproadie_call( 'run-wp-cli', array( 'command' => 'plugin list --format=json --fields=name,status' ) ) );
		wproadie_assert_same( 0, $result['exit_code'], $result['stderr'] );
		wproadie_assert( is_array( json_decode( $result['stdout'], true ) ), 'stdout is JSON' );

		$failed = wproadie_ok( wproadie_call( 'run-wp-cli', array( 'command' => 'post get 999999999' ) ) );
		wproadie_assert( 0 !== $failed['exit_code'], 'missing post fails' );
		wproadie_assert_same( false, $failed['success'] );
		wproadie_assert( '' !== $failed['stderr'], 'error on stderr' );
	}
);

wproadie_test(
	'run-wp-cli refuses commands off the allow-list',
	function () {
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'eval "echo 1;"' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'db query "SELECT 1"' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'config get DB_PASSWORD' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'shell' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'search-replace foo bar' ) ), 'wproadie_cli_not_allowed' );
	}
);

wproadie_test(
	'run-wp-cli refuses blocked flags',
	function () {
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'option get blogname --exec="file_put_contents(\'/tmp/x\', 1);"' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'cache flush --require=/tmp/x.php' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'option get blogname --path=/' ) ), 'wproadie_cli_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'user list --user=2' ) ), 'wproadie_cli_not_allowed' );
	}
);

wproadie_test(
	'run-wp-cli passes shell metacharacters as plain arguments',
	function () {
		$marker = get_temp_dir() . 'wproadie-cli-injection';
		@unlink( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		$result = wproadie_ok( wproadie_call( 'run-wp-cli', array( 'command' => 'transient get "blogname; touch ' . $marker . '" $(touch ' . $marker . ')' ) ) );
		wproadie_assert( ! file_exists( $marker ), 'no shell ran' );
		wproadie_assert( 0 !== $result['exit_code'], 'odd transient name returns no value' );
	}
);

wproadie_test(
	'run-wp-cli does not leak the parent environment',
	function () {
		putenv( 'WPROADIE_TEST_SECRET=leak' );
		try {
			$env = RunWpCli::run_process( array( PHP_BINARY, '-r', 'echo getenv("WPROADIE_TEST_SECRET") === false ? "clean" : "leaked";' ), 10, array( 'PATH' => (string) getenv( 'PATH' ) ) );
			wproadie_assert_same( 'clean', $env['stdout'] );
		} finally {
			putenv( 'WPROADIE_TEST_SECRET' );
		}
	}
);

wproadie_test(
	'run-wp-cli stops commands at the timeout',
	function () {
		$allow = static function ( $commands ) {
			$commands[] = 'eval';
			return $commands;
		};
		add_filter( 'wproadie_cli_allowlist', $allow );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'run-wp-cli',
					array(
						'command' => 'eval "echo \"started\"; sleep(20);"',
						'timeout' => 2,
					)
				)
			);
		} finally {
			remove_filter( 'wproadie_cli_allowlist', $allow );
		}
		wproadie_assert_same( true, $result['timed_out'] );
		wproadie_assert_same( false, $result['success'] );
		wproadie_assert( $result['duration_ms'] < 10000, 'stopped well before the sleep ended: ' . $result['duration_ms'] );
		wproadie_assert_same( 'started', $result['stdout'] );
	}
);

wproadie_test(
	'run-wp-cli caps output',
	function () {
		$result = RunWpCli::run_process( array( PHP_BINARY, '-r', 'echo str_repeat("x", 300000); fwrite(STDERR, "done");' ), 10, array( 'PATH' => (string) getenv( 'PATH' ) ) );
		wproadie_assert_same( RunWpCli::MAX_OUTPUT, strlen( $result['stdout'] ) );
		wproadie_assert_same( true, $result['stdout_truncated'] );
		wproadie_assert_same( 'done', $result['stderr'] );
		wproadie_assert_same( 0, $result['exit_code'] );
	}
);

wproadie_test(
	'run-wp-cli reports a missing WP-CLI binary',
	function () {
		wproadie_test_with_wp_cli_path(
			'/nonexistent/wp',
			function () {
				$ability = new RunWpCli();
				wproadie_assert_same( false, $ability->is_available() );
				wproadie_assert( false !== strpos( $ability->unavailable_reason(), '/nonexistent/wp' ), $ability->unavailable_reason() );
				wproadie_assert_error( wproadie_call( 'run-wp-cli', array( 'command' => 'cache flush' ) ), 'wproadie_cli_unavailable' );
			}
		);
	}
);
