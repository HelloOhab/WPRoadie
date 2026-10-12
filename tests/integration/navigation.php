<?php
/**
 * Navigation tools: menus, menu locations and widgets.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Log\Undo;

/**
 * Item ids of a menu in order, as get-menu returns them.
 */
function wproadie_nav_test_items( int $menu_id ): array {
	$menu = wproadie_ok( wproadie_call( 'get-menu', array( 'menu' => $menu_id ) ) );
	return $menu['items'];
}

wproadie_test(
	'menus: create, add items, reorder, get, list, undo',
	function () {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'WPRoadie nav test page',
			)
		);
		$menu_id = 0;
		try {
			$created = wproadie_ok( wproadie_call( 'create-menu', array( 'name' => 'WPRoadie test menu' ) ) );
			$menu_id = $created['id'];
			wproadie_assert( is_nav_menu( $menu_id ), 'menu exists' );
			wproadie_assert( ! empty( $created['undo_id'] ), 'create-menu returns undo_id' );

			wproadie_assert_error( wproadie_call( 'create-menu', array( 'name' => 'WPRoadie test menu' ) ), 'wproadie_menu_create_failed' );

			$home = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id' => $menu_id,
						'title'   => 'Home',
						'url'     => 'https://example.com/',
						'target'  => '_blank',
					)
				)
			);
			$page = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id'   => $menu_id,
						'type'      => 'post_type',
						'object_id' => $page_id,
						'parent_id' => $home['item_id'],
					)
				)
			);
			$cat  = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id'   => $menu_id,
						'type'      => 'taxonomy',
						'object'    => 'category',
						'object_id' => (int) get_option( 'default_category' ),
						'position'  => 1,
						'classes'   => 'one two',
					)
				)
			);

			$items = wproadie_nav_test_items( $menu_id );
			wproadie_assert_same( array( $cat['item_id'], $home['item_id'], $page['item_id'] ), array_column( $items, 'id' ), 'order after insert at position 1' );
			wproadie_assert_same( array( 1, 2, 3 ), array_column( $items, 'position' ) );
			wproadie_assert_same( 'WPRoadie nav test page', $items[2]['title'], 'post_type item takes the page title' );
			wproadie_assert_same( $home['item_id'], $items[2]['parent'] );
			wproadie_assert_same( '_blank', $items[1]['target'] );
			wproadie_assert_same( array( 'one', 'two' ), $items[0]['classes'] );
			wproadie_assert_same( 'publish', $items[0]['status'] );

			// Undo the positioned insert: the item goes and the order comes back.
			Undo::revert( $cat['undo_id'] );
			$items = wproadie_nav_test_items( $menu_id );
			wproadie_assert_same( array( $home['item_id'], $page['item_id'] ), array_column( $items, 'id' ), 'after undo of add' );
			wproadie_assert_same( array( 1, 2 ), array_column( $items, 'position' ) );

			$list = wproadie_ok( wproadie_call( 'list-menus' ) );
			$mine = array_values( wp_list_filter( $list['menus'], array( 'id' => $menu_id ) ) );
			wproadie_assert_same( 1, count( $mine ), 'list-menus lists the menu' );
			wproadie_assert_same( wp_is_block_theme(), $list['block_theme'] );

			// Bad input.
			wproadie_assert_error( wproadie_call( 'add-menu-item', array( 'menu_id' => $menu_id ) ), 'wproadie_missing_url' );
			wproadie_assert_error(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id'   => $menu_id,
						'type'      => 'post_type',
						'object'    => 'post',
						'object_id' => $page_id,
					)
				),
				'wproadie_object_mismatch'
			);
			wproadie_assert_error(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id'   => $menu_id,
						'title'     => 'x',
						'url'       => 'https://example.com/x',
						'parent_id' => 999999,
					)
				),
				'wproadie_invalid_parent'
			);
			wproadie_assert_error( wproadie_call( 'get-menu', array( 'menu' => 'no-such-menu-xyz' ) ), 'wproadie_menu_not_found' );
		} finally {
			if ( $menu_id ) {
				wp_delete_nav_menu( $menu_id );
			}
			wp_delete_post( $page_id, true );
		}
	}
);

wproadie_test(
	'create-menu undo deletes the menu',
	function () {
		$created = wproadie_ok( wproadie_call( 'create-menu', array( 'name' => 'WPRoadie undo menu' ) ) );
		try {
			Undo::revert( $created['undo_id'] );
			wproadie_assert( ! is_nav_menu( $created['id'] ), 'menu deleted by undo' );
		} finally {
			if ( is_nav_menu( $created['id'] ) ) {
				wp_delete_nav_menu( $created['id'] );
			}
		}
	}
);

