<?php
/**
 * Installed plugins with status and available updates.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\PendingUpdates;

defined( 'ABSPATH' ) || exit;

final class ListPlugins extends AbilityBase {

	public function slug(): string {
		return 'list-plugins';
	}

	public function label(): string {
		return __( 'List plugins', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists installed plugins with their file, name, version, author, whether they are active, and the newer version available if WordPress knows of an update (including updates premium plugins have announced in wp-admin). Includes must-use plugins. Read-only: it does not check for new updates itself.', 'wproadie' );
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'active', 'inactive', 'update_available' ),
					'default'     => 'all',
					'description' => __( 'Only return plugins with this status.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$filter  = $input['status'] ?? 'all';
		$updates = get_site_transient( 'update_plugins' );
		$pending = PendingUpdates::plugins();

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$active      = is_plugin_active( $file );
			$new_version = isset( $pending[ $file ]->new_version ) ? (string) $pending[ $file ]->new_version : null;

			if ( 'active' === $filter && ! $active ) {
				continue;
			}
			if ( 'inactive' === $filter && $active ) {
				continue;
			}
			if ( 'update_available' === $filter && null === $new_version ) {
				continue;
			}

			$plugins[] = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'author'           => wp_strip_all_tags( (string) $data['Author'] ),
				'active'           => $active,
				'network_active'   => is_multisite() && is_plugin_active_for_network( $file ),
				'update_available' => $new_version,
				'auto_update'      => in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true ),
			);
		}

		$must_use = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$must_use[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
			);
		}

		return array(
			'plugins'          => $plugins,
			'must_use_plugins' => $must_use,
			'updates_checked'  => ( is_object( $updates ) && isset( $updates->last_checked ) ) ? gmdate( 'c', (int) $updates->last_checked ) : null,
		);
	}
}
