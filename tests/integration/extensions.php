<?php
/**
 * Plugin and theme tools.
 *
 * Needs a staging-mode site with Hello Dolly (hello.php) inactive and
 * twentytwentyfour installed. WordPress.org may be unreachable; the tests
 * then check that slug installs and updates fail cleanly. The zip install
 * downloads the Two Factor plugin from a GitHub release.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Abilities\Extensions\Installer;
use WPRoadie\Abilities\Registry;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

// These tests make many calls; the last test puts the rate limit back.
add_filter( 'wproadie_rate_limit', '__return_zero' );

wproadie_test(
	'filesystem is direct here, so install tools are available',
	function () {
		wproadie_assert( Installer::filesystem_ready( WP_PLUGIN_DIR ) );
		wproadie_assert( (bool) wp_get_ability( 'wproadie/install-plugin' ) );
		wproadie_assert( (bool) wp_get_ability( 'wproadie/update-theme' ) );
	}
);

wproadie_test(
	'activate-plugin activates and undo deactivates',
	function () {
		deactivate_plugins( 'hello.php' );
		try {
			$result = wproadie_ok( wproadie_call( 'activate-plugin', array( 'plugin' => 'hello.php' ) ) );
			wproadie_assert_same( true, $result['active'] );
			wproadie_assert_same( true, $result['changed'] );
			wproadie_assert( ! empty( $result['undo_id'] ), 'undo_id expected' );
			wproadie_assert( is_plugin_active( 'hello.php' ) );

			$again = wproadie_ok( wproadie_call( 'activate-plugin', array( 'plugin' => 'hello.php' ) ) );
			wproadie_assert_same( false, $again['changed'] );
			wproadie_assert( empty( $again['undo_id'] ), 'no-op should not be undoable' );

			$undone = Undo::revert( (int) $result['undo_id'] );
			wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
			wproadie_assert( ! is_plugin_active( 'hello.php' ), 'plugin should be inactive after undo' );
		} finally {
			deactivate_plugins( 'hello.php' );
		}
	}
);

wproadie_test(
	'activate-plugin refuses unknown plugins',
	function () {
		wproadie_assert_error( wproadie_call( 'activate-plugin', array( 'plugin' => 'nope/nope.php' ) ), 'wproadie_plugin_not_found' );
	}
);

wproadie_test(
	'activate-plugin refuses a plugin with a syntax error',
	function () {
		$file = WP_PLUGIN_DIR . '/wproadie-test-broken.php';
		file_put_contents( $file, "<?php\n/*\nPlugin Name: WPRoadie broken test\n*/\necho 1\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		try {
			wp_clean_plugins_cache( false );
			wproadie_assert_error( wproadie_call( 'activate-plugin', array( 'plugin' => 'wproadie-test-broken.php' ) ), 'wproadie_plugin_syntax' );
			wproadie_assert( ! is_plugin_active( 'wproadie-test-broken.php' ) );
		} finally {
			wp_delete_file( $file );
			wp_clean_plugins_cache( false );
		}
	}
);

wproadie_test(
	'activate-plugin reports a plugin that throws while loading',
	function () {
		$file = WP_PLUGIN_DIR . '/wproadie-test-throws.php';
		file_put_contents( $file, "<?php\n/*\nPlugin Name: WPRoadie throwing test\n*/\nwproadie_no_such_function_xyz();\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$level = ob_get_level();
		try {
			wp_clean_plugins_cache( false );
			wproadie_assert_error( wproadie_call( 'activate-plugin', array( 'plugin' => 'wproadie-test-throws.php' ) ), 'wproadie_activation_failed' );
			wproadie_assert( ! is_plugin_active( 'wproadie-test-throws.php' ) );
			wproadie_assert_same( $level, ob_get_level(), 'output buffers must be closed' );
		} finally {
			deactivate_plugins( 'wproadie-test-throws.php' );
			wp_delete_file( $file );
			wp_clean_plugins_cache( false );
		}
	}
);

wproadie_test(
	'deactivate-plugin refuses protected plugins',
	function () {
		wproadie_assert_error( wproadie_call( 'deactivate-plugin', array( 'plugin' => 'mcp-adapter/mcp-adapter.php' ) ), 'wproadie_protected_plugin' );
		wproadie_assert_error( wproadie_call( 'deactivate-plugin', array( 'plugin' => plugin_basename( WPROADIE_FILE ) ) ), 'wproadie_protected_plugin' );
		wproadie_assert( is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) );
	}
);

