<?php
/**
 * Deactivates a plugin.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class DeactivatePlugin extends AbilityBase {

	public function slug(): string {
		return 'deactivate-plugin';
	}

	public function label(): string {
		return __( 'Deactivate plugin', 'wproadie' );
	}

	public function description(): string {
		return __( 'Deactivates an active plugin by its plugin file, e.g. "akismet/akismet.php" (get it from list-plugins). The plugin stays installed and its settings are kept. WPRoadie and the MCP Adapter cannot be deactivated, since that would cut off this connection. Network-activated plugins on multisite are refused. Returns the plugin file, name, version and active state, and an undo_id; pass it to undo-change to reactivate it.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'plugin' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Plugin file relative to the plugins folder, e.g. "akismet/akismet.php".', 'wproadie' ),
				),
			),
			'required'             => array( 'plugin' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		return current_user_can( 'deactivate_plugin', (string) ( $input['plugin'] ?? '' ) );
	}

	protected function execute( array $input ) {
		Installer::load();
		$file = (string) $input['plugin'];
		if ( Guardrails::is_protected_plugin( $file ) ) {
			return Installer::protected_error();
		}
		$exists = Installer::check_plugin_exists( $file );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}
		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			return $this->error( 'network_active', __( 'That plugin is network-activated; deactivate it from the network admin.', 'wproadie' ), 400 );
		}

		if ( ! is_plugin_active( $file ) ) {
			$summary            = Installer::plugin_summary( $file );
			$summary['changed'] = false;
			return $summary;
		}

		$this->add_undo( Undo::plugin_state( $file ) );
		Installer::quietly(
			static function () use ( $file ) {
				deactivate_plugins( $file );
			}
		);

		$summary            = Installer::plugin_summary( $file );
		$summary['changed'] = true;
		if ( $summary['active'] ) {
			return $this->error( 'deactivation_failed', __( 'WordPress did not deactivate the plugin. Another plugin may be forcing it on.', 'wproadie' ), 500 );
		}
		return $summary;
	}
}
