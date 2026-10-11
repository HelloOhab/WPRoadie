<?php
/**
 * Database tools: list-tables, run-query, run-write-query.
 *
 * The run-write-query tool is in the database_write group, off by default. These
 * tests switch it on (and register it for this run if needed) and switch
 * it back off at the end.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride -- Test file; the helper fakes the init actions.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests check the database directly.

use WPRoadie\Abilities\Database\Sql;
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
		// Category slugs allow only [a-z0-9-]; Registry::category() keeps
		// the underscore of database_write, so normalise it here.
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
	'database_write is off by default',
	function () {
		wproadie_assert_same( false, Settings::GROUPS['database_write']['default'] );
		wproadie_assert_same( true, Settings::GROUPS['database']['default'] );
	}
);

wproadie_test(
	'list-tables lists core tables with the prefix',
	function () {
		global $wpdb;
		$result = wproadie_ok( wproadie_call( 'list-tables' ) );
		wproadie_assert_same( $wpdb->prefix, $result['prefix'] );
		$by_name = array();
		foreach ( $result['tables'] as $table ) {
			$by_name[ $table['name'] ] = $table;
		}
		wproadie_assert( isset( $by_name[ $wpdb->posts ] ), 'posts table listed' );
		wproadie_assert( $by_name[ $wpdb->posts ]['core'], 'posts table is core' );
		wproadie_assert( $by_name[ $wpdb->options ]['core'], 'options table is core' );
		wproadie_assert( count( $result['tables'] ) >= 10, 'all tables listed' );
	}
);

wproadie_test(
	'run-query returns rows and columns',
	function () {
		global $wpdb;
		$result = wproadie_ok( wproadie_call( 'run-query', array( 'sql' => "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = 'blogname'" ) ) );
		wproadie_assert_same( array( 'option_name', 'option_value' ), $result['columns'] );
		wproadie_assert_same( 1, $result['row_count'] );
		wproadie_assert_same( get_option( 'blogname' ), $result['rows'][0]['option_value'] );
		wproadie_assert_same( false, $result['truncated'] );
		wproadie_assert_same( true, $result['rolled_back'] );
		// SQLite emulation accepts but does not enforce READ ONLY.
		wproadie_assert_same( ! Sql::is_emulated(), $result['read_only_transaction'] );
	}
);

wproadie_test(
	'run-query truncates to max_rows',
	function () {
		global $wpdb;
		$result = wproadie_ok(
			wproadie_call(
				'run-query',
				array(
					'sql'      => "SELECT option_name FROM {$wpdb->options}",
					'max_rows' => 2,
				)
			)
		);
		wproadie_assert_same( 2, count( $result['rows'] ) );
		wproadie_assert( $result['row_count'] > 2 );
		wproadie_assert_same( true, $result['truncated'] );
	}
);

wproadie_test(
	'run-query masks password hashes and activation keys',
	function () {
		global $wpdb;
		$result = wproadie_ok( wproadie_call( 'run-query', array( 'sql' => "SELECT ID, user_login, user_pass, user_activation_key FROM {$wpdb->users} ORDER BY ID LIMIT 1" ) ) );
		wproadie_assert_same( '[hidden]', $result['rows'][0]['user_pass'] );
		wproadie_assert_same( '[hidden]', $result['rows'][0]['user_activation_key'] );
		wproadie_assert( '[hidden]' !== $result['rows'][0]['user_login'] );
		// An alias does not get around hash detection.
		$aliased = wproadie_ok( wproadie_call( 'run-query', array( 'sql' => "SELECT user_pass AS p FROM {$wpdb->users} ORDER BY ID LIMIT 1" ) ) );
		wproadie_assert_same( '[hidden]', $aliased['rows'][0]['p'] );
		wproadie_assert( Sql::looks_like_hash( wp_hash_password( 'secret' ) ) );
		wproadie_assert( ! Sql::looks_like_hash( 'Hello $P$ world' ) );
	}
);

wproadie_test(
	'run-query refuses writes and multiple statements',
	function () {
		global $wpdb;
		wproadie_assert_error( wproadie_call( 'run-query', array( 'sql' => "DELETE FROM {$wpdb->options} WHERE option_name = 'blogname'" ) ), 'wproadie_sql_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-query', array( 'sql' => "UPDATE {$wpdb->options} SET option_value = 'x' WHERE option_name = 'blogname'" ) ), 'wproadie_sql_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-query', array( 'sql' => 'SELECT 1; DROP TABLE ' . $wpdb->posts ) ), 'wproadie_sql_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-query', array( 'sql' => 'SELECT SLEEP(5)' ) ), 'wproadie_sql_not_allowed' );
		wproadie_assert_same( get_option( 'blogname' ), $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogname'" ) );
	}
);

wproadie_test(
	'second SQL check closes comment and string-literal tricks',
	function () {
		$read_blocked = array(
			"SELECT * FROM wp_users INTO/**/OUTFILE '/tmp/x'",
			"SELECT * FROM wp_users INTO#c\nOUTFILE '/tmp/x'",
			"SELECT '/*' INTO OUTFILE '/tmp/x' -- */'",
			'/*M!DELETE FROM wp_posts WHERE 1=1 OR EXISTS (*/SELECT 1)',
			'EXPLAIN ANALYZE DELETE wp_posts FROM wp_posts JOIN wp_postmeta ON 1=1',
			'SELECT * FROM wp_posts FOR/**/UPDATE',
			'SELECT * FROM wp_posts FOR SHARE',
			"SELECT '/*'; DELETE FROM wp_posts; -- */'",
			"SELECT 'unterminated",
		);
		foreach ( $read_blocked as $sql ) {
			wproadie_assert_error( Sql::check_read( $sql ), 'wproadie_sql_not_allowed' );
		}
		$read_ok = array(
			"SELECT * FROM wp_posts WHERE post_title = 'for update; -- /*' LIMIT 1;",
			"SELECT 'It''s', \"a \\\" b\", `select` FROM wp_posts # trailing comment",
			'EXPLAIN ANALYZE SELECT 1',
			'SHOW TABLES',
		);
		foreach ( $read_ok as $sql ) {
			wproadie_assert_same( true, Sql::check_read( $sql ), $sql );
		}
		wproadie_assert_error( Sql::check_write( "UPDATE wp_posts SET post_title = 'WHERE'" ), 'wproadie_sql_not_allowed' );
		wproadie_assert_error( Sql::check_write( "UPDATE wp_posts SET post_title = 'x' /* WHERE */" ), 'wproadie_sql_not_allowed' );
		wproadie_assert_same( true, Sql::check_write( "UPDATE wp_posts SET post_title = 'a;b' WHERE ID = 1" ) );
		// Through the tool, too.
		wproadie_assert_error( wproadie_call( 'run-query', array( 'sql' => "SELECT * FROM wp_users INTO/**/OUTFILE '/tmp/x'" ) ), 'wproadie_sql_not_allowed' );
	}
);