wproadie_test(
	'update-menu-item keeps unspecified fields and undo restores everything',
	function () {
		$menu_id = wp_create_nav_menu( 'WPRoadie update menu' );
		try {
			$a      = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id'     => $menu_id,
						'title'       => 'A',
						'url'         => 'https://example.com/a',
						'target'      => '_blank',
						'classes'     => 'keep-me',
						'attr_title'  => 'Attr',
						'description' => 'Desc',
					)
				)
			);
			$b      = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id' => $menu_id,
						'title'   => 'B',
						'url'     => 'https://example.com/b',
					)
				)
			);
			$before = wproadie_nav_test_items( $menu_id );

			$updated = wproadie_ok(
				wproadie_call(
					'update-menu-item',
					array(
						'item_id' => $a['item_id'],
						'title'   => 'A2',
						'url'     => 'https://example.com/a2',
					)
				)
			);
			wproadie_assert_same( 'A2', $updated['item']['title'] );
			wproadie_assert_same( 'https://example.com/a2', $updated['item']['url'] );
			wproadie_assert_same( '_blank', $updated['item']['target'], 'target kept' );
			wproadie_assert_same( array( 'keep-me' ), $updated['item']['classes'], 'classes kept' );
			wproadie_assert_same( 'Attr', $updated['item']['attr_title'], 'attr_title kept' );
			wproadie_assert_same( 'Desc', $updated['item']['description'], 'description kept' );

			// Nest A under B and move it after B.
			$moved = wproadie_ok(
				wproadie_call(
					'update-menu-item',
					array(
						'item_id'   => $a['item_id'],
						'parent_id' => $b['item_id'],
						'position'  => 2,
					)
				)
			);
			wproadie_assert_same( $b['item_id'], $moved['item']['parent'] );
			wproadie_assert_same( array( $b['item_id'], $a['item_id'] ), array_column( wproadie_nav_test_items( $menu_id ), 'id' ) );

			// Cycles are refused.
			wproadie_assert_error(
				wproadie_call(
					'update-menu-item',
					array(
						'item_id'   => $b['item_id'],
						'parent_id' => $a['item_id'],
					)
				),
				'wproadie_invalid_parent'
			);

			Undo::revert( $moved['undo_id'] );
			Undo::revert( $updated['undo_id'] );
			$after = wproadie_nav_test_items( $menu_id );
			wproadie_assert_same( $before, $after, 'undo restores the items exactly' );
		} finally {
			wp_delete_nav_menu( $menu_id );
		}
	}
);

wproadie_test(
	'delete-menu-item trashes the item, lifts children, and undo restores',
	function () {
		$menu_id = wp_create_nav_menu( 'WPRoadie delete menu' );
		try {
			$parent = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id' => $menu_id,
						'title'   => 'Parent',
						'url'     => 'https://example.com/p',
					)
				)
			);
			$child  = wproadie_ok(
				wproadie_call(
					'add-menu-item',
					array(
						'menu_id'   => $menu_id,
						'title'     => 'Child',
						'url'       => 'https://example.com/c',
						'parent_id' => $parent['item_id'],
					)
				)
			);
			$before = wproadie_nav_test_items( $menu_id );

			$deleted = wproadie_ok( wproadie_call( 'delete-menu-item', array( 'item_id' => $parent['item_id'] ) ) );
			wproadie_assert_same( array( $child['item_id'] ), $deleted['children_moved'] );
			$items = wproadie_nav_test_items( $menu_id );
			wproadie_assert_same( array( $child['item_id'] ), array_column( $items, 'id' ), 'parent gone from menu' );
			wproadie_assert_same( 0, $items[0]['parent'], 'child lifted to top level' );
			wproadie_assert_error( wproadie_call( 'delete-menu-item', array( 'item_id' => $parent['item_id'] ) ), 'wproadie_item_not_found' );

			Undo::revert( $deleted['undo_id'] );
			wproadie_assert_same( $before, wproadie_nav_test_items( $menu_id ), 'undo restores item and nesting' );
		} finally {
			wp_delete_nav_menu( $menu_id );
		}
	}
);

