<?php
/**
 * Software versions and server environment.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class Versions extends AbilityBase {

	public function slug(): string {
		return 'versions';
	}

	public function label(): string {
		return __( 'Versions and environment', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns WordPress, PHP, database and web server versions, the PHP memory and upload limits, whether a WordPress core update is available, and WPRoadie and MCP Adapter versions.', 'wproadie' );
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	protected function execute( array $input ) {
		global $wpdb;

		$core_update = null;
		$updates     = get_site_transient( 'update_core' );
		if ( is_object( $updates ) && ! empty( $updates->updates ) ) {
			foreach ( $updates->updates as $offer ) {
				if ( isset( $offer->response ) && 'upgrade' === $offer->response ) {
					$core_update = (string) $offer->current;
					break;
				}
			}
		}

		return array(
			'wordpress'        => get_bloginfo( 'version' ),
			'core_update'      => $core_update,
			'php'              => PHP_VERSION,
			'database'         => array(
				'server'  => $wpdb->db_server_info(),
				'version' => $wpdb->db_version(),
				'charset' => $wpdb->charset,
			),
			'web_server'       => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null,
			'memory_limit'     => WP_MEMORY_LIMIT,
			'php_memory_limit' => (string) ini_get( 'memory_limit' ),
			'max_upload'       => size_format( wp_max_upload_size() ),
			'max_execution'    => (int) ini_get( 'max_execution_time' ),
			'debug'            => array(
				'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			),
			'wproadie'         => WPROADIE_VERSION,
			'mcp_adapter'      => defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : null,
		);
	}
}