wproadie_test(
	'run-query reports SQL errors as plain text',
	function () {
		$result = wproadie_call( 'run-query', array( 'sql' => 'SELECT nope FROM wproadie_no_such_table' ) );
		wproadie_assert_error( $result, 'wproadie_sql_error' );
		wproadie_assert( false === strpos( $result->get_error_message(), '<' ), 'no HTML in the error' );
		wproadie_assert( false === strpos( $result->get_error_message(), 'Backtrace' ), 'no backtrace in the error' );
	}
);

wproadie_test(
	'every group maps to a valid ability category slug',
	function () {
		foreach ( array_keys( Settings::GROUPS ) as $group ) {
			$slug = Registry::category( $group );
			wproadie_assert( (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ), "Invalid category slug {$slug}: abilities in the {$group} group can never register." );
		}
	}
);

wproadie_test(
	'run-write-query is registered once its group is on',
	function () {
		wproadie_test_enable_group( 'database_write' );
		wproadie_assert( (bool) wp_get_ability( 'wproadie/run-write-query' ) );
	}
);

wproadie_test(
	'run-write-query refuses unsafe SQL',
	function () {
		global $wpdb;
		wproadie_assert_error( wproadie_call( 'run-write-query', array( 'sql' => "UPDATE {$wpdb->options} SET option_value = 'x'" ) ), 'wproadie_sql_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-write-query', array( 'sql' => 'DROP TABLE ' . $wpdb->posts ) ), 'wproadie_sql_not_allowed' );
		wproadie_assert_error( wproadie_call( 'run-write-query', array( 'sql' => 'SELECT 1' ) ), 'wproadie_sql_not_allowed' );
	}
);

