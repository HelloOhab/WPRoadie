<?php
/**
 * Site info tools and the shared safety layer.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;

wproadie_test(
	'site-overview returns the site name',
	function () {
		$result = wproadie_ok( wproadie_call( 'site-overview' ) );
		wproadie_assert_same( get_bloginfo( 'name' ), $result['name'] );
	}
);

wproadie_test(
	'list-plugins filters by status',
	function () {
		$result = wproadie_ok( wproadie_call( 'list-plugins', array( 'status' => 'active' ) ) );
		foreach ( $result['plugins'] as $plugin ) {
			wproadie_assert( $plugin['active'], $plugin['file'] . ' should be active' );
		}
	}
);

wproadie_test(
	'invalid input is rejected by the schema',
	function () {
		wproadie_assert_error( wproadie_call( 'list-plugins', array( 'status' => 'nope' ) ), 'ability_invalid_input' );
	}
);

wproadie_test(
	'modes allow the right risk levels',
	function () {
		wproadie_assert( Mode::allows( Mode::RISK_DANGEROUS, Mode::STAGING ) );
		wproadie_assert( Mode::allows( Mode::RISK_WRITE, Mode::PRODUCTION ) );
		wproadie_assert( ! Mode::allows( Mode::RISK_ADMIN, Mode::PRODUCTION ) );
		wproadie_assert( ! Mode::allows( Mode::RISK_WRITE, Mode::READ_ONLY ) );
		wproadie_assert( Mode::allows( Mode::RISK_READ, Mode::READ_ONLY ) );
	}
);

wproadie_test(
	'read tools keep working in read-only mode',
	function () {
		$ability = wp_get_ability( 'wproadie/versions' );
		Settings::set( 'mode', Mode::READ_ONLY );
		try {
			wproadie_ok( $ability->execute( array() ) );
		} finally {
			Settings::set( 'mode', '' );
		}
	}
);

wproadie_test(
	'rate limit refuses calls over the limit',
	function () {
		Settings::set( 'rate_limit', 1 );
		$GLOBALS['wproadie_test_rate_limit'] = true;
		try {
			// The window may already have calls in it; two more must hit it.
			wproadie_call( 'versions' );
			wproadie_assert_error( wproadie_call( 'versions' ), 'wproadie_rate_limited' );
		} finally {
			$GLOBALS['wproadie_test_rate_limit'] = false;
			Settings::set( 'rate_limit', WPRoadie\Safety\RateLimit::DEFAULT_PER_MINUTE );
			$window = (int) floor( time() / MINUTE_IN_SECONDS );
			delete_transient( 'wproadie_rl_' . get_current_user_id() . '_' . $window );
		}
	}
);

wproadie_test(
	'option allow-list blocks login-related options',
	function () {
		wproadie_assert( Guardrails::can_write_option( 'blogname' ) );
		wproadie_assert( ! Guardrails::can_write_option( 'admin_email' ) );
		wproadie_assert( ! Guardrails::can_write_option( 'users_can_register' ) );
		wproadie_assert( ! Guardrails::can_write_option( 'default_role' ) );
		wproadie_assert( Guardrails::can_read_option( 'admin_email' ) );
		wproadie_assert( ! Guardrails::can_read_option( 'auth_key' ) );
	}
);

wproadie_test(
	'path checks keep reads and writes inside their roots',
	function () {
		wproadie_assert_error( Guardrails::resolve_path( ABSPATH . 'wp-config.php', false ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( Guardrails::resolve_path( 'themes/../../wp-config.php', false ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( Guardrails::resolve_path( ABSPATH . 'wp-includes/version.php', false ), 'wproadie_path_not_allowed' );
		wproadie_assert( is_string( Guardrails::resolve_path( 'themes/' . get_template() . '/style.css', false ) ) );
		// Parent theme files are readable but never writable.
		wproadie_assert_error( Guardrails::resolve_path( 'themes/' . get_template() . '/style.css', true ), 'wproadie_path_not_allowed' );
		wproadie_assert( is_string( Guardrails::resolve_path( 'wproadie/notes.md', true ) ) );
		wproadie_assert_error( Guardrails::resolve_path( 'wproadie/shell.php', true ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( Guardrails::resolve_path( 'wproadie/.htaccess', true ), 'wproadie_path_not_allowed' );
	}
);

wproadie_test(
	'symlinks cannot escape the allowed roots',
	function () {
		Guardrails::ensure_own_dir();
		$link = Guardrails::own_dir() . '/escape-link';
		@unlink( $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! @symlink( ABSPATH, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return; // Symlinks unsupported here.
		}
		try {
			wproadie_assert_error( Guardrails::resolve_path( 'wproadie/escape-link/wp-config.php', false ), 'wproadie_path_not_allowed' );
			wproadie_assert_error( Guardrails::resolve_path( 'wproadie/escape-link/index.php', false ), 'wproadie_path_not_allowed' );
		} finally {
			unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
);

wproadie_test(
	'PHP syntax check catches errors',
	function () {
		wproadie_assert_same( true, Guardrails::check_php_syntax( "<?php\necho 1;\n" ) );
		wproadie_assert_error( Guardrails::check_php_syntax( "<?php\necho 1\n" ), 'wproadie_php_syntax' );
	}
);

wproadie_test(
	'WP-CLI allow-list',
	function () {
		$ok = function ( $command ) {
			$args = Guardrails::split_command( $command );
			return ! is_wp_error( $args ) && true === Guardrails::check_cli( $args );
		};
		wproadie_assert( $ok( 'cache flush' ) );
		wproadie_assert( $ok( 'wp plugin list --status=active --format=json' ) );
		wproadie_assert( $ok( 'search-replace old new --dry-run' ) );
		wproadie_assert( ! $ok( 'search-replace old new' ) );
		wproadie_assert( ! $ok( 'eval "echo 1;"' ) );
		wproadie_assert( ! $ok( 'db query "DROP TABLE wp_posts"' ) );
		wproadie_assert( ! $ok( 'config get DB_PASSWORD' ) );
		wproadie_assert( ! $ok( 'plugin list --exec="system(\'id\');"' ) );
		wproadie_assert( ! $ok( 'cache flush --require=/tmp/x.php' ) );
		wproadie_assert( ! $ok( "cache flush\nrm -rf /" ) );
		wproadie_assert_same( array( 'post', 'list', '--s=a b' ), Guardrails::split_command( 'post list --s="a b"' ) );
	}
);

wproadie_test(
	'SQL guards',
	function () {
		wproadie_assert_same( true, Guardrails::check_read_query( 'SELECT * FROM wp_posts LIMIT 1;' ) );
		wproadie_assert_same( true, Guardrails::check_read_query( 'SHOW TABLES' ) );
		wproadie_assert_error( Guardrails::check_read_query( 'DELETE FROM wp_posts' ) );
		wproadie_assert_error( Guardrails::check_read_query( 'SELECT 1; DROP TABLE wp_posts' ) );
		wproadie_assert_error( Guardrails::check_read_query( 'SELECT 1 /*! INTO OUTFILE "/tmp/x" */' ) );
		wproadie_assert_error( Guardrails::check_read_query( "SELECT * FROM wp_users INTO OUTFILE '/tmp/x'" ) );
		wproadie_assert_error( Guardrails::check_read_query( 'SELECT SLEEP(10)' ) );
		wproadie_assert_error( Guardrails::check_read_query( 'WITH x AS (SELECT 1) UPDATE wp_posts SET post_title = 1' ) );
		wproadie_assert_same( true, Guardrails::check_write_query( "UPDATE wp_posts SET post_title = 'x' WHERE ID = 1" ) );
		wproadie_assert_error( Guardrails::check_write_query( "UPDATE wp_posts SET post_title = 'x'" ) );
		wproadie_assert_error( Guardrails::check_write_query( 'DROP TABLE wp_posts' ) );
		wproadie_assert_error( Guardrails::check_write_query( 'TRUNCATE wp_posts' ) );
	}
);

