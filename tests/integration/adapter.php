<?php
/**
 * One-click install, activation and update of the MCP Adapter.
 *
 * The adapter's code stays loaded in this process whatever happens on
 * disk, so these tests tell WPRoadie it is unavailable (the
 * wproadie_adapter_available filter) and check the plugin's state on disk.
 * Every test puts the adapter back the way it found it.
 *
 * @package WPRoadie
 */

// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.AlternativeFunctions -- Test file moving plugin folders around.

use WPRoadie\Admin\AdapterInstaller;
use WPRoadie\Log\AuditLog;

/**
 * Runs $fn with the adapter treated as unavailable, then restores the
 * original adapter folder and activation.
 *
 * @param callable $fn Test body; gets the path of the backed-up folder.
 */
function adapter_test_sandbox( callable $fn ): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$dir    = WP_PLUGIN_DIR . '/mcp-adapter';
	$backup = WP_PLUGIN_DIR . '/mcp-adapter.wproadie-test-' . wp_generate_password( 6, false, false );
	wproadie_assert( is_dir( $dir ), 'the test site has mcp-adapter/ installed' );
	$was_active = is_plugin_active( AdapterInstaller::PLUGIN_FILE );

	add_filter( 'wproadie_adapter_available', '__return_false' );
	try {
		$fn( $dir, $backup );
	} finally {
		remove_filter( 'wproadie_adapter_available', '__return_false' );
		if ( is_dir( $backup ) ) {
			if ( is_dir( $dir ) ) {
				WP_Filesystem();
				global $wp_filesystem;
				$wp_filesystem->delete( $dir, true );
			}
			rename( $backup, $dir );
		}
		wp_clean_plugins_cache( true );
		if ( $was_active && ! is_plugin_active( AdapterInstaller::PLUGIN_FILE ) ) {
			// Already loaded in this process; just record it as active.
			activate_plugin( AdapterInstaller::PLUGIN_FILE, '', false, true );
		}
	}
	wproadie_assert( is_plugin_active( AdapterInstaller::PLUGIN_FILE ), 'adapter restored and active' );
}

/**
 * Calls the install route as the current user.
 */
function adapter_test_install(): WP_REST_Response {
	return rest_do_request( new WP_REST_Request( 'POST', '/wproadie/v1/adapter/install' ) );
}

/**
 * Whether a failed install was the download, which CI may not reach.
 *
 * @param WP_REST_Response $response Install response.
 */
function adapter_test_download_failed( WP_REST_Response $response ): bool {
	$data = $response->get_data();
	if ( 200 === $response->get_status() || 'wproadie_adapter_install_failed' !== ( $data['code'] ?? '' ) ) {
		return false;
	}
	WP_CLI::warning( 'Skipping: could not download the MCP Adapter from GitHub: ' . ( $data['message'] ?? '' ) );
	return true;
}

wproadie_test(
	'the needed step follows the installed version and state',
	function () {
		wproadie_assert_same( 'update', AdapterInstaller::action_for( '0.6.9', true ) );
		wproadie_assert_same( 'update', AdapterInstaller::action_for( '0.6.0', false ) );
		wproadie_assert_same( 'activate', AdapterInstaller::action_for( WPROADIE_MCP_ADAPTER_MIN, false ) );
		wproadie_assert_same( 'none', AdapterInstaller::action_for( WPROADIE_MCP_ADAPTER_MIN, true ) );
		wproadie_assert_same( 'none', AdapterInstaller::needed_action() );
		wproadie_assert_same(
			'https://github.com/WordPress/mcp-adapter/releases/download/v' . WPROADIE_MCP_ADAPTER_MIN . '/mcp-adapter.zip',
			AdapterInstaller::package()
		);
		$ready = adapter_test_install();
		wproadie_assert_same( 200, $ready->get_status() );
		wproadie_assert_same( 'ready', $ready->get_data()['status'] );
	}
);

