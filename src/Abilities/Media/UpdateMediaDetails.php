<?php
/**
 * Changes the alt text, title, caption or description of a media item.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Media;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateMediaDetails extends AbilityBase {

	public function slug(): string {
		return 'update-media-details';
	}

	public function label(): string {
		return __( 'Update media details', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes the alt text, title, caption and/or description of a media library item. Send only the fields to change; an empty string clears a field. Alt text should describe the image for screen readers. This does not change copies of the image already inserted into post content. Returns the item\'s details, plus an undo_id; pass it to undo-change to restore the previous values.', 'wproadie' );
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
				'id'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Attachment ID.', 'wproadie' ),
				),
				'alt'         => array(
					'type'        => 'string',
					'description' => __( 'Alt text (plain text).', 'wproadie' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Title.', 'wproadie' ),
				),
				'caption'     => array(
					'type'        => 'string',
					'description' => __( 'Caption.', 'wproadie' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Description.', 'wproadie' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$id = (int) $input['id'];
		return ! get_post( $id ) || current_user_can( 'edit_post', $id );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', __( 'No media item with that ID.', 'wproadie' ), 404 );
		}

		$map  = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		);
		$data = array();
		foreach ( $map as $field => $column ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $column ] = (string) $input[ $field ];
			}
		}
		$has_alt = array_key_exists( 'alt', $input );
		if ( ! $data && ! $has_alt ) {
			return $this->error( 'nothing_to_update', __( 'Send at least one of alt, title, caption or description.', 'wproadie' ) );
		}

		if ( $data ) {
			$this->add_undo( Undo::post( $post->ID ) );
		}
		if ( $has_alt ) {
			$this->add_undo( Undo::meta( 'post', $post->ID, '_wp_attachment_image_alt' ) );
		}

		if ( $data ) {
			$data['ID'] = $post->ID;
			$updated    = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		if ( $has_alt ) {
			$alt = trim( wp_strip_all_tags( (string) $input['alt'] ) );
			if ( '' === $alt ) {
				delete_post_meta( $post->ID, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $post->ID, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}
		}

		$post = get_post( $post->ID );
		return array_merge(
			ListMedia::item( $post ),
			array(
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
			)
		);
	}
}
