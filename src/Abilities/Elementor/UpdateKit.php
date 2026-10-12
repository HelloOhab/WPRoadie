<?php
/**
 * Changes the active Elementor kit: global colors, fonts and site-wide
 * settings.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateKit extends ElementorAbility {

	public function slug(): string {
		return 'elementor-update-kit';
	}

	public function label(): string {
		return __( 'Update Elementor global styles', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes the site\'s Elementor global colors, global fonts or other kit (Site Settings) values. This restyles every page that uses them, so confirm with the user first. colors: [{"id": "primary", "color": "#1A73E8"}] changes a color by id (from elementor-get-kit); an id that does not exist adds a new custom color (title and color required). fonts: [{"id": "primary", "family": "Roboto", "weight": "600", "size": 18}] changes a global font the same way (size is pixels or a string such as "1.2em"; settings takes raw typography_* keys). remove: ids of custom colors or fonts to delete (system ones cannot be removed). settings: other kit keys to set (null removes), e.g. {"container_width": {"unit": "px", "size": 1200}}. Elementor\'s CSS is regenerated. Returns the updated colors and fonts, plus an undo_id; pass it to undo-change to restore the previous kit.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function input_schema(): array {
		$id = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 40,
			'pattern'   => '^[A-Za-z0-9_-]+$',
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'colors'   => array(
					'type'        => 'array',
					'maxItems'    => 100,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'    => $id,
							'title' => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'color' => array(
								'type'      => 'string',
								'maxLength' => 60,
							),
						),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
					'description' => __( 'Colors to change or add, by id.', 'wproadie' ),
				),
				'fonts'    => array(
					'type'        => 'array',
					'maxItems'    => 100,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'              => $id,
							'title'           => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'family'          => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'weight'          => array(
								'type'      => array( 'string', 'integer' ),
								'maxLength' => 10,
							),
							'size'            => array( 'type' => array( 'number', 'string', 'object' ) ),
							'line_height'     => array( 'type' => array( 'number', 'string', 'object' ) ),
							'letter_spacing'  => array( 'type' => array( 'number', 'string', 'object' ) ),
							'transform'       => array( 'type' => 'string' ),
							'style'           => array( 'type' => 'string' ),
							'text_decoration' => array( 'type' => 'string' ),
							'settings'        => array(
								'type'        => 'object',
								'description' => __( 'Raw typography_* keys to set (null removes).', 'wproadie' ),
							),
						),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
					'description' => __( 'Global fonts to change or add, by id.', 'wproadie' ),
				),
				'remove'   => array(
					'type'        => 'array',
					'maxItems'    => 100,
					'items'       => $id,
					'description' => __( 'Ids of custom colors or fonts to delete.', 'wproadie' ),
				),
				'settings' => array(
					'type'        => 'object',
					'description' => __( 'Other kit settings to set; null removes a key. Colors and fonts go in colors and fonts.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		$kit_id = Kit::id();
		return current_user_can( 'edit_theme_options' ) && ( is_wp_error( $kit_id ) || current_user_can( 'edit_post', $kit_id ) );
	}

	protected function execute( array $input ) {
		$kit_id = Kit::id();
		if ( is_wp_error( $kit_id ) ) {
			return $kit_id;
		}
		if ( empty( $input['colors'] ) && empty( $input['fonts'] ) && empty( $input['remove'] ) && empty( $input['settings'] ) ) {
			return $this->error( 'nothing_to_update', __( 'Send colors, fonts, remove or settings.', 'wproadie' ) );
		}

		$stored = Kit::stored( $kit_id );
		$lists  = array();
		foreach ( array_merge( Kit::COLOR_KEYS, Kit::FONT_KEYS ) as $key ) {
			$lists[ $key ] = Kit::items( $kit_id, $key, $stored );
		}
		$taken = array();
		foreach ( $lists as $items ) {
			foreach ( $items as $item ) {
				if ( isset( $item['_id'] ) ) {
					$taken[ (string) $item['_id'] ] = true;
				}
			}
		}
		$touched = array();
		$added   = array();

		foreach ( (array) ( $input['colors'] ?? array() ) as $change ) {
			if ( isset( $change['color'] ) && ! Kit::valid_color( $change['color'] ) ) {
				return $this->error( 'invalid_color', sprintf( /* translators: %s: color value. */ __( '"%s" is not a color. Use #RRGGBB, #RGB, #RRGGBBAA or rgba(…).', 'wproadie' ), (string) $change['color'] ) );
			}
			$done = $this->apply_change( $lists, Kit::COLOR_KEYS, $change, $touched, $added, $taken, 'color' );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		foreach ( (array) ( $input['fonts'] ?? array() ) as $change ) {
			$done = $this->apply_change( $lists, Kit::FONT_KEYS, $change, $touched, $added, $taken, 'font' );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		foreach ( (array) ( $input['remove'] ?? array() ) as $remove_id ) {
			$removed = false;
			foreach ( array( 'system_colors', 'system_typography' ) as $key ) {
				if ( in_array( (string) $remove_id, array_column( $lists[ $key ], '_id' ), true ) ) {
					return $this->error( 'system_global', sprintf( /* translators: %s: global id. */ __( '"%s" is a system global; it can be changed but not removed.', 'wproadie' ), (string) $remove_id ) );
				}
			}
			foreach ( array( 'custom_colors', 'custom_typography' ) as $key ) {
				foreach ( $lists[ $key ] as $i => $item ) {
					if ( isset( $item['_id'] ) && (string) $item['_id'] === (string) $remove_id ) {
						array_splice( $lists[ $key ], $i, 1 );
						$touched[ $key ] = true;
						$removed         = true;
						break;
					}
				}
			}
			if ( ! $removed ) {
				return $this->error( 'global_not_found', sprintf( /* translators: %s: global id. */ __( 'No custom color or font with id "%s".', 'wproadie' ), (string) $remove_id ) );
			}
		}

		$new = $stored;
		foreach ( array_keys( $touched ) as $key ) {
			$new[ $key ] = array_values( $lists[ $key ] );
		}
		if ( ! empty( $input['settings'] ) ) {
			$settings = (array) $input['settings'];
			$blocked  = array_intersect( array_keys( $settings ), array_merge( Kit::COLOR_KEYS, Kit::FONT_KEYS ) );
			if ( $blocked ) {
				return $this->error( 'use_colors_fonts', __( 'Change global colors and fonts with the colors and fonts arguments, not settings.', 'wproadie' ) );
			}
			$new = Tree::merge_settings( $new, ElementorHelper::sanitize( $settings ) );
		}

		if ( $new === $stored ) {
			$result            = GetKit::describe( $kit_id );
			$result['changed'] = false;
			$result['note']    = __( 'The kit already had these values, so nothing was saved.', 'wproadie' );
			return $result;
		}

		$this->add_undo( Undo::meta( 'post', $kit_id, ElementorHelper::PAGE_SETTINGS_KEY ) );
		update_post_meta( $kit_id, ElementorHelper::PAGE_SETTINGS_KEY, wp_slash( $new ) );
		if ( Kit::stored( $kit_id ) !== $new ) {
			return $this->error( 'save_failed', __( 'WordPress did not store the kit settings (a plugin may have blocked it).', 'wproadie' ), 500 );
		}
		ElementorHelper::clear_all_css();

		$result            = GetKit::describe( $kit_id );
		$result['changed'] = true;
		if ( $added ) {
			$result['added'] = $added;
		}
		return $result;
	}

	/**
	 * Applies one color or font change to the lists.
	 *
	 * @param array               $lists   Kit lists by key, changed in place.
	 * @param string[]            $keys    System and custom list keys.
	 * @param array               $change  The change.
	 * @param array               $touched Changed list keys, by reference.
	 * @param string[]            $added   Ids of new globals, by reference.
	 * @param array<string, true> $taken   Ids in use, by reference.
	 * @param string              $kind    "color" or "font".
	 * @return true|\WP_Error
	 */
	private function apply_change( array &$lists, array $keys, array $change, array &$touched, array &$added, array &$taken, string $kind ) {
		$id     = (string) $change['id'];
		$fields = 'color' === $kind ? $this->color_fields( $change ) : $this->font_fields( $change );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		foreach ( $keys as $key ) {
			foreach ( $lists[ $key ] as $i => $item ) {
				if ( is_array( $item ) && isset( $item['_id'] ) && (string) $item['_id'] === $id ) {
					$lists[ $key ][ $i ] = Tree::merge_settings( $item, $fields );
					$touched[ $key ]     = true;
					return true;
				}
			}
		}

		// A new custom global.
		if ( empty( $change['title'] ) || ( 'color' === $kind && empty( $change['color'] ) ) || ( 'font' === $kind && count( $fields ) < 2 ) ) {
			return $this->error(
				'global_not_found',
				sprintf(
					/* translators: %s: global id. */
					__( 'No global with id "%s". To add one, send a title and its value (color, or font fields).', 'wproadie' ),
					$id
				),
				404
			);
		}
		if ( isset( $taken[ $id ] ) ) {
			$id = Tree::new_id( $taken );
		}
		$taken[ $id ]       = true;
		$custom             = 'color' === $kind ? 'custom_colors' : 'custom_typography';
		$lists[ $custom ][] = array_merge( array( '_id' => $id ), Tree::merge_settings( array(), $fields ) );
		$touched[ $custom ] = true;
		$added[]            = $id;
		return true;
	}

	private function color_fields( array $change ): array {
		$fields = array();
		if ( isset( $change['title'] ) ) {
			$fields['title'] = sanitize_text_field( (string) $change['title'] );
		}
		if ( isset( $change['color'] ) ) {
			$fields['color'] = (string) $change['color'];
		}
		return $fields;
	}

	/**
	 * @return array|\WP_Error
	 */
	private function font_fields( array $change ) {
		$fields = array();
		if ( isset( $change['title'] ) ) {
			$fields['title'] = sanitize_text_field( (string) $change['title'] );
		}
		foreach ( Kit::FONT_FIELDS as $short => $key ) {
			if ( ! array_key_exists( $short, $change ) ) {
				continue;
			}
			$value = $change[ $short ];
			if ( null !== $value && in_array( $short, array( 'size', 'line_height', 'letter_spacing' ), true ) ) {
				$value = Kit::size( $value, 'line_height' === $short && is_numeric( $value ) ? 'em' : 'px' );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			} elseif ( null !== $value ) {
				$value = sanitize_text_field( (string) $value );
			}
			$fields[ $key ] = $value;
		}
		if ( ! empty( $change['settings'] ) && is_array( $change['settings'] ) ) {
			foreach ( $change['settings'] as $key => $value ) {
				if ( 0 !== strpos( (string) $key, 'typography_' ) ) {
					return $this->error( 'invalid_font_setting', __( 'Font settings keys start with typography_, e.g. typography_font_size_mobile.', 'wproadie' ) );
				}
				$fields[ (string) $key ] = ElementorHelper::sanitize( $value );
			}
		}
		if ( count( array_diff( array_keys( $fields ), array( 'title' ) ) ) > 0 ) {
			// Elementor only applies typography switched to "custom".
			$fields['typography_typography'] = 'custom';
		}
		return $fields;
	}
}
