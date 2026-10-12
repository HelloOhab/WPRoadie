<?php
/**
 * Divi 4 storage format: nested shortcodes in post_content.
 *
 *   [et_pb_section …][et_pb_row …][et_pb_column type="4_4" …]
 *     [et_pb_text admin_label="Intro" …]<p>Hello</p>[/et_pb_text]
 *   [/et_pb_column][/et_pb_row][/et_pb_section]
 *
 * The parser follows WordPress's own shortcode grammar (the tag and
 * attribute patterns of get_shortcode_regex() and
 * get_shortcode_atts_regex()): an opening tag's content runs to the first
 * matching closing tag inside its parent, a tag without one is a leaf.
 * Every byte of the input lands in exactly one node or in the text
 * between nodes, so an unchanged page serializes back byte for byte.
 *
 * Divi encodes characters that would break a shortcode attribute:
 * %22 for ", %91 for [, %93 for ] and %92 (or %5c) for \.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	/**
	 * Shortcodes whose content is other shortcodes (layout structure and
	 * parent modules). Everything else keeps its content as raw text, so a
	 * shortcode from another plugin inside a Text module stays text.
	 */
	const CONTAINERS = array(
		'et_pb_section',
		'et_pb_row',
		'et_pb_row_inner',
		'et_pb_column',
		'et_pb_column_inner',
		'et_pb_tabs',
		'et_pb_accordion',
		'et_pb_slider',
		'et_pb_fullwidth_slider',
		'et_pb_video_slider',
		'et_pb_pricing_tables',
		'et_pb_contact_form',
		'et_pb_social_media_follow',
		'et_pb_counters',
		'et_pb_map',
		'et_pb_fullwidth_map',
	);

	const STRUCTURE = array(
		'et_pb_section'      => 'section',
		'et_pb_row'          => 'row',
		'et_pb_row_inner'    => 'row',
		'et_pb_column'       => 'column',
		'et_pb_column_inner' => 'column',
	);

	/**
	 * Opening tag at an offset: name, attribute text, optional "/".
	 * Same rules as WordPress: attributes cannot contain "]", and "/" only
	 * when not followed by "]".
	 */
	const OPEN_TAG = '/\G\[([A-Za-z0-9_-]+)(?=[\s\]\/])((?:[^\]\/]|\/(?!\]))*+)(\/)?\]/';

	/**
	 * Parses post content into a root node whose parts are the top-level
	 * shortcodes and the text between them.
	 */
	public static function parse( string $content ): Node {
		$root        = new Node();
		$root->name  = '#root';
		$root->parts = self::parse_region( $content, 0, strlen( $content ), 0 );
		return $root;
	}

	/**
	 * Parses one shortcode string, e.g. a newly built module.
	 *
	 * @return Node|WP_Error
	 */
	public static function parse_one( string $markup ) {
		$root  = self::parse( $markup );
		$nodes = $root->children();
		if ( 1 !== count( $nodes ) ) {
			return new WP_Error( 'wproadie_divi_bad_markup', __( 'Could not build the Divi 4 shortcode.', 'wproadie' ) );
		}
		return $nodes[0];
	}

	/**
	 * Splits $s[$start, $end) into shortcode nodes and the text between.
	 *
	 * @return array<int, string|Node>
	 */
	private static function parse_region( string $s, int $start, int $end, int $depth ): array {
		$parts = array();
		$glue  = '';
		$i     = $start;
		while ( $i < $end ) {
			$bracket = strpos( $s, '[', $i );
			if ( false === $bracket || $bracket >= $end ) {
				$glue .= substr( $s, $i, $end - $i );
				break;
			}
			$glue .= substr( $s, $i, $bracket - $i );
			$i     = $bracket;

			$next = $s[ $i + 1 ] ?? '';
			if ( '[' === $next || '/' === $next ) {
				// An escaped [[shortcode]] or a stray closing tag: text.
				$glue .= substr( $s, $i, 2 );
				$i    += 2;
				continue;
			}

			if ( ! preg_match( self::OPEN_TAG, $s, $m, 0, $i ) || $i + strlen( $m[0] ) > $end ) {
				$glue .= '[';
				++$i;
				continue;
			}

			$node            = new Node();
			$node->name      = $m[1];
			$node->raw_name  = $m[1];
			$node->open      = $m[0];
			$node->attr_text = $m[2];
			$node->attrs     = self::parse_attrs( $m[2] );
			$after_open      = $i + strlen( $m[0] );

			if ( ! empty( $m[3] ) ) {
				$node->void = true;
				$next_i     = $after_open;
			} else {
				$closer = '[/' . $m[1] . ']';
				$found  = strpos( $s, $closer, $after_open );
				if ( false === $found || $found + strlen( $closer ) > $end ) {
					// No closing tag inside the parent: a leaf without content.
					$next_i = $after_open;
				} else {
					$node->close = $closer;
					if ( in_array( strtolower( $m[1] ), self::CONTAINERS, true ) && $depth < 40 ) {
						$node->parts = self::parse_region( $s, $after_open, $found, $depth + 1 );
					} else {
						$node->content = substr( $s, $after_open, $found - $after_open );
					}
					$next_i = $found + strlen( $closer );
				}
			}

			if ( '' !== $glue ) {
				$parts[] = $glue;
				$glue    = '';
			}
			$parts[] = $node;
			$i       = $next_i;
		}
		if ( '' !== $glue ) {
			$parts[] = $glue;
		}
		return $parts;
	}

	/**
	 * Attributes as WordPress (and so Divi) reads them.
	 */
	public static function parse_attrs( string $text ): array {
		$atts = shortcode_parse_atts( $text );
		return is_array( $atts ) ? $atts : array();
	}

	/**
	 * Decodes Divi's attribute escapes for display.
	 */
	public static function decode( string $value ): string {
		return str_replace( array( '%22', '%92', '%91', '%93', '%5c' ), array( '"', '\\', '[', ']', '\\' ), $value );
	}

	/**
	 * Encodes a value the way Divi stores it in an attribute.
	 */
	public static function encode( string $value ): string {
		return str_replace( array( '\\', '"', '[', ']' ), array( '%92', '%22', '%91', '%93' ), $value );
	}

	/**
	 * Attributes with Divi's escapes decoded.
	 *
	 * @return array<string, string>
	 */
	public static function decoded_attrs( Node $node ): array {
		$out = array();
		foreach ( (array) $node->attrs as $key => $value ) {
			$out[ (string) $key ] = self::decode( (string) $value );
		}
		return $out;
	}

	/**
	 * Raw (still encoded) value of one attribute, or null.
	 */
	public static function attr( Node $node, string $key ): ?string {
		$key = strtolower( $key );
		return isset( $node->attrs[ $key ] ) ? (string) $node->attrs[ $key ] : null;
	}

	/**
	 * Sets (or with null, removes) one attribute, rewriting only that
	 * attribute's text in the opening tag.
	 *
	 * @param Node        $node  Node.
	 * @param string      $key   Attribute name.
	 * @param string|null $value Decoded value, or null to remove.
	 */
	public static function set_attr( Node $node, string $key, ?string $value ): void {
		$text  = $node->attr_text;
		$found = self::find_attr( $text, $key );
		$coded = null === $value ? null : self::encode( $value );

		if ( $found ) {
			if ( null === $coded ) {
				$text = substr( $text, 0, $found['remove_start'] ) . substr( $text, $found['end'] );
			} elseif ( '"' === $found['quote'] ) {
				$text = substr( $text, 0, $found['value_start'] ) . $coded . substr( $text, $found['value_end'] );
			} else {
				$text = substr( $text, 0, $found['start'] ) . $key . '="' . $coded . '"' . substr( $text, $found['end'] );
			}
		} elseif ( null !== $coded ) {
			$trimmed  = rtrim( $text );
			$trailing = substr( $text, strlen( $trimmed ) );
			$text     = $trimmed . ' ' . $key . '="' . $coded . '"' . $trailing;
		} else {
			return;
		}

		$node->attr_text = $text;
		$node->attrs     = self::parse_attrs( $text );
		$node->open      = '[' . $node->raw_name . $text . ( $node->void ? '/' : '' ) . ']';
	}

	/**
	 * Locates a named attribute in raw attribute text.
	 *
	 * @return array|null start/end of the whole attribute, the value span,
	 *                    its quote and where removal starts (with the
	 *                    whitespace before it).
	 */
	private static function find_attr( string $text, string $key ): ?array {
		if ( ! preg_match_all( get_shortcode_atts_regex(), $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$found = null;
		foreach ( $matches as $m ) {
			foreach ( array(
				1 => '"',
				3 => "'",
				5 => '',
			) as $group => $quote ) {
				if ( ! isset( $m[ $group ] ) || '' === $m[ $group ][0] || -1 === $m[ $group ][1] ) {
					continue;
				}
				if ( strtolower( $m[ $group ][0] ) !== strtolower( $key ) ) {
					break;
				}
				$value       = $m[ $group + 1 ];
				$value_start = (int) $value[1];
				$value_end   = $value_start + strlen( $value[0] );
				$start       = (int) $m[ $group ][1];
				$remove      = $start;
				while ( $remove > 0 && ctype_space( $text[ $remove - 1 ] ) ) {
					--$remove;
				}
				// The last match wins, as in shortcode_parse_atts().
				$found = array(
					'start'        => $start,
					'end'          => $value_end + strlen( $quote ),
					'value_start'  => $value_start,
					'value_end'    => $value_end,
					'quote'        => $quote,
					'remove_start' => $remove,
				);
				break;
			}
		}
		return $found;
	}

	/**
	 * Replaces a leaf's inner content, adding a closing tag if needed.
	 */
	public static function set_content( Node $node, string $content ): void {
		self::ensure_closed( $node );
		$node->content = $content;
	}

	/**
	 * Gives a self-closing or unclosed shortcode a closing tag, so content
	 * or children can go inside it.
	 */
	public static function ensure_closed( Node $node ): void {
		if ( '' !== $node->close ) {
			return;
		}
		$node->void  = false;
		$node->open  = '[' . $node->raw_name . rtrim( $node->attr_text ) . ']';
		$node->close = '[/' . $node->raw_name . ']';
		if ( in_array( strtolower( $node->name ), self::CONTAINERS, true ) ) {
			$node->parts = array();
		} else {
			$node->content = '';
		}
	}

	/**
	 * Builds a new shortcode.
	 *
	 * @param string                $tag      Shortcode name.
	 * @param array<string, string> $attrs    Decoded attribute values.
	 * @param string|null           $content  Inner content for leaves.
	 * @param Node[]                $children Children for containers.
	 * @return Node|WP_Error
	 */
	public static function build( string $tag, array $attrs, ?string $content = null, array $children = array() ) {
		$text = '';
		foreach ( $attrs as $key => $value ) {
			$text .= ' ' . $key . '="' . self::encode( (string) $value ) . '"';
		}
		$inner = '';
		if ( in_array( $tag, self::CONTAINERS, true ) ) {
			$inner = Node::join( $children );
		} elseif ( null !== $content ) {
			$inner = $content;
		}
		return self::parse_one( '[' . $tag . $text . ']' . $inner . '[/' . $tag . ']' );
	}
}
