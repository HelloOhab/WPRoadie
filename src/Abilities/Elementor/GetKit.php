<?php
/**
 * Reads the active Elementor kit: global colors, fonts and site settings.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetKit extends ElementorAbility {

	public function slug(): string {
		return 'elementor-get-kit';
	}

	public function label(): string {
		return __( 'Get Elementor global styles', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns the site\'s Elementor kit (Site Settings): global colors and global fonts with their ids, plus the names of the other site-wide settings (set include_settings for their values). Elements use a global through __globals__, e.g. elementor-update-element with {"__globals__": {"title_color": "globals/colors?id=primary"}}; each color and font lists its "global" reference. Change globals with elementor-update-kit.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'include_settings' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also return every other stored kit setting (layout, buttons, forms, custom CSS …).', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_posts' );
	}

	protected function execute( array $input ) {
		$kit_id = Kit::id();
		if ( is_wp_error( $kit_id ) ) {
			return $kit_id;
		}
		return self::describe( $kit_id, ! empty( $input['include_settings'] ) );
	}

	/**
	 * The kit as the AI sees it. Shared with elementor-update-kit.
	 */
	public static function describe( int $kit_id, bool $include_settings = false ): array {
		$stored = Kit::stored( $kit_id );
		$colors = array();
		foreach ( Kit::COLOR_KEYS as $key ) {
			foreach ( Kit::items( $kit_id, $key, $stored ) as $item ) {
				if ( is_array( $item ) ) {
					$colors[] = Kit::color_out( $item, 'system_colors' === $key );
				}
			}
		}
		$fonts = array();
		foreach ( Kit::FONT_KEYS as $key ) {
			foreach ( Kit::items( $kit_id, $key, $stored ) as $item ) {
				if ( is_array( $item ) ) {
					$fonts[] = Kit::font_out( $item, 'system_typography' === $key );
				}
			}
		}

		$other  = array_diff_key( $stored, array_flip( array_merge( Kit::COLOR_KEYS, Kit::FONT_KEYS ) ) );
		$result = array(
			'kit_id'        => $kit_id,
			'title'         => get_the_title( $kit_id ),
			'colors'        => $colors,
			'fonts'         => $fonts,
			'settings_keys' => array_keys( $other ),
		);
		if ( $include_settings ) {
			$cut                = false;
			$result['settings'] = $other ? ContentHelper::meta_value( $other, 30000, $cut ) : new \stdClass();
		}
		return $result;
	}
}
