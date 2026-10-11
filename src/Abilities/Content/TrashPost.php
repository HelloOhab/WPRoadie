<?php
/**
 * Moves a post, page or other content item to the trash.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class TrashPost extends AbilityBase {

	public function slug(): string {
		return 'trash-post';
	}

	public function label(): string {
		return __( 'Trash post', 'wproadie' );
	}

	public function description(): string {
		return __( 'Moves a post, page or other content item to the trash. It never deletes permanently: it refuses if the site has the trash turned off. WordPress empties the trash automatically after a number of days (usually 30). Returns id, previous_status and status, plus an undo_id; pass it to undo-change to restore the item with its previous status.', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the item to trash.', 'wproadie' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$id = (int) $input['id'];
		return ! get_post( $id ) || current_user_can( 'delete_post', $id );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}
		if ( 'attachment' === $post->post_type ) {
			return $this->error( 'wrong_tool', __( 'That ID is a media item. Deleting media is not supported.', 'wproadie' ) );
		}
		$valid = ContentHelper::check_type( $post->post_type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'trash' === $post->post_status ) {
			return $this->error( 'already_trashed', __( 'That item is already in the trash.', 'wproadie' ), 409 );
		}
		// With the trash off, wp_trash_post() deletes permanently.
		if ( ! EMPTY_TRASH_DAYS ) {
			return $this->error( 'trash_disabled', __( 'The trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so trashing would delete permanently. Refusing.', 'wproadie' ), 409 );
		}

		$previous = $post->post_status;
		$this->add_undo( Undo::post_trashed( $post->ID ) );

		$trashed = wp_trash_post( $post->ID );
		if ( ! $trashed ) {
			return $this->error( 'trash_failed', __( 'WordPress could not move the item to the trash.', 'wproadie' ), 500 );
		}

		return array(
			'id'              => (int) $post->ID,
			'title'           => $post->post_title,
			'previous_status' => $previous,
			'status'          => (string) get_post_status( $post->ID ),
			'days_in_trash'   => (int) EMPTY_TRASH_DAYS,
		);
	}
}
