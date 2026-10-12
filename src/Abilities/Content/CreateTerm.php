<?php
/**
 * Creates a term (category, tag or custom taxonomy term).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class CreateTerm extends AbilityBase {

	public function slug(): string {
		return 'create-term';
	}

	public function label(): string {
		return __( 'Create term', 'wproadie' );
	}

	public function description(): string {
		return __( 'Creates a term in a taxonomy, e.g. a category ("category") or tag ("post_tag"). Fails with the existing term\'s ID if a term with the same name already exists at that level; check list-terms first. parent only applies to hierarchical taxonomies such as categories. Returns id, name, slug and parent, plus an undo_id; pass it to undo-change to delete the new term.', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	protected function annotations(): array {
		$annotations                = parent::annotations();
		$annotations['destructive'] = false;
		return $annotations;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Taxonomy slug, e.g. "category" or "post_tag".', 'wproadie' ),
				),
				'name'        => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Term name as shown on the site.', 'wproadie' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'URL slug. Made from the name if omitted.', 'wproadie' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Parent term ID, for hierarchical taxonomies only.', 'wproadie' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Term description. Some themes show it on archive pages.', 'wproadie' ),
				),
			),
			'required'             => array( 'taxonomy', 'name' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$taxonomy = get_taxonomy( (string) $input['taxonomy'] );
		if ( ! $taxonomy ) {
			// Reported as an invalid taxonomy by execute().
			return true;
		}
		return current_user_can( $taxonomy->cap->edit_terms ) || current_user_can( $taxonomy->cap->manage_terms );
	}

	protected function execute( array $input ) {
		$taxonomy = (string) $input['taxonomy'];
		$valid    = ContentHelper::check_taxonomy( $taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$args   = array();
		$parent = (int) ( $input['parent'] ?? 0 );
		if ( $parent ) {
			if ( ! is_taxonomy_hierarchical( $taxonomy ) ) {
				return $this->error( 'invalid_parent', __( 'That taxonomy is not hierarchical, so terms cannot have a parent.', 'wproadie' ) );
			}
			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				return $this->error( 'invalid_parent', __( 'The parent term does not exist in that taxonomy.', 'wproadie' ) );
			}
			$args['parent'] = $parent;
		}
		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$args['slug'] = (string) $input['slug'];
		}
		if ( isset( $input['description'] ) ) {
			$args['description'] = (string) $input['description'];
		}

		$created = wp_insert_term( wp_slash( (string) $input['name'] ), $taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $created ) ) {
			if ( 'term_exists' === $created->get_error_code() ) {
				return $this->error(
					'term_exists',
					sprintf(
						/* translators: %d: term ID. */
						__( 'A term with that name already exists (ID %d).', 'wproadie' ),
						(int) $created->get_error_data()
					),
					409
				);
			}
			return $created;
		}

		$term_id = (int) $created['term_id'];
		$this->add_undo( Undo::term_created( $term_id, $taxonomy ) );

		$term = get_term( $term_id, $taxonomy );
		$link = get_term_link( $term );
		return array(
			'id'       => $term_id,
			'taxonomy' => $taxonomy,
			'name'     => $term->name,
			'slug'     => $term->slug,
			'parent'   => (int) $term->parent,
			'link'     => is_wp_error( $link ) ? null : $link,
		);
	}
}
