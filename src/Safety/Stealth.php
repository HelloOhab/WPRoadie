<?php
/**
 * Stealth mode: hides WPRoadie's interface from client admins.
 *
 * Only the interface is hidden. The MCP endpoint, logging and the kill
 * switch keep working, and WPRoadie users (administrators granted access)
 * still see everything.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Safety;

use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Stealth {

	public static function enabled(): bool {
		return (bool) Settings::get( 'stealth' );
	}

	/**
	 * Whether the current user should have WPRoadie hidden from them.
	 */
	public static function hides_from_current_user(): bool {
		return self::enabled() && ! Permission::can_connect();
	}

	public function hooks(): void {
		add_filter( 'all_plugins', array( $this, 'filter_plugins' ) );
		add_filter( 'debug_information', array( $this, 'filter_site_health' ) );
	}

	/**
	 * Removes WPRoadie and the MCP Adapter from the Plugins screen.
	 *
	 * @param array $plugins Plugin file => data.
	 * @return array
	 */
	public function filter_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || ! self::hides_from_current_user() ) {
			return $plugins;
		}
		foreach ( self::hidden_plugins() as $file ) {
			unset( $plugins[ $file ] );
		}
		return $plugins;
	}

	/**
	 * Removes WPRoadie and the MCP Adapter from Site Health > Info.
	 *
	 * @param array $info Debug information sections.
	 * @return array
	 */
	public function filter_site_health( $info ) {
		if ( ! is_array( $info ) || ! self::hides_from_current_user() ) {
			return $info;
		}
		foreach ( array( 'wp-plugins-active', 'wp-plugins-inactive' ) as $section ) {
			if ( empty( $info[ $section ]['fields'] ) ) {
				continue;
			}
			foreach ( $info[ $section ]['fields'] as $key => $field ) {
				$label = isset( $field['label'] ) ? (string) $field['label'] : '';
				if ( in_array( $label, array( 'WPRoadie', 'MCP Adapter' ), true ) ) {
					unset( $info[ $section ]['fields'][ $key ] );
				}
			}
		}
		return $info;
	}

	/**
	 * @return string[] Plugin files hidden in stealth mode.
	 */
	public static function hidden_plugins(): array {
		$hidden = array( plugin_basename( WPROADIE_FILE ), 'mcp-adapter/mcp-adapter.php' );
		return array_values( (array) apply_filters( 'wproadie_stealth_hidden_plugins', $hidden ) );
	}
}
