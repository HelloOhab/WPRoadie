<?php
/**
 * Sets or deletes one custom field (post meta) of a post.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdatePostMeta extends AbilityBase {

	public function slug(): string {
		return 'update-post-meta';
	}

	public function label(): string {
		return __( 'Update post meta', 'wproadie' );
	}

	public function description(): string {
		return sprintf(
			/* translators: %s: comma-separated list of meta keys. */
			__( 'Sets one custom field (post meta) on a post, page or other item to value (a string, number, boolean, object or list; objects and lists are stored serialized), or removes the key with delete: true. Setting replaces every existing row of the key with the single new value. Keys starting with "_" are internal and refused, except: %s. Prefer set-featured-image for _thumbnail_id. Returns the stored value, plus an undo_id; pass it to undo-change to restore the previous value(s).', 'wproadie' ),
			implode( ', ', ContentHelper::META_ALLOW_LIST )
		);
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
				'id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post ID.', 'wproadie' ),
				),
				'key'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'description' => __( 'Meta key.', 'wproadie' ),
				),
				'value'  => array(
					'type'        => array( 'string', 'number', 'integer', 'boolean', 'object', 'array' ),
					'description' => __( 'New value. Required unless delete is true.', 'wproadie' ),
				),
				'delete' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Remove the key (every row of it) instead of setting it.', 'wproadie' ),
				),
			),
			'required'             => array( 'id', 'key' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		$id  = (int) $input['id'];
		$key = (string) $input['key'];
		if ( ! get_post( $id ) ) {
			// Reported as not found by execute().
			return true;
		}
		if ( in_array( $key, ContentHelper::META_ALLOW_LIST, true ) ) {
			// Core registers no auth callback for these protected keys, so
			// edit_post_meta would always refuse; editing the post is what
			// the editor requires to change them.
			return current_user_can( 'edit_post', $id );
		}
		if ( ! ContentHelper::meta_key_allowed( $key ) ) {
			// Refused with a clear message by execute().
			return current_user_can( 'edit_post', $id );
		}
		$cap = empty( $input['delete'] ) ? 'edit_post_meta' : 'delete_post_meta';
		return current_user_can( $cap, $id, $key );
	}

	protected function execute( array $input ) {
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}
		$key = (string) $input['key'];
		if ( ! ContentHelper::meta_key_allowed( $key ) ) {
			return $this->error( 'protected_meta', __( 'That meta key is internal (protected) and cannot be changed with this tool.', 'wproadie' ), 403 );
		}

		$delete = ! empty( $input['delete'] );
		if ( ! $delete && ! array_key_exists( 'value', $input ) ) {
			return $this->error( 'missing_value', __( 'Send value, or delete: true to remove the key.', 'wproadie' ) );
		}

		$existed = metadata_exists( 'post', $post->ID, $key );
		if ( $delete && ! $existed ) {
			return array(
				'id'      => (int) $post->ID,
				'key'     => $key,
				'deleted' => false,
				'note'    => __( 'The key did not exist, so nothing changed.', 'wproadie' ),
			);
		}

		$this->add_undo( Undo::meta( 'post', $post->ID, $key ) );

		if ( $delete ) {
			delete_post_meta( $post->ID, wp_slash( $key ) );
			return array(
				'id'      => (int) $post->ID,
				'key'     => $key,
				'deleted' => true,
			);
		}

		$value = $input['value'];
		if ( count( (array) get_post_meta( $post->ID, $key, false ) ) > 1 ) {
			// update_post_meta() would set every row to the value; keep one.
			delete_post_meta( $post->ID, wp_slash( $key ) );
		}
		$stored = update_post_meta( $post->ID, wp_slash( $key ), wp_slash( $value ) );
		if ( false === $stored && get_post_meta( $post->ID, $key, true ) != $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Stored scalars come back as strings.
			return $this->error( 'meta_failed', __( 'WordPress did not store the value (a plugin may have blocked it).', 'wproadie' ), 500 );
		}

		$truncated = false;
		$result    = array(
			'id'      => (int) $post->ID,
			'key'     => $key,
			'value'   => ContentHelper::meta_value( get_post_meta( $post->ID, $key, true ), 2000, $truncated ),
			'existed' => $existed,
		);
		return $result;
	}
}
