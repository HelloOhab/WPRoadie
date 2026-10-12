<?php
/**
 * Clears Divi's generated CSS (and page caches Divi knows about) for one
 * page or the whole site.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ClearCache extends DiviAbility {

	public function slug(): string {
		return 'divi-clear-cache';
	}

	public function label(): string {
		return __( 'Clear Divi cache', 'wproadie' );
	}

	public function description(): string {
		return __( 'Clears Divi\'s generated static CSS files, and the cache of page-caching plugins Divi supports, for one page (id) or the whole site (no id). Use it when a page still looks old after a change, or after editing a global library item or Theme Builder layout. The divi-* editing tools already clear the cache of the page they change. Nothing to undo: Divi rebuilds the files on the next visit, so the first page load may be slower.', 'wproadie' );
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
					'description' => __( 'Page to clear. Leave out to clear the whole site.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		if ( empty( $input['id'] ) ) {
			// Site-wide: Divi's own "clear static CSS" needs manage_options.
			return current_user_can( 'manage_options' );
		}
		return parent::permission( $input );
	}

	protected function execute( array $input ) {
		$target = 'all';
		if ( ! empty( $input['id'] ) ) {
			$post = get_post( (int) $input['id'] );
			if ( ! $post ) {
				return Divi::error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
			}
			$target = (int) $post->ID;
		}
		$cleared = Divi::clear_cache( $target );
		$result  = array(
			'target'  => $target,
			'cleared' => $cleared,
		);
		if ( ! $cleared ) {
			$result['note'] = __( 'Divi\'s cache functions are not loaded on this request, so there was nothing to clear.', 'wproadie' );
		}
		return $result;
	}
}
