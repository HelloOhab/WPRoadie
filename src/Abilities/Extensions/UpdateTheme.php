<?php
/**
 * Updates a theme to the version WordPress.org offers.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Theme_Upgrader;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\PendingUpdates;

defined( 'ABSPATH' ) || exit;

final class UpdateTheme extends AbilityBase {

	public function slug(): string {
		return 'update-theme';
	}

	public function label(): string {
		return __( 'Update theme', 'wproadie' );
	}

	public function description(): string {
		return __( 'Updates one theme, by stylesheet (folder name, e.g. "twentytwentyfour"), to the newest version WordPress knows about. Checks for updates first, so it needs WordPress.org (or the theme\'s own update server) to be reachable; if not, the error says so. Any edits made directly to that theme\'s files are lost, which is why WPRoadie only writes to child themes. Returns the stylesheet, name, previous_version, version and active state. Cannot be undone.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function is_available(): bool {
		return Installer::filesystem_ready( get_theme_root() );
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
				'stylesheet' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Theme folder name, e.g. "twentytwentyfour".', 'wproadie' ),
				),
			),
			'required'             => array( 'stylesheet' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'update_themes' );
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
		$stylesheet = (string) $input['stylesheet'];
		if ( ! wp_get_theme( $stylesheet )->exists() ) {
			return $this->error( 'theme_not_found', __( 'No installed theme with that stylesheet (folder name).', 'wproadie' ), 404 );
		}
		if ( ! Installer::filesystem_ready( get_theme_root() ) ) {
			return Installer::filesystem_error();
		}

		$before  = Installer::theme_summary( $stylesheet );
		$warning = Installer::refresh_updates( 'theme' );
		$updates = get_site_transient( 'update_themes' );
		if ( ! is_object( $updates ) || empty( $updates->response[ $stylesheet ] ) ) {
			if ( '' !== $warning ) {
				return $this->error(
					'update_check_failed',
					sprintf(
						/* translators: %s: error message. */
						__( 'Could not check for theme updates: %s', 'wproadie' ),
						$warning
					),
					502
				);
			}
			return $this->error(
				'no_update',
				sprintf(
					/* translators: %s: version number. */
					__( 'WordPress knows of no update for this theme (installed version %s).', 'wproadie' ),
					$before['version']
				),
				409
			);
		}

		$skin     = Installer::skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = Installer::quietly(
			static function () use ( $upgrader, $stylesheet ) {
				return $upgrader->upgrade( $stylesheet );
			}
		);
		$outcome  = Installer::outcome( $result, $skin );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$summary                     = Installer::theme_summary( $stylesheet );
		$summary['previous_version'] = $before['version'];
		$summary['log']              = Installer::log( $skin );
		return $summary;
	}
}