wproadie_test(
	'deactivate-plugin deactivates and undo reactivates',
	function () {
		activate_plugin( 'hello.php' );
		try {
			$result = wproadie_ok( wproadie_call( 'deactivate-plugin', array( 'plugin' => 'hello.php' ) ) );
			wproadie_assert_same( false, $result['active'] );
			wproadie_assert( ! is_plugin_active( 'hello.php' ) );
			$undone = Undo::revert( (int) $result['undo_id'] );
			wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
			wproadie_assert( is_plugin_active( 'hello.php' ), 'plugin should be active after undo' );
		} finally {
			deactivate_plugins( 'hello.php' );
		}
	}
);

wproadie_test(
	'install-plugin validates its source',
	function () {
		wproadie_assert_error( wproadie_call( 'install-plugin', array() ), 'wproadie_bad_source' );
		wproadie_assert_error(
			wproadie_call(
				'install-plugin',
				array(
					'slug'    => 'hello-dolly',
					'zip_url' => 'https://example.com/x.zip',
				)
			),
			'wproadie_bad_source'
		);
		wproadie_assert_error( wproadie_call( 'install-plugin', array( 'zip_url' => 'http://example.com/x.zip' ) ), 'wproadie_bad_source' );
		wproadie_assert_error( wproadie_call( 'install-plugin', array( 'zip_url' => 'https://192.168.1.1/x.zip' ) ), 'wproadie_bad_source' );
	}
);

wproadie_test(
	'install-plugin by slug fails cleanly when WordPress.org is unreachable (or installs)',
	function () {
		$result = wproadie_call( 'install-plugin', array( 'slug' => 'wproadie-surely-not-a-real-plugin-slug' ) );
		wproadie_assert_error( $result, 'wproadie_wporg_failed' );
		wproadie_assert( '' !== $result->get_error_message() );
	}
);

wproadie_test(
	'install-plugin from a zip URL, activate, undo, and refuse a second install',
	function () {
		$file = 'two-factor/two-factor.php';
		wproadie_assert( ! array_key_exists( $file, get_plugins() ), 'two-factor must not be installed before the test' );
		// Sandboxes that re-sign TLS through a proxy publish their CA in
		// CURL_CA_BUNDLE; WordPress only trusts its own bundle by default.
		$ca_filter = static function ( $args ) {
			$bundle = getenv( 'CURL_CA_BUNDLE' );
			if ( $bundle && is_readable( $bundle ) ) {
				$args['sslcertificates'] = $bundle;
			}
			return $args;
		};
		add_filter( 'http_request_args', $ca_filter );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'install-plugin',
					array(
						'zip_url'  => 'https://github.com/WordPress/two-factor/releases/download/0.14.0/two-factor.zip',
						'activate' => true,
					)
				)
			);
			wproadie_assert_same( $file, $result['plugin'] );
			wproadie_assert_same( true, $result['active'], wp_json_encode( $result ) );
			wproadie_assert( '' !== $result['version'] );

			$undone = Undo::revert( (int) $result['undo_id'] );
			wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
			wproadie_assert( ! is_plugin_active( $file ), 'undo should deactivate' );
			wproadie_assert( array_key_exists( $file, get_plugins() ), 'undo keeps the plugin installed' );

			$again = wproadie_call( 'install-plugin', array( 'zip_url' => 'https://github.com/WordPress/two-factor/releases/download/0.14.0/two-factor.zip' ) );
			wproadie_assert_error( $again );
		} finally {
			if ( array_key_exists( $file, get_plugins() ) ) {
				deactivate_plugins( $file );
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				delete_plugins( array( $file ) );
			}
			wp_clean_plugins_cache( false );
			remove_filter( 'http_request_args', $ca_filter );
		}
		wproadie_assert( ! array_key_exists( $file, get_plugins() ), 'cleanup should remove the plugin' );
	}
);

