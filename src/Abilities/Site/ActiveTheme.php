<?php
/**
 * Active theme details, including the parent theme for child themes.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WP_Theme;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ActiveTheme extends AbilityBase {

	public function slug(): string {
		return 'active-theme';
	}

	public function label(): string {
		return __( 'Active theme', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns the active theme: name, version, author, whether it is a block theme, its parent theme if it is a child theme, registered menu locations and widget areas. Also lists the other installed themes.', 'wproadie' );
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	protected function execute( array $input ) {
		global $wp_registered_sidebars;

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$installed = array();
		foreach ( wp_get_themes() as $stylesheet => $other ) {
			$installed[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $other->get( 'Name' ),
				'version'    => $other->get( 'Version' ),
				'active'     => $stylesheet === $theme->get_stylesheet(),
			);
		}

		$sidebars = array();
		foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
			$sidebars[] = array(
				'id'   => $id,
				'name' => $sidebar['name'] ?? $id,
			);
		}

		return array(
			'theme'          => $this->describe( $theme ),
			'parent'         => $parent instanceof WP_Theme ? $this->describe( $parent ) : null,
			'is_child_theme' => $parent instanceof WP_Theme,
			'is_block_theme' => wp_is_block_theme(),
			'menu_locations' => get_registered_nav_menus(),
			'widget_areas'   => $sidebars,
			'installed'      => $installed,
		);
	}

	private function describe( WP_Theme $theme ): array {
		return array(
			'name'         => $theme->get( 'Name' ),
			'stylesheet'   => $theme->get_stylesheet(),
			'template'     => $theme->get_template(),
			'version'      => $theme->get( 'Version' ),
			'author'       => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'theme_uri'    => $theme->get( 'ThemeURI' ),
			'requires_wp'  => $theme->get( 'RequiresWP' ),
			'requires_php' => $theme->get( 'RequiresPHP' ),
		);
	}
}
