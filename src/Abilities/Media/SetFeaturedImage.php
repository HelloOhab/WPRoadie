<?php
/**
 * Sets or removes the featured image of a post.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Media;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class SetFeaturedImage extends AbilityBase {

	public function slug(): string {
		return 'set-featured-image';
	}

	public function label(): string {
		return __( 'Set featured image', 'wproadie' );
	}

	public function description(): string {
		return __( 'Sets the featured image (post thumbnail) of a post, page or other item to an image from the media library, or removes it when attachment_id is 0 or null. Find image IDs with list-media, or upload one with upload-media-from-url. Returns post_id, attachment_id and the image url, plus an undo_id; pass it to undo-change to restore the previous featured image.', 'wproadie' );
	}

	public function group(): string {
		return 'media';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post, page or other item.', 'wproadie' ),
				),
				'attachment_id' => array(
					'type'        => array( 'integer', 'null' ),
					'minimum'     => 0,
					'description' => __( 'Image attachment ID; 0 or null removes the featured image.', 'wproadie' ),
				),
			),
			'required'             => array( 'post_id', 'attachment_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$id = (int) $input['post_id'];
		return ! get_post( $id ) || current_user_can( 'edit_post', $id );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}
		if ( 'attachment' === $post->post_type || ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return $this->error( 'unsupported', __( 'That post type does not support featured images.', 'wproadie' ) );
		}

		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		if ( $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return $this->error( 'not_found', __( 'No media item with that attachment_id.', 'wproadie' ), 404 );
			}
			if ( ! wp_attachment_is_image( $attachment ) ) {
				return $this->error( 'not_an_image', __( 'That media item is not an image.', 'wproadie' ) );
			}
		}

		$previous = (int) get_post_thumbnail_id( $post );
		if ( $previous === $attachment_id ) {
			return $this->result( $post->ID, $attachment_id, $previous );
		}

		$this->add_undo( Undo::meta( 'post', $post->ID, '_thumbnail_id' ) );

		if ( $attachment_id ) {
			if ( ! set_post_thumbnail( $post, $attachment_id ) ) {
				return $this->error( 'failed', __( 'WordPress could not set the featured image.', 'wproadie' ), 500 );
			}
		} else {
			delete_post_thumbnail( $post );
		}

		return $this->result( $post->ID, $attachment_id, $previous );
	}

	private function result( int $post_id, int $attachment_id, int $previous ): array {
		return array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id ? $attachment_id : null,
			'url'           => $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : null,
			'previous_id'   => $previous ? $previous : null,
		);
	}
}
