<?php
/**
 * Lists saved Elementor templates (the Elementor library).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WP_Query;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListTemplates extends ElementorAbility {

	public function slug(): string {
		return 'elementor-list-templates';
	}

	public function label(): string {
		return __( 'List Elementor templates', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the saved templates in the Elementor library: pages, sections, containers and, with Elementor Pro, headers, footers, popups and other theme parts. Filter by template_type or search text. Returns id, title, template_type, status, element_count, modified and edit_url for each. Insert a template\'s content into a page with elementor-insert-template; a template\'s own content can be read and edited with elementor-get-page and the element tools, using its id as post_id (changing a header or footer template changes it on every page that uses it).', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_type' => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_-]+$',
					'description' => __( 'Only this template type, e.g. "page", "section", "container", "header", "footer", "popup".', 'wproadie' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search text matched against the title.', 'wproadie' ),
				),
				'page'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 50,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$type = get_post_type_object( ElementorHelper::LIBRARY_TYPE );
		if ( ! $type ) {
			return array(
				'items' => array(),
				'total' => 0,
				'note'  => __( 'The Elementor template library is not registered on this site.', 'wproadie' ),
			);
		}
		if ( ! current_user_can( $type->cap->edit_posts ) ) {
			return $this->error( 'forbidden', __( 'You cannot edit Elementor templates.', 'wproadie' ), 403 );
		}

		if ( ! empty( $input['template_type'] ) && 'kit' !== $input['template_type'] ) {
			$meta = array(
				array(
					'key'   => ElementorHelper::TEMPLATE_TYPE_KEY,
					'value' => (string) $input['template_type'],
				),
			);
		} else {
			// Kits hold the global site settings; elementor-get-kit covers them.
			$meta = array(
				'relation' => 'OR',
				array(
					'key'     => ElementorHelper::TEMPLATE_TYPE_KEY,
					'value'   => 'kit',
					'compare' => '!=',
				),
				array(
					'key'     => ElementorHelper::TEMPLATE_TYPE_KEY,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$page = (int) ( $input['page'] ?? 1 );
		$args = array(
			'post_type'           => ElementorHelper::LIBRARY_TYPE,
			'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'      => (int) ( $input['per_page'] ?? 50 ),
			'paged'               => $page,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Elementor keeps the template type in meta.
			'meta_query'          => $meta,
		);
		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$item                  = ElementorHelper::page_info( $post );
			$item['element_count'] = ElementorHelper::element_count( (int) $post->ID );
			unset( $item['link'] );
			$items[] = $item;
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}
}
