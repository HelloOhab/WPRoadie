<?php
/**
 * Full details of one post, page or other content item.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetPost extends AbilityBase {

	/**
	 * At most this many meta keys are returned.
	 */
	const MAX_META_KEYS = 50;

	/**
	 * Meta values longer than this (as JSON) are cut to a preview.
	 */
	const MAX_META_VALUE = 500;

	public function slug(): string {
		return 'get-post';
	}

	public function label(): string {
		return __( 'Get post', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns one post, page or other content item by ID with its raw content (block markup or HTML, exactly as stored), excerpt, status, slug, date, author, parent, menu order, page template, featured image ID, terms by taxonomy, link, edit link and its public custom fields (meta keys not starting with "_"; long values are cut to a preview, use get-post-meta for the full value). Use this before update-post so you edit the current content.', 'wproadie' );
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
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post ID.', 'wproadie' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$id = (int) $input['id'];
		// A missing post is reported as not found by execute().
		return ! get_post( $id ) || current_user_can( 'edit_post', $id );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}

		$meta      = array();
		$truncated = false;
		$count     = 0;
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			$key = (string) $key;
			if ( is_protected_meta( $key, 'post' ) ) {
				continue;
			}
			if ( ++$count > self::MAX_META_KEYS ) {
				$truncated = true;
				break;
			}
			$values       = array_map( 'maybe_unserialize', (array) $values );
			$meta[ $key ] = ContentHelper::meta_value( 1 === count( $values ) ? $values[0] : $values, self::MAX_META_VALUE, $truncated );
		}

		$thumbnail = (int) get_post_thumbnail_id( $post );

		$result = array(
			'id'             => (int) $post->ID,
			'type'           => $post->post_type,
			'status'         => $post->post_status,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'date'           => ContentHelper::date( $post->post_date ),
			'modified'       => ContentHelper::date( $post->post_modified ),
			'author'         => (int) $post->post_author,
			'parent'         => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'template'       => (string) get_page_template_slug( $post ),
			'featured_image' => $thumbnail ? $thumbnail : null,
			'has_password'   => '' !== $post->post_password,
			'terms'          => (object) ContentHelper::post_terms( $post ),
			'link'           => (string) get_permalink( $post ),
			'edit_link'      => (string) get_edit_post_link( $post, 'raw' ),
			'meta'           => (object) $meta,
		);
		if ( 'post' === $post->post_type ) {
			$result['sticky'] = is_sticky( $post->ID );
		}
		if ( $truncated ) {
			$result['meta_truncated'] = true;
		}
		return $result;
	}
}