wproadie_test(
	'production mode offers plugin and theme updates but no installs or (de)activations',
	function () {
		try {
			Settings::set( 'mode', Mode::PRODUCTION );
			$names = ( new Registry() )->enabled_names();
			foreach ( array( 'update-plugin', 'update-theme' ) as $slug ) {
				wproadie_assert( in_array( 'wproadie/' . $slug, $names, true ), $slug . ' should be offered on a live site' );
			}
			foreach ( array( 'install-plugin', 'install-theme', 'activate-plugin', 'deactivate-plugin', 'activate-theme' ) as $slug ) {
				wproadie_assert( ! in_array( 'wproadie/' . $slug, $names, true ), $slug . ' must stay off on a live site' );
			}
			wproadie_assert_same( true, wp_get_ability( 'wproadie/update-plugin' )->check_permissions( array( 'plugin' => 'hello.php' ) ) );
			wproadie_assert_same( false, wp_get_ability( 'wproadie/install-plugin' )->check_permissions( array( 'slug' => 'hello-dolly' ) ) );
			Settings::set( 'mode', Mode::READ_ONLY );
			wproadie_assert_same( false, wp_get_ability( 'wproadie/update-plugin' )->check_permissions( array( 'plugin' => 'hello.php' ) ) );
		} finally {
			Settings::set( 'mode', '' );
		}
	}
);

wproadie_test(
	'update-plugin refuses protected plugins and reports a failed or empty update check',
	function () {
		wproadie_assert_error( wproadie_call( 'update-plugin', array( 'plugin' => 'mcp-adapter/mcp-adapter.php' ) ), 'wproadie_protected_plugin' );
		wproadie_assert_error( wproadie_call( 'update-plugin', array( 'plugin' => 'nope/nope.php' ) ), 'wproadie_plugin_not_found' );
		$result = wproadie_call( 'update-plugin', array( 'plugin' => 'hello.php' ) );
		wproadie_assert_error( $result );
		wproadie_assert( in_array( $result->get_error_code(), array( 'wproadie_update_check_failed', 'wproadie_no_update' ), true ), $result->get_error_code() );
	}
);

/**
 * Builds a zip of a one-folder plugin with the given main-file body.
 */
