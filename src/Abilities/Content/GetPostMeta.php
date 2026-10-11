<?php
/**
 * Reads custom fields (post meta) of a post.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetPostMeta extends AbilityBase {

	/**
	 * Maximum JSON size of one value when listing every key.
	 */
	const MAX_LIST_VALUE = 2000;

	/**
	 * Maximum JSON size of one value when reading a single key.
	 */
	const MAX_SINGLE_VALUE = 50000;

	public function slug(): string {
		return 'get-post-meta';
	}

	public function label(): string {
		return __( 'Get post meta', 'wproadie' );
	}

	public function description(): string {
		return sprintf(
			/* translators: %s: comma-separated list of meta keys. */
			__( 'Reads custom fields (post meta) of a post, page, media item or other content item. Without key, returns every visible key with its value (long values cut to a preview) and how many hidden keys were skipped. With key, returns that key\'s full value. Keys starting with "_" are internal and hidden, except: %s. A key with several rows returns values as a list.', 'wproadie' ),
			implode( ', ', ContentHelper::META_ALLOW_LIST )
		);
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
				'id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Post ID.', 'wproadie' ),
				),
				'key' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Meta key to read. Omit to list every visible key.', 'wproadie' ),
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
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}

		if ( isset( $input['key'] ) ) {
			$key = (string) $input['key'];
			if ( ! ContentHelper::meta_key_allowed( $key ) ) {
				return $this->error( 'protected_meta', __( 'That meta key is internal (protected) and cannot be read with this tool.', 'wproadie' ), 403 );
			}
			$exists = metadata_exists( 'post', $post->ID, $key );
			$values = $exists ? (array) get_post_meta( $post->ID, $key, false ) : array();

			$truncated = false;
			$result    = array(
				'id'     => (int) $post->ID,
				'key'    => $key,
				'exists' => $exists,
				'value'  => $values ? ContentHelper::meta_value( $values[0], self::MAX_SINGLE_VALUE, $truncated ) : null,
			);
			if ( count( $values ) > 1 ) {
				$result['values'] = ContentHelper::meta_value( $values, self::MAX_SINGLE_VALUE, $truncated );
			}
			if ( $truncated ) {
				$result['truncated'] = true;
			}
			return $result;
		}

		$meta      = array();
		$hidden    = 0;
		$truncated = false;
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			$key = (string) $key;
			if ( ! ContentHelper::meta_key_allowed( $key ) ) {
				++$hidden;
				continue;
			}
			$values       = array_map( 'maybe_unserialize', (array) $values );
			$meta[ $key ] = ContentHelper::meta_value( 1 === count( $values ) ? $values[0] : $values, self::MAX_LIST_VALUE, $truncated );
		}

		$result = array(
			'id'          => (int) $post->ID,
			'meta'        => (object) $meta,
			'hidden_keys' => $hidden,
		);
		if ( $truncated ) {
			$result['truncated'] = true;
		}
		return $result;
	}
}
