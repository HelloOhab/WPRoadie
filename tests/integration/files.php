<?php
/**
 * File tools.
 *
 * Creates a throwaway child theme (themes/roadie-child, parent
 * twentytwentyfive), switches to it for the tests and removes it at the
 * end. The site check test starts `php -S` on the home URL's port if
 * nothing is listening there.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions -- Test file working on scratch files.

use WPRoadie\Abilities\Files\FileTools;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Guardrails;

// These tests make many calls; the last test puts the rate limit back.
add_filter( 'wproadie_rate_limit', '__return_zero' );

$GLOBALS['roadie_child'] = array(
	'dir'      => get_theme_root() . '/roadie-child',
	'original' => get_stylesheet(),
);

/**
 * Removes a folder and everything in it (test scratch only).
 */
function roadie_rmdir( string $dir ): void {
	if ( is_link( $dir ) || is_file( $dir ) ) {
		unlink( $dir );
		return;
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' !== $name && '..' !== $name ) {
			roadie_rmdir( $dir . '/' . $name );
		}
	}
	rmdir( $dir );
}

function roadie_child_teardown(): void {
	if ( get_stylesheet() !== $GLOBALS['roadie_child']['original'] ) {
		switch_theme( $GLOBALS['roadie_child']['original'] );
	}
	roadie_rmdir( $GLOBALS['roadie_child']['dir'] );
	roadie_rmdir( Guardrails::own_dir() . '/files-test' );
	wp_clean_themes_cache( false );
}

/**
 * Path relative to wp-content for a child theme file.
 */
function roadie_child( string $file = '' ): string {
	return 'themes/roadie-child' . ( '' === $file ? '' : '/' . $file );
}

const ROADIE_CHILD_FUNCTIONS = "<?php\n// Roadie child theme.\n";

wproadie_test(
	'setup: child theme',
	function () {
		$dir = $GLOBALS['roadie_child']['dir'];
		roadie_rmdir( $dir );
		mkdir( $dir );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: Roadie Child\nTemplate: twentytwentyfive\n*/\n" );
		file_put_contents( $dir . '/functions.php', ROADIE_CHILD_FUNCTIONS );
		wp_clean_themes_cache( false );
		switch_theme( 'roadie-child' );
		wproadie_assert_same( 'roadie-child', get_stylesheet() );
		wproadie_assert( is_child_theme(), 'child theme should be active' );
	}
);

wproadie_test(
	'list-files without a path lists the readable roots',
	function () {
		$result = wproadie_ok( wproadie_call( 'list-files' ) );
		$paths  = wp_list_pluck( $result['entries'], 'writable', 'path' );
		wproadie_assert( array_key_exists( 'themes', $paths ), 'themes root expected: ' . wp_json_encode( $paths ) );
		wproadie_assert( array_key_exists( 'plugins', $paths ), 'plugins root expected' );
		wproadie_assert_same( false, $paths['themes'] );
	}
);

wproadie_test(
	'list-files marks child theme files writable and parent files not',
	function () {
		$child = wproadie_ok( wproadie_call( 'list-files', array( 'path' => roadie_child() ) ) );
		$paths = wp_list_pluck( $child['entries'], 'writable', 'path' );
		wproadie_assert_same( true, $paths[ roadie_child( 'style.css' ) ] ?? null );
		wproadie_assert_same( true, $paths[ roadie_child( 'functions.php' ) ] ?? null );

		$parent = wproadie_ok(
			wproadie_call(
				'list-files',
				array(
					'path'      => 'themes/twentytwentyfive',
					'recursive' => true,
				)
			)
		);
		wproadie_assert( count( $parent['entries'] ) > 5 && count( $parent['entries'] ) <= 500 );
		foreach ( $parent['entries'] as $entry ) {
			wproadie_assert( ! $entry['writable'], $entry['path'] . ' should not be writable' );
			wproadie_assert( in_array( $entry['type'], array( 'file', 'dir' ), true ) );
		}
		$depths = array_map(
			static function ( $entry ) {
				return substr_count( $entry['path'], '/' );
			},
			$parent['entries']
		);
		wproadie_assert( max( $depths ) <= 4, 'depth is capped' );
	}
);

