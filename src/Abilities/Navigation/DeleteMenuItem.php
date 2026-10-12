<?php
/**
 * Removes an item from a classic navigation menu.
 *
 * Menu items are moved to the trash rather than deleted, so the removal
 * can be undone like any trashed post; WordPress empties the trash after
 * EMPTY_TRASH_DAYS. Child items move up to the removed item's parent.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class DeleteMenuItem extends AbilityBase {

	public function slug(): string {
		return 'delete-menu-item';
	}

	public function label(): string {
		return __( 'Delete menu item', 'wproadie' );
	}

	public function description(): string {
		return __( 'Removes an item from a classic navigation menu. Its child items are not removed: they move up to the removed item\'s parent. The item is moved to the trash (hidden from the menu and the menu editor) rather than deleted, so it can be restored; WordPress empties the trash after its usual retention period. Returns an undo_id; pass it to undo-change to put the item and its children back. If the site has the trash turned off (EMPTY_TRASH_DAYS = 0) the item is deleted permanently and undoable is false.', 'wproadie' );
	}

	public function group(): string {
		return 'navigation';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'item_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Menu item id from get-menu.', 'wproadie' ),
				),
			),
			'required'             => array( 'item_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$item_id = (int) $input['item_id'];
		$post    = get_post( $item_id );
		if ( ! $post || 'nav_menu_item' !== $post->post_type || 'trash' === $post->post_status ) {
			return $this->error( 'item_not_found', __( 'No menu item with that id. Use get-menu to see item ids.', 'wproadie' ), 404 );
		}

		$parent_id = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );
		$undoable  = (bool) EMPTY_TRASH_DAYS;

		// Lift the children to this item's parent so they stay in the menu.
		$children = get_posts(
			array(
				'post_type'      => 'nav_menu_item',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_menu_item_menu_item_parent', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $item_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $children as $child_id ) {
			$this->add_undo( Undo::meta( 'post', (int) $child_id, '_menu_item_menu_item_parent' ) );
			update_post_meta( (int) $child_id, '_menu_item_menu_item_parent', (string) $parent_id );
		}

		if ( $undoable ) {
			$this->add_undo( Undo::post_trashed( $item_id ) );
			$done = wp_trash_post( $item_id );
		} else {
			$done = wp_delete_post( $item_id, true );
		}
		if ( ! $done ) {
			return $this->error( 'delete_failed', __( 'Could not remove the menu item.', 'wproadie' ), 500 );
		}

		return array(
			'item_id'        => $item_id,
			'trashed'        => $undoable,
			'undoable'       => $undoable,
			'children_moved' => array_map( 'intval', $children ),
			'new_parent'     => $parent_id,
		);
	}
}
