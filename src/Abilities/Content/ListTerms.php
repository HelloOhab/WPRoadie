<?php
/**
 * Lists terms (categories, tags, custom taxonomies).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListTerms extends AbilityBase {

	public function slug(): string {
		return 'list-terms';
	}

	public function label(): string {
		return __( 'List terms', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the terms of one taxonomy: "category" for categories, "post_tag" for tags, or a custom taxonomy. Returns id, name, slug, parent, count (items using it) and description for each, plus total, total_pages and available_taxonomies (every taxonomy you can use, so call it once to discover them). Includes unused terms unless hide_empty is true. Use the IDs with set-post-terms.', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'taxonomy'   => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Taxonomy slug, e.g. "category" or "post_tag".', 'wproadie' ),
				),
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Only terms whose name or slug contains this text.', 'wproadie' ),
				),
				'parent'     => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Only direct children of this term ID (0 for top-level terms).', 'wproadie' ),
				),
				'hide_empty' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Skip terms no item uses.', 'wproadie' ),
				),
				'orderby'    => array(
					'type'        => 'string',
					'enum'        => array( 'name', 'count', 'id', 'slug' ),
					'default'     => 'name',
					'description' => __( 'Sort field. count sorts most-used last unless order is desc.', 'wproadie' ),
				),
				'order'      => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'asc',
					'description' => __( 'Sort direction.', 'wproadie' ),
				),
				'page'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page of results, starting at 1.', 'wproadie' ),
				),
				'per_page'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 100,
					'description' => __( 'Terms per page, up to 200.', 'wproadie' ),
				),
			),
			'required'             => array( 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$taxonomy = (string) $input['taxonomy'];
		$valid    = ContentHelper::check_taxonomy( $taxonomy );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$per_page = (int) ( $input['per_page'] ?? 100 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => ! empty( $input['hide_empty'] ),
		);
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['search'] = trim( (string) $input['search'] );
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$total = wp_count_terms( $args );
		$total = is_wp_error( $total ) ? 0 : (int) $total;

		$terms = get_terms(
			array_merge(
				$args,
				array(
					'orderby' => 'id' === ( $input['orderby'] ?? 'name' ) ? 'term_id' : (string) ( $input['orderby'] ?? 'name' ),
					'order'   => strtoupper( (string) ( $input['order'] ?? 'asc' ) ),
					'number'  => $per_page,
					'offset'  => ( $page - 1 ) * $per_page,
				)
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'description' => wp_trim_words( $term->description, 30, '…' ),
			);
		}

		return array(
			'taxonomy'             => $taxonomy,
			'hierarchical'         => is_taxonomy_hierarchical( $taxonomy ),
			'items'                => $items,
			'total'                => $total,
			'total_pages'          => (int) ceil( $total / $per_page ),
			'available_taxonomies' => ContentHelper::available_taxonomies(),
		);
	}
}