function extensions_test_plugin_zip( string $dir, string $main_file, string $body ): string {
	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
	$work = trailingslashit( get_temp_dir() ) . 'wproadie-zip-' . wp_generate_password( 6, false );
	wp_mkdir_p( $work . '/' . $dir );
	file_put_contents( $work . '/' . $dir . '/' . $main_file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$zip     = $work . '.zip';
	$archive = new PclZip( $zip );
	$made    = $archive->create( $work . '/' . $dir, PCLZIP_OPT_REMOVE_PATH, $work );
	wproadie_assert( 0 !== $made, 'zip failed: ' . $archive->errorInfo( true ) );
	return $zip;
}

function extensions_test_plugin_body( string $version, string $extra = '' ): string {
	return "<?php\n/**\n * Plugin Name: WPRoadie Test Plugin\n * Version: {$version}\n */\n{$extra}\n";
}

wproadie_test(
	'apply-updates backs up, updates, can be undone, and rolls back a broken update',
	function () {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$dir  = 'wproadie-test-plugin';
		$file = $dir . '/wproadie-test-plugin.php';
		$path = WP_PLUGIN_DIR . '/' . $dir;
		wp_mkdir_p( $path );
		file_put_contents( $path . '/wproadie-test-plugin.php', extensions_test_plugin_body( '1.0.0' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_clean_plugins_cache( false );
		wproadie_assert_same( '1.0.0', get_plugins()[ $file ]['Version'] );

		$good   = extensions_test_plugin_zip( $dir, 'wproadie-test-plugin.php', extensions_test_plugin_body( '1.1.0' ) );
		$broken = extensions_test_plugin_zip( $dir, 'wproadie-test-plugin.php', extensions_test_plugin_body( '1.2.0', 'function (' ) );
		$serve  = $good;
		// The update "download": WordPress asks for the package URL and gets a
		// copy of our local zip (the upgrader deletes the file it was given).
		$download = static function ( $reply, $package ) use ( &$serve ) {
			if ( 'https://updates.example.test/wproadie-test-plugin.zip' !== $package ) {
				return $reply;
			}
			$copy = trailingslashit( get_temp_dir() ) . 'wproadie-serve-' . wp_generate_password( 6, false ) . '.zip';
			copy( $serve, $copy );
			return $copy;
		};
		add_filter( 'upgrader_pre_download', $download, 10, 2 );
		$offer = static function ( string $version ) use ( $file ) {
			$current           = get_site_transient( 'update_plugins' );
			$current           = is_object( $current ) ? $current : (object) array( 'response' => array() );
			$current->response = array(
				$file => (object) array(
					'plugin'      => $file,
					'slug'        => 'wproadie-test-plugin',
					'new_version' => $version,
					'package'     => 'https://updates.example.test/wproadie-test-plugin.zip',
				),
			);
			set_site_transient( 'update_plugins', $current );
		};

		try {
			activate_plugin( $file );
			$offer( '1.1.0' );

			$plan = wproadie_ok(
				wproadie_call(
					'apply-updates',
					array(
						'plugins' => array( $file, 'nope/nope.php' ),
						'dry_run' => true,
					)
				)
			);
			wproadie_assert_same(
				array(
					array(
						'kind' => 'plugin',
						'id'   => $file,
						'name' => 'WPRoadie Test Plugin',
						'from' => '1.0.0',
						'to'   => '1.1.0',
					),
				),
				$plan['items']
			);
			wproadie_assert_same( 'not_installed', $plan['skipped'][0]['reason'] );
			wproadie_assert_same( '1.0.0', get_plugins()[ $file ]['Version'], 'a dry run changes nothing' );

			$result = wproadie_ok( wproadie_call( 'apply-updates', array( 'plugins' => array( $file ) ) ) );
			wproadie_assert_same( 1, $result['updated'], wp_json_encode( $result ) );
			wproadie_assert_same( 'updated', $result['results'][0]['status'] );
			wproadie_assert_same( '1.1.0', get_plugins()[ $file ]['Version'] );
			wproadie_assert( is_plugin_active( $file ), 'the plugin should be active again after the update' );
			wproadie_assert( ! empty( $result['undo_id'] ), 'an update must be undoable' );
			$backups = glob( WP_CONTENT_DIR . '/wproadie/backups/*-plugin-wproadie-test-plugin-1.0.0-*' );
			wproadie_assert( $backups && is_dir( $backups[0] ), 'the previous version should be kept' );

			$undone = Undo::revert( (int) $result['undo_id'] );
			wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed: ' . wp_json_encode( $undone ) );
			wp_clean_plugins_cache( false );
			wproadie_assert_same( '1.0.0', get_plugins()[ $file ]['Version'], 'undo should put 1.0.0 back' );
			wproadie_assert( is_plugin_active( $file ), 'undo should leave the plugin active' );

			// A release whose main file does not parse is rolled back before it can take the site down.
			$serve = $broken;
			$offer( '1.2.0' );
			$rolled = wproadie_ok( wproadie_call( 'apply-updates', array( 'all' => true ) ) );
			$item   = null;
			foreach ( $rolled['results'] as $r ) {
				if ( $r['id'] === $file ) {
					$item = $r;
				}
			}
			wproadie_assert( $item, wp_json_encode( $rolled ) );
			wproadie_assert_same( 'rolled_back', $item['status'], $item['message'] );
			wproadie_assert_same( 1, $rolled['rolled_back'] );
			wp_clean_plugins_cache( false );
			wproadie_assert_same( '1.0.0', get_plugins()[ $file ]['Version'], 'the previous version should be back' );
			wproadie_assert( is_plugin_active( $file ) );
			wproadie_assert( empty( $rolled['undo_id'] ), 'nothing to undo after a rollback' );

			// update-plugin takes the same safe path.
			$serve = $good;
			$offer( '1.1.0' );
			$single = wproadie_ok( wproadie_call( 'update-plugin', array( 'plugin' => $file ) ) );
			wproadie_assert_same( '1.1.0', $single['version'] );
			wproadie_assert_same( '1.0.0', $single['previous_version'] );
			wproadie_assert( ! empty( $single['undo_id'] ) );
		} finally {
			remove_filter( 'upgrader_pre_download', $download, 10 );
			deactivate_plugins( $file, true );
			global $wp_filesystem;
			WP_Filesystem();
			$wp_filesystem->delete( $path, true );
			foreach ( (array) glob( WP_CONTENT_DIR . '/wproadie/backups/*-plugin-wproadie-test-plugin-*' ) as $b ) {
				$wp_filesystem->delete( $b, true );
			}
			\WPRoadie\Safety\PendingUpdates::forget();
			delete_site_transient( 'update_plugins' );
			wp_clean_plugins_cache( false );
		}
	}
);

wproadie_test(
	'install-theme validates its source and fails cleanly by slug',
	function () {
		wproadie_assert_error( wproadie_call( 'install-theme', array() ), 'wproadie_bad_source' );
		wproadie_assert_error( wproadie_call( 'install-theme', array( 'zip_url' => 'ftp://example.com/x.zip' ) ), 'wproadie_bad_source' );
		wproadie_assert_error( wproadie_call( 'install-theme', array( 'slug' => 'wproadie-surely-not-a-real-theme-slug' ) ), 'wproadie_wporg_failed' );
	}
);

wproadie_test(
	'activate-theme switches and undo switches back',
	function () {
		$original = get_stylesheet();
		$target   = 'twentytwentyfour' === $original ? 'twentytwentythree' : 'twentytwentyfour';
		try {
			$result = wproadie_ok( wproadie_call( 'activate-theme', array( 'stylesheet' => $target ) ) );
			wproadie_assert_same( $target, get_stylesheet() );
			wproadie_assert_same( $original, $result['previous_stylesheet'] );
			wproadie_assert_same( true, $result['active'] );

			$undone = Undo::revert( (int) $result['undo_id'] );
			wproadie_assert( ! is_wp_error( $undone ) && ! $undone['errors'], 'undo failed' );
			wproadie_assert_same( $original, get_stylesheet(), 'theme should be restored' );

			$same = wproadie_ok( wproadie_call( 'activate-theme', array( 'stylesheet' => $original ) ) );
			wproadie_assert_same( false, $same['changed'] );
		} finally {
			if ( get_stylesheet() !== $original ) {
				switch_theme( $original );
			}
		}
	}
);

wproadie_test(
	'activate-theme refuses missing themes',
	function () {
		wproadie_assert_error( wproadie_call( 'activate-theme', array( 'stylesheet' => 'no-such-theme' ) ), 'wproadie_theme_not_found' );
	}
);

wproadie_test(
	'update-theme updates, or reports a failed or empty update check',
	function () {
		wproadie_assert_error( wproadie_call( 'update-theme', array( 'stylesheet' => 'no-such-theme' ) ), 'wproadie_theme_not_found' );
		$result = wproadie_call( 'update-theme', array( 'stylesheet' => 'twentytwentyone' ) );
		if ( is_wp_error( $result ) ) {
			// No network to WordPress.org, or the bundled theme is current.
			wproadie_assert( in_array( $result->get_error_code(), array( 'wproadie_update_check_failed', 'wproadie_no_update' ), true ), $result->get_error_code() );
			return;
		}
		// With WordPress.org reachable (e.g. CI), the bundled theme may really update.
		wproadie_assert_same( 'twentytwentyone', $result['stylesheet'] );
		wproadie_assert( version_compare( $result['version'], $result['previous_version'], '>' ), 'The version should go up: ' . $result['previous_version'] . ' to ' . $result['version'] );
		wproadie_assert_same( $result['version'], wp_get_theme( 'twentytwentyone' )->get( 'Version' ) );
	}
);

wproadie_test(
	'teardown: restore the rate limit',
	function () {
		remove_filter( 'wproadie_rate_limit', '__return_zero' );
		wproadie_assert( ! array_key_exists( 'two-factor/two-factor.php', get_plugins() ) );
	}
);
