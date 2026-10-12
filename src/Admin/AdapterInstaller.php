<?php
/**
 * Installs, activates or updates the MCP Adapter plugin with one click,
 * from the pinned GitHub release (WPROADIE_MCP_ADAPTER_MIN).
 *
 * Used by the setup checklist (REST) and the admin notice (admin-post).
 * WPRoadie's MCP server is only registered when the adapter is loaded at
 * boot, so after this runs the page has to be reloaded.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use Plugin_Upgrader;
use WP_Error;
use WPRoadie\Abilities\Extensions\Installer;
use WPRoadie\Log\AuditLog;
use WPRoadie\Plugin;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class AdapterInstaller {

	const PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

	/**
	 * Admin-post action for the notice button.
	 */
	const ACTION = 'wproadie_install_adapter';

	/**
	 * The zip to install: the pinned release on GitHub.
	 */
	public static function package(): string {
		$url = 'https://github.com/WordPress/mcp-adapter/releases/download/v' . WPROADIE_MCP_ADAPTER_MIN . '/mcp-adapter.zip';
		/**
		 * Filters the MCP Adapter package WPRoadie installs.
		 *
		 * @param string $url     Zip URL.
		 * @param string $version Pinned version.
		 */
		return (string) apply_filters( 'wproadie_mcp_adapter_package', $url, WPROADIE_MCP_ADAPTER_MIN );
	}

	/**
	 * What the adapter needs: "none", "install", "activate", "update", or
	 * "manual" (loaded from somewhere WPRoadie cannot manage, but too old).
	 */
	public static function needed_action(): string {
		if ( Plugin::adapter_available() ) {
			return 'none';
		}
		$installed = self::installed_version();
		if ( null !== $installed ) {
			return self::action_for( $installed, self::is_active() );
		}
		// An old adapter loaded from a folder we do not manage: hands off.
		if ( defined( 'WP_MCP_VERSION' ) && version_compare( WP_MCP_VERSION, WPROADIE_MCP_ADAPTER_MIN, '<' ) ) {
			return 'manual';
		}
		return 'install';
	}

	/**
	 * The step needed for an installed adapter.
	 *
	 * @param string $installed_version Version in the plugin header.
	 * @param bool   $active            Whether it is active.
	 */
	public static function action_for( string $installed_version, bool $active ): string {
		if ( version_compare( $installed_version, WPROADIE_MCP_ADAPTER_MIN, '<' ) ) {
			return 'update';
		}
		// Installed, recent enough and active but not loaded yet: it was
		// activated earlier in this request, so a reload finishes the job.
		return $active ? 'none' : 'activate';
	}

	public static function installed_version(): ?string {
		self::load_plugin_functions();
		$plugins = get_plugins();
		return isset( $plugins[ self::PLUGIN_FILE ] ) ? (string) $plugins[ self::PLUGIN_FILE ]['Version'] : null;
	}

	public static function is_active(): bool {
		self::load_plugin_functions();
		return is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * State for the setup checklist.
	 */
	public static function status(): array {
		$action = self::needed_action();
		$can    = self::can_run( $action );
		return array(
			'available'          => Plugin::adapter_available(),
			'version'            => defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : self::installed_version(),
			'required'           => WPROADIE_MCP_ADAPTER_MIN,
			'action'             => $action,
			'can_install'        => true === $can,
			'cannot_install_why' => true === $can ? '' : $can->get_error_message(),
		);
	}

	/**
	 * Whether the current user may take the step here.
	 *
	 * @param string $action From needed_action().
	 * @return true|WP_Error
	 */
	public static function can_run( string $action ) {
		if ( 'none' === $action ) {
			return true;
		}
		if ( 'manual' === $action ) {
			return new WP_Error(
				'wproadie_adapter_manual',
				sprintf(
					/* translators: %s: version. */
					__( 'An older MCP Adapter is loaded from a place WPRoadie does not manage. Update it to version %s yourself, or ask your host.', 'wproadie' ),
					WPROADIE_MCP_ADAPTER_MIN
				),
				array( 'status' => 409 )
			);
		}
		if ( is_multisite() && ! is_super_admin() ) {
			return new WP_Error( 'wproadie_adapter_multisite', __( 'This site is part of a multisite network. Ask a network administrator to install and activate the MCP Adapter plugin under Network Admin → Plugins.', 'wproadie' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'wproadie_adapter_forbidden', __( 'You are not allowed to activate plugins on this site.', 'wproadie' ), array( 'status' => 403 ) );
		}
		if ( 'activate' === $action ) {
			return true;
		}

		// Installing and updating write files.
		if ( ! wp_is_file_mod_allowed( 'wproadie_install_adapter' ) ) {
			return new WP_Error( 'wproadie_adapter_file_mods', __( 'This site does not allow installing plugins from wp-admin (DISALLOW_FILE_MODS is set). Ask your host to install the MCP Adapter plugin, or install it manually.', 'wproadie' ), array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'install_plugins' ) || ( 'update' === $action && ! current_user_can( 'update_plugins' ) ) ) {
			return new WP_Error( 'wproadie_adapter_forbidden', __( 'You are not allowed to install plugins on this site.', 'wproadie' ), array( 'status' => 403 ) );
		}
		if ( ! Installer::filesystem_ready( WP_PLUGIN_DIR ) ) {
			return new WP_Error( 'wproadie_adapter_filesystem', Installer::filesystem_reason() . ' ' . __( 'Ask your host to install the MCP Adapter plugin, or install it manually.', 'wproadie' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Installs, activates or updates the adapter as needed.
	 *
	 * @return array{ok: bool, status: string, version: string|null, reload: bool}|WP_Error
	 *         status is "installed", "activated", "updated" or "ready".
	 */
	public static function run() {
		$started = microtime( true );
		$action  = self::needed_action();
		$result  = self::run_action( $action );

		if ( 'none' !== $action ) {
			AuditLog::record(
				'admin/install-mcp-adapter',
				array( 'action' => $action ),
				$result,
				(int) round( ( microtime( true ) - $started ) * 1000 )
			);
		}
		return $result;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function run_action( string $action ) {
		$allowed = self::can_run( $action );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( 'none' === $action ) {
			return self::done( 'ready' );
		}

		Installer::load();

		if ( 'install' === $action || 'update' === $action ) {
			$was_active = self::is_active();
			$skin       = Installer::skin();
			$upgrader   = new Plugin_Upgrader( $skin );
			$package    = self::package();
			// After installing, WordPress checks WordPress.org for updates and
			// raises a PHP warning if it cannot reach it. That says nothing
			// about the install (the skin reports real failures), and printed
			// warnings would corrupt the REST response.
			set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
				static function () {
					return true;
				},
				E_USER_WARNING | E_USER_NOTICE
			);
			try {
				$installed = Installer::quietly(
					static function () use ( $upgrader, $package ) {
						return $upgrader->install( $package, array( 'overwrite_package' => true ) );
					}
				);
			} finally {
				restore_error_handler();
			}
			$outcome = Installer::outcome( $installed, $skin );
			if ( is_wp_error( $outcome ) ) {
				return new WP_Error(
					'wproadie_adapter_install_failed',
					sprintf(
						/* translators: %s: error message. */
						__( 'Could not install the MCP Adapter: %s', 'wproadie' ),
						$outcome->get_error_message()
					),
					array( 'status' => 500 )
				);
			}
			wp_clean_plugins_cache( true );
			if ( null === self::installed_version() ) {
				return new WP_Error( 'wproadie_adapter_install_failed', __( 'The download did not contain the MCP Adapter plugin.', 'wproadie' ), array( 'status' => 500 ) );
			}
			if ( 'update' === $action && $was_active && self::is_active() ) {
				return self::done( 'updated' );
			}
		}

		$network   = is_multisite() && is_plugin_active_for_network( plugin_basename( WPROADIE_FILE ) );
		$activated = Installer::quietly(
			static function () use ( $network ) {
				return activate_plugin( self::PLUGIN_FILE, '', $network );
			}
		);
		if ( is_wp_error( $activated ) && ! self::is_active() ) {
			return new WP_Error(
				'wproadie_adapter_activation_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The MCP Adapter could not be activated: %s', 'wproadie' ),
					Installer::plain( $activated->get_error_message() )
				),
				array( 'status' => 500 )
			);
		}

		$status = array(
			'install'  => 'installed',
			'update'   => 'updated',
			'activate' => 'activated',
		);
		return self::done( $status[ $action ] );
	}

	private static function done( string $status ): array {
		return array(
			'ok'      => true,
			'status'  => $status,
			'version' => self::installed_version(),
			// WPRoadie's MCP server starts with the adapter on the next load.
			'reload'  => 'ready' !== $status,
		);
	}

	/**
	 * Admin-post handler behind the notice's button. Redirects back with a
	 * fixed notice code; never echoes text from the URL.
	 */
	public static function handle_post(): void {
		check_admin_referer( self::ACTION );
		if ( ! Permission::can_set_up() ) {
			wp_die( esc_html__( 'You are not allowed to set up WPRoadie.', 'wproadie' ), 403 );
		}

		$result = self::run();
		if ( is_wp_error( $result ) ) {
			$code = array(
				'wproadie_adapter_file_mods'  => 'adapter_file_mods',
				'wproadie_adapter_multisite'  => 'adapter_multisite',
				'wproadie_adapter_forbidden'  => 'adapter_forbidden',
				'wproadie_adapter_filesystem' => 'adapter_filesystem',
			)[ $result->get_error_code() ] ?? 'adapter_failed';
		} else {
			$code = 'adapter_' . $result['status'];
		}

		$back = wp_get_referer();
		$back = $back ? $back : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( Notices::QUERY_ARG, $code, remove_query_arg( Notices::QUERY_ARG, $back ) ) );
		exit;
	}

	private static function load_plugin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
