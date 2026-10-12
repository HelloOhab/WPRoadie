<?php
/**
 * Lists the Divi Library: saved layouts, sections, rows and modules.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WP_Query;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListLayouts extends DiviAbility {

	public function slug(): string {
		return 'divi-list-layouts';
	}

	public function label(): string {
		return __( 'List Divi Library layouts', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists items saved in the Divi Library (post type et_pb_layout): whole layouts, sections, rows and modules. Returns id, title, layout_type (layout, section, row, module…), global (true for global items: every page using one shows the library version, so editing the item changes all of them), categories, format (4 or 5), modules and modified. Use divi-get-page with an id to look inside, divi-insert-layout to put one on a page.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'layout_type' => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'layout', 'section', 'row', 'module', 'fullwidth_section', 'specialty_section', 'fullwidth_module' ),
					'default'     => 'any',
					'description' => __( 'Only this kind of library item.', 'wproadie' ),
				),
				'global'      => array(
					'type'        => 'boolean',
					'description' => __( 'true: only global items; false: only non-global ones.', 'wproadie' ),
				),
				'search'      => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 30,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		if ( ! Divi::editable_types( array( 'et_pb_layout' ) ) ) {
			return Divi::error( 'forbidden', __( 'You cannot edit Divi Library items.', 'wproadie' ), 403 );
		}
		$args = array(
			'post_type'              => 'et_pb_layout',
			'post_status'            => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page'         => (int) ( $input['per_page'] ?? 30 ),
			'paged'                  => (int) ( $input['page'] ?? 1 ),
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_term_cache' => true,
		);
		$tax  = array();
		$type = (string) ( $input['layout_type'] ?? 'any' );
		if ( 'any' !== $type && taxonomy_exists( 'layout_type' ) ) {
			$tax[] = array(
				'taxonomy' => 'layout_type',
				'field'    => 'slug',
				'terms'    => $type,
			);
		}
		if ( isset( $input['global'] ) && taxonomy_exists( 'scope' ) ) {
			$tax[] = array(
				'taxonomy' => 'scope',
				'field'    => 'slug',
				'terms'    => 'global',
				'operator' => $input['global'] ? 'IN' : 'NOT IN',
			);
		}
		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The library is small.
		}
		$search = trim( (string) ( $input['search'] ?? '' ) );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$format = Divi::format_of( $post );
			$doc    = $format && strlen( $post->post_content ) <= Divi::MAX_CONTENT ? new Document( (string) $post->post_content, $format ) : null;
			$item   = array(
				'id'          => (int) $post->ID,
				'title'       => $post->post_title,
				'status'      => $post->post_status,
				'layout_type' => self::term_slug( $post->ID, 'layout_type' ),
				'global'      => 'global' === self::term_slug( $post->ID, 'scope' ),
				'format'      => $format,
				'modules'     => $doc ? $doc->module_count() : 0,
				'modified'    => \WPRoadie\Abilities\Content\ContentHelper::date( $post->post_modified ),
			);
			if ( taxonomy_exists( 'layout_category' ) ) {
				$cats = wp_get_post_terms( $post->ID, 'layout_category', array( 'fields' => 'names' ) );
				if ( $cats && ! is_wp_error( $cats ) ) {
					$item['categories'] = $cats;
				}
			}
			$items[] = $item;
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => (int) ( $input['page'] ?? 1 ),
		);
	}

	/**
	 * First term slug of a post in a taxonomy, or "".
	 */
	public static function term_slug( int $post_id, string $taxonomy ): string {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$terms = get_the_terms( $post_id, $taxonomy );
		return ( $terms && ! is_wp_error( $terms ) ) ? (string) $terms[0]->slug : '';
	}
}
