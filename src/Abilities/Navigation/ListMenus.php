<?php
/**
 * Classic navigation menus, theme menu locations and block navigation posts.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListMenus extends AbilityBase {

	public function slug(): string {
		return 'list-menus';
	}

	public function label(): string {
		return __( 'List menus', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the classic navigation menus (id, name, slug, item count and the theme locations each is assigned to), the menu locations the active theme registers with the menu assigned to each, and whether the active theme is a block theme. Block themes do not use classic menus or locations: their menus are wp_navigation posts, also listed here (id, title, status); edit those with the content tools (get-post / update-post on the wp_navigation post). Use get-menu to see a classic menu\'s items.', 'wproadie' );
	}

	public function group(): string {
		return 'navigation';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$assigned   = get_nav_menu_locations();
		$registered = get_registered_nav_menus();

		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$locations = array();
			foreach ( $assigned as $location => $menu_id ) {
				if ( (int) $menu_id === (int) $menu->term_id ) {
					$locations[] = $location;
				}
			}
			$menus[] = array(
				'id'         => (int) $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'item_count' => (int) $menu->count,
				'locations'  => $locations,
			);
		}

		$locations = array();
		foreach ( $registered as $location => $description ) {
			$menu_id     = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
			$locations[] = array(
				'location'    => $location,
				'description' => $description,
				'menu_id'     => $menu_id && is_nav_menu( $menu_id ) ? $menu_id : null,
			);
		}

		$navigation = array();
		$posts      = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $post ) {
			$navigation[] = array(
				'id'     => (int) $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			);
		}

		$block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$result      = array(
			'menus'              => $menus,
			'locations'          => $locations,
			'block_theme'        => $block_theme,
			'navigation_posts'   => $navigation,
			'supports_locations' => (bool) current_theme_supports( 'menus' ) || ! empty( $registered ),
		);

		if ( $block_theme ) {
			$result['note'] = __( 'The active theme is a block theme. Its site navigation comes from Navigation blocks that reference wp_navigation posts (listed in navigation_posts), not from classic menus or locations. Edit a wp_navigation post\'s block content with the content tools (update-post). Classic menus here only show up where a theme or plugin renders them.', 'wproadie' );
		}

		return $result;
	}
}
