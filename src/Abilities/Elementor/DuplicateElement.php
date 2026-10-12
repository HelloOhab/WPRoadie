<?php
/**
 * Duplicates an element on an Elementor page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class DuplicateElement extends ElementorAbility {

	public function slug(): string {
		return 'elementor-duplicate-element';
	}

	public function label(): string {
		return __( 'Duplicate Elementor element', 'wproadie' );
	}

	public function description(): string {
		return __( 'Copies an element (with everything inside it) and places the copy right after the original, like Elementor\'s Duplicate command. The copy gets fresh ids. Use it to add another card, testimonial or section like an existing one, then change the copy with elementor-update-element. Returns the copy\'s id and path, plus an undo_id; pass it to undo-change to remove the copy.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => self::post_id_schema(),
				'id'      => self::element_id_schema( __( 'Element id to copy.', 'wproadie' ) ),
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

		$taken     = Tree::ids( $tree );
		$copy      = Tree::refresh_ids( $found['element'], $taken );
		$index     = (int) end( $found['path'] );
		$parent_id = $found['parent'] ? (string) ( $found['parent']['id'] ?? '' ) : null;

		$result = $this->insert_and_save( $post, $tree, array( $copy ), $parent_id, $index + 1, $taken );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['original'] = Tree::node( $found['element'], $found['path'] );
		return $result;
	}
}
