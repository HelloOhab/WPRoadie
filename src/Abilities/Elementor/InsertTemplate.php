<?php
/**
 * Inserts a saved Elementor template's content into a page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class InsertTemplate extends ElementorAbility {

	public function slug(): string {
		return 'elementor-insert-template';
	}

	public function label(): string {
		return __( 'Insert Elementor template', 'wproadie' );
	}

	public function description(): string {
		return __( 'Copies the content of a saved Elementor template (from elementor-list-templates) into a page, like inserting it from the Elementor library. The copy gets fresh element ids and does not stay linked to the template. Place it with parent_id (omit for the top level of the page, which suits page, section and container templates) and position (0-based; omit for the end). Returns the inserted elements, plus an undo_id; pass it to undo-change to remove them again.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'     => self::post_id_schema(),
				'template_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Template id from elementor-list-templates.', 'wproadie' ),
				),
				'parent_id'   => self::element_id_schema( __( 'Element id of the container or column to insert into. Omit for the top level of the page.', 'wproadie' ) ),
				'position'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '0-based position among the parent\'s children (or the page\'s top-level elements). Omit to add at the end.', 'wproadie' ),
				),
			),
			'required'             => array( 'post_id', 'template_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$template = (int) ( $input['template_id'] ?? 0 );
		if ( $template && get_post( $template ) && ! current_user_can( 'edit_post', $template ) ) {
			return false;
		}
		return parent::permission( $input );
	}

	protected function execute( array $input ) {
		$template = get_post( (int) $input['template_id'] );
		if ( ! $template || ElementorHelper::LIBRARY_TYPE !== $template->post_type || 'trash' === $template->post_status || ElementorHelper::is_kit( (int) $template->ID ) ) {
			return $this->error( 'template_not_found', __( 'No Elementor template with that id. Use elementor-list-templates to find one.', 'wproadie' ), 404 );
		}
		$content = ElementorHelper::read_tree( (int) $template->ID );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( ! $content ) {
			return $this->error( 'empty_template', __( 'That template has no content to insert.', 'wproadie' ) );
		}
		$json = wp_json_encode( $content );
		if ( false === $json || strlen( $json ) > ElementorHelper::MAX_PAGE_BYTES ) {
			return $this->error( 'too_large', __( 'That template is too large to insert.', 'wproadie' ), 413 );
		}

		$loaded = $this->load( (int) $input['post_id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;
		if ( (int) $post->ID === (int) $template->ID ) {
			return $this->error( 'invalid_placement', __( 'A template cannot be inserted into itself.', 'wproadie' ) );
		}

		$taken    = Tree::ids( $tree );
		$elements = array();
		foreach ( $content as $element ) {
			// Existing content: its widget types are not checked, so a
			// template using an inactive add-on's widgets is copied intact.
			$element = Tree::normalize( $element );
			if ( is_wp_error( $element ) ) {
				return $this->error( 'invalid_template', __( 'The template\'s content is not valid Elementor data.', 'wproadie' ) );
			}
			$elements[] = Tree::refresh_ids( Tree::map_element_strings( $element, array( ElementorHelper::class, 'sanitize' ) ), $taken );
		}

		$result = $this->insert_and_save( $post, $tree, $elements, self::parent_from_input( $input ), self::position_from_input( $input ), $taken );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['template'] = array(
			'id'            => (int) $template->ID,
			'title'         => $template->post_title,
			'template_type' => ElementorHelper::template_type( (int) $template->ID ),
		);
		return $result;
	}
}