wproadie_test(
	'outbound URL check refuses private, link-local and odd addresses',
	function () {
		foreach ( array( 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.2/', 'http://10.0.0.5/', 'http://100.64.1.1/', 'http://0.0.0.0/', 'file:///etc/passwd', 'ftp://example.com/x', 'http://user:pass@example.com/' ) as $url ) {
			wproadie_assert( ! Guardrails::is_public_url( $url ), $url . ' should be refused' );
		}
		wproadie_assert( Guardrails::is_public_url( 'https://8.8.8.8/' ), 'A public IP should be allowed' );
	}
);

wproadie_test(
	'SQL guard bypasses found in review stay closed',
	function () {
		$reads = array(
			"SELECT * FROM wp_users INTO/**/OUTFILE '/tmp/x'",
			"SELECT 1 INTO#c\nOUTFILE '/tmp/x'",
			'SELECT * FROM wp_posts FOR/**/UPDATE',
			'SELECT * FROM wp_posts FOR SHARE',
			"SELECT '/*' INTO OUTFILE '/tmp/x' -- */'",
			'/*M!DELETE FROM wp_posts WHERE 1=1 OR EXISTS (*/SELECT 1)',
			'EXPLAIN ANALYZE DELETE FROM wp_posts WHERE ID = 1',
			"SELECT '/*'; DELETE FROM wp_posts; -- */'",
		);
		foreach ( $reads as $sql ) {
			wproadie_assert( is_wp_error( Guardrails::check_read_query( $sql ) ), 'Should refuse: ' . $sql );
		}
		wproadie_assert( is_wp_error( Guardrails::check_write_query( "UPDATE wp_posts SET post_title = 'WHERE'" ) ), 'WHERE in a string must not count' );
		wproadie_assert_same( true, Guardrails::check_read_query( "SELECT ID FROM wp_posts WHERE post_title = 'it''s; fine -- ok'" ) );
	}
);

wproadie_test(
	'WP-CLI guard bypasses found in review stay closed',
	function () {
		$refused = function ( $command ) {
			$args = Guardrails::split_command( $command );
			return is_wp_error( $args ) || is_wp_error( Guardrails::check_cli( $args ) );
		};
		wproadie_assert( $refused( 'search-replace a b --dry-run --no-dry-run' ) );
		wproadie_assert( $refused( 'search-replace a b --dry-run=false' ) );
		wproadie_assert( $refused( 'option get auth_key' ) );
		wproadie_assert( $refused( 'option list' ) );
		wproadie_assert( $refused( 'user list --fields=user_pass' ) );
		wproadie_assert( $refused( 'cron event run --all' ) );
		wproadie_assert( ! $refused( 'option get blogname' ) );
		wproadie_assert( ! $refused( 'cron event run --due-now' ) );
	}
);
