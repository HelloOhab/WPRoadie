<?php
/**
 * Installs a theme from WordPress.org or a zip URL.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Theme_Upgrader;
use WP_Theme;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class InstallTheme extends AbilityBase {

	public function slug(): string {
		return 'install-theme';
	}

	public function label(): string {
		return __( 'Install theme', 'wproadie' );
	}

	public function description(): string {
		return __( 'Installs a theme from WordPress.org (slug) or from an https .zip URL (zip_url), and optionally switches the site to it. A child theme\'s parent is installed from WordPress.org too when possible. Returns the theme stylesheet (folder name), name, version, template (parent) and whether it is active. Fails if the theme folder already exists (use update-theme instead). The install itself cannot be undone. When activate is true the result has an undo_id; passing it to undo-change switches back to the previous theme (the new one stays installed).', 'wproadie' );
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
			'properties'           => Installer::source_properties( 'theme' ),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		if ( ! empty( $input['activate'] ) && ! current_user_can( 'switch_themes' ) ) {
			return false;
		}
		return current_user_can( 'install_themes' );
	}

	protected function execute( array $input ) {
		Installer::load();
		if ( ! Installer::filesystem_ready( get_theme_root() ) ) {
			return Installer::filesystem_error();
		}

		$package = Installer::package( $input, 'theme' );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$skin     = Installer::skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = Installer::quietly(
			static function () use ( $upgrader, $package ) {
				return $upgrader->install( $package, array( 'overwrite_package' => false ) );
			}
		);
		$outcome  = Installer::outcome( $result, $skin );
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$theme = $upgrader->theme_info();
		if ( ! $theme instanceof WP_Theme ) {
			return $this->error( 'theme_not_found', __( 'The package installed, but WordPress found no theme in it (no style.css with a Theme Name header).', 'wproadie' ), 500 );
		}
		$stylesheet = $theme->get_stylesheet();

		$warning = '';
		if ( ! empty( $input['activate'] ) ) {
			wp_clean_themes_cache( false );
			$theme = wp_get_theme( $stylesheet );
			if ( $theme->errors() ) {
				$warning = sprintf(
					/* translators: %s: error message. */
					__( 'Installed, but not activated: %s', 'wproadie' ),
					Installer::plain( $theme->errors()->get_error_message() )
				);
			} else {
				$this->add_undo( Undo::theme_switch() );
				switch_theme( $stylesheet );
			}
		}

		$summary        = Installer::theme_summary( $stylesheet );
		$summary['log'] = Installer::log( $skin );
		if ( '' !== $warning ) {
			$summary['warning'] = $warning;
		}
		return $summary;
	}
}
