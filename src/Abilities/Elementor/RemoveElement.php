<?php
/**
 * Removes an element (and everything inside it) from an Elementor page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RemoveElement extends ElementorAbility {

	public function slug(): string {
		return 'elementor-remove-element';
	}

	public function label(): string {
		return __( 'Remove Elementor element', 'wproadie' );
	}

	public function description(): string {
		return __( 'Deletes one element from an Elementor page, including everything inside it (a container\'s widgets, a section\'s columns). Check what it holds with elementor-get-page first. Returns a summary of what was removed and how many elements, plus an undo_id; pass it to undo-change to put it back.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => self::post_id_schema(),
				'id'      => self::element_id_schema( __( 'Element id to remove.', 'wproadie' ) ),
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

		$found = Tree::find( $tree, (string) $input['id'] );
		if ( ! $found ) {
			return $this->element_not_found();
		}
		list( $new_tree, $removed ) = Tree::remove( $tree, (string) $input['id'] );

		$saved = $this->save_tree( $post, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result = array(
			'post_id'          => (int) $post->ID,
			'removed'          => Tree::node( $removed, $found['path'] ),
			'elements_removed' => Tree::count( array( $removed ) ),
			'parent_id'        => $found['parent'] ? (string) ( $found['parent']['id'] ?? '' ) : null,
		);
		if ( $found['parent'] && 'column' !== ( $found['parent']['elType'] ?? '' ) && empty( Tree::find( $new_tree, (string) $found['parent']['id'] )['element']['elements'] ) ) {
			$result['note'] = __( 'Its parent is now empty. Remove the parent too if it is no longer needed.', 'wproadie' );
		}
		return array_merge( $result, $saved );
	}
}
