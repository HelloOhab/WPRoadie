<?php
/**
 * Divi 5 storage format: block markup with divi/* blocks.
 *
 *   <!-- wp:divi/placeholder -->
 *   <!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}},"builderVersion":"5.0.0"} -->
 *   <!-- wp:divi/row {…} --><!-- wp:divi/column {…} -->
 *   <!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hi</p>"}}}} /-->
 *   <!-- /wp:divi/column --><!-- /wp:divi/row -->
 *   <!-- /wp:divi/section -->
 *   <!-- /wp:divi/placeholder -->
 *
 * Tokens are found with the exact pattern WordPress's block parser
 * (WP_Block_Parser) uses, and nesting follows its rules, so the tree has
 * the same blocks and attributes as parse_blocks(). Unlike
 * parse_blocks() + serialize_blocks(), which re-encode every block's JSON
 * (unicode escapes, "\/" and spacing change), each node keeps its original
 * comment text: unchanged blocks serialize back byte for byte and only an
 * edited block's comment is rebuilt, with core's
 * serialize_block_attributes(), the same encoder Divi 5 saves with.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	/**
	 * Attribute names Divi 5 gives every block: the builder version and,
	 * for links to a Divi Library item, its post ID.
	 */
	const VERSION       = 'builderVersion';
	const GLOBAL_MODULE = 'globalModule';

	/**
	 * The block delimiter pattern of WP_Block_Parser::next_token().
	 */
	const TOKEN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	const STRUCTURE = array(
		'divi/placeholder'   => 'placeholder',
		'divi/section'       => 'section',
		'divi/row'           => 'row',
		'divi/row-inner'     => 'row',
		'divi/column'        => 'column',
		'divi/column-inner'  => 'column',
		'divi/global-layout' => 'module',
	);

	/**
	 * Divi 5 modules that hold other modules, so a new one is created with
	 * an opening and closing comment rather than self-closing.
	 */
	const CONTAINERS = array(
		'divi/placeholder',
		'divi/section',
		'divi/row',
		'divi/row-inner',
		'divi/column',
		'divi/column-inner',
		'divi/group',
		'divi/group-carousel',
		'divi/accordion',
		'divi/tabs',
		'divi/contact-form',
		'divi/counters',
		'divi/pricing-tables',
		'divi/social-media-follow',
		'divi/icon-list',
		'divi/slider',
		'divi/video-slider',
		'divi/timeline',
		'divi/map',
		'divi/fullwidth-map',
	);

	/**
	 * Parses post content into a root node whose parts are the top-level
	 * blocks and the HTML between them.
	 */
	public static function parse( string $content ): Node {
		$root        = new Node();
		$root->name  = '#root';
		$root->parts = array();

		$stack  = array( $root );
		$offset = 0;
		$length = strlen( $content );

		while ( $offset < $length ) {
			$found = preg_match( self::TOKEN, $content, $m, PREG_OFFSET_CAPTURE, $offset );
			if ( ! $found ) {
				break;
			}
			$start = (int) $m[0][1];
			$text  = $m[0][0];
			$top   = end( $stack );
			if ( $start > $offset ) {
				$top->parts[] = substr( $content, $offset, $start - $offset );
			}
			$offset = $start + strlen( $text );

			$is_closer = isset( $m['closer'] ) && -1 !== $m['closer'][1] && '' !== $m['closer'][0];
			if ( $is_closer ) {
				if ( count( $stack ) < 2 ) {
					// WordPress stops parsing at a closer with nothing open:
					// everything from here on is plain HTML.
					$top->parts[] = substr( $content, $start );
					$offset       = $length;
					break;
				}
				// Like WordPress, the closer ends whatever block is open.
				$node        = array_pop( $stack );
				$node->close = $text;
				continue;
			}

			$namespace      = ( isset( $m['namespace'] ) && -1 !== $m['namespace'][1] ) ? $m['namespace'][0] : '';
			$node           = new Node();
			$node->open     = $text;
			$node->raw_name = $namespace . $m['name'][0];
			$node->name     = ( '' !== $namespace ? $namespace : 'core/' ) . $m['name'][0];
			$node->void     = isset( $m['void'] ) && -1 !== $m['void'][1] && '' !== $m['void'][0];

			$has_attrs = isset( $m['attrs'] ) && -1 !== $m['attrs'][1] && '' !== $m['attrs'][0];
			if ( $has_attrs ) {
				$decoded = json_decode( $m['attrs'][0] );
				if ( $decoded instanceof stdClass ) {
					$node->attrs = $decoded;
				} else {
					$node->bad_attrs = true;
				}
			}

			$top->parts[] = $node;
			if ( ! $node->void ) {
				$node->parts = array();
				$stack[]     = $node;
			}
		}

		if ( $offset < $length ) {
			$top          = end( $stack );
			$top->parts[] = substr( $content, $offset );
		}

		return $root;
	}

	/**
	 * Parses one block's markup, e.g. a newly built module.
	 *
	 * @return Node|WP_Error
	 */
	public static function parse_one( string $markup ) {
		$nodes = self::parse( $markup )->children();
		if ( 1 !== count( $nodes ) ) {
			return new WP_Error( 'wproadie_divi_bad_markup', __( 'Could not build the Divi 5 block.', 'wproadie' ) );
		}
		return $nodes[0];
	}

	/**
	 * Rebuilds a node's opening comment after its attributes changed.
	 */
	public static function refresh_open( Node $node ): void {
		$attrs      = self::encode_attrs( $node->attrs );
		$node->open = '<!-- wp:' . $node->raw_name . ' ' . ( '' !== $attrs ? $attrs . ' ' : '' ) . ( $node->void ? '/' : '' ) . '-->';
	}

	/**
	 * Attribute JSON as WordPress (and Divi 5) writes it, or "" for none.
	 *
	 * @param stdClass|null $attrs Attributes.
	 */
	public static function encode_attrs( $attrs ): string {
		if ( ! is_object( $attrs ) || ! get_object_vars( $attrs ) ) {
			return '';
		}
		return serialize_block_attributes( $attrs );
	}

	/**
	 * Turns a self-closing block into an open/close pair so children can
	 * go inside it.
	 */
	public static function ensure_closed( Node $node ): void {
		if ( ! $node->void ) {
			if ( null === $node->parts ) {
				$node->parts = array();
			}
			return;
		}
		$node->void  = false;
		$node->open  = preg_replace( '/\/-->$/', '-->', $node->open );
		$node->close = '<!-- /wp:' . $node->raw_name . ' -->';
		$node->parts = array();
	}

	/**
	 * Builds a new block.
	 *
	 * @param string        $name     Block name, e.g. "divi/text".
	 * @param stdClass|null $attrs    Attributes.
	 * @param Node[]        $children Children (containers only).
	 * @return Node|WP_Error
	 */
	public static function build( string $name, $attrs, array $children = array() ) {
		$json   = self::encode_attrs( $attrs );
		$prefix = '<!-- wp:' . $name . ' ' . ( '' !== $json ? $json . ' ' : '' );
		if ( in_array( $name, self::CONTAINERS, true ) || $children ) {
			$markup = $prefix . '-->' . Node::join( $children ) . '<!-- /wp:' . $name . ' -->';
		} else {
			$markup = $prefix . '/-->';
		}
		return self::parse_one( $markup );
	}

	/**
	 * Reads a dotted path ("button.innerContent.desktop.value.text").
	 *
	 * @param mixed $attrs Attributes.
	 * @return mixed|null
	 */
	public static function get_path( $attrs, string $path ) {
		$value = $attrs;
		foreach ( explode( '.', $path ) as $key ) {
			if ( is_object( $value ) && property_exists( $value, $key ) ) {
				$value = $value->$key;
			} elseif ( is_array( $value ) && array_key_exists( $key, $value ) ) {
				$value = $value[ $key ];
			} else {
				return null;
			}
		}
		return $value;
	}

	/**
	 * Sets a dotted path, creating objects on the way.
	 *
	 * @param Node   $node  Node.
	 * @param string $path  Dotted path.
	 * @param mixed  $value JSON-ready value (objects as stdClass).
	 * @return true|WP_Error
	 */
	public static function set_path( Node $node, string $path, $value ) {
		if ( ! is_object( $node->attrs ) ) {
			$node->attrs = new stdClass();
		}
		$keys   = explode( '.', $path );
		$last   = array_pop( $keys );
		$target = $node->attrs;
		foreach ( $keys as $key ) {
			if ( ! property_exists( $target, $key ) ) {
				$target->$key = new stdClass();
			} elseif ( ! is_object( $target->$key ) ) {
				return new WP_Error(
					'wproadie_divi_bad_path',
					/* translators: %s: attribute path. */
					sprintf( __( 'Cannot set "%s": a part of that path is a value, not an object.', 'wproadie' ), $path ),
					array( 'status' => 400 )
				);
			}
			$target = $target->$key;
		}
		$target->$last = $value;
		return true;
	}

	/**
	 * Removes a dotted path. Returns whether it existed.
	 */
	public static function remove_path( Node $node, string $path ): bool {
		if ( ! is_object( $node->attrs ) ) {
			return false;
		}
		$keys   = explode( '.', $path );
		$last   = array_pop( $keys );
		$target = $node->attrs;
		foreach ( $keys as $key ) {
			if ( ! is_object( $target ) || ! property_exists( $target, $key ) ) {
				return false;
			}
			$target = $target->$key;
		}
		if ( ! is_object( $target ) || ! property_exists( $target, $last ) ) {
			return false;
		}
		unset( $target->$last );
		return true;
	}

	/**
	 * Converts input from an AI client (JSON decoded to PHP arrays) into
	 * Divi 5 attribute values: associative and empty arrays become objects,
	 * so "{}" is written back as "{}", not "[]"; lists stay lists.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function to_json_value( $value ) {
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
			$out   = new stdClass();
			foreach ( $value as $key => $item ) {
				$out->$key = self::to_json_value( $item );
			}
			return $out;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $value && wp_is_numeric_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			return array_map( array( self::class, 'to_json_value' ), $value );
		}
		$out = new stdClass();
		foreach ( $value as $key => $item ) {
			$out->$key = self::to_json_value( $item );
		}
		return $out;
	}
}
