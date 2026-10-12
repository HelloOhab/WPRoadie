<?php
/**
 * Clears Elementor's generated CSS so it is rebuilt.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RegenerateCss extends ElementorAbility {

	public function slug(): string {
		return 'elementor-regenerate-css';
	}

	public function label(): string {
		return __( 'Regenerate Elementor CSS', 'wproadie' );
	}

	public function description(): string {
		return __( 'Deletes Elementor\'s generated CSS (and element cache) so it is rebuilt on the next page view, like Elementor > Tools > Regenerate CSS & Data. Pass post_id for one page, or nothing for the whole site. Use it when a page looks unstyled or out of date; the Elementor editing tools already do this for the pages they change. Changes no content and has nothing to undo; a page cache plugin or CDN may need purging separately.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Only this page. Omit for the whole site.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		if ( empty( $input['post_id'] ) ) {
			// Site-wide, as Elementor's own Tools screen requires.
			return current_user_can( 'manage_options' );
		}
		return parent::permission( $input );
	}

	protected function execute( array $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			$post = ElementorHelper::get_page( (int) $input['post_id'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			ElementorHelper::clear_post_css( (int) $post->ID );
			return array(
				'post_id'     => (int) $post->ID,
				'scope'       => 'post',
				'regenerated' => true,
			);
		}
		ElementorHelper::clear_all_css();
		return array(
			'scope'       => 'site',
			'regenerated' => true,
		);
	}
}
