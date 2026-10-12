<?php
/**
 * Wires every WPRoadie component into WordPress.
 *
 * @package WPRoadie
 */

namespace WPRoadie;

use WPRoadie\Abilities\Registry;
use WPRoadie\Admin\Menu;
use WPRoadie\Admin\RestApi;
use WPRoadie\Admin\Updater;
use WPRoadie\Admin\WpCliCommand;
use WPRoadie\Hq\Commands as HqCommands;
use WPRoadie\Hq\Heartbeat as HqHeartbeat;
use WPRoadie\Hq\HqCliCommand;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Settings;
use WPRoadie\Safety\Stealth;
use WPRoadie\Server\McpServer;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks. Runs once, when the plugin file is loaded.
	 */
	const PRUNE_HOOK = 'wproadie_prune_log';

	public function boot(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( $this, 'adapter_notice' ) );
		add_action( self::PRUNE_HOOK, array( AuditLog::class, 'prune' ) );

		// The adapter's default server lets any logged-in user (even a
		// Subscriber) connect. WPRoadie serves its own locked-down server,
		// so the default one stays off unless a site opts back in.
		add_filter(
			'mcp_adapter_create_default_server',
			static function ( $create ) {
				return (bool) apply_filters( 'wproadie_allow_default_server', false, $create );
			}
		);

		if ( ! Settings::is_disabled() ) {
			$registry = new Registry();
			add_action( 'wp_abilities_api_categories_init', array( $registry, 'register_categories' ) );
			add_action( 'wp_abilities_api_init', array( $registry, 'register_abilities' ) );
			add_action( 'mcp_adapter_init', array( new McpServer( $registry ), 'register' ) );
		}

		// The admin screen, its REST API, updates and stealth keep working
		// with the kill switch on, so WPRoadie can be turned back on.
		( new RestApi() )->hooks();
		( new Updater() )->hooks();
		( new Stealth() )->hooks();
		// HQ keeps working with the kill switch on: it reports the switch,
		// and its commands can still take access away (never grant it).
		( new HqHeartbeat() )->hooks();
		( new HqCommands() )->hooks();
		if ( is_admin() ) {
			( new Menu() )->hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wproadie', WpCliCommand::class );
			\WP_CLI::add_command( 'wproadie hq', HqCliCommand::class );
		}
	}

	/**
	 * Whether the MCP Adapter plugin is loaded and recent enough.
	 */
	public static function adapter_available(): bool {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return false;
		}
		if ( defined( 'WP_MCP_VERSION' ) && version_compare( WP_MCP_VERSION, WPROADIE_MCP_ADAPTER_MIN, '<' ) ) {
			return false;
		}
		return true;
	}

	public function adapter_notice(): void {
		if ( self::adapter_available() || ! Permission::can_manage() ) {
			return;
		}
		wp_admin_notice(
			sprintf(
				/* translators: %s: minimum MCP Adapter version. */
				esc_html__( 'WPRoadie needs the MCP Adapter plugin (version %s or newer) to be installed and active. AI clients cannot connect until it is.', 'wproadie' ),
				esc_html( WPROADIE_MCP_ADAPTER_MIN )
			),
			array( 'type' => 'error' )
		);
	}

	/**
	 * Runs schema upgrades after a plugin update, which does not fire the
	 * activation hook.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( AuditLog::DB_VERSION_OPTION ) !== AuditLog::DB_VERSION ) {
			AuditLog::install();
		}
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	public static function activate(): void {
		AuditLog::install();
		Settings::ensure_defaults();

		// Whoever activates the plugin from wp-admin may connect. Activations
		// over WP-CLI have no user; grant access with `wp wproadie grant`.
		$user_id = get_current_user_id();
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			Permission::grant( $user_id );
		}
	}

	public static function deactivate(): void {
		// Data is kept on deactivation; uninstall.php removes it.
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
		HqHeartbeat::unschedule();
	}
}
