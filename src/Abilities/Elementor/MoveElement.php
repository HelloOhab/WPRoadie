<?php
/**
 * Moves an element to another place on the same Elementor page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class MoveElement extends ElementorAbility {

	public function slug(): string {
		return 'elementor-move-element';
	}

	public function label(): string {
		return __( 'Move Elementor element', 'wproadie' );
	}

	public function description(): string {
		return __( 'Moves an element (with everything inside it) to a new place on the same Elementor page: into another container, column or section with parent_id ("root" for the top level of the page; omit to stay in the current parent) at position (0-based index in the new parent, counted without the element itself; omit for the end). Use it to reorder sections or move a widget into another column. Elementor\'s nesting rules are checked. Returns the element\'s new path, plus an undo_id; pass it to undo-change to move it back.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => self::post_id_schema(),
				'id'        => self::element_id_schema( __( 'Element id to move.', 'wproadie' ) ),
				'parent_id' => self::element_id_schema( __( 'New parent element id, or "root" for the top level of the page. Omit to keep the current parent and only change the position.', 'wproadie' ) ),
				'position'  => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '0-based position in the new parent. Omit to move to the end.', 'wproadie' ),
				),
			),
			'required'             => array( 'post_id', 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = $this->load( (int) $input['post_id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;

		$id    = (string) $input['id'];
		$found = Tree::find( $tree, $id );
		if ( ! $found ) {
			return $this->element_not_found();
		}

		if ( array_key_exists( 'parent_id', $input ) ) {
			$parent_id = self::parent_from_input( $input );
		} else {
			$parent_id = $found['parent'] ? (string) ( $found['parent']['id'] ?? '' ) : null;
		}
		if ( null !== $parent_id ) {
			if ( $parent_id === $id || Tree::find( isset( $found['element']['elements'] ) && is_array( $found['element']['elements'] ) ? $found['element']['elements'] : array(), $parent_id ) ) {
				return $this->error( 'invalid_placement', __( 'An element cannot be moved into itself or into one of its own children.', 'wproadie' ) );
			}
			if ( ! Tree::find( $tree, $parent_id ) ) {
				return $this->error( 'parent_not_found', __( 'No element with that parent_id on this page. Use "root" for the top level.', 'wproadie' ), 404 );
			}
		}

		list( $without, $element ) = Tree::remove( $tree, $id );
		$new_tree                  = Tree::insert( $without, $parent_id, self::position_from_input( $input ), array( $element ) );
		if ( is_wp_error( $new_tree ) ) {
			return $new_tree;
		}
		if ( $new_tree === $tree ) {
			return array(
				'post_id' => (int) $post->ID,
				'moved'   => Tree::node( $found['element'], $found['path'] ),
				'changed' => false,
				'note'    => __( 'The element is already there, so nothing was saved.', 'wproadie' ),
			);
		}

		$saved = $this->save_tree( $post, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$moved = Tree::find( $new_tree, $id );
		return array_merge(
			array(
				'post_id'   => (int) $post->ID,
				'moved'     => Tree::node( $moved['element'], $moved['path'] ),
				'from_path' => implode( '.', $found['path'] ),
				'parent_id' => $parent_id,
				'changed'   => true,
			),
			$saved
		);
	}
}
