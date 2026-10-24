<?php
/**
 * The line between content and code in page-builder writes.
 *
 * A builder page is meant to be edited in the builder afterwards. Raw
 * HTML that fakes a layout (a <div> grid in a Text module) and inline
 * CSS (style="", <style>) render once and are dead in the builder: no
 * responsive settings, no editing, no dynamic content. The builder
 * tools refuse both unless the call says the person asked for exactly
 * that (an embed code, a widget script), through two flags:
 *
 * - allow_raw_html: Code/HTML modules, and layout or embed tags inside
 *   text content.
 * - allow_inline_css: style="" attributes, <style> blocks, custom CSS
 *   settings.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RawHtml {

	/**
	 * Tags that build layout or embed something, which text content
	 * should never need; ordinary prose tags (p, h1–h6, ul, li, a,
	 * strong, em, img, br, span, blockquote, table) are fine.
	 */
	const LAYOUT_TAGS = array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main', 'iframe', 'form', 'svg', 'canvas', 'video', 'audio', 'object', 'embed', 'script', 'template' );

	/** Builder modules and widgets whose whole purpose is raw code. */
	const CODE_MODULES = array( 'et_pb_code', 'et_pb_fullwidth_code', 'divi/code', 'divi/fullwidth-code', 'html' );

	/** Settings keys that hold custom CSS. */
	const CSS_SETTINGS = array( 'custom_css', '_custom_css', 'custom_css_main_element', 'custom_css_before', 'custom_css_after', 'free_form_css' );

	/** Flag names, as the abilities take them. */
	const FLAG_HTML = 'allow_raw_html';
	const FLAG_CSS  = 'allow_inline_css';

	/**
	 * Input schema for the two flags.
	 *
	 * @return array<string, array>
	 */
	public static function flags_schema(): array {
		return array(
			self::FLAG_HTML => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Allow a Code/HTML module, or layout and embed tags (div, iframe, script …) inside text. Only when the person asked for exactly that snippet; never to fake a layout.', 'wproadie' ),
			),
			self::FLAG_CSS  => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Allow style="" attributes, <style> blocks and custom CSS settings. Only when the person asked for CSS; every visual property has a module setting.', 'wproadie' ),
			),
		);
	}

	/**
	 * What a piece of HTML contains that the builder should hold as
	 * settings instead: layout/embed tags and inline CSS.
	 *
	 * @return array{tags: string[], css: string[]} Tag names found, and the CSS forms found ("style attribute", "<style> block").
	 */
	public static function inspect( string $html ): array {
		$found = array(
			'tags' => array(),
			'css'  => array(),
		);
		if ( false === strpos( $html, '<' ) && false === stripos( $html, 'style' ) ) {
			return $found;
		}
		if ( preg_match_all( '/<\s*([a-z][a-z0-9-]*)\b/i', $html, $m ) ) {
			foreach ( array_unique( array_map( 'strtolower', $m[1] ) ) as $tag ) {
				if ( in_array( $tag, self::LAYOUT_TAGS, true ) ) {
					$found['tags'][] = $tag;
				}
			}
		}
		if ( preg_match( '/<\s*style\b/i', $html ) ) {
			$found['css'][] = '<style> block';
		}
		if ( preg_match( '/<[^>]*\sstyle\s*=/i', $html ) ) {
			$found['css'][] = 'style attribute';
		}
		return $found;
	}

	/**
	 * Checks one builder element: its type, its text content and the
	 * string values among its settings.
	 *
	 * @param string   $type    Module or widget type (full name).
	 * @param string[] $strings Content and setting strings to inspect.
	 * @param array    $css_keys Settings keys present that hold custom CSS.
	 * @param array    $input   The call's input (for the flags).
	 * @param string   $where   Where the element is, for the message.
	 * @param string   $prefix  Error code prefix ("divi_" or "").
	 * @return true|WP_Error
	 */
	public static function check( string $type, array $strings, array $css_keys, array $input, string $where = '', string $prefix = '' ) {
		$allow_html = ! empty( $input[ self::FLAG_HTML ] );
		$allow_css  = ! empty( $input[ self::FLAG_CSS ] );
		$at         = '' !== $where ? ' (' . $where . ')' : '';

		if ( ! $allow_html && in_array( strtolower( $type ), self::CODE_MODULES, true ) ) {
			return new WP_Error(
				'wproadie_' . $prefix . 'raw_html',
				sprintf(
					/* translators: 1: module type, 2: where, 3: flag name. */
					__( '%1$s is a raw-code module%2$s: it renders once and cannot be edited, made responsive or given dynamic content in the builder. Build the design from the builder\'s own modules (text, heading, image, button, blurb, video …). Only when the person asked for this exact embed (a form embed, a map, a widget script) send %3$s: true.', 'wproadie' ),
					$type,
					$at,
					self::FLAG_HTML
				),
				array(
					'status' => 400,
					'flag'   => self::FLAG_HTML,
				)
			);
		}

		if ( ! $allow_css && $css_keys ) {
			return new WP_Error(
				'wproadie_' . $prefix . 'inline_css',
				sprintf(
					/* translators: 1: comma-separated setting names, 2: where, 3: flag name. */
					__( 'Custom CSS (%1$s)%2$s is refused by default: every visual property has a module setting (colour, font, spacing, border, shadow, width, alignment, responsive values), which stays editable in the builder. Set those instead. Only when the person asked for CSS send %3$s: true.', 'wproadie' ),
					implode( ', ', $css_keys ),
					$at,
					self::FLAG_CSS
				),
				array(
					'status' => 400,
					'flag'   => self::FLAG_CSS,
				)
			);
		}

		$tags = array();
		$css  = array();
		foreach ( $strings as $string ) {
			$found = self::inspect( (string) $string );
			$tags  = array_merge( $tags, $found['tags'] );
			$css   = array_merge( $css, $found['css'] );
		}
		$tags = array_values( array_unique( $tags ) );
		$css  = array_values( array_unique( $css ) );

		if ( ! $allow_html && $tags ) {
			return new WP_Error(
				'wproadie_' . $prefix . 'raw_html',
				sprintf(
					/* translators: 1: comma-separated tag names, 2: module type, 3: where, 4: flag name. */
					__( 'The content holds layout or embed HTML (<%1$s>) inside %2$s%3$s. Text content is for prose (p, headings, lists, links, images); columns, cards, grids and embeds are built from the builder\'s own modules, one per element, so the page stays editable and responsive. Only when the person asked for this exact snippet send %4$s: true.', 'wproadie' ),
					implode( '>, <', $tags ),
					$type,
					$at,
					self::FLAG_HTML
				),
				array(
					'status' => 400,
					'flag'   => self::FLAG_HTML,
					'tags'   => $tags,
				)
			);
		}
		if ( ! $allow_css && $css ) {
			return new WP_Error(
				'wproadie_' . $prefix . 'inline_css',
				sprintf(
					/* translators: 1: what was found, 2: module type, 3: where, 4: flag name. */
					__( 'The content holds inline CSS (%1$s) inside %2$s%3$s. Use the module\'s own settings for colour, font, size, spacing, alignment and the rest; they stay editable and responsive in the builder. Only when the person asked for CSS send %4$s: true.', 'wproadie' ),
					implode( ', ', $css ),
					$type,
					$at,
					self::FLAG_CSS
				),
				array(
					'status' => 400,
					'flag'   => self::FLAG_CSS,
				)
			);
		}
		return true;
	}

	/**
	 * Every string in a value, at any depth.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public static function strings( $value ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}
		$out = array();
		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $item ) {
				$out = array_merge( $out, self::strings( $item ) );
			}
		}
		return $out;
	}

	/**
	 * The CSS-holding keys among a flat list of setting or attribute names
	 * (Divi 5 dotted paths count by their first segment or last segment).
	 *
	 * @param string[] $keys Names.
	 * @return string[]
	 */
	public static function css_keys( array $keys ): array {
		$found = array();
		foreach ( $keys as $key ) {
			$key   = (string) $key;
			$parts = explode( '.', $key );
			if ( in_array( strtolower( $key ), self::CSS_SETTINGS, true ) || in_array( strtolower( $parts[0] ), array( 'css', 'customcss' ), true ) || in_array( strtolower( end( $parts ) ), self::CSS_SETTINGS, true ) ) {
				$found[] = $key;
			}
		}
		return $found;
	}
}
