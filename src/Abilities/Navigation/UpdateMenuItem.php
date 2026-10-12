<?php
/**
 * Changes an item of a classic navigation menu.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WP_Error;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateMenuItem extends AbilityBase {

	/**
	 * Meta keys wp_update_nav_menu_item() rewrites on every save.
	 */
	const META_KEYS = array( '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url' );

	public function slug(): string {
		return 'update-menu-item';
	}

	public function label(): string {
		return __( 'Update menu item', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes an item of a classic navigation menu. Pass the item id (from get-menu) and only the fields to change; everything else is kept. Changing type, object_id or url re-points the link (url applies to custom links; object_id to post_type/taxonomy items). An empty title on a post_type or taxonomy item means "use the linked object\'s title". position moves the item to that 1-based place in the whole menu and renumbers the rest. parent_id 0 moves it to the top level. Returns the item as saved. Returns an undo_id; pass it to undo-change to reverse this.', 'wproadie' );
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
				'item_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Menu item id from get-menu.', 'wproadie' ),
				),
				'type'        => array(
					'type'        => 'string',
					'enum'        => array( 'custom', 'post_type', 'taxonomy' ),
					'description' => __( 'What the item links to. Omit to keep.', 'wproadie' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Link text.', 'wproadie' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'Link URL, for custom links.', 'wproadie' ),
				),
				'object'      => array(
					'type'        => 'string',
					'description' => __( 'Post type or taxonomy of object_id. Optional; checked if given.', 'wproadie' ),
				),
				'object_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post or term id, for post_type and taxonomy items.', 'wproadie' ),
				),
				'parent_id'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Parent item id in the same menu, or 0 for top level.', 'wproadie' ),
				),
				'position'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '1-based position across the whole menu.', 'wproadie' ),
				),
				'target'      => array(
					'type'        => 'string',
					'enum'        => array( '', '_blank' ),
					'description' => __( '"_blank" opens the link in a new tab; "" in the same tab.', 'wproadie' ),
				),
				'classes'     => array(
					'type'        => 'string',
					'description' => __( 'Space-separated CSS classes (replaces the current ones).', 'wproadie' ),
				),
				'attr_title'  => array(
					'type'        => 'string',
					'description' => __( 'Title attribute of the link.', 'wproadie' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Item description.', 'wproadie' ),
				),
				'xfn'         => array(
					'type'        => 'string',
					'description' => __( 'Link relationship (rel attribute), e.g. "nofollow".', 'wproadie' ),
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
		$menus = wp_get_object_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $menus ) || empty( $menus ) ) {
			return $this->error( 'item_not_found', __( 'That menu item does not belong to a menu.', 'wproadie' ), 404 );
		}
		$menu_id = (int) $menus[0];
		$item    = wp_setup_nav_menu_item( $post );

		// Work out the link target, keeping what is there unless asked.
		$type        = $input['type'] ?? (string) $item->type;
		$type_change = $type !== (string) $item->type;
		if ( isset( $input['type'] ) || isset( $input['object_id'] ) || isset( $input['url'] ) || isset( $input['object'] ) ) {
			if ( ! in_array( $type, array( 'custom', 'post_type', 'taxonomy' ), true ) ) {
				return $this->error( 'unsupported_type', __( 'Only custom, post_type and taxonomy items can be re-pointed.', 'wproadie' ) );
			}
			$target = $this->resolve_target(
				$type,
				$input['object'] ?? ( $type_change ? '' : (string) $item->object ),
				(int) ( $input['object_id'] ?? ( $type_change ? 0 : (int) $item->object_id ) ),
				$input['url'] ?? ( 'custom' === $item->type ? (string) $item->url : '' )
			);
			if ( is_wp_error( $target ) ) {
				return $target;
			}
		} else {
			$target = array(
				'menu-item-type'      => (string) $item->type,
				'menu-item-object'    => (string) $item->object,
				'menu-item-object-id' => (int) $item->object_id,
				'menu-item-url'       => 'custom' === $item->type ? (string) $item->url : '',
			);
		}

		$parent_id = isset( $input['parent_id'] ) ? (int) $input['parent_id'] : (int) $item->menu_item_parent;
		if ( isset( $input['parent_id'] ) && $parent_id ) {
			$valid = $this->valid_parent( $parent_id, $item_id, $menu_id );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : (string) $post->post_title;
		if ( 'custom' === $target['menu-item-type'] && '' === $title ) {
			return $this->error( 'missing_title', __( 'A custom link needs a title.', 'wproadie' ) );
		}

		// wp_update_nav_menu_item() resets anything not passed, so pass it all.
		$args = array_merge(
			$target,
			array(
				'menu-item-db-id'       => $item_id,
				'menu-item-title'       => $title,
				'menu-item-parent-id'   => $parent_id,
				'menu-item-position'    => (int) $post->menu_order,
				'menu-item-target'      => $input['target'] ?? (string) $item->target,
				'menu-item-classes'     => isset( $input['classes'] ) ? $this->classes( $input['classes'] ) : implode( ' ', array_filter( (array) $item->classes ) ),
				'menu-item-xfn'         => isset( $input['xfn'] ) ? sanitize_text_field( $input['xfn'] ) : (string) $item->xfn,
				'menu-item-attr-title'  => isset( $input['attr_title'] ) ? sanitize_text_field( $input['attr_title'] ) : (string) $post->post_excerpt,
				'menu-item-description' => isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : (string) $post->post_content,
				'menu-item-status'      => (string) $post->post_status,
			)
		);

		$this->add_undo( Undo::post( $item_id ) );
		foreach ( self::META_KEYS as $key ) {
			$this->add_undo( Undo::meta( 'post', $item_id, $key ) );
		}

		$saved = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( $args ) );
		if ( is_wp_error( $saved ) ) {
			return $this->error( 'menu_item_failed', $saved->get_error_message() );
		}

		if ( isset( $input['position'] ) ) {
			$this->reorder( $menu_id, $item_id, (int) $input['position'] );
		}

		clean_post_cache( $item_id );
		$item = wp_setup_nav_menu_item( get_post( $item_id ) );

		return array(
			'menu_id' => $menu_id,
			'item'    => array(
				'id'          => $item_id,
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
			),
		);
	}

	/**
	 * @return true|WP_Error
	 */
	private function valid_parent( int $parent_id, int $item_id, int $menu_id ) {
		$post = get_post( $parent_id );
		if ( ! $post || 'nav_menu_item' !== $post->post_type || 'trash' === $post->post_status || true !== is_object_in_term( $parent_id, 'nav_menu', $menu_id ) ) {
			return $this->error( 'invalid_parent', __( 'parent_id is not an item of this menu.', 'wproadie' ) );
		}
		// Refuse to nest an item under itself or one of its own children.
		$seen = array();
		for ( $id = $parent_id; $id && ! isset( $seen[ $id ] ); $id = (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ) ) {
			if ( $id === $item_id ) {
				return $this->error( 'invalid_parent', __( 'An item cannot be nested under itself or one of its children.', 'wproadie' ) );
			}
			$seen[ $id ] = true;
		}
		return true;
	}

	/**
	 * Checks what the item links to and returns the matching
	 * wp_update_nav_menu_item() arguments.
	 *
	 * @return array|WP_Error
	 */
	private function resolve_target( string $type, string $kind, int $object_id, string $url ) {
		if ( 'custom' === $type ) {
			$url = esc_url_raw( trim( $url ) );
			if ( '' === $url ) {
				return $this->error( 'missing_url', __( 'A custom link needs a valid url.', 'wproadie' ) );
			}
			return array(
				'menu-item-type'      => 'custom',
				'menu-item-object'    => 'custom',
				'menu-item-object-id' => 0,
				'menu-item-url'       => $url,
			);
		}

		if ( ! $object_id ) {
			return $this->error( 'missing_object_id', __( 'object_id is required for post_type and taxonomy items.', 'wproadie' ) );
		}

		if ( 'post_type' === $type ) {
			$post = get_post( $object_id );
			if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				return $this->error( 'object_not_found', __( 'No post with that object_id.', 'wproadie' ), 404 );
			}
			if ( '' !== $kind && $kind !== $post->post_type ) {
				return $this->error( 'object_mismatch', __( 'object_id is not of the post type given in object.', 'wproadie' ) );
			}
			if ( ! is_post_type_viewable( $post->post_type ) ) {
				return $this->error( 'object_not_linkable', __( 'That post type has no public pages to link to.', 'wproadie' ) );
			}
			return array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => $post->post_type,
				'menu-item-object-id' => (int) $post->ID,
				'menu-item-url'       => '',
			);
		}

		$term = '' !== $kind ? get_term( $object_id, $kind ) : get_term( $object_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error( 'object_not_found', __( 'No term with that object_id (in the taxonomy given in object).', 'wproadie' ), 404 );
		}
		if ( ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return $this->error( 'object_not_linkable', __( 'That taxonomy has no public pages to link to.', 'wproadie' ) );
		}
		return array(
			'menu-item-type'      => 'taxonomy',
			'menu-item-object'    => $term->taxonomy,
			'menu-item-object-id' => (int) $term->term_id,
			'menu-item-url'       => '',
		);
	}

	private function classes( string $classes ): string {
		return implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $classes ) ) ) );
	}

	/**
	 * Moves an item to a 1-based position and renumbers the menu, saving
	 * an undo record for every item whose order changes.
	 */
	private function reorder( int $menu_id, int $item_id, int $position ): void {
		$ids = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			if ( (int) $item->ID !== $item_id ) {
				$ids[] = (int) $item->ID;
			}
		}
		$index = max( 0, min( $position - 1, count( $ids ) ) );
		array_splice( $ids, $index, 0, array( $item_id ) );

		foreach ( $ids as $i => $id ) {
			$order = $i + 1;
			$post  = get_post( $id );
			if ( $post && (int) $post->menu_order !== $order ) {
				$this->add_undo( Undo::post( $id ) );
				wp_update_post(
					array(
						'ID'         => $id,
						'menu_order' => $order,
					)
				);
			}
		}
	}
}
