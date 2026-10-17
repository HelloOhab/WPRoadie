<?php
/**
 * Reports this site's status to HQ (docs/hq-protocol.md, "Heartbeat").
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WP_Error;
use WPRoadie\Abilities\Registry;
use WPRoadie\Abilities\Site\HealthCheck;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\PendingUpdates;
use WPRoadie\Safety\RateLimit;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\McpServer;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Heartbeat {

	const CRON_HOOK    = 'wproadie_hq_heartbeat';
	const SOON_HOOK    = 'wproadie_hq_heartbeat_soon';
	const HEALTH_CACHE = 'wproadie_hq_health';
	const LAST_RESULT  = 'wproadie_hq_last_heartbeat';
	const SCHEMA       = 1;

	public function hooks(): void {
		add_action( self::CRON_HOOK, array( self::class, 'send' ) );
		add_action( self::SOON_HOOK, array( self::class, 'send' ) );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
		// Report mode, kill switch and tool changes within a minute.
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'send_soon' ) );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'send_soon' ) );
	}

	public static function ensure_scheduled(): void {
		if ( Connection::is_connected() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
			}
		} elseif ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public static function send_soon(): void {
		if ( Connection::is_connected() && ! wp_next_scheduled( self::SOON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SOON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::SOON_HOOK );
	}

	/**
	 * Tells HQ right away that WPRoadie is going away (`deactivated`) or
	 * leaving HQ (`disconnected`), so HQ stops offering the site instead of
	 * waiting for heartbeats to stop. Best effort: a short timeout, and
	 * failures are ignored.
	 *
	 * @param string $event `deactivated` or `disconnected`.
	 */
	public static function goodbye( string $event ): void {
		if ( Connection::is_connected() ) {
			self::send( $event, 5 );
		}
	}

	/**
	 * Sends a heartbeat now.
	 *
	 * @param string|null $event   Optional goodbye event (see goodbye()).
	 * @param int         $timeout Request timeout in seconds.
	 * @return true|WP_Error
	 */
	public static function send( ?string $event = null, int $timeout = 15 ) {
		$config = Connection::get();
		$key    = Connection::key();
		if ( ! $config || null === $key ) {
			return new WP_Error( 'wproadie_hq_not_connected', __( 'This site is not connected to HQ.', 'wproadie' ) );
		}

		$payload = self::payload();
		if ( null !== $event ) {
			$payload = array( 'event' => $event ) + $payload;
		}
		$body     = (string) wp_json_encode( $payload );
		$response = wp_remote_post(
			$config['url'] . '/api/v1/heartbeat',
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => Signer::headers( $key, 'heartbeat', $config['site_id'], $body ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = $response;
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$code   = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'http_' . wp_remote_retrieve_response_code( $response );
			$result = new WP_Error(
				'wproadie_hq_rejected',
				sprintf(
					/* translators: %s: error code from HQ. */
					__( 'HQ rejected the heartbeat (%s). If HQ rotated this site\'s secret, connect again with the new connection string.', 'wproadie' ),
					$code
				)
			);
		} else {
			$result = true;
		}

		update_option(
			self::LAST_RESULT,
			array(
				'at'    => time(),
				'ok'    => true === $result,
				'error' => is_wp_error( $result ) ? $result->get_error_message() : null,
			),
			false
		);

		return $result;
	}

	/**
	 * The last send attempt, for status displays.
	 *
	 * @return array{at: int, ok: bool, error: string|null}|null
	 */
	public static function last_result(): ?array {
		$last = get_option( self::LAST_RESULT );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * The heartbeat body.
	 */
	public static function payload(): array {
		global $wpdb;

		$config   = Connection::get();
		$registry = new Registry();
		$disabled = Settings::is_disabled();

		return array(
			'schema'   => self::SCHEMA,
			'site_id'  => $config ? $config['site_id'] : null,
			'sent_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'     => array(
				'name'             => get_bloginfo( 'name' ),
				'home_url'         => home_url(),
				'admin_url'        => admin_url(),
				'environment_type' => wp_get_environment_type(),
				'multisite'        => is_multisite(),
				'mcp_endpoint'     => McpServer::endpoint_url(),
				'hq_endpoint'      => rest_url( Commands::ROUTE_NAMESPACE . Commands::ROUTE ),
			),
			'versions' => array(
				'wordpress'   => get_bloginfo( 'version' ),
				'php'         => PHP_VERSION,
				'wproadie'    => WPROADIE_VERSION,
				'mcp_adapter' => defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : null,
				'database'    => $wpdb->db_version(),
			),
			'wproadie' => array(
				'mode'              => Mode::current(),
				'mode_source'       => Mode::source(),
				'disabled'          => $disabled,
				'stealth'           => (bool) Settings::get( 'stealth' ),
				'rate_limit'        => RateLimit::per_minute(),
				'tools_exposed'     => $disabled ? 0 : count( $registry->enabled_names_of_type( 'tool' ) ),
				'tools_hash'        => ToolCatalog::hash( ToolCatalog::tools() ),
				'users_with_access' => array_values(
					array_map(
						static function ( $user ) {
							return $user->user_login;
						},
						array_filter(
							Permission::granted_users(),
							static function ( $user ) {
								return Permission::can_connect( (int) $user->ID );
							}
						)
					)
				),
			),
			'stack'    => self::stack(),
			'updates'  => self::updates(),
			'health'   => self::health(),
			'activity' => AuditLog::activity_summary(),
		);
	}

	/**
	 * What the site is built with, for the site briefing HQ gives AI
	 * clients: the theme (and parent), page builders with their versions,
	 * and active plugins.
	 */
	public static function stack(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( ! is_plugin_active( $file ) ) {
				continue;
			}
			$plugins[] = array(
				'file'    => (string) $file,
				'name'    => wp_strip_all_tags( (string) $data['Name'] ),
				'version' => (string) $data['Version'],
			);
			if ( count( $plugins ) >= 200 ) {
				break;
			}
		}

		$divi = null;
		foreach ( array( 'ET_BUILDER_PRODUCT_VERSION', 'ET_BUILDER_VERSION' ) as $constant ) {
			if ( null === $divi && defined( $constant ) ) {
				$divi = (string) constant( $constant );
			}
		}
		if ( null === $divi && in_array( $theme->get_template(), array( 'Divi', 'Extra' ), true ) ) {
			$divi = (string) ( $parent ? $parent->get( 'Version' ) : $theme->get( 'Version' ) );
		}

		return array(
			'theme'    => array(
				'name'        => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
				'version'     => (string) $theme->get( 'Version' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'parent'      => $parent ? wp_strip_all_tags( (string) $parent->get( 'Name' ) ) : null,
				'block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
			),
			'builders' => array(
				'divi'          => $divi,
				'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : null,
				'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : null,
			),
			'plugins'  => $plugins,
		);
	}

	/**
	 * Pending updates: WordPress's cached update checks, plus the updates
	 * premium plugins only announce in wp-admin (see PendingUpdates).
	 */
	private static function updates(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$core      = null;
		$core_data = get_site_transient( 'update_core' );
		if ( is_object( $core_data ) && ! empty( $core_data->updates ) ) {
			foreach ( $core_data->updates as $offer ) {
				if ( isset( $offer->response ) && 'upgrade' === $offer->response ) {
					$core = (string) $offer->current;
					break;
				}
			}
		}

		$plugins   = array();
		$installed = get_plugins();
		foreach ( PendingUpdates::plugins() as $file => $offer ) {
			if ( ! isset( $installed[ $file ] ) ) {
				continue;
			}
			$plugins[] = array(
				'file'        => (string) $file,
				'name'        => (string) $installed[ $file ]['Name'],
				'version'     => (string) $installed[ $file ]['Version'],
				'new_version' => isset( $offer->new_version ) ? (string) $offer->new_version : null,
			);
		}

		$themes = array();
		foreach ( PendingUpdates::themes() as $stylesheet => $offer ) {
			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				continue;
			}
			$themes[] = array(
				'stylesheet'  => (string) $stylesheet,
				'name'        => (string) $theme->get( 'Name' ),
				'version'     => (string) $theme->get( 'Version' ),
				'new_version' => isset( $offer['new_version'] ) ? (string) $offer['new_version'] : null,
			);
		}

		return array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * Site Health summary, recomputed at most every 6 hours because the
	 * checks are slow (one makes a request back to the site).
	 */
	private static function health(): ?array {
		$cached = get_transient( self::HEALTH_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = ( new HealthCheck() )->run_internal();
		if ( ! is_array( $result ) ) {
			return null;
		}

		$critical_items = array();
		foreach ( (array) $result['results'] as $item ) {
			if ( 'critical' === $item['status'] ) {
				$critical_items[] = $item['label'];
			}
		}

		$health = array(
			'checked_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'critical'       => (int) $result['summary']['critical'],
			'recommended'    => (int) $result['summary']['recommended'],
			'critical_items' => $critical_items,
		);
		set_transient( self::HEALTH_CACHE, $health, 6 * HOUR_IN_SECONDS );
		return $health;
	}
}
