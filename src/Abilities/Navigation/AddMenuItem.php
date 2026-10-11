<?php
/**
 * Adds an item to a classic navigation menu.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WP_Error;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class AddMenuItem extends AbilityBase {

	public function slug(): string {
		return 'add-menu-item';
	}

	public function label(): string {
		return __( 'Add menu item', 'wproadie' );
	}

	public function description(): string {
		return __( 'Adds a published item to a classic navigation menu. type "custom" links to a url; type "post_type" links to a post or page by object_id (object, e.g. "page", is optional and checked if given); type "taxonomy" links to a term by object_id (object, e.g. "category", optional). Title defaults to the linked object\'s title for post_type and taxonomy items. parent_id nests it under another item of the same menu. position is the 1-based order across the whole menu; other items move down to make room; omit it to append at the end. Returns the new item id and the menu\'s items. Returns an undo_id; pass it to undo-change to remove the item and restore the order.', 'wproadie' );
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
				'menu_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Menu id from list-menus.', 'wproadie' ),
				),
				'type'        => array(
					'type'        => 'string',
					'enum'        => array( 'custom', 'post_type', 'taxonomy' ),
					'default'     => 'custom',
					'description' => __( 'What the item links to.', 'wproadie' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Link text. Required for custom links; defaults to the object\'s title otherwise.', 'wproadie' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'Link URL, for type custom.', 'wproadie' ),
				),
				'object'      => array(
					'type'        => 'string',
					'description' => __( 'Post type (e.g. page) or taxonomy (e.g. category) of object_id. Optional; checked if given.', 'wproadie' ),
				),
				'object_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post or term id, for types post_type and taxonomy.', 'wproadie' ),
				),
				'parent_id'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'Parent menu item id in the same menu, or 0 for top level.', 'wproadie' ),
				),
				'position'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '1-based position across the whole menu. Omit to append.', 'wproadie' ),
				),
				'target'      => array(
					'type'        => 'string',
					'enum'        => array( '', '_blank' ),
					'default'     => '',
					'description' => __( '"_blank" opens the link in a new tab.', 'wproadie' ),
				),
				'classes'     => array(
					'type'        => 'string',
					'description' => __( 'Space-separated CSS classes.', 'wproadie' ),
				),
				'attr_title'  => array(
					'type'        => 'string',
					'description' => __( 'Title attribute of the link.', 'wproadie' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Item description (shown by some themes).', 'wproadie' ),
				),
			),
			'required'             => array( 'menu_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$menu_id = (int) $input['menu_id'];
		if ( ! is_nav_menu( $menu_id ) ) {
			return $this->error( 'menu_not_found', __( 'No menu with that id. Use list-menus to see the menus.', 'wproadie' ), 404 );
		}

		$type   = $input['type'] ?? 'custom';
		$target = $this->resolve_target( $type, $input['object'] ?? '', (int) ( $input['object_id'] ?? 0 ), $input['url'] ?? '' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		if ( 'custom' === $type && '' === $title ) {
			return $this->error( 'missing_title', __( 'A custom link needs a title.', 'wproadie' ) );
		}

		$parent_id = (int) ( $input['parent_id'] ?? 0 );
		if ( $parent_id && ! $this->item_in_menu( $parent_id, $menu_id ) ) {
			return $this->error( 'invalid_parent', __( 'parent_id is not an item of this menu.', 'wproadie' ) );
		}

		$args = array_merge(
			$target,
			array(
				'menu-item-title'       => $title,
				'menu-item-parent-id'   => $parent_id,
				'menu-item-position'    => 0,
				'menu-item-target'      => $input['target'] ?? '',
				'menu-item-classes'     => isset( $input['classes'] ) ? $this->classes( $input['classes'] ) : '',
				'menu-item-attr-title'  => isset( $input['attr_title'] ) ? sanitize_text_field( $input['attr_title'] ) : '',
				'menu-item-description' => isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '',
				'menu-item-status'      => 'publish',
			)
		);

		$item_id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $args ) );
		if ( is_wp_error( $item_id ) ) {
			return $this->error( 'menu_item_failed', $item_id->get_error_message() );
		}
		$item_id = (int) $item_id;
		$this->add_undo( Undo::post_created( $item_id ) );

		if ( isset( $input['position'] ) ) {
			$this->reorder( $menu_id, $item_id, (int) $input['position'] );
		}

		return array(
			'item_id' => $item_id,
			'menu_id' => $menu_id,
			'items'   => $this->summary( $menu_id ),
		);
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

	private function item_in_menu( int $item_id, int $menu_id ): bool {
		$post = get_post( $item_id );
		if ( ! $post || 'nav_menu_item' !== $post->post_type || 'trash' === $post->post_status ) {
			return false;
		}
		return is_object_in_term( $item_id, 'nav_menu', $menu_id ) === true;
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

	private function summary( int $menu_id ): array {
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			$items[] = array(
				'id'       => (int) $item->ID,
				'title'    => (string) $item->title,
				'parent'   => (int) $item->menu_item_parent,
				'position' => (int) $item->menu_order,
			);
		}
		return $items;
	}
}
