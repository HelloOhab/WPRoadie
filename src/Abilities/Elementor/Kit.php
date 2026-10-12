<?php
/**
 * The active Elementor kit: global colors, global fonts (typography) and
 * site-wide settings, stored as _elementor_page_settings on the kit post.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Kit {

	const COLOR_KEYS = array( 'system_colors', 'custom_colors' );
	const FONT_KEYS  = array( 'system_typography', 'custom_typography' );

	/**
	 * Typography fields the font shorthand understands.
	 */
	const FONT_FIELDS = array(
		'family'          => 'typography_font_family',
		'weight'          => 'typography_font_weight',
		'size'            => 'typography_font_size',
		'line_height'     => 'typography_line_height',
		'letter_spacing'  => 'typography_letter_spacing',
		'transform'       => 'typography_text_transform',
		'style'           => 'typography_font_style',
		'text_decoration' => 'typography_text_decoration',
	);

	/**
	 * The active kit's post ID.
	 *
	 * @return int|WP_Error
	 */
	public static function id() {
		$id   = ElementorHelper::active_kit_id();
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ElementorHelper::LIBRARY_TYPE !== $post->post_type ) {
			return new WP_Error( 'wproadie_no_kit', __( 'This site has no active Elementor kit (Site Settings). Open Elementor\'s Site Settings once to create it.', 'wproadie' ), array( 'status' => 404 ) );
		}
		return (int) $post->ID;
	}

	/**
	 * Stored kit settings.
	 */
	public static function stored( int $kit_id ): array {
		$settings = get_post_meta( $kit_id, ElementorHelper::PAGE_SETTINGS_KEY, true );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * A list setting (colors or typography) as Elementor sees it: the
	 * stored value, or Elementor's defaults when nothing is stored yet.
	 *
	 * @return array[]
	 */
	public static function items( int $kit_id, string $key, ?array $stored = null ): array {
		$stored = null === $stored ? self::stored( $kit_id ) : $stored;
		if ( isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
			return array_values( $stored[ $key ] );
		}
		if ( ElementorHelper::is_loaded() ) {
			try {
				$kit   = \Elementor\Plugin::$instance->documents->get( $kit_id, false );
				$value = $kit ? $kit->get_settings( $key ) : null;
				if ( is_array( $value ) ) {
					return array_values( $value );
				}
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
		return array();
	}

	/**
	 * Compact color for output.
	 */
	public static function color_out( array $item, bool $system ): array {
		return array(
			'id'     => (string) ( $item['_id'] ?? '' ),
			'title'  => (string) ( $item['title'] ?? '' ),
			'color'  => (string) ( $item['color'] ?? '' ),
			'system' => $system,
			'global' => 'globals/colors?id=' . ( $item['_id'] ?? '' ),
		);
	}

	/**
	 * Compact font for output.
	 */
	public static function font_out( array $item, bool $system ): array {
		$out = array(
			'id'    => (string) ( $item['_id'] ?? '' ),
			'title' => (string) ( $item['title'] ?? '' ),
		);
		foreach ( self::FONT_FIELDS as $short => $key ) {
			if ( ! isset( $item[ $key ] ) || '' === $item[ $key ] ) {
				continue;
			}
			$value = $item[ $key ];
			if ( is_array( $value ) ) {
				if ( ! isset( $value['size'] ) || '' === $value['size'] ) {
					continue;
				}
				$value = $value['size'] . ( $value['unit'] ?? '' );
			}
			$out[ $short ] = $value;
		}
		$out['system'] = $system;
		$out['global'] = 'globals/typography?id=' . ( $item['_id'] ?? '' );
		return $out;
	}

	/**
	 * Whether a value is a CSS color Elementor's color picker accepts.
	 *
	 * @param mixed $color Value.
	 */
	public static function valid_color( $color ): bool {
		if ( ! is_string( $color ) ) {
			return false;
		}
		return (bool) preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color )
			|| (bool) preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.%,\s\/]+\)$/i', $color );
	}

	/**
	 * A size value as Elementor stores it: {unit, size, sizes}.
	 *
	 * @param mixed  $value        Number (pixels), "1.5em" style string, or {unit, size}.
	 * @param string $default_unit Unit for bare numbers.
	 * @return array|WP_Error
	 */
	public static function size( $value, string $default_unit = 'px' ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return array(
				'unit'  => $default_unit,
				'size'  => $value,
				'sizes' => array(),
			);
		}
		if ( is_string( $value ) && preg_match( '/^\s*(-?[0-9]*\.?[0-9]+)\s*(px|em|rem|vw|vh|%)?\s*$/', $value, $m ) ) {
			return array(
				'unit'  => isset( $m[2] ) && '' !== $m[2] ? $m[2] : $default_unit,
				'size'  => 0 + $m[1],
				'sizes' => array(),
			);
		}
		if ( is_array( $value ) && isset( $value['size'] ) && is_numeric( $value['size'] ) ) {
			return array(
				'unit'  => isset( $value['unit'] ) ? (string) $value['unit'] : $default_unit,
				'size'  => 0 + $value['size'],
				'sizes' => array(),
			);
		}
		return new WP_Error( 'wproadie_invalid_size', __( 'Sizes are a number of pixels, a string such as "1.5em", or {"unit": "px", "size": 16}.', 'wproadie' ), array( 'status' => 400 ) );
	}
}
