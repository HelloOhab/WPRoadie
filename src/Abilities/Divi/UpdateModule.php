<?php
/**
 * Changes attributes and/or the content of one Divi module.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WP_Error;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateModule extends DiviAbility {

	/**
	 * Attributes that link an element to a Divi Library item. Changing them
	 * by hand breaks the link in ways the builder does not expect.
	 */
	const PROTECTED_ATTRS = array( 'global_module', 'global_parent', 'globalmodule' );

	public function slug(): string {
		return 'divi-update-module';
	}

	public function label(): string {
		return __( 'Update Divi module', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes one Divi module (or section, row or column) found by path, admin label or CSS ID, leaving everything else on the page byte-for-byte as it was. Read it first with divi-get-module; divi-get-module-schema lists the attribute names a module takes, and an unknown name is refused with the likely right one. Divi 4: set is {attribute: value} with plain values (e.g. {"button_text": "Book now", "button_url": "/contact", "admin_label": "Hero CTA"}; Divi\'s escaping of quotes and brackets is done for you; true/false become on/off), remove is a list of attribute names, content replaces the HTML between the tags (e.g. a Text module\'s body). Divi 5: set is {dotted.path: value} into the block\'s JSON (e.g. {"button.innerContent.desktop.value.text": "Book now", "content.innerContent.desktop.value": "<p>Hi</p>", "module.decoration.background.desktop.value.color": "#fff"}), values may be objects; remove is a list of dotted paths; content is shorthand for content.innerContent.desktop.value. Layout HTML inside text, custom CSS and inline styles are refused unless allow_raw_html / allow_inline_css say the person asked for them. Users without the unfiltered_html capability cannot add scripts: HTML is filtered with wp_kses_post. Elements inside a global library item are refused (edit the library item instead). Saves a revision and clears Divi\'s CSS cache. Returns the updated element and an undo_id for undo-change.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array( 'id' => self::id_property() ),
				self::module_properties(),
				array(
					'set'                      => array(
						'type'          => 'object',
						'maxProperties' => 100,
						'description'   => __( 'Attributes to set. Divi 4: name => text. Divi 5: dotted.path => any JSON value.', 'wproadie' ),
					),
					'remove'                   => array(
						'type'        => 'array',
						'maxItems'    => 100,
						'items'       => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'description' => __( 'Attribute names (Divi 4) or dotted paths (Divi 5) to remove, so Divi uses its default.', 'wproadie' ),
					),
					'content'                  => array(
						'type'        => 'string',
						'maxLength'   => 500000,
						'description' => __( 'New content: Divi 4, the HTML between the module\'s tags; Divi 5, content.innerContent.desktop.value.', 'wproadie' ),
					),
					'allow_raw_html'           => \WPRoadie\Abilities\RawHtml::flags_schema()['allow_raw_html'],
					'allow_inline_css'         => \WPRoadie\Abilities\RawHtml::flags_schema()['allow_inline_css'],
					'allow_unknown_attributes' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Divi 4: store attribute names the module has no field for instead of refusing them (only for names you know an add-on reads).', 'wproadie' ),
					),
				)
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$set    = isset( $input['set'] ) ? (array) $input['set'] : array();
		$remove = isset( $input['remove'] ) ? array_map( 'strval', (array) $input['remove'] ) : array();
		if ( ! $set && ! $remove && ! array_key_exists( 'content', $input ) ) {
			return Divi::error( 'nothing_to_update', __( 'Pass set, remove and/or content.', 'wproadie' ) );
		}

		$loaded = Divi::load( (int) $input['id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$doc   = $loaded['doc'];
		$entry = $doc->find( $input );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$global = $this->check_not_global( $doc, $entry );
		if ( is_wp_error( $global ) ) {
			return $global;
		}

		$node      = $entry['node'];
		$sanitized = false;
		$strings   = \WPRoadie\Abilities\RawHtml::strings( $set );
		if ( array_key_exists( 'content', $input ) ) {
			$strings[] = (string) $input['content'];
		}
		// An existing Code module keeps its settings editable; new code in it, and new CSS anywhere, need the flags.
		$is_code = in_array( strtolower( $node->name ), \WPRoadie\Abilities\RawHtml::CODE_MODULES, true );
		$code    = \WPRoadie\Abilities\RawHtml::check( $is_code && ! array_key_exists( 'content', $input ) ? 'et_pb_text' : $node->name, $strings, \WPRoadie\Abilities\RawHtml::css_keys( array_keys( $set ) ), $input, '', 'divi_' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		$applied = 5 === $doc->format
			? $this->apply_5( $node, $set, $remove, $input, $sanitized )
			: $this->apply_4( $node, $set, $remove, $input, $sanitized );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$saved = $this->save( $loaded['post'], $doc );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result = array_merge(
			$saved,
			array( 'module' => $doc->describe( $entry ) ),
			$applied
		);
		if ( $sanitized ) {
			$result['sanitized'] = __( 'Some HTML was removed because your account cannot save unfiltered HTML (scripts, iframes, event attributes).', 'wproadie' );
		}
		return $result;
	}

	/**
	 * @return array|WP_Error What was changed.
	 */
	private function apply_4( Node $node, array $set, array $remove, array $input, bool &$sanitized ) {
		if ( $set && empty( $input['allow_unknown_attributes'] ) ) {
			$known = $this->check_attributes_4( $node->name, $set );
			if ( is_wp_error( $known ) ) {
				return $known;
			}
		}
		$changed = array();
		foreach ( $set as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				return Divi::error(
					'bad_attribute',
					/* translators: %s: attribute name. */
					sprintf( __( '"%s" is not a valid Divi 4 attribute name (letters, digits, _ and - only). Divi 5 dotted paths only work on Divi 5 pages.', 'wproadie' ), $key )
				);
			}
			if ( in_array( strtolower( $key ), self::PROTECTED_ATTRS, true ) ) {
				return $this->protected_error( $key );
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'on' : 'off';
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$value = (string) $value;
			} elseif ( ! is_string( $value ) ) {
				return Divi::error(
					'bad_value',
					/* translators: %s: attribute name. */
					sprintf( __( 'Divi 4 attribute values are text; "%s" got a list or object.', 'wproadie' ), $key )
				);
			}
			Shortcodes::set_attr( $node, $key, Divi::clean_attr( $value, $sanitized ) );
			$changed[] = $key;
		}

		$removed = array();
		$missing = array();
		foreach ( $remove as $key ) {
			if ( in_array( strtolower( $key ), self::PROTECTED_ATTRS, true ) ) {
				return $this->protected_error( $key );
			}
			if ( null === Shortcodes::attr( $node, $key ) ) {
				$missing[] = $key;
				continue;
			}
			Shortcodes::set_attr( $node, $key, null );
			$removed[] = $key;
		}

		if ( array_key_exists( 'content', $input ) ) {
			if ( null !== $node->parts ) {
				return Divi::error( 'container_content', __( 'This element holds other modules; change those with divi-update-module on their own paths, or use divi-add-module / divi-remove-module.', 'wproadie' ) );
			}
			Shortcodes::set_content( $node, Divi::clean( (string) $input['content'], $sanitized ) );
			$changed[] = 'content';
		}

		return $this->changes( $changed, $removed, $missing );
	}

	/**
	 * @return array|WP_Error What was changed.
	 */
	private function apply_5( Node $node, array $set, array $remove, array $input, bool &$sanitized ) {
		if ( $node->bad_attrs ) {
			return Divi::error( 'bad_attrs', __( 'This block\'s attributes are not valid JSON, so it cannot be edited safely. Fix it in the Divi Builder.', 'wproadie' ) );
		}
		if ( array_key_exists( 'content', $input ) ) {
			$set['content.innerContent.desktop.value'] = (string) $input['content'];
		}

		$changed = array();
		foreach ( $set as $path => $value ) {
			$path = (string) $path;
			$bad  = $this->check_path( $path );
			if ( $bad ) {
				return $bad;
			}
			$done = Blocks::set_path( $node, $path, Divi::clean( Blocks::to_json_value( $value ), $sanitized ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$changed[] = $path;
		}

		$removed = array();
		$missing = array();
		foreach ( $remove as $path ) {
			$bad = $this->check_path( $path );
			if ( $bad ) {
				return $bad;
			}
			if ( Blocks::remove_path( $node, $path ) ) {
				$removed[] = $path;
			} else {
				$missing[] = $path;
			}
		}

		if ( $changed || $removed ) {
			Blocks::refresh_open( $node );
		}
		return $this->changes( $changed, $removed, $missing );
	}

	private function check_path( string $path ): ?WP_Error {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $path ) ) {
			return Divi::error(
				'bad_attribute',
				/* translators: %s: attribute path. */
				sprintf( __( '"%s" is not a valid Divi 5 attribute path, e.g. "title.innerContent.desktop.value".', 'wproadie' ), $path )
			);
		}
		$first = strtolower( explode( '.', $path )[0] );
		if ( in_array( $first, self::PROTECTED_ATTRS, true ) ) {
			return $this->protected_error( $path );
		}
		return null;
	}

	private function protected_error( string $key ): WP_Error {
		return Divi::error(
			'protected_attribute',
			/* translators: %s: attribute name. */
			sprintf( __( '"%s" links the element to the Divi Library and cannot be changed here. Insert a library item with divi-insert-layout, or remove the element.', 'wproadie' ), $key )
		);
	}

	private function changes( array $changed, array $removed, array $missing ): array {
		$out = array( 'set' => $changed );
		if ( $removed ) {
			$out['removed'] = $removed;
		}
		if ( $missing ) {
			$out['not_found'] = $missing;
		}
		return $out;
	}
}
