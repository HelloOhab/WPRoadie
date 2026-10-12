<?php
/**
 * PHP tool: run-php.
 *
 * The php group is off by default. These tests switch it on (and register
 * the tool for this run if needed) and switch it back off at the end.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride -- Test file; the helper fakes the init actions.

use WPRoadie\Abilities\Registry;
use WPRoadie\Safety\Settings;

if ( ! function_exists( 'wproadie_test_enable_group' ) ) {
	/**
	 * Switches a group on and, if its abilities were not registered at
	 * init (the group was off then), registers them for this run.
	 *
	 * @param string $group Group key.
	 */
	function wproadie_test_enable_group( string $group ): void {
		global $wp_current_filter;
		Settings::set_group( $group, true );
		// Category slugs allow only [a-z0-9-].
		$category = str_replace( '_', '-', Registry::category( $group ) );
		foreach ( ( new Registry() )->enabled() as $ability ) {
			if ( $ability->group() !== $group || wp_has_ability( $ability->name() ) ) {
				continue;
			}
			if ( ! wp_has_ability_category( $category ) ) {
				$wp_current_filter[] = 'wp_abilities_api_categories_init';
				wp_register_ability_category(
					$category,
					array(
						'label'       => $category,
						'description' => $category,
					)
				);
				array_pop( $wp_current_filter );
			}
			$wp_current_filter[] = 'wp_abilities_api_init';
			$ability->register( $category );
			array_pop( $wp_current_filter );
		}
	}
}

wproadie_test(
	'php is off by default',
	function () {
		wproadie_assert_same( false, Settings::GROUPS['php']['default'] );
	}
);

wproadie_test(
	'run-php is registered once its group is on',
	function () {
		wproadie_test_enable_group( 'php' );
		wproadie_assert( (bool) wp_get_ability( 'wproadie/run-php' ) );
	}
);

wproadie_test(
	'run-php returns the value and output',
	function () {
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'echo "hello"; return get_option( "blogname" );' ) ) );
		wproadie_assert_same( true, $result['success'] );
		wproadie_assert_same( get_option( 'blogname' ), $result['return_value'] );
		wproadie_assert_same( 'string', $result['return_type'] );
		wproadie_assert_same( 'hello', $result['output'] );
		wproadie_assert_same( array(), $result['errors'] );
	}
);

wproadie_test(
	'run-php strips an opening tag and encodes arrays and objects',
	function () {
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => "<?php\nreturn array( 'a' => 1, 'b' => array( 2, 3 ) );" ) ) );
		wproadie_assert_same(
			array(
				'a' => 1,
				'b' => array( 2, 3 ),
			),
			$result['return_value']
		);
		$object = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'return get_userdata( get_current_user_id() );' ) ) );
		wproadie_assert_same( 'WP_User', $object['return_type'] );
		wproadie_assert( is_array( $object['return_value'] ), 'object converted to an array' );
		wproadie_assert( wp_json_encode( $object ) !== false, 'whole result is JSON-encodable' );
	}
);

wproadie_test(
	'run-php runs as the connected user with WordPress loaded',
	function () {
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'global $wpdb; return array( get_current_user_id(), $wpdb->prefix );' ) ) );
		global $wpdb;
		wproadie_assert_same( array( get_current_user_id(), $wpdb->prefix ), $result['return_value'] );
	}
);

wproadie_test(
	'run-php catches exceptions and errors',
	function () {
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'echo "before"; throw new RuntimeException( "boom" );' ) ) );
		wproadie_assert_same( false, $result['success'] );
		wproadie_assert_same( 'RuntimeException', $result['exception']['class'] );
		wproadie_assert_same( 'boom', $result['exception']['message'] );
		wproadie_assert_same( 'snippet', $result['exception']['file'] );
		wproadie_assert_same( 'before', $result['output'] );

		$error = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'return wproadie_no_such_function();' ) ) );
		wproadie_assert_same( false, $error['success'] );
		wproadie_assert_same( 'Error', $error['exception']['class'] );
	}
);

wproadie_test(
	'run-php collects warnings and notices',
	function () {
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => '$b = file_get_contents( "/wproadie-no-such-file" ); trigger_error( "custom", E_USER_NOTICE ); return 1;' ) ) );
		wproadie_assert_same( true, $result['success'] );
		wproadie_assert_same( 1, $result['return_value'] );
		wproadie_assert_same( 2, count( $result['errors'] ), wp_json_encode( $result['errors'] ) );
		// A failed file open is E_WARNING on PHP 7.4 and 8 alike (unlike an
		// undefined array key, a notice before PHP 8).
		wproadie_assert_same( 'warning', $result['errors'][0]['type'] );
		wproadie_assert_same( 'user_notice', $result['errors'][1]['type'] );
		wproadie_assert_same( 'custom', $result['errors'][1]['message'] );
		// The handler is removed again afterwards.
		$handler = set_error_handler( 'var_dump' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		restore_error_handler();
		wproadie_assert( ! ( $handler instanceof Closure ), 'error handler restored' );
	}
);

wproadie_test(
	'run-php rejects syntax errors and exit without running anything',
	function () {
		delete_option( 'wproadie_test_php_ran' );
		wproadie_assert_error( wproadie_call( 'run-php', array( 'code' => 'update_option( "wproadie_test_php_ran", 1 ); return 1 +;' ) ), 'wproadie_php_syntax' );
		wproadie_assert_error( wproadie_call( 'run-php', array( 'code' => 'update_option( "wproadie_test_php_ran", 1 ); exit;' ) ), 'wproadie_php_exit' );
		wproadie_assert_error( wproadie_call( 'run-php', array( 'code' => 'update_option( "wproadie_test_php_ran", 1 ); die( "x" );' ) ), 'wproadie_php_exit' );
		wproadie_assert_same( false, get_option( 'wproadie_test_php_ran' ) );
	}
);

wproadie_test(
	'run-php output is captured, not printed, and buffers are balanced',
	function () {
		$level  = ob_get_level();
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'ob_start(); echo "inner"; echo str_repeat( "y", 200000 ); return null;' ) ) );
		wproadie_assert_same( $level, ob_get_level() );
		wproadie_assert_same( true, $result['output_truncated'] );
		wproadie_assert_same( 102400, strlen( $result['output'] ) );
		wproadie_assert_same( 0, strpos( $result['output'], 'inner' ) );
		wproadie_assert_same( null, $result['return_value'] );
	}
);

wproadie_test(
	'run-php does not leak variables between calls',
	function () {
		wproadie_ok( wproadie_call( 'run-php', array( 'code' => '$wproadie_leak = 1; return 1;' ) ) );
		$result = wproadie_ok( wproadie_call( 'run-php', array( 'code' => 'return isset( $wproadie_leak ) || isset( $this );' ) ) );
		wproadie_assert_same( false, $result['return_value'] );
	}
);

wproadie_test(
	'teardown: php back off',
	function () {
		Settings::set_group( 'php', false );
		wproadie_assert( ! Settings::group_enabled( 'php' ) );
	}
);