wproadie_test(
	'an installed but inactive adapter is activated',
	function () {
		adapter_test_sandbox(
			function () {
				deactivate_plugins( AdapterInstaller::PLUGIN_FILE, true );
				wproadie_assert_same( 'activate', AdapterInstaller::needed_action() );

				$response = adapter_test_install();
				wproadie_assert_same( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
				$data = $response->get_data();
				wproadie_assert_same( 'activated', $data['status'] );
				wproadie_assert_same( true, $data['reload'] );
				wproadie_assert_same( WPROADIE_MCP_ADAPTER_MIN, $data['version'] );
				wproadie_assert( is_plugin_active( AdapterInstaller::PLUGIN_FILE ) );
				wproadie_assert_same( 'none', AdapterInstaller::needed_action() );

				$log = AuditLog::query( array( 'ability' => 'admin/install-mcp-adapter' ), 1 )['rows'][0];
				wproadie_assert_same( 'success', $log->status );
			}
		);
	}
);

wproadie_test(
	'a missing adapter is downloaded from GitHub, installed and activated',
	function () {
		adapter_test_sandbox(
			function ( $dir, $backup ) {
				deactivate_plugins( AdapterInstaller::PLUGIN_FILE, true );
				rename( $dir, $backup );
				wp_clean_plugins_cache( true );
				wproadie_assert_same( 'install', AdapterInstaller::needed_action() );

				$response = adapter_test_install();
				if ( adapter_test_download_failed( $response ) ) {
					return;
				}
				wproadie_assert_same( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
				wproadie_assert_same( 'installed', $response->get_data()['status'] );
				wproadie_assert_same( WPROADIE_MCP_ADAPTER_MIN, $response->get_data()['version'] );
				wproadie_assert( is_readable( $dir . '/mcp-adapter.php' ) );
				wproadie_assert( is_plugin_active( AdapterInstaller::PLUGIN_FILE ) );
			}
		);
	}
);

wproadie_test(
	'an adapter older than the minimum is replaced with the pinned version',
	function () {
		adapter_test_sandbox(
			function ( $dir, $backup ) {
				deactivate_plugins( AdapterInstaller::PLUGIN_FILE, true );
				rename( $dir, $backup );
				mkdir( $dir );
				file_put_contents( $dir . '/mcp-adapter.php', "<?php\n/**\n * Plugin Name: MCP Adapter\n * Version: 0.6.0\n */\n" );
				wp_clean_plugins_cache( true );
				wproadie_assert_same( 'update', AdapterInstaller::needed_action() );

				$response = adapter_test_install();
				if ( adapter_test_download_failed( $response ) ) {
					return;
				}
				wproadie_assert_same( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
				wproadie_assert_same( 'updated', $response->get_data()['status'] );
				wproadie_assert_same( WPROADIE_MCP_ADAPTER_MIN, AdapterInstaller::installed_version() );
				wproadie_assert( is_plugin_active( AdapterInstaller::PLUGIN_FILE ) );
			}
		);
	}
);

wproadie_test(
	'installing is refused without the capability or when file changes are disallowed',
	function () {
		adapter_test_sandbox(
			function () {
				deactivate_plugins( AdapterInstaller::PLUGIN_FILE, true );

				// Activating writes no files, so it works with file changes off.
				$no_mods = static function ( $allowed, $context ) {
					return 'wproadie_install_adapter' === $context ? false : $allowed;
				};
				add_filter( 'file_mod_allowed', $no_mods, 10, 2 );
				try {
					wproadie_assert( true === AdapterInstaller::can_run( 'activate' ) );
					wproadie_assert_error( AdapterInstaller::can_run( 'install' ), 'wproadie_adapter_file_mods' );
					wproadie_assert_error( AdapterInstaller::can_run( 'update' ), 'wproadie_adapter_file_mods' );
				} finally {
					remove_filter( 'file_mod_allowed', $no_mods, 10 );
				}
				wproadie_assert_error( AdapterInstaller::can_run( 'manual' ), 'wproadie_adapter_manual' );

				$deny = static function ( $allcaps ) {
					$allcaps['install_plugins']  = false;
					$allcaps['activate_plugins'] = false;
					return $allcaps;
				};
				add_filter( 'user_has_cap', $deny );
				try {
					$response = adapter_test_install();
					wproadie_assert_same( 403, $response->get_status() );
					wproadie_assert_same( 'wproadie_adapter_forbidden', $response->get_data()['code'] );
					wproadie_assert( ! is_plugin_active( AdapterInstaller::PLUGIN_FILE ) );
				} finally {
					remove_filter( 'user_has_cap', $deny );
				}
			}
		);
	}
);
