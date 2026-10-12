<?php
/**
 * Lists the posts and pages built with Elementor.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WP_Query;
use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListPages extends ElementorAbility {

	public function slug(): string {
		return 'elementor-list-pages';
	}

	public function label(): string {
		return __( 'List Elementor pages', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists pages, posts and other items built with Elementor (Elementor templates are listed by elementor-list-templates). Filter by post_type, status or search text. Returns id, title, type, status, link, edit_url (opens the Elementor editor), modified and element_count for each, plus total and total_pages. Start here, then use elementor-get-page with an id to see its layout.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'default'     => 'any',
					'description' => __( 'Post type slug, e.g. "page" or "post", or "any" for every type you can edit.', 'wproadie' ),
				),
				'status'    => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private', 'future' ),
					'default'     => 'any',
					'description' => __( 'Only items with this status. "any" means every status except trash.', 'wproadie' ),
				),
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Search text matched against the title and the page text.', 'wproadie' ),
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'  => array(
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
		$post_type = (string) ( $input['post_type'] ?? 'any' );
		if ( 'any' === $post_type ) {
			$types = array_values( array_diff( ContentHelper::editable_types(), array( ElementorHelper::LIBRARY_TYPE ) ) );
		} else {
			$valid = ContentHelper::check_type( $post_type );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$object = get_post_type_object( $post_type );
			if ( ! current_user_can( $object->cap->edit_posts ) ) {
				return $this->error( 'forbidden', __( 'You cannot edit items of that post type.', 'wproadie' ), 403 );
			}
			$types = array( $post_type );
		}
		if ( ! $types ) {
			return $this->error( 'forbidden', __( 'You cannot edit any content types.', 'wproadie' ), 403 );
		}

		$per_page = (int) ( $input['per_page'] ?? 30 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'post_type'           => $types,
			'post_status'         => (string) ( $input['status'] ?? 'any' ),
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The only marker of an Elementor page.
			'meta_query'          => array(
				array(
					'key'   => ElementorHelper::EDIT_MODE_KEY,
					'value' => 'builder',
				),
			),
		);
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ElementorHelper::is_kit( (int) $post->ID ) ) {
				continue;
			}
			$item                  = ElementorHelper::page_info( $post );
			$item['element_count'] = ElementorHelper::element_count( (int) $post->ID );
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				$item['editable'] = false;
			}
			$items[] = $item;
		}

		return array(
			'items'             => $items,
			'total'             => (int) $query->found_posts,
			'total_pages'       => (int) $query->max_num_pages,
			'page'              => $page,
			'elementor_version' => ElementorHelper::version(),
		);
	}
}
