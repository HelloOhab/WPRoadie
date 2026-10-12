<?php
/**
 * Pure operations on Elementor element trees (the decoded _elementor_data):
 * finding, inserting, removing and copying elements, merging settings,
 * text replacement and compact summaries.
 *
 * Nothing here reads or writes the database, so every operation can be
 * tested on fixture data. Elements are handled as the arrays Elementor
 * itself decodes them to; keys this class does not know (atomic "styles",
 * "version", add-on data) are carried along untouched.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Tree {

	/**
	 * Element types that hold other elements in the classic layout model.
	 */
	const LAYOUT_TYPES = array( 'section', 'column', 'container' );

	/**
	 * Settings keys text replacement never touches: ids, dynamic tag and
	 * global references, and CSS hooks.
	 */
	const REPLACE_SKIP_KEYS = array( '_id', '__globals__', '__dynamic__', '_element_id', '_css_classes', 'css_classes', 'custom_css', '$$type', 'classes' );

	/**
	 * Settings that best describe a widget, in order of preference, for
	 * widgets without a dedicated summary.
	 */
	const SUMMARY_KEYS = array( 'title', 'title_text', 'text', 'editor', 'heading', 'description', 'description_text', 'content', 'testimonial_content', 'alert_title', 'caption', 'label', 'button_text', 'tab_title', 'shortcode', 'html' );

	/**
	 * Characters of text kept in a summary.
	 */
	const SUMMARY_LENGTH = 120;

	/**
	 * Calls $callback for every element, depth first, in document order.
	 *
	 * @param array    $elements Element list.
	 * @param callable $callback fn( array $element, array $path, ?array $parent, int $depth ).
	 *                           Return false to skip the element's children.
	 * @param array    $path     Index path of the list (internal).
	 * @param array    $owner    Parent element (internal).
	 */
	public static function walk( array $elements, callable $callback, array $path = array(), ?array $owner = null ): void {
		foreach ( array_values( $elements ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$here = array_merge( $path, array( $index ) );
			if ( false === $callback( $element, $here, $owner, count( $here ) - 1 ) ) {
				continue;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk( $element['elements'], $callback, $here, $element );
			}
		}
	}

	/**
	 * Number of elements in a tree, at any depth.
	 */
	public static function count( array $elements ): int {
		$count = 0;
		self::walk(
			$elements,
			static function () use ( &$count ) {
				++$count;
			}
		);
		return $count;
	}

	/**
	 * Every element ID in a tree.
	 *
	 * @return array<string, true>
	 */
	public static function ids( array $elements ): array {
		$ids = array();
		self::walk(
			$elements,
			static function ( $element ) use ( &$ids ) {
				if ( isset( $element['id'] ) ) {
					$ids[ (string) $element['id'] ] = true;
				}
			}
		);
		return $ids;
	}

	/**
	 * Finds an element by ID.
	 *
	 * @return array{element: array, path: int[], parent: array|null}|null
	 */
	public static function find( array $elements, string $id ): ?array {
		$found = null;
		self::walk(
			$elements,
			static function ( $element, $path, $owner ) use ( $id, &$found ) {
				if ( null !== $found ) {
					return false;
				}
				if ( isset( $element['id'] ) && (string) $element['id'] === $id ) {
					$found = array(
						'element' => $element,
						'path'    => $path,
						'parent'  => $owner,
					);
					return false;
				}
				return true;
			}
		);
		return $found;
	}

	/**
	 * Replaces the element at an index path.
	 *
	 * @param array $elements Element list.
	 * @param int[] $path     Index path from find().
	 * @param array $element  New element.
	 */
	public static function set_at( array $elements, array $path, array $element ): array {
		$index = array_shift( $path );
		if ( ! $path ) {
			$elements[ $index ] = $element;
			return $elements;
		}
		$elements[ $index ]['elements'] = self::set_at( $elements[ $index ]['elements'], $path, $element );
		return $elements;
	}

	/**
	 * Removes the element with an ID.
	 *
	 * @return array{0: array, 1: array|null} The new tree and the removed element.
	 */
	public static function remove( array $elements, string $id ): array {
		$found = self::find( $elements, $id );
		if ( ! $found ) {
			return array( $elements, null );
		}
		$path   = $found['path'];
		$index  = array_pop( $path );
		$parent = $path ? self::get_at( $elements, $path ) : null;
		$list   = $parent ? $parent['elements'] : $elements;
		array_splice( $list, $index, 1 );
		if ( ! $path ) {
			return array( $list, $found['element'] );
		}
		$parent['elements'] = $list;
		return array( self::set_at( $elements, $path, $parent ), $found['element'] );
	}

	/**
	 * The element at an index path.
	 *
	 * @param array $elements Element list.
	 * @param int[] $path     Index path.
	 */
	public static function get_at( array $elements, array $path ): ?array {
		$element = null;
		foreach ( $path as $index ) {
			if ( ! isset( $elements[ $index ] ) || ! is_array( $elements[ $index ] ) ) {
				return null;
			}
			$element  = $elements[ $index ];
			$elements = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
		}
		return $element;
	}

	/**
	 * Inserts elements under a parent (or at the top level) at a position.
	 * Checks the placement is one Elementor allows and sets isInner to
	 * match the new parent.
	 *
	 * @param array       $elements  Element list.
	 * @param string|null $parent_id Parent element ID, or null for the top level.
	 * @param int|null    $position  Index among the parent's children; null appends.
	 * @param array[]     $additions Elements to insert, in order.
	 * @return array|WP_Error The new tree.
	 */
	public static function insert( array $elements, ?string $parent_id, ?int $position, array $additions ) {
		$parent = null;
		$path   = array();
		if ( null !== $parent_id ) {
			$found = self::find( $elements, $parent_id );
			if ( ! $found ) {
				return self::error( 'parent_not_found', __( 'No element with that parent_id on this page. Get the page outline for valid IDs.', 'wproadie' ), 404 );
			}
			$parent = $found['element'];
			$path   = $found['path'];
		}

		foreach ( $additions as $i => $element ) {
			$valid = self::check_placement( $parent, $element );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$additions[ $i ] = self::fix_inner( $element, $parent );
		}

		$list     = $parent ? ( isset( $parent['elements'] ) && is_array( $parent['elements'] ) ? array_values( $parent['elements'] ) : array() ) : array_values( $elements );
		$position = null === $position ? count( $list ) : max( 0, min( $position, count( $list ) ) );
		array_splice( $list, $position, 0, $additions );

		if ( ! $parent ) {
			return $list;
		}
		$parent['elements'] = $list;
		return self::set_at( $elements, $path, $parent );
	}

	/**
	 * Whether Elementor allows $child directly inside $container (null:
	 * the top level of the page).
	 *
	 * @return true|WP_Error
	 */
	public static function check_placement( ?array $container, array $child ) {
		$type        = (string) ( $child['elType'] ?? '' );
		$parent_type = $container ? (string) ( $container['elType'] ?? '' ) : '';

		if ( ! $container ) {
			if ( 'widget' === $type ) {
				return self::error( 'invalid_placement', __( 'A widget cannot sit at the top level of a page; put it inside a container (or a section\'s column).', 'wproadie' ) );
			}
			if ( 'column' === $type ) {
				return self::error( 'invalid_placement', __( 'A column can only go inside a section.', 'wproadie' ) );
			}
			return true;
		}

		if ( 'widget' === $parent_type ) {
			return self::error( 'invalid_placement', __( 'Widgets cannot contain other elements. Choose a container, column or section as the parent.', 'wproadie' ) );
		}
		if ( 'column' === $type && 'section' !== $parent_type ) {
			return self::error( 'invalid_placement', __( 'A column can only go inside a section.', 'wproadie' ) );
		}
		if ( 'section' === $parent_type && 'column' !== $type ) {
			return self::error( 'invalid_placement', __( 'A section can only contain columns. Add the element to one of its columns instead.', 'wproadie' ) );
		}
		if ( 'column' === $parent_type ) {
			if ( 'container' === $type ) {
				return self::error( 'invalid_placement', __( 'A container cannot go inside a column; Elementor only allows widgets and inner sections there.', 'wproadie' ) );
			}
			if ( 'section' === $type && ! empty( $container['isInner'] ) ) {
				return self::error( 'invalid_placement', __( 'An inner section cannot contain another section.', 'wproadie' ) );
			}
		}
		if ( 'container' === $parent_type && in_array( $type, array( 'section', 'column' ), true ) ) {
			return self::error( 'invalid_placement', __( 'Sections and columns cannot go inside a container. Use a nested container instead.', 'wproadie' ) );
		}
		if ( 'section' === $type && ! in_array( $parent_type, array( 'column' ), true ) ) {
			return self::error( 'invalid_placement', __( 'A section can only go at the top level or inside a column (as an inner section).', 'wproadie' ) );
		}
		return true;
	}

	/**
	 * Sets isInner the way Elementor does for an element under $container:
	 * sections inside columns, their columns, and containers inside
	 * containers are inner. Other element types are left alone.
	 */
	public static function fix_inner( array $element, ?array $container ): array {
		$type        = (string) ( $element['elType'] ?? '' );
		$parent_type = $container ? (string) ( $container['elType'] ?? '' ) : '';

		if ( 'section' === $type ) {
			$element['isInner'] = 'column' === $parent_type;
		} elseif ( 'column' === $type ) {
			$element['isInner'] = ! empty( $container['isInner'] );
		} elseif ( 'container' === $type ) {
			$element['isInner'] = 'container' === $parent_type;
		} else {
			return $element;
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$element['elements'][ $i ] = self::fix_inner( $child, $element );
				}
			}
		}
		return $element;
	}

	/**
	 * Checks an element the AI supplied and fills in what Elementor
	 * expects (settings, elements, isInner for layout elements). Unknown
	 * keys are kept. IDs are not checked; callers give the element fresh
	 * ones with refresh_ids().
	 *
	 * @param mixed $element Element data.
	 * @param int   $depth   Nesting depth (internal).
	 * @return array|WP_Error
	 */
	public static function normalize( $element, int $depth = 0 ) {
		if ( $depth > 30 ) {
			return self::error( 'invalid_element', __( 'The element is nested too deeply.', 'wproadie' ) );
		}
		if ( ! is_array( $element ) || ( $element && wp_is_numeric_array( $element ) ) ) {
			return self::error( 'invalid_element', __( 'Each element must be an object with at least elType (and widgetType for widgets).', 'wproadie' ) );
		}
		$type = $element['elType'] ?? null;
		if ( ! is_string( $type ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/i', $type ) ) {
			return self::error( 'invalid_element', __( 'Every element needs an elType such as "container", "section", "column" or "widget".', 'wproadie' ) );
		}
		if ( 'widget' === $type && ( ! isset( $element['widgetType'] ) || ! is_string( $element['widgetType'] ) || ! preg_match( '/^[a-z0-9][a-z0-9_.-]*$/i', $element['widgetType'] ) ) ) {
			return self::error( 'invalid_element', __( 'A widget needs a widgetType such as "heading", "text-editor", "button" or "image".', 'wproadie' ) );
		}

		$settings = $element['settings'] ?? array();
		if ( ! is_array( $settings ) || ( $settings && wp_is_numeric_array( $settings ) ) ) {
			return self::error( 'invalid_element', __( 'An element\'s settings must be an object.', 'wproadie' ) );
		}
		$children = $element['elements'] ?? array();
		if ( ! is_array( $children ) || ( $children && ! wp_is_numeric_array( $children ) ) ) {
			return self::error( 'invalid_element', __( 'An element\'s elements must be a list of elements.', 'wproadie' ) );
		}
		if ( 'widget' === $type && $children ) {
			return self::error( 'invalid_element', __( 'Widgets cannot contain other elements.', 'wproadie' ) );
		}

		$normalized             = array_merge(
			array(
				'id'       => isset( $element['id'] ) ? (string) $element['id'] : '',
				'elType'   => $type,
				'settings' => $settings,
				'elements' => array(),
			),
			$element
		);
		$normalized['settings'] = $settings;
		foreach ( array_values( $children ) as $i => $child ) {
			$child = self::normalize( $child, $depth + 1 );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
			$valid = self::check_placement( $normalized, $child );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$normalized['elements'][ $i ] = $child;
		}
		if ( in_array( $type, self::LAYOUT_TYPES, true ) && ! isset( $normalized['isInner'] ) ) {
			$normalized['isInner'] = false;
		}
		return $normalized;
	}

	/**
	 * A fresh element ID in Elementor's format (7 lowercase hex
	 * characters), unique among $taken. Adds it to $taken.
	 *
	 * @param array<string, true> $taken IDs already in use.
	 */
	public static function new_id( array &$taken ): string {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $taken[ $id ] ) );
		$taken[ $id ] = true;
		return $id;
	}

	/**
	 * Gives an element and all its children fresh IDs, as Elementor does
	 * when duplicating or inserting a template. Atomic (v4) local style
	 * IDs that embed the element ID are renamed to match.
	 *
	 * @param array               $element Element.
	 * @param array<string, true> $taken   IDs already in use; new ones are added.
	 */
	public static function refresh_ids( array $element, array &$taken ): array {
		$old           = isset( $element['id'] ) ? (string) $element['id'] : '';
		$new           = self::new_id( $taken );
		$element['id'] = $new;

		if ( '' !== $old && ! empty( $element['styles'] ) && is_array( $element['styles'] ) && ! wp_is_numeric_array( $element['styles'] ) ) {
			$renamed = array();
			$styles  = array();
			foreach ( $element['styles'] as $key => $style ) {
				$new_key = false !== strpos( (string) $key, $old ) ? str_replace( $old, $new, (string) $key ) : (string) $key;
				if ( $new_key !== (string) $key ) {
					$renamed[ (string) $key ] = $new_key;
					if ( is_array( $style ) && isset( $style['id'] ) && (string) $style['id'] === (string) $key ) {
						$style['id'] = $new_key;
					}
				}
				$styles[ $new_key ] = $style;
			}
			$element['styles'] = $styles;
			if ( $renamed && isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::rename_strings( $element['settings'], $renamed );
			}
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$element['elements'][ $i ] = self::refresh_ids( $child, $taken );
				}
			}
		}
		return $element;
	}

	/**
	 * Replaces string values that exactly equal a key of $map.
	 *
	 * @param mixed                 $value Value.
	 * @param array<string, string> $map   Old to new.
	 * @return mixed
	 */
	private static function rename_strings( $value, array $map ) {
		if ( is_string( $value ) ) {
			return $map[ $value ] ?? $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rename_strings( $item, $map );
			}
		}
		return $value;
	}

	/**
	 * Merges changes into settings: keys are set, null removes a key.
	 * Objects (associative arrays) merge recursively, so {"link": {"url":
	 * "…"}} keeps the link's other fields; lists (repeaters such as
	 * icon_list) are replaced as a whole.
	 *
	 * @param array $settings Current settings.
	 * @param array $changes  Changes.
	 */
	public static function merge_settings( array $settings, array $changes ): array {
		foreach ( $changes as $key => $value ) {
			if ( null === $value ) {
				unset( $settings[ $key ] );
				continue;
			}
			if ( is_array( $value ) && $value && ! wp_is_numeric_array( $value ) && isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) && ( ! $settings[ $key ] || ! wp_is_numeric_array( $settings[ $key ] ) ) ) {
				$settings[ $key ] = self::merge_settings( $settings[ $key ], $value );
				continue;
			}
			$settings[ $key ] = self::strip_nulls( $value );
		}
		return $settings;
	}

	/**
	 * Drops null members of objects in a new value, so null means "absent"
	 * at every level, as in merge_settings().
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function strip_nulls( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = wp_is_numeric_array( $value );
		foreach ( $value as $key => $item ) {
			if ( null === $item && ! $is_list ) {
				unset( $value[ $key ] );
			} else {
				$value[ $key ] = self::strip_nulls( $item );
			}
		}
		return $value;
	}

	/**
	 * Finds (and optionally replaces) text in every element's settings.
	 * Only string values are searched; ids, dynamic tags, global style
	 * references and CSS classes are skipped.
	 *
	 * @param array         $elements       Element list.
	 * @param string        $search         Text to find.
	 * @param string        $replace        Replacement.
	 * @param bool          $case_sensitive Match case.
	 * @param callable|null $filter         fn( string $new_value ): string, applied to
	 *                                      each changed value (e.g. kses).
	 * @return array{0: array, 1: array[]} New tree and the list of matches:
	 *                                     element_id, elType, widgetType, key, count, before, after.
	 */
	public static function replace_text( array $elements, string $search, string $replace, bool $case_sensitive = true, ?callable $filter = null ): array {
		$matches = array();
		$pattern = '/' . preg_quote( $search, '/' ) . '/u' . ( $case_sensitive ? '' : 'i' );
		$tree    = self::replace_in_list( $elements, $pattern, $replace, $filter, $matches );
		return array( $tree, $matches );
	}

	/**
	 * @param array         $elements Element list.
	 * @param string        $pattern  Regex for the search text.
	 * @param string        $replace  Replacement.
	 * @param callable|null $filter   Value filter.
	 * @param array         $matches  Collected matches.
	 */
	private static function replace_in_list( array $elements, string $pattern, string $replace, ?callable $filter, array &$matches ): array {
		foreach ( $elements as $i => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::replace_in_value( $element['settings'], '', $element, $pattern, $replace, $filter, $matches );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::replace_in_list( $element['elements'], $pattern, $replace, $filter, $matches );
			}
			$elements[ $i ] = $element;
		}
		return $elements;
	}

	/**
	 * @param mixed         $value    Settings value.
	 * @param string        $key_path Dotted key path so far.
	 * @param array         $element  Owning element.
	 * @param string        $pattern  Regex.
	 * @param string        $replace  Replacement.
	 * @param callable|null $filter   Value filter.
	 * @param array         $matches  Collected matches.
	 * @return mixed
	 */
	private static function replace_in_value( $value, string $key_path, array $element, string $pattern, string $replace, ?callable $filter, array &$matches ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( in_array( (string) $key, self::REPLACE_SKIP_KEYS, true ) ) {
					continue;
				}
				$value[ $key ] = self::replace_in_value( $item, '' === $key_path ? (string) $key : $key_path . '.' . $key, $element, $pattern, $replace, $filter, $matches );
			}
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$count = 0;
		$new   = preg_replace_callback(
			$pattern,
			static function () use ( $replace ) {
				return $replace;
			},
			$value,
			-1,
			$count
		);
		if ( null === $new || ! $count ) {
			return $value;
		}
		if ( $filter ) {
			$new = (string) $filter( $new );
		}
		$match = array(
			'element_id' => (string) ( $element['id'] ?? '' ),
			'elType'     => (string) ( $element['elType'] ?? '' ),
			'key'        => $key_path,
			'count'      => $count,
			'before'     => self::snippet( $value, $pattern ),
			'after'      => self::text( $new, self::SUMMARY_LENGTH ),
		);
		if ( isset( $element['widgetType'] ) ) {
			$match['widgetType'] = (string) $element['widgetType'];
		}
		$matches[] = $match;
		return $new;
	}

	/**
	 * Plain text around the first match, for previews.
	 */
	private static function snippet( string $value, string $pattern ): string {
		$text = self::text( $value, 100000 );
		if ( preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = max( 0, $m[0][1] - 50 );
			$cut   = mb_strcut( $text, $start, 140 );
			return ( $start > 0 ? '…' : '' ) . $cut . ( strlen( $text ) > $start + 140 ? '…' : '' );
		}
		return self::text( $value, self::SUMMARY_LENGTH );
	}

	/**
	 * A setting value as plain text, cut to $length characters. Unwraps
	 * atomic (v4) typed values {"$$type": …, "value": …}.
	 *
	 * @param mixed $value  Setting value.
	 * @param int   $length Maximum characters.
	 */
	public static function text( $value, int $length = self::SUMMARY_LENGTH ): string {
		$value = self::unwrap( $value );
		if ( is_array( $value ) ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( mb_strlen( $text ) > $length ) {
			$text = rtrim( mb_substr( $text, 0, $length ) ) . '…';
		}
		return $text;
	}

	/**
	 * Unwraps atomic typed values, recursively for nested "value" keys.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function unwrap( $value ) {
		$guard = 0;
		while ( is_array( $value ) && isset( $value['$$type'] ) && array_key_exists( 'value', $value ) && $guard < 5 ) {
			$value = $value['value'];
			++$guard;
		}
		if ( is_array( $value ) && isset( $value['content'] ) ) {
			// Atomic "html" props keep the text in content.
			return self::unwrap( $value['content'] );
		}
		return $value;
	}

	/**
	 * A setting that may be a link object: returns its URL.
	 *
	 * @param mixed $value Setting value.
	 */
	private static function url( $value ): string {
		$value = self::unwrap( $value );
		if ( is_array( $value ) ) {
			$value = self::unwrap( $value['url'] ?? ( $value['destination'] ?? '' ) );
			if ( is_array( $value ) ) {
				return '';
			}
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A short human summary of an element: the heading text, button text
	 * and link, image URL and alt, the start of a text block, and so on.
	 */
	public static function summary( array $element ): string {
		$s    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$type = (string) ( $element['elType'] ?? '' );

		if ( 'widget' !== $type ) {
			return self::layout_summary( $element, $s );
		}

		$widget = (string) ( $element['widgetType'] ?? '' );
		$parts  = array();
		switch ( $widget ) {
			case 'heading':
			case 'e-heading':
				$parts[] = self::text( $s['title'] ?? '' );
				$link    = self::url( $s['link'] ?? '' );
				if ( '' !== $link ) {
					$parts[] = '→ ' . $link;
				}
				break;
			case 'button':
			case 'e-button':
				$parts[] = self::text( $s['text'] ?? '' );
				$link    = self::url( $s['link'] ?? '' );
				if ( '' !== $link ) {
					$parts[] = '→ ' . $link;
				}
				break;
			case 'image':
			case 'e-image':
				$image = self::unwrap( $s['image'] ?? array() );
				if ( is_array( $image ) ) {
					$src     = self::unwrap( $image['src'] ?? $image );
					$parts[] = self::url( is_array( $src ) ? $src : array( 'url' => $image['url'] ?? '' ) );
					if ( ! empty( $image['alt'] ) ) {
						$parts[] = 'alt: ' . self::text( $image['alt'] );
					}
					if ( ! empty( $image['id'] ) && is_scalar( $image['id'] ) ) {
						$parts[] = '#' . $image['id'];
					}
				}
				break;
			case 'text-editor':
			case 'e-paragraph':
				$parts[] = self::text( $s['editor'] ?? ( $s['paragraph'] ?? '' ) );
				break;
			case 'icon-list':
				$texts = array();
				foreach ( (array) ( $s['icon_list'] ?? array() ) as $item ) {
					if ( is_array( $item ) && isset( $item['text'] ) ) {
						$texts[] = self::text( $item['text'], 40 );
					}
				}
				$parts[] = self::text( implode( ' | ', $texts ) );
				break;
			case 'video':
				$parts[] = self::url( $s['youtube_url'] ?? ( $s['vimeo_url'] ?? ( $s['hosted_url'] ?? '' ) ) );
				break;
			case 'form':
				$parts[] = trim( self::text( $s['form_name'] ?? '' ) . ' (' . count( (array) ( $s['form_fields'] ?? array() ) ) . ' fields)' );
				break;
			case 'html':
				$parts[] = 'HTML: ' . self::text( esc_html( (string) self::unwrap( $s['html'] ?? '' ) ), 80 );
				break;
			case 'template':
				$parts[] = 'template #' . ( is_scalar( $s['template_id'] ?? '' ) ? (string) ( $s['template_id'] ?? '' ) : '' );
				break;
			case 'global':
				$parts[] = 'global widget template #' . ( is_scalar( $element['templateID'] ?? '' ) ? (string) ( $element['templateID'] ?? '' ) : '' );
				break;
			default:
				foreach ( self::SUMMARY_KEYS as $key ) {
					if ( isset( $s[ $key ] ) ) {
						$text = self::text( 'html' === $key ? esc_html( (string) self::unwrap( $s[ $key ] ) ) : $s[ $key ] );
						if ( '' !== $text ) {
							$parts[] = $text;
							break;
						}
					}
				}
				if ( ! $parts ) {
					$parts[] = self::first_text( $s );
				}
				$link = self::url( $s['link'] ?? '' );
				if ( '' !== $link ) {
					$parts[] = '→ ' . $link;
				}
		}
		return trim( implode( ' ', array_filter( $parts, 'strlen' ) ) );
	}

	/**
	 * The first repeater item or nested text found in settings, for widgets
	 * with no known text key (tabs, accordions, add-on widgets).
	 */
	private static function first_text( array $settings ): string {
		foreach ( $settings as $key => $value ) {
			if ( in_array( (string) $key, self::REPLACE_SKIP_KEYS, true ) || 0 === strpos( (string) $key, '_' ) ) {
				continue;
			}
			if ( is_array( $value ) && wp_is_numeric_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						foreach ( self::SUMMARY_KEYS as $text_key ) {
							if ( isset( $item[ $text_key ] ) && is_string( $item[ $text_key ] ) && '' !== trim( $item[ $text_key ] ) ) {
								return self::text( $item[ $text_key ] );
							}
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * Summary of a container, section or column: its layout in a few words.
	 */
	private static function layout_summary( array $element, array $s ): string {
		$type  = (string) ( $element['elType'] ?? '' );
		$parts = array();
		if ( 'container' === $type ) {
			$parts[] = ( isset( $s['flex_direction'] ) && is_string( $s['flex_direction'] ) && '' !== $s['flex_direction'] ) ? $s['flex_direction'] : 'column';
			if ( isset( $s['content_width'] ) && is_string( $s['content_width'] ) ) {
				$parts[] = $s['content_width'];
			}
		} elseif ( 'column' === $type && isset( $s['_column_size'] ) && is_scalar( $s['_column_size'] ) ) {
			$parts[] = ( isset( $s['_inline_size'] ) && is_numeric( $s['_inline_size'] ) ? (float) $s['_inline_size'] : $s['_column_size'] ) . '%';
		} elseif ( 'section' === $type && isset( $s['layout'] ) && is_string( $s['layout'] ) ) {
			$parts[] = $s['layout'];
		}
		if ( ! empty( $s['_title'] ) && is_string( $s['_title'] ) ) {
			// The navigator label the site builder gave it.
			$parts[] = '"' . self::text( $s['_title'], 40 ) . '"';
		}
		$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? count( $element['elements'] ) : 0;
		/* translators: %d: number of child elements. */
		$parts[] = sprintf( _n( '%d child', '%d children', $children, 'wproadie' ), $children );
		return implode( ', ', $parts );
	}

	/**
	 * One element as an outline node: id, elType, widgetType, summary and
	 * path, without settings.
	 *
	 * @param array $element Element.
	 * @param int[] $path    Index path.
	 */
	public static function node( array $element, array $path ): array {
		$node = array(
			'id'     => (string) ( $element['id'] ?? '' ),
			'elType' => (string) ( $element['elType'] ?? '' ),
		);
		if ( isset( $element['widgetType'] ) ) {
			$node['widgetType'] = (string) $element['widgetType'];
		}
		$summary = self::summary( $element );
		if ( '' !== $summary ) {
			$node['summary'] = $summary;
		}
		$node['path'] = implode( '.', $path );
		return $node;
	}

	/**
	 * A compact outline tree for the AI.
	 *
	 * @param array $elements  Element list.
	 * @param int   $max_depth Deepest level to include (0 = top level only).
	 * @param int   $limit     Maximum number of nodes.
	 * @param bool  $truncated Set when the limit cut the outline.
	 * @param int[] $path      Index path of the list (internal).
	 * @param int   $count     Nodes so far (internal).
	 */
	public static function outline( array $elements, int $max_depth, int $limit, bool &$truncated = false, array $path = array(), int &$count = 0 ): array {
		$nodes = array();
		foreach ( array_values( $elements ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( $count >= $limit ) {
				$truncated = true;
				break;
			}
			++$count;
			$here     = array_merge( $path, array( $index ) );
			$node     = self::node( $element, $here );
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( $children ) {
				if ( count( $here ) - 1 >= $max_depth ) {
					$node['children_count'] = count( $children );
				} else {
					$node['children'] = self::outline( $children, $max_depth, $limit, $truncated, $here, $count );
					if ( count( $node['children'] ) < count( $children ) ) {
						$node['children_count'] = count( $children );
					}
				}
			}
			$nodes[] = $node;
		}
		return $nodes;
	}

	/**
	 * Applies $callback to every string in a value (for sanitizing).
	 *
	 * @param mixed    $value    Value.
	 * @param callable $callback fn( string ): string.
	 * @return mixed
	 */
	public static function map_strings( $value, callable $callback ) {
		if ( is_string( $value ) ) {
			return $callback( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::map_strings( $item, $callback );
			}
		}
		return $value;
	}

	/**
	 * Applies $callback to every string in an element's settings and its
	 * children's settings (not to ids or types).
	 *
	 * @param array    $element  Element.
	 * @param callable $callback fn( string ): string.
	 */
	public static function map_element_strings( array $element, callable $callback ): array {
		if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
			$element['settings'] = self::map_strings( $element['settings'], $callback );
		}
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$element['elements'][ $i ] = self::map_element_strings( $child, $callback );
				}
			}
		}
		return $element;
	}

	private static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wproadie_' . $code, $message, array( 'status' => $status ) );
	}
}