wproadie_test(
	'list-files hides secret files and symlinks out of the roots',
	function () {
		$dir = $GLOBALS['roadie_child']['dir'];
		file_put_contents( $dir . '/.env', "SECRET=1\n" );
		file_put_contents( $dir . '/key.pem', "x\n" );
		$linked = @symlink( ABSPATH, $dir . '/escape' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		try {
			$result = wproadie_ok( wproadie_call( 'list-files', array( 'path' => roadie_child() ) ) );
			$paths  = wp_list_pluck( $result['entries'], 'path' );
			wproadie_assert( ! in_array( roadie_child( '.env' ), $paths, true ), '.env must be hidden' );
			wproadie_assert( ! in_array( roadie_child( 'key.pem' ), $paths, true ), 'key.pem must be hidden' );
			if ( $linked ) {
				wproadie_assert( ! in_array( roadie_child( 'escape' ), $paths, true ), 'escaping symlink must be hidden' );
			}
			wproadie_assert_error( wproadie_call( 'read-file', array( 'path' => roadie_child( '.env' ) ) ), 'wproadie_path_not_allowed' );
		} finally {
			unlink( $dir . '/.env' );
			unlink( $dir . '/key.pem' );
			if ( $linked ) {
				unlink( $dir . '/escape' );
			}
		}
	}
);

wproadie_test(
	'list-files and read-file refuse traversal and core paths',
	function () {
		wproadie_assert_error( wproadie_call( 'list-files', array( 'path' => 'themes/../..' ) ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( wproadie_call( 'list-files', array( 'path' => ABSPATH . 'wp-includes' ) ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( wproadie_call( 'read-file', array( 'path' => '../wp-config.php' ) ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( wproadie_call( 'read-file', array( 'path' => ABSPATH . 'wp-config.php' ) ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( wproadie_call( 'read-file', array( 'path' => ABSPATH . 'wp-includes/version.php' ) ), 'wproadie_path_not_allowed' );
	}
);

wproadie_test(
	'read-file returns content and sha1',
	function () {
		$result = wproadie_ok( wproadie_call( 'read-file', array( 'path' => roadie_child( 'functions.php' ) ) ) );
		wproadie_assert_same( ROADIE_CHILD_FUNCTIONS, $result['content'] );
		wproadie_assert_same( sha1( ROADIE_CHILD_FUNCTIONS ), $result['sha1'] );
		wproadie_assert_same( false, $result['truncated'] );
		wproadie_assert_same( true, $result['writable'] );
		wproadie_assert_same( 2, $result['total_lines'] );

		$parent = wproadie_ok( wproadie_call( 'read-file', array( 'path' => 'themes/twentytwentyfive/style.css' ) ) );
		wproadie_assert_same( false, $parent['writable'] );
	}
);

wproadie_test(
	'read-file slices lines and truncates by bytes',
	function () {
		$dir = Guardrails::own_dir() . '/files-test';
		wp_mkdir_p( $dir );
		$lines = '';
		for ( $i = 1; $i <= 10; $i++ ) {
			$lines .= "line {$i}\n";
		}
		file_put_contents( $dir . '/lines.txt', $lines );

		$slice = wproadie_ok(
			wproadie_call(
				'read-file',
				array(
					'path'       => 'wproadie/files-test/lines.txt',
					'start_line' => 3,
					'line_count' => 2,
				)
			)
		);
		wproadie_assert_same( "line 3\nline 4\n", $slice['content'] );
		wproadie_assert_same( 4, $slice['end_line'] );
		wproadie_assert_same( 10, $slice['total_lines'] );

		$cut = wproadie_ok(
			wproadie_call(
				'read-file',
				array(
					'path'      => 'wproadie/files-test/lines.txt',
					'max_bytes' => 10,
				)
			)
		);
		wproadie_assert_same( true, $cut['truncated'] );
		wproadie_assert_same( 10, strlen( $cut['content'] ) );
		wproadie_assert_same( sha1( $lines ), $cut['sha1'], 'sha1 covers the whole file' );

		// A multi-byte character cut in half is dropped, keeping valid UTF-8.
		file_put_contents( $dir . '/utf8.txt', 'aé' );
		$utf8 = wproadie_ok(
			wproadie_call(
				'read-file',
				array(
					'path'      => 'wproadie/files-test/utf8.txt',
					'max_bytes' => 2,
				)
			)
		);
		wproadie_assert_same( 'a', $utf8['content'] );
	}
);

wproadie_test(
	'read-file refuses binary files',
	function () {
		$file = $GLOBALS['roadie_child']['dir'] . '/screenshot.png';
		file_put_contents( $file, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" );
		try {
			wproadie_assert_error( wproadie_call( 'read-file', array( 'path' => roadie_child( 'screenshot.png' ) ) ), 'wproadie_binary_file' );
		} finally {
			unlink( $file );
		}
	}
);

wproadie_test(
	'write-file refuses paths outside the writable roots',
	function () {
		$parent = 'themes/twentytwentyfive/style.css';
		$before = sha1_file( WP_CONTENT_DIR . '/' . $parent );
		wproadie_assert_error(
			wproadie_call(
				'write-file',
				array(
					'path'    => $parent,
					'content' => 'x',
				)
			),
			'wproadie_path_not_allowed'
		);
		wproadie_assert_same( $before, sha1_file( WP_CONTENT_DIR . '/' . $parent ) );

		$refused = array( 'plugins/hello.php', 'wproadie/shell.php', roadie_child( '../escape.css' ), roadie_child( '.htaccess' ), roadie_child( 'payload.exe' ), '../wp-config.php' );
		foreach ( $refused as $path ) {
			wproadie_assert_error(
				wproadie_call(
					'write-file',
					array(
						'path'    => $path,
						'content' => 'x',
					)
				),
				'wproadie_path_not_allowed'
			);
		}
	}
);

wproadie_test(
	'write-file creates a file and undo removes it',
	function () {
		$path   = roadie_child( 'extra.css' );
		$result = wproadie_ok(
			wproadie_call(
				'write-file',
				array(
					'path'    => $path,
					'content' => "body { color: red; }\n",
				)
			)
		);
		$real   = WP_CONTENT_DIR . '/' . $path;
		wproadie_assert_same( true, $result['created'] );
		wproadie_assert_same( sha1( "body { color: red; }\n" ), $result['sha1'] );
		wproadie_assert_same( "body { color: red; }\n", file_get_contents( $real ) );
		wproadie_assert( ! glob( dirname( $real ) . '/.wproadie-*' ), 'no temp files left behind' );

		$undone = Undo::revert( (int) $result['undo_id'] );
		wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
		wproadie_assert( ! file_exists( $real ), 'undo should remove the created file' );
	}
);

wproadie_test(
	'write-file overwrites with expected_sha1, keeps permissions, and undo restores',
	function () {
		$path     = roadie_child( 'style.css' );
		$real     = WP_CONTENT_DIR . '/' . $path;
		$original = file_get_contents( $real );
		chmod( $real, 0640 );
		$read = wproadie_ok( wproadie_call( 'read-file', array( 'path' => $path ) ) );

		wproadie_assert_error(
			wproadie_call(
				'write-file',
				array(
					'path'          => $path,
					'content'       => 'nope',
					'expected_sha1' => str_repeat( 'a', 40 ),
				)
			),
			'wproadie_file_changed'
		);
		wproadie_assert_same( $original, file_get_contents( $real ) );

		$new    = $original . "\n.roadie { margin: 0; }\n";
		$result = wproadie_ok(
			wproadie_call(
				'write-file',
				array(
					'path'          => $path,
					'content'       => $new,
					'expected_sha1' => $read['sha1'],
				)
			)
		);
		wproadie_assert_same( false, $result['created'] );
		wproadie_assert_same( $new, file_get_contents( $real ) );
		clearstatcache();
		wproadie_assert_same( 0640, fileperms( $real ) & 0777, 'permissions kept' );

		$undone = Undo::revert( (int) $result['undo_id'] );
		wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
		wproadie_assert_same( $original, file_get_contents( $real ), 'undo restores the content' );
		chmod( $real, 0644 );

		$same = wproadie_ok(
			wproadie_call(
				'write-file',
				array(
					'path'    => $path,
					'content' => $original,
				)
			)
		);
		wproadie_assert_same( false, $same['changed'] );
		wproadie_assert( empty( $same['undo_id'] ), 'unchanged write is not undoable' );
	}
);

wproadie_test(
	'write-file refuses PHP with a syntax error',
	function () {
		$path = roadie_child( 'functions.php' );
		wproadie_assert_error(
			wproadie_call(
				'write-file',
				array(
					'path'    => $path,
					'content' => "<?php\nfunction broken( {\n",
				)
			),
			'wproadie_php_syntax'
		);
		wproadie_assert_same( ROADIE_CHILD_FUNCTIONS, file_get_contents( WP_CONTENT_DIR . '/' . $path ) );
	}
);

wproadie_test(
	'write-file needs create_dirs for new folders',
	function () {
		$path = roadie_child( 'assets/css/new.css' );
		wproadie_assert_error(
			wproadie_call(
				'write-file',
				array(
					'path'    => $path,
					'content' => 'a{}',
				)
			),
			'wproadie_no_folder'
		);
		$result = wproadie_ok(
			wproadie_call(
				'write-file',
				array(
					'path'        => $path,
					'content'     => 'a{}',
					'create_dirs' => true,
				)
			)
		);
		wproadie_assert_same( true, $result['created'] );
		wproadie_assert( file_exists( WP_CONTENT_DIR . '/' . $path ) );
	}
);

wproadie_test(
	'write-file in wp-content/wproadie creates the protected folder',
	function () {
		$result = wproadie_ok(
			wproadie_call(
				'write-file',
				array(
					'path'        => 'wproadie/files-test/notes.md',
					'content'     => "# Notes\n",
					'create_dirs' => true,
				)
			)
		);
		wproadie_assert_same( 'wproadie/files-test/notes.md', $result['path'] );
		wproadie_assert( file_exists( Guardrails::own_dir() . '/index.php' ) );
		wproadie_assert( file_exists( Guardrails::own_dir() . '/.htaccess' ) );
	}
);

wproadie_test(
	'write-file PHP without a reachable site reports it could not verify',
	function () {
		$home = wp_parse_url( home_url() );
		$sock = @fsockopen( $home['host'], $home['port'] ?? 80, $errno, $errstr, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $sock ) {
			fclose( $sock );
			return; // A web server is running; covered by the next test.
		}
		$path    = roadie_child( 'functions.php' );
		$content = ROADIE_CHILD_FUNCTIONS . "// unverified\n";
		$result  = wproadie_ok(
			wproadie_call(
				'write-file',
				array(
					'path'    => $path,
					'content' => $content,
				)
			)
		);
		wproadie_assert_same( false, $result['verified'] );
		wproadie_assert( false !== stripos( $result['check'], 'could not verify' ), $result['check'] );
		wproadie_assert_same( $content, file_get_contents( WP_CONTENT_DIR . '/' . $path ), 'change is kept' );
		wproadie_ok( Undo::revert( (int) $result['undo_id'] ) );
		wproadie_assert_same( ROADIE_CHILD_FUNCTIONS, file_get_contents( WP_CONTENT_DIR . '/' . $path ) );
	}
);

wproadie_test(
	'write-file rolls back a functions.php that breaks the site',
	function () {
		$home   = wp_parse_url( home_url() );
		$host   = $home['host'];
		$port   = (int) ( $home['port'] ?? 80 );
		$server = null;
		$pipes  = array();
		$sock   = @fsockopen( $host, $port, $errno, $errstr, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $sock ) {
			fclose( $sock );
		} else {
			if ( ! function_exists( 'proc_open' ) ) {
				return; // Cannot start a web server here.
			}
			$server = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts a local web server for the loopback check.
				array( PHP_BINARY, '-S', $host . ':' . $port, '-t', ABSPATH ),
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'file', '/dev/null', 'w' ),
					2 => array( 'file', '/dev/null', 'w' ),
				),
				$pipes
			);
			$up     = false;
			for ( $i = 0; $i < 50 && ! $up; $i++ ) {
				usleep( 100000 );
				$sock = @fsockopen( $host, $port, $errno, $errstr, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $sock ) {
					fclose( $sock );
					$up = true;
				}
			}
			wproadie_assert( $up, 'php -S did not start' );
		}

		try {
			$path = roadie_child( 'functions.php' );
			$real = WP_CONTENT_DIR . '/' . $path;

			// Valid syntax, fatal at runtime.
			$broken = wproadie_call(
				'write-file',
				array(
					'path'    => $path,
					'content' => "<?php\nwproadie_this_function_does_not_exist();\n",
				)
			);
			wproadie_assert_error( $broken, 'wproadie_site_broken' );
			wproadie_assert_same( true, $broken->get_error_data()['rolled_back'] );
			wproadie_assert_same( ROADIE_CHILD_FUNCTIONS, file_get_contents( $real ), 'functions.php should be rolled back' );

			$good   = ROADIE_CHILD_FUNCTIONS . "add_filter( 'body_class', function ( \$c ) { return \$c; } );\n";
			$result = wproadie_ok(
				wproadie_call(
					'write-file',
					array(
						'path'    => $path,
						'content' => $good,
					)
				)
			);
			wproadie_assert_same( true, $result['verified'], $result['check'] );
			wproadie_assert_same( $good, file_get_contents( $real ) );
			wproadie_ok( Undo::revert( (int) $result['undo_id'] ) );
			wproadie_assert_same( ROADIE_CHILD_FUNCTIONS, file_get_contents( $real ) );

			// Deleting a PHP file the theme needs is rolled back too.
			file_put_contents( $GLOBALS['roadie_child']['dir'] . '/inc.php', "<?php\n" );
			file_put_contents( $real, ROADIE_CHILD_FUNCTIONS . "require __DIR__ . '/inc.php';\n" );
			$deleted = wproadie_call( 'delete-file', array( 'path' => roadie_child( 'inc.php' ) ) );
			wproadie_assert_error( $deleted, 'wproadie_site_broken' );
			wproadie_assert( file_exists( $GLOBALS['roadie_child']['dir'] . '/inc.php' ), 'deleted file should be restored' );
			file_put_contents( $real, ROADIE_CHILD_FUNCTIONS );
		} finally {
			if ( $server ) {
				foreach ( $pipes as $pipe ) {
					fclose( $pipe );
				}
				proc_terminate( $server );
				proc_close( $server );
			}
		}
	}
);

wproadie_test(
	'delete-file deletes with a backup and undo restores',
	function () {
		$path = roadie_child( 'old.css' );
		$real = WP_CONTENT_DIR . '/' . $path;
		file_put_contents( $real, "p { margin: 0; }\n" );

		wproadie_assert_error(
			wproadie_call(
				'delete-file',
				array(
					'path'          => $path,
					'expected_sha1' => str_repeat( 'b', 40 ),
				)
			),
			'wproadie_file_changed'
		);

		$result = wproadie_ok( wproadie_call( 'delete-file', array( 'path' => $path ) ) );
		wproadie_assert_same( true, $result['deleted'] );
		wproadie_assert( ! file_exists( $real ) );

		$undone = Undo::revert( (int) $result['undo_id'] );
		wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
		wproadie_assert_same( "p { margin: 0; }\n", file_get_contents( $real ) );
		unlink( $real );
	}
);

wproadie_test(
	'delete-file refuses style.css, folders, missing files and parent theme files',
	function () {
		wproadie_assert_error( wproadie_call( 'delete-file', array( 'path' => roadie_child( 'style.css' ) ) ), 'wproadie_theme_stylesheet' );
		wproadie_assert( file_exists( $GLOBALS['roadie_child']['dir'] . '/style.css' ) );
		wproadie_assert_error( wproadie_call( 'delete-file', array( 'path' => roadie_child( 'nope.css' ) ) ), 'wproadie_not_found' );
		wproadie_assert_error( wproadie_call( 'delete-file', array( 'path' => 'themes/twentytwentyfive/style.css' ) ), 'wproadie_path_not_allowed' );
		wproadie_assert_error( wproadie_call( 'delete-file', array( 'path' => roadie_child() ) ), 'wproadie_path_not_allowed' );
	}
);

wproadie_test(
	'DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS turn off writes',
	function () {
		wproadie_assert( ! FileTools::edits_disallowed( false, false ) );
		wproadie_assert( FileTools::edits_disallowed( true, false ) );
		wproadie_assert( FileTools::edits_disallowed( false, true ) );
		$expected = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		wproadie_assert_same( $expected, FileTools::edits_disallowed() );
		wproadie_assert_same( ! $expected, (bool) wp_get_ability( 'wproadie/write-file' ) );
		wproadie_assert_same( ! $expected, (bool) wp_get_ability( 'wproadie/delete-file' ) );
		wproadie_assert( (bool) wp_get_ability( 'wproadie/read-file' ), 'reads stay available' );
	}
);

wproadie_test(
	'write-file refuses once the child theme is no longer active',
	function () {
		switch_theme( $GLOBALS['roadie_child']['original'] );
		try {
			wproadie_assert_error(
				wproadie_call(
					'write-file',
					array(
						'path'    => roadie_child( 'style.css' ),
						'content' => 'x',
					)
				),
				'wproadie_path_not_allowed'
			);
		} finally {
			switch_theme( 'roadie-child' );
		}
	}
);

wproadie_test(
	'teardown: remove child theme',
	function () {
		roadie_child_teardown();
		remove_filter( 'wproadie_rate_limit', '__return_zero' );
		wproadie_assert( ! is_dir( $GLOBALS['roadie_child']['dir'] ) );
		wproadie_assert_same( $GLOBALS['roadie_child']['original'], get_stylesheet() );
	}
);
