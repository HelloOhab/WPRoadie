<?php
/**
 * Lists media library items.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Media;

use WP_Post;
use WP_Query;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListMedia extends AbilityBase {

	public function slug(): string {
		return 'list-media';
	}

	public function label(): string {
		return __( 'List media', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists media library items, newest first, with filters for search text, MIME type ("image", "video", "audio" or a full type such as "application/pdf") and items not attached to any post. Returns id, title, url, mime, alt text, width and height (images and video), file size when known, date and attached_to (the post ID it was uploaded to, 0 for none), plus total and total_pages. Use the IDs with set-featured-image or update-media-details.', 'wproadie' );
	}

	public function group(): string {
		return 'media';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Search text matched against title, caption, description and file name.', 'wproadie' ),
				),
				'mime_type'  => array(
					'type'        => 'string',
					'description' => __( 'MIME type or prefix, e.g. "image", "video", "image/png" or "application/pdf".', 'wproadie' ),
				),
				'unattached' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only items not attached to any post.', 'wproadie' ),
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
					'maximum'     => 100,
					'default'     => 20,
					'description' => __( 'Items per page, up to 100.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'upload_files' );
	}

	protected function execute( array $input ) {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$args     = array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = trim( (string) $input['search'] );
		}
		if ( isset( $input['mime_type'] ) && '' !== trim( (string) $input['mime_type'] ) ) {
			$args['post_mime_type'] = trim( (string) $input['mime_type'] );
		}
		if ( ! empty( $input['unattached'] ) ) {
			$args['post_parent'] = 0;
		}

		$query = new WP_Query( $args );
		$items = array_map( array( self::class, 'item' ), $query->posts );

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Compact description of one attachment, shared with the other media
	 * tools.
	 */
	public static function item( WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( $post->ID );
		$meta = is_array( $meta ) ? $meta : array();

		return array(
			'id'          => (int) $post->ID,
			'title'       => $post->post_title,
			'url'         => (string) wp_get_attachment_url( $post->ID ),
			'mime'        => $post->post_mime_type,
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'filesize'    => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null,
			'date'        => ContentHelper::date( $post->post_date ),
			'attached_to' => (int) $post->post_parent,
		);
	}
}
