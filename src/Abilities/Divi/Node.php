<?php
/**
 * One element of a Divi page: a Divi 4 shortcode or a Divi 5 block.
 *
 * A node keeps the exact bytes it was parsed from (its opening tag, its
 * closing tag and everything in between), so serializing an unchanged
 * page reproduces it byte for byte. Only a node that was edited has its
 * opening tag rebuilt.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

defined( 'ABSPATH' ) || exit;

final class Node {

	/**
	 * Element name: "et_pb_text" (Divi 4) or "divi/text" (Divi 5; core
	 * blocks written without a namespace get "core/").
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Divi 5: the name exactly as written in the comment, e.g. "divi/text"
	 * or "paragraph". Divi 4: same as $name.
	 *
	 * @var string
	 */
	public $raw_name = '';

	/**
	 * The opening tag or block comment, exactly as stored.
	 *
	 * @var string
	 */
	public $open = '';

	/**
	 * The closing tag or block comment, or "" when there is none.
	 *
	 * @var string
	 */
	public $close = '';

	/**
	 * For containers: the inner content split into child nodes and the
	 * text between them (strings). Null for leaves.
	 *
	 * @var array<int, string|Node>|null
	 */
	public $parts = null;

	/**
	 * Divi 4 leaves: the raw inner content between the tags, or null when
	 * the shortcode has no closing tag.
	 *
	 * @var string|null
	 */
	public $content = null;

	/**
	 * Divi 4: the raw attribute text between the tag name and "]".
	 *
	 * @var string
	 */
	public $attr_text = '';

	/**
	 * Attributes. Divi 4: name => raw value as WordPress parses it (still
	 * Divi-encoded, e.g. %22 for a quote). Divi 5: the decoded JSON as
	 * objects (stdClass), so empty objects stay "{}" when re-encoded; null
	 * when the block has no attributes or they are not valid JSON.
	 *
	 * @var array|\stdClass|null
	 */
	public $attrs = null;

	/**
	 * Divi 5: whether the attribute JSON could not be parsed.
	 *
	 * @var bool
	 */
	public $bad_attrs = false;

	/**
	 * Self-closing ("/]" or "/-->").
	 *
	 * @var bool
	 */
	public $void = false;

	/**
	 * Child nodes, without the text between them.
	 *
	 * @return Node[]
	 */
	public function children(): array {
		if ( null === $this->parts ) {
			return array();
		}
		$nodes = array();
		foreach ( $this->parts as $part ) {
			if ( $part instanceof self ) {
				$nodes[] = $part;
			}
		}
		return $nodes;
	}

	/**
	 * The exact markup of this node and everything inside it.
	 */
	public function to_string(): string {
		return $this->open . $this->inner() . $this->close;
	}

	/**
	 * The markup between the opening and closing tag.
	 */
	public function inner(): string {
		if ( null !== $this->parts ) {
			return self::join( $this->parts );
		}
		return (string) $this->content;
	}

	/**
	 * @param array<int, string|Node> $parts Parts.
	 */
	public static function join( array $parts ): string {
		$out = '';
		foreach ( $parts as $part ) {
			$out .= $part instanceof self ? $part->to_string() : $part;
		}
		return $out;
	}

	/**
	 * Deep copy, so a duplicate can be edited without touching the original.
	 */
	public function copy(): Node {
		$copy = clone $this;
		if ( is_object( $this->attrs ) ) {
			$copy->attrs = json_decode( (string) wp_json_encode( $this->attrs ) );
		}
		if ( null !== $this->parts ) {
			$copy->parts = array();
			foreach ( $this->parts as $part ) {
				$copy->parts[] = $part instanceof self ? $part->copy() : $part;
			}
		}
		return $copy;
	}
}
