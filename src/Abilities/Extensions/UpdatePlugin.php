<?php
/**
 * Updates a plugin to the version WordPress.org offers.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

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
		return __( 'Updates one plugin, by plugin file (e.g. "akismet/akismet.php"), to the newest version WordPress knows about. Checks for updates first, so it needs WordPress.org (or the plugin\'s own update server) to be reachable; if not, the error says so. An active plugin is reactivated after the update. Returns the plugin file, name, previous_version, version and active state. The previous version is backed up first; if the update leaves the site erroring, it is put back automatically (update_rolled_back). Returns an undo_id that restores the previous version. Allowed on live sites (through HQ, a person approves it first). For several updates at once, use apply-updates. WPRoadie and the MCP Adapter cannot be updated from here.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_MAINTAIN;
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

		$run = SafeUpdate::run(
			array(
				array(
					'kind' => SafeUpdate::KIND_PLUGIN,
					'id'   => $file,
					'name' => $before['name'],
					'from' => $before['version'],
					'to'   => (string) $updates->response[ $file ]->new_version,
				),
			),
			true,
			function ( array $record ) {
				$this->add_undo( $record );
			}
		);
		return $this->report( $run, Installer::plugin_summary( $file ), $before['version'] );
	}

	/**
	 * One item's SafeUpdate result as this tool's output: the plugin's
	 * summary plus previous_version, status, message, log and health.
	 *
	 * @return array|\WP_Error
	 */
	private function report( array $run, array $summary, string $previous ) {
		$result = $run['results'][0];
		if ( 'updated' !== $result['status'] ) {
			return $this->error(
				'rolled_back' === $result['status'] ? 'update_rolled_back' : 'update_failed',
				$result['message'],
				500
			);
		}
		$summary['previous_version'] = $previous;
		$summary['status']           = $result['status'];
		$summary['message']          = $result['message'];
		$summary['log']              = $result['log'];
		$summary['health']           = $run['health'];
		return $summary;
	}
}