wproadie_test(
	'run-write-query dry run reports rows and changes nothing',
	function () {
		global $wpdb;
		$before = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogdescription'" );
		$result = wproadie_ok(
			wproadie_call(
				'run-write-query',
				array(
					'sql'     => "UPDATE {$wpdb->options} SET option_value = 'wproadie dry run' WHERE option_name = 'blogdescription'",
					'dry_run' => true,
				)
			)
		);
		wproadie_assert_same( true, $result['dry_run'] );
		wproadie_assert_same( false, $result['committed'] );
		wproadie_assert_same( 1, $result['rows_affected'] );
		wproadie_assert_same( $before, $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'blogdescription'" ) );
	}
);

wproadie_test(
	'run-write-query rolls back when expected_rows does not match',
	function () {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wproadie_test_row_%'" );
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('wproadie_test_row_a', 'a', 'off'), ('wproadie_test_row_b', 'b', 'off')" );
		try {
			$result = wproadie_call(
				'run-write-query',
				array(
					'sql'           => "UPDATE {$wpdb->options} SET option_value = 'changed' WHERE option_name LIKE 'wproadie_test_row_%'",
					'expected_rows' => 1,
				)
			);
			wproadie_assert_error( $result, 'wproadie_unexpected_row_count' );
			wproadie_assert( false !== strpos( $result->get_error_message(), 'nothing changed' ), $result->get_error_message() );
			$values = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wproadie_test_row_%' ORDER BY option_name" );
			wproadie_assert_same( array( 'a', 'b' ), $values, 'rows unchanged after rollback' );

			// The right count commits.
			$result = wproadie_ok(
				wproadie_call(
					'run-write-query',
					array(
						'sql'           => "UPDATE {$wpdb->options} SET option_value = 'changed' WHERE option_name LIKE 'wproadie_test_row_%'",
						'expected_rows' => 2,
					)
				)
			);
			wproadie_assert_same( true, $result['committed'] );
			wproadie_assert_same( 2, $result['rows_affected'] );
			$values = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wproadie_test_row_%'" );
			wproadie_assert_same( array( 'changed', 'changed' ), $values );
		} finally {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wproadie_test_row_%'" );
		}
	}
);

wproadie_test(
	'run-write-query inserts and returns the insert id',
	function () {
		global $wpdb;
		try {
			$result = wproadie_ok( wproadie_call( 'run-write-query', array( 'sql' => "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('wproadie_test_insert', '1', 'off')" ) ) );
			wproadie_assert_same( 1, $result['rows_affected'] );
			wproadie_assert( $result['insert_id'] > 0, 'insert id returned' );
			wproadie_assert_same( '1', $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wproadie_test_insert'" ) );
		} finally {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'wproadie_test_insert'" );
		}
	}
);

wproadie_test(
	'run-write-query reports SQL errors and leaves no transaction open',
	function () {
		global $wpdb;
		$result = wproadie_call( 'run-write-query', array( 'sql' => 'UPDATE wproadie_no_such_table SET x = 1 WHERE y = 2' ) );
		wproadie_assert_error( $result, 'wproadie_sql_error' );
		// A later plain write must persist, i.e. no transaction was left open.
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('wproadie_test_after', '1', 'off')" );
		$wpdb->query( 'ROLLBACK' );
		$found = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wproadie_test_after'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'wproadie_test_after'" );
		wproadie_assert_same( '1', $found );
	}
);

wproadie_test(
	'teardown: database_write back off',
	function () {
		Settings::set_group( 'database_write', false );
		wproadie_assert( ! Settings::group_enabled( 'database_write' ) );
	}
);
