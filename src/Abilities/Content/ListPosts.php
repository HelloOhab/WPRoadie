<?php
/**
 * Lists posts, pages and other content types.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WP_Query;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListPosts extends AbilityBase {

	const ORDERBY = array(
		'date'       => 'date',
		'modified'   => 'modified',
		'title'      => 'title',
		'menu_order' => 'menu_order',
		'id'         => 'ID',
		'relevance'  => 'relevance',
	);

	public function slug(): string {
		return 'list-posts';
	}

	public function label(): string {
		return __( 'List posts', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists posts, pages or other content types (custom post types shown in wp-admin) with filters for status, search text, author and parent. Returns id, title, status, type, date, modified, author, link and a short excerpt for each, plus total and total_pages for paging. Use post_type "any" to search across every content type you can edit. Status "any" covers everything except the trash. Media files are listed by list-media instead. Use get-post for the full content of one item.', 'wproadie' );
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
				'post_type' => array(
					'type'        => 'string',
					'default'     => 'post',
					'description' => __( 'Post type slug, e.g. "post", "page" or a custom type, or "any" for every editable type.', 'wproadie' ),
				),
				'status'    => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'default'     => 'any',
					'description' => __( 'Only items with this status. "any" means every status except trash.', 'wproadie' ),
				),
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Search text matched against title, excerpt and content.', 'wproadie' ),
				),
				'author'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Only items by this user ID.', 'wproadie' ),
				),
				'parent'    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Only children of this post ID (0 for top-level items). Mostly useful for pages.', 'wproadie' ),
				),
				'page'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( 'Page of results, starting at 1.', 'wproadie' ),
				),
				'per_page'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => __( 'Items per page, up to 100.', 'wproadie' ),
				),
				'orderby'   => array(
					'type'        => 'string',
					'enum'        => array_keys( self::ORDERBY ),
					'default'     => 'date',
					'description' => __( 'Sort field. "relevance" only applies with search.', 'wproadie' ),
				),
				'order'     => array(
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
					'description' => __( 'Sort direction.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$post_type = (string) ( $input['post_type'] ?? 'post' );
		if ( 'any' === $post_type ) {
			$types = ContentHelper::editable_types();
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

		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$orderby  = self::ORDERBY[ $input['orderby'] ?? 'date' ];
		$search   = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( 'relevance' === $orderby && '' === $search ) {
			$orderby = 'date';
		}

		$args = array(
			'post_type'           => $types,
			'post_status'         => (string) ( $input['status'] ?? 'any' ),
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => $orderby,
			'order'               => strtoupper( (string) ( $input['order'] ?? 'desc' ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( isset( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'       => (int) $post->ID,
				'title'    => $post->post_title,
				'status'   => $post->post_status,
				'type'     => $post->post_type,
				'date'     => ContentHelper::date( $post->post_date ),
				'modified' => ContentHelper::date( $post->post_modified ),
				'author'   => (int) $post->post_author,
				'link'     => (string) get_permalink( $post ),
				'excerpt'  => ContentHelper::short_excerpt( $post, 20 ),
			);
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}
}
