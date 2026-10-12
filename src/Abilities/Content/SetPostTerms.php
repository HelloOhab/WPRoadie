<?php
/**
 * Sets the terms (categories, tags...) of a post in one taxonomy.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class SetPostTerms extends AbilityBase {

	public function slug(): string {
		return 'set-post-terms';
	}

	public function label(): string {
		return __( 'Set post terms', 'wproadie' );
	}

	public function description(): string {
		return __( 'Sets the terms of a post in one taxonomy, e.g. its categories ("category") or tags ("post_tag"). terms is a list of term IDs, slugs or names. By default it replaces the current terms (an empty list removes them all); with append: true it adds to them. Unknown names fail unless create_missing is true, which creates them. Returns the post\'s terms in that taxonomy afterwards, plus an undo_id; pass it to undo-change to restore the previous terms (and delete any terms it created).', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post ID.', 'wproadie' ),
				),
				'taxonomy'       => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Taxonomy slug, e.g. "category" or "post_tag".', 'wproadie' ),
				),
				'terms'          => array(
					'type'        => 'array',
					'items'       => array(
						'type' => array( 'integer', 'string' ),
					),
					'maxItems'    => 100,
					'description' => __( 'Term IDs, slugs or names.', 'wproadie' ),
				),
				'append'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Add to the current terms instead of replacing them.', 'wproadie' ),
				),
				'create_missing' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Create terms for names that do not exist yet.', 'wproadie' ),
				),
			),
			'required'             => array( 'id', 'taxonomy', 'terms' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$id       = (int) $input['id'];
		$taxonomy = get_taxonomy( (string) $input['taxonomy'] );
		if ( ! get_post( $id ) || ! $taxonomy ) {
			// Reported by execute().
			return true;
		}
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return false;
		}
		return empty( $input['create_missing'] ) || current_user_can( $taxonomy->cap->edit_terms );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}
		$taxonomy = (string) $input['taxonomy'];
		$valid    = ContentHelper::check_taxonomy( $taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return $this->error(
				'invalid_taxonomy',
				sprintf(
					/* translators: 1: taxonomy, 2: post type. */
					__( 'Taxonomy "%1$s" is not used by post type "%2$s".', 'wproadie' ),
					$taxonomy,
					$post->post_type
				)
			);
		}

		// Resolve every reference before changing anything.
		$ids     = array();
		$missing = array();
		foreach ( (array) $input['terms'] as $ref ) {
			$term = ContentHelper::find_term( $ref, $taxonomy );
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			} elseif ( is_int( $ref ) || ctype_digit( (string) $ref ) || empty( $input['create_missing'] ) ) {
				$missing[] = (string) $ref;
			} elseif ( '' !== trim( (string) $ref ) ) {
				$ids[] = trim( (string) $ref );
			}
		}
		if ( $missing ) {
			return $this->error(
				'term_not_found',
				sprintf(
					/* translators: %s: comma-separated list of term references. */
					__( 'These terms do not exist: %s. Check list-terms, or set create_missing to create names.', 'wproadie' ),
					implode( ', ', $missing )
				),
				404
			);
		}

		$this->add_undo( Undo::terms( $post->ID, $taxonomy ) );

		// Create any new names, recording each for undo.
		foreach ( $ids as $i => $ref ) {
			if ( is_int( $ref ) ) {
				continue;
			}
			$created = wp_insert_term( wp_slash( $ref ), $taxonomy );
			if ( is_wp_error( $created ) ) {
				if ( 'term_exists' === $created->get_error_code() ) {
					$ids[ $i ] = (int) $created->get_error_data();
					continue;
				}
				return $created;
			}
			$ids[ $i ] = (int) $created['term_id'];
			$this->add_undo( Undo::term_created( (int) $created['term_id'], $taxonomy ) );
		}

		$set = wp_set_object_terms( $post->ID, array_values( array_unique( $ids ) ), $taxonomy, ! empty( $input['append'] ) );
		if ( is_wp_error( $set ) ) {
			return $set;
		}

		$terms = wp_get_object_terms( $post->ID, $taxonomy );
		return array(
			'id'       => (int) $post->ID,
			'taxonomy' => $taxonomy,
			'terms'    => is_wp_error( $terms ) ? array() : array_map( array( ContentHelper::class, 'term_brief' ), $terms ),
		);
	}
}
