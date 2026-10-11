<?php
/**
 * Installs a plugin from WordPress.org or a zip URL.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Plugin_Upgrader;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class InstallPlugin extends AbilityBase {

	public function slug(): string {
		return 'install-plugin';
	}

	public function label(): string {
		return __( 'Install plugin', 'wproadie' );
	}

	public function description(): string {
		return __( 'Installs a plugin from WordPress.org (slug) or from an https .zip URL (zip_url), and optionally activates it. Returns the plugin file (e.g. "classic-editor/classic-editor.php"), name, version and whether it is active. Fails if the plugin folder already exists (use update-plugin instead). The install itself cannot be undone: remove the plugin in wp-admin if needed. When activate is true the result has an undo_id; passing it to undo-change deactivates the plugin again (it stays installed). Only install plugins the user asked for.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function is_available(): bool {
		return Installer::filesystem_ready( WP_PLUGIN_DIR );
	}

	public function unavailable_reason(): string {
		return Installer::filesystem_reason();
	}

	/**
	 * Downloads from WordPress.org or the given URL.
	 */
	protected function annotations(): array {
		$annotations                  = parent::annotations();
		$annotations['openWorldHint'] = true;
		return $annotations;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => Installer::source_properties( 'plugin' ),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'install_plugins' );
	}

	protected function execute( array $input ) {
		Installer::load();
		if ( ! Installer::filesystem_ready( WP_PLUGIN_DIR ) ) {
			return Installer::filesystem_error();
		}

		$package = Installer::package( $input, 'plugin' );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$skin     = Installer::skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = Installer::quietly(
			static function () use ( $upgrader, $package ) {
				return $upgrader->install( $package, array( 'overwrite_package' => false ) );
			}
		);
		$outcome  = Installer::outcome( $result, $skin );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$file = (string) $upgrader->plugin_info();
		if ( '' === $file ) {
			return $this->error( 'plugin_not_found', __( 'The package installed, but WordPress found no plugin in it (no PHP file with a Plugin Name header).', 'wproadie' ), 500 );
		}

		$warning = '';
		if ( ! empty( $input['activate'] ) ) {
			$this->add_undo( Undo::plugin_state( $file ) );
			$activated = Installer::activate( $file );
			if ( is_wp_error( $activated ) ) {
				$warning = sprintf(
					/* translators: %s: error message. */
					__( 'Installed, but not activated. %s', 'wproadie' ),
					$activated->get_error_message()
				);
			} elseif ( ! empty( $activated['warning'] ) ) {
				$warning = $activated['warning'];
			}
		}

		$summary        = Installer::plugin_summary( $file );
		$summary['log'] = Installer::log( $skin );
		if ( '' !== $warning ) {
			$summary['warning'] = $warning;
		}
		return $summary;
	}
}
