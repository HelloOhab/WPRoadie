<?php
/**
 * Updates a plugin to the version WordPress.org offers.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Plugin_Upgrader;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\PendingUpdates;

defined( 'ABSPATH' ) || exit;

final class UpdatePlugin extends AbilityBase {

	public function slug(): string {
		return 'update-plugin';
	}

	public function label(): string {
		return __( 'Update plugin', 'wproadie' );
	}

	public function description(): string {
		return __( 'Updates one plugin, by plugin file (e.g. "akismet/akismet.php"), to the newest version WordPress knows about. Checks for updates first, so it needs WordPress.org (or the plugin\'s own update server) to be reachable; if not, the error says so. An active plugin is reactivated after the update. Returns the plugin file, name, previous_version, version and active state. Cannot be undone: take a backup first on important sites. WPRoadie and the MCP Adapter cannot be updated from here.', 'wproadie' );
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
		unset( $input );
		return current_user_can( 'update_plugins' );
	}

	protected function execute( array $input ) {
		return PendingUpdates::with_remembered(
			function () use ( $input ) {
				return $this->run( $input );
			}
		);
	}

	/**
	 * The update itself, with remembered premium updates visible to WordPress.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	private function run( array $input ) {
		Installer::load();
		$file = (string) $input['plugin'];
		if ( Guardrails::is_protected_plugin( $file ) ) {
			return Installer::protected_error();
		}
		$exists = Installer::check_plugin_exists( $file );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}
		if ( ! Installer::filesystem_ready( WP_PLUGIN_DIR ) ) {
			return Installer::filesystem_error();
		}

		$before  = Installer::plugin_summary( $file );
		$warning = Installer::refresh_updates( 'plugin' );
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->response[ $file ] ) ) {
			if ( '' !== $warning ) {
				return $this->error(
					'update_check_failed',
					sprintf(
						/* translators: %s: error message. */
						__( 'Could not check for plugin updates: %s', 'wproadie' ),
						$warning
					),
					502
				);
			}
			return $this->error(
				'no_update',
				sprintf(
					/* translators: %s: version number. */
					__( 'WordPress knows of no update for this plugin (installed version %s). Plugins not hosted on WordPress.org only update through their own updater, if they have one.', 'wproadie' ),
					$before['version']
				),
				409
			);
		}

		$skin     = Installer::skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = Installer::quietly(
			static function () use ( $upgrader, $file ) {
				return $upgrader->upgrade( $file );
			}
		);
		$outcome  = Installer::outcome( $result, $skin );

		// The upgrader deactivates an active plugin while it swaps the
		// files. Put it back, as wp-admin does, even if the update failed.
		$reactivation = array();
		if ( $before['active'] && ! is_plugin_active( $file ) && array_key_exists( $file, get_plugins() ) ) {
			$reactivation = Installer::activate( $file, true );
		}

		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$summary                     = Installer::plugin_summary( $file );
		$summary['previous_version'] = $before['version'];
		$summary['log']              = Installer::log( $skin );
		if ( is_wp_error( $reactivation ) ) {
			$summary['warning'] = sprintf(
				/* translators: %s: error message. */
				__( 'Updated, but the plugin could not be reactivated. %s', 'wproadie' ),
				$reactivation->get_error_message()
			);
		} elseif ( ! empty( $reactivation['warning'] ) ) {
			$summary['warning'] = $reactivation['warning'];
		}
		return $summary;
	}
}
