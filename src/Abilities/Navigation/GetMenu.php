<?php
/**
 * One classic menu and its items.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetMenu extends AbilityBase {

	public function slug(): string {
		return 'get-menu';
	}

	public function label(): string {
		return __( 'Get menu', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns a classic navigation menu and its items as a flat list in display order. Each item has id, title, url, type (custom, post_type, taxonomy, post_type_archive), object (e.g. page, category), object_id, parent (0 for top level, otherwise the parent item id), position (menu order across the whole menu, starting at 1), target, classes, attr_title, description, xfn and status. Build the tree from the parent ids. Use list-menus to find the menu id.', 'wproadie' );
	}

	public function group(): string {
		return 'navigation';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'menu' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Menu id, slug or name.', 'wproadie' ),
				),
			),
			'required'             => array( 'menu' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$menu = wp_get_nav_menu_object( $input['menu'] );
		if ( ! $menu ) {
			return $this->error( 'menu_not_found', __( 'No menu with that id, slug or name. Use list-menus to see the menus.', 'wproadie' ), 404 );
		}

		$items = wp_get_nav_menu_items(
			$menu->term_id,
			array(
				'post_status' => 'any',
				'orderby'     => 'menu_order',
			)
		);

		$list = array();
		foreach ( (array) $items as $item ) {
			$list[] = array(
				'id'          => (int) $item->ID,
				'title'       => (string) $item->title,
				'url'         => (string) $item->url,
				'type'        => (string) $item->type,
				'object'      => (string) $item->object,
				'object_id'   => (int) $item->object_id,
				'parent'      => (int) $item->menu_item_parent,
				'position'    => (int) $item->menu_order,
				'target'      => (string) $item->target,
				'classes'     => array_values( array_filter( (array) $item->classes ) ),
				'attr_title'  => (string) $item->attr_title,
				'description' => (string) $item->description,
				'xfn'         => (string) $item->xfn,
				'status'      => (string) $item->post_status,
				'invalid'     => ! empty( $item->_invalid ),
			);
		}

		$locations = array();
		foreach ( get_nav_menu_locations() as $location => $menu_id ) {
			if ( (int) $menu_id === (int) $menu->term_id ) {
				$locations[] = $location;
			}
		}

		return array(
			'id'        => (int) $menu->term_id,
			'name'      => $menu->name,
			'slug'      => $menu->slug,
			'locations' => $locations,
			'items'     => $list,
		);
	}
}