wproadie_test(
	'assign-menu-location validates the location and undo restores it',
	function () {
		register_nav_menus( array( 'wproadie_test_loc' => 'WPRoadie test location' ) );
		$menu_id = wp_create_nav_menu( 'WPRoadie location menu' );
		$saved   = get_theme_mod( 'nav_menu_locations' );
		try {
			wproadie_assert_error(
				wproadie_call(
					'assign-menu-location',
					array(
						'location' => 'no_such_location',
						'menu_id'  => $menu_id,
					)
				),
				'wproadie_unknown_location'
			);
			$result    = wproadie_ok(
				wproadie_call(
					'assign-menu-location',
					array(
						'location' => 'wproadie_test_loc',
						'menu_id'  => $menu_id,
					)
				)
			);
			$locations = get_nav_menu_locations();
			wproadie_assert_same( $menu_id, (int) $locations['wproadie_test_loc'] );

			$list = wproadie_ok( wproadie_call( 'list-menus' ) );
			$mine = array_values( wp_list_filter( $list['menus'], array( 'id' => $menu_id ) ) );
			wproadie_assert_same( array( 'wproadie_test_loc' ), $mine[0]['locations'] );

			Undo::revert( $result['undo_id'] );
			$locations = get_nav_menu_locations();
			wproadie_assert( empty( $locations['wproadie_test_loc'] ), 'location unassigned by undo' );
		} finally {
			set_theme_mod( 'nav_menu_locations', $saved );
			if ( ! $saved ) {
				remove_theme_mod( 'nav_menu_locations' );
			}
			unregister_nav_menu( 'wproadie_test_loc' );
			wp_delete_nav_menu( $menu_id );
		}
	}
);

wproadie_test(
	'widgets: add block widget, list, remove to inactive, undo',
	function () {
		register_sidebar(
			array(
				'id'   => 'wproadie-test-sidebar',
				'name' => 'WPRoadie test sidebar',
			)
		);
		$saved_sidebars = get_option( 'sidebars_widgets' );
		$saved_blocks   = get_option( 'widget_block' );
		try {
			wproadie_assert_error(
				wproadie_call(
					'add-block-widget',
					array(
						'sidebar_id' => 'no-such-sidebar',
						'content'    => 'x',
					)
				),
				'wproadie_unknown_sidebar'
			);

			$first  = wproadie_ok(
				wproadie_call(
					'add-block-widget',
					array(
						'sidebar_id' => 'wproadie-test-sidebar',
						'content'    => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->',
					)
				)
			);
			$second = wproadie_ok(
				wproadie_call(
					'add-block-widget',
					array(
						'sidebar_id' => 'wproadie-test-sidebar',
						'content'    => '<p>Raw HTML</p>',
						'position'   => 1,
					)
				)
			);
			wproadie_assert( 0 === strpos( $second['content'], '<!-- wp:html -->' ), 'plain HTML wrapped in an HTML block' );

			$list = wproadie_ok( wproadie_call( 'list-widgets' ) );
			$area = array_values( wp_list_filter( $list['sidebars'], array( 'id' => 'wproadie-test-sidebar' ) ) );
			wproadie_assert_same( array( $second['widget_id'], $first['widget_id'] ), array_column( $area[0]['widgets'], 'id' ) );
			wproadie_assert( false !== strpos( $area[0]['widgets'][1]['content'], 'First' ), 'block content listed' );

			$removed  = wproadie_ok( wproadie_call( 'remove-widget', array( 'widget_id' => $first['widget_id'] ) ) );
			$sidebars = get_option( 'sidebars_widgets' );
			wproadie_assert_same( array( $second['widget_id'] ), $sidebars['wproadie-test-sidebar'] );
			wproadie_assert( in_array( $first['widget_id'], $sidebars['wp_inactive_widgets'], true ), 'moved to inactive' );
			wproadie_assert_error( wproadie_call( 'remove-widget', array( 'widget_id' => $first['widget_id'] ) ), 'wproadie_widget_inactive' );
			wproadie_assert_error( wproadie_call( 'remove-widget', array( 'widget_id' => 'block-999999' ) ), 'wproadie_widget_not_found' );

			Undo::revert( $removed['undo_id'] );
			$sidebars = get_option( 'sidebars_widgets' );
			wproadie_assert_same( array( $second['widget_id'], $first['widget_id'] ), $sidebars['wproadie-test-sidebar'], 'undo puts it back in place' );

			Undo::revert( $second['undo_id'] );
			Undo::revert( $first['undo_id'] );
			$blocks = get_option( 'widget_block' );
			wproadie_assert_same( $saved_blocks, $blocks, 'widget_block restored' );
			$sidebars = get_option( 'sidebars_widgets' );
			wproadie_assert( empty( $sidebars['wproadie-test-sidebar'] ), 'sidebar empty after undo' );
		} finally {
			update_option( 'sidebars_widgets', $saved_sidebars );
			if ( false === $saved_blocks ) {
				delete_option( 'widget_block' );
			} else {
				update_option( 'widget_block', $saved_blocks );
			}
			unregister_sidebar( 'wproadie-test-sidebar' );
		}
	}
);
