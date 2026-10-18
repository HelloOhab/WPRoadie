<?php
/**
 * Updates an existing post, page or other content item.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdatePost extends AbilityBase {

	public function slug(): string {
		return 'update-post';
	}

	public function label(): string {
		return __( 'Update post', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes an existing post, page or other content item. Send only the fields to change; content replaces the whole body, so read it with get-post first and send the full edited version. Setting status to publish, private or future publishes or schedules it. The previous version is kept as a WordPress revision. Media items are changed with update-media-details, custom fields with update-post-meta, terms with set-post-terms. Returns id, link, status and revision_id, plus an undo_id; pass it to undo-change to restore the previous version.', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		$properties = array_merge(
			array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the item to change.', 'wproadie' ),
				),
			),
			CreatePost::post_properties()
		);

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			// Reported as not found by execute().
			return true;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		$status = (string) ( $input['status'] ?? '' );
		if ( '' === $status || $status === $post->post_status || ! ContentHelper::needs_publish_cap( $status ) ) {
			return true;
		}
		$type = get_post_type_object( $post->post_type );
		return $type && current_user_can( $type->cap->publish_posts );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}
		if ( 'attachment' === $post->post_type ) {
			return $this->error( 'wrong_tool', __( 'That ID is a media item. Use update-media-details instead.', 'wproadie' ) );
		}
		$valid = ContentHelper::check_type( $post->post_type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$data = CreatePost::fields_to_post_data( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		unset( $data['edit_date'] );
		$settings = CreatePost::page_settings( $input );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if ( ! $data && ! $settings['set'] && ! $settings['delete'] ) {
			return $this->error( 'nothing_to_update', __( 'Send at least one field to change.', 'wproadie' ) );
		}
		if ( isset( $data['post_date'] ) ) {
			$data['edit_date'] = true;
		}
		if ( ! empty( $data['post_parent'] ) && (int) $data['post_parent'] === (int) $post->ID ) {
			return $this->error( 'invalid_parent', __( 'A post cannot be its own parent.', 'wproadie' ) );
		}

		$undo = Undo::post( $post->ID );
		$this->add_undo( $undo );

		if ( $data ) {
			$data['ID'] = $post->ID;
			$updated    = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		$applied = CreatePost::apply_page_settings(
			$post->ID,
			$settings,
			function ( array $record ) {
				$this->add_undo( $record );
			}
		);

		$post   = get_post( $post->ID );
		$result = array(
			'id'          => (int) $post->ID,
			'type'        => $post->post_type,
			'status'      => $post->post_status,
			'title'       => $post->post_title,
			'slug'        => $post->post_name,
			'link'        => (string) get_permalink( $post ),
			'revision_id' => isset( $undo['revision_id'] ) ? (int) $undo['revision_id'] : null,
			'revision'    => ! empty( $undo['revision_id'] )
				? __( 'The previous version was saved as a revision and can be restored from the editor\'s revision history.', 'wproadie' )
				: __( 'No revision was saved (revisions are off for this type, or nothing changed since the last one); undo-change still restores the previous fields.', 'wproadie' ),
		);
		if ( isset( $input['status'] ) && $post->post_status !== $input['status'] ) {
			$result['note'] = CreatePost::status_note( (string) $input['status'], $post->post_status );
		}
		if ( $applied ) {
			$result['page_settings'] = $applied;
		}
		return $result;
	}
}
