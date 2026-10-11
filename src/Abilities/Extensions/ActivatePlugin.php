<?php
/**
 * Activates an installed plugin.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ActivatePlugin extends AbilityBase {

	public function slug(): string {
		return 'activate-plugin';
	}

	public function label(): string {
		return __( 'Activate plugin', 'wproadie' );
	}

	public function description(): string {
		return __( 'Activates an installed plugin by its plugin file, e.g. "akismet/akismet.php" (get it from list-plugins). The main file is syntax-checked first; if activation fails (fatal error, missing requirements) the error is returned and the plugin stays inactive. Returns the plugin file, name, version and active state, plus a warning if the plugin printed output. Returns an undo_id; pass it to undo-change to deactivate it again. Activating an already active plugin changes nothing.', 'wproadie' );
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
		return current_user_can( 'activate_plugin', (string) ( $input['plugin'] ?? '' ) );
	}

	protected function execute( array $input ) {
		Installer::load();
		$file   = (string) $input['plugin'];
		$exists = Installer::check_plugin_exists( $file );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}

		if ( is_plugin_active( $file ) ) {
			$summary            = Installer::plugin_summary( $file );
			$summary['changed'] = false;
			return $summary;
		}

		$this->add_undo( Undo::plugin_state( $file ) );
		$activated = Installer::activate( $file );
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}

		$summary            = Installer::plugin_summary( $file );
		$summary['changed'] = true;
		if ( ! empty( $activated['warning'] ) ) {
			$summary['warning'] = $activated['warning'];
		}
		return $summary;
	}
}
