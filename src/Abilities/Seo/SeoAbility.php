<?php
/**
 * Shared base for the SEO (Rank Math) abilities: group, availability and
 * resolving the post or term a call is about.
 *
 * Abstract, so the Registry never lists it as an ability.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Seo;

use WP_Error;
use WP_Post;
use WP_Term;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Content\ContentHelper;

defined( 'ABSPATH' ) || exit;

abstract class SeoAbility extends AbilityBase {

	public function group(): string {
		return 'seo';
	}

	public function is_available(): bool {
		return RankMath::is_active();
	}

	public function unavailable_reason(): string {
		return RankMath::unavailable_reason();
	}

	/**
	 * Input properties naming a post, or a term with its taxonomy.
	 *
	 * @return array<string, array>
	 */
	protected static function target_properties(): array {
		return array(
			'id'       => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post, page or other item ID. Or give term_id and taxonomy for a category, tag or other term.', 'wproadie' ),
			),
			'term_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Term ID (with taxonomy) for a category, tag or other term archive.', 'wproadie' ),
			),
			'taxonomy' => array(
				'type'        => 'string',
				'maxLength'   => 32,
				'description' => __( 'The term\'s taxonomy, e.g. "category" or "product_cat".', 'wproadie' ),
			),
		);
	}

	/**
	 * The post or term the call is about.
	 *
	 * @return array{0: string, 1: WP_Post|WP_Term}|WP_Error Object type ("post" or "term") and the object.
	 */
	protected function target( array $input ) {
		if ( ! empty( $input['term_id'] ) ) {
			$taxonomy = (string) ( $input['taxonomy'] ?? '' );
			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return RankMath::error( 'bad_taxonomy', __( 'Give the term\'s taxonomy (e.g. "category", "post_tag", "product_cat").', 'wproadie' ) );
			}
			$term = get_term( (int) $input['term_id'], $taxonomy );
			if ( ! $term instanceof WP_Term ) {
				return RankMath::error( 'not_found', __( 'No term with that ID in that taxonomy.', 'wproadie' ), 404 );
			}
			return array( 'term', $term );
		}
		if ( empty( $input['id'] ) ) {
			return RankMath::error( 'missing_target', __( 'Give id (a post) or term_id with taxonomy (a term).', 'wproadie' ) );
		}
		$post = get_post( (int) $input['id'] );
		if ( ! $post || ! in_array( $post->post_type, ContentHelper::content_types(), true ) ) {
			return RankMath::error( 'not_found', __( 'No post, page or other content item with that ID.', 'wproadie' ), 404 );
		}
		return array( 'post', $post );
	}

	/**
	 * Permission: the user can edit the post or term named. Missing
	 * targets pass so execute() can report them.
	 */
	protected function permission( array $input ): bool {
		if ( ! empty( $input['term_id'] ) ) {
			$taxonomy = get_taxonomy( (string) ( $input['taxonomy'] ?? '' ) );
			return ! $taxonomy || current_user_can( $taxonomy->cap->edit_terms );
		}
		$id = (int) ( $input['id'] ?? 0 );
		return ! $id || ! get_post( $id ) || current_user_can( 'edit_post', $id );
	}

	/**
	 * The item as the result describes it.
	 *
	 * @param string          $object_type "post" or "term".
	 * @param WP_Post|WP_Term $item        The post or term.
	 */
	protected static function describe( string $object_type, $item ): array {
		if ( 'term' === $object_type ) {
			return array(
				'term_id'  => (int) $item->term_id,
				'taxonomy' => $item->taxonomy,
				'name'     => $item->name,
				'link'     => (string) get_term_link( $item ),
			);
		}
		return array(
			'id'     => (int) $item->ID,
			'type'   => $item->post_type,
			'title'  => $item->post_title,
			'status' => $item->post_status,
			'link'   => (string) get_permalink( $item ),
		);
	}
}
