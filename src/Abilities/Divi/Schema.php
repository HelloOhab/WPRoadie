<?php
/**
 * Divi's own module definitions, for the AI: which modules exist and the
 * attributes each one takes, in the shape Divi stores them.
 *
 * Divi 4 defines modules in PHP (ET_Builder_Element) but registers them on
 * the `wp` hook, which a REST request never reaches: the plugin's MCP
 * server answers before WordPress gets there. The framework itself is
 * loaded (Divi loads it whenever the request is not wp-admin), so the two
 * functions it would have run on `wp` can be called on demand instead.
 * That instantiates every module (Divi's own and add-ons that hook
 * et_builder_ready, such as Divi Supreme), once per request.
 *
 * Divi 5 keeps module metadata in a registry class; that is read as is.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Divi 4 attribute suffixes: responsive, hover and sticky variants of a field. */
	const SUFFIXES = array( '_tablet', '_phone', '_last_edited', '__hover', '__hover_enabled', '__sticky', '__sticky_enabled' );

	/**
	 * Divi 4 attributes every module stores that are not fields of its own.
	 * Anything starting with "_" is Divi bookkeeping (_builder_version,
	 * _module_preset, _dynamic_attributes, _i, _address ...).
	 */
	const ALWAYS_ALLOWED = array( 'admin_label', 'module_id', 'module_class', 'global_colors_info', 'global_module', 'global_parent', 'theme_builder_area', 'saved_tabs', 'fb_built', 'locked', 'collapsed', 'disabled', 'disabled_on', 'template_type', 'prev_background_color', 'next_background_color', 'hover_enabled', 'sticky_enabled', 'module_alignment' );

	/** Field types that are not settable attributes. */
	const SKIP_TYPES = array( 'skip', 'computed', 'tab_filler', 'warning', 'notice' );

	/**
	 * Fields Divi's "advanced" option groups add to every module; listed
	 * once per group in the summary instead of as hundreds of rows.
	 */
	const COMMON_PREFIXES = array( 'background', 'border', 'box_shadow', 'custom_margin', 'custom_padding', 'animation', 'hover_transition', 'max_width', 'width', 'module_alignment', 'height', 'min_height', 'max_height', 'overflow', 'position', 'z_index', 'filter', 'mix_blend_mode', 'transform', 'scroll', 'sticky', 'motion', 'link_option', 'text_shadow', 'positioning', 'custom_css', 'free_form_css', 'transition' );

	/**
	 * @var array<string, array>|null Divi 4: slug => module instance, once loaded.
	 */
	private static $modules_4 = null;

	// Divi 4.


	/**
	 * Loads Divi 4's module definitions in this request. True when
	 * ET_Builder_Element::get_modules() now has them.
	 */
	public static function load_4(): bool {
		if ( null !== self::$modules_4 ) {
			return (bool) self::$modules_4;
		}
		self::$modules_4 = array();
		if ( ! class_exists( 'ET_Builder_Element', false ) || ! function_exists( 'et_builder_add_main_elements' ) ) {
			return false;
		}
		try {
			$modules = (array) \ET_Builder_Element::get_modules();
			if ( ! $modules || ! did_action( 'et_builder_ready' ) ) {
				// Register every module, as Divi does on `wp` for a page load.
				add_filter( 'et_builder_should_load_all_module_data', '__return_true' );
				wp_raise_memory_limit( 'admin' );
				if ( function_exists( 'et_builder_init_global_settings' ) ) {
					et_builder_init_global_settings();
				}
				et_builder_add_main_elements();
				$modules = (array) \ET_Builder_Element::get_modules();
			}
		} catch ( Throwable $e ) {
			return false;
		}
		/**
		 * Filters the Divi 4 module instances the schema tools read
		 * (slug => ET_Builder_Element). Tests supply stand-ins.
		 *
		 * @param array $modules Modules.
		 */
		self::$modules_4 = (array) apply_filters( 'wproadie_divi4_modules', $modules );
		return (bool) self::$modules_4;
	}

	/**
	 * Divi 4 modules as a catalogue: slug, name, type, parent (for child
	 * modules), child slug, and whether it comes from an add-on.
	 *
	 * @return array[]
	 */
	public static function catalog_4(): array {
		if ( ! self::load_4() ) {
			return array();
		}
		$rows = array();
		foreach ( self::$modules_4 as $slug => $module ) {
			$slug = (string) $slug;
			if ( ! is_object( $module ) || 'et_pb_layout' === $slug ) {
				continue;
			}
			$row = array(
				'slug' => $slug,
				'name' => wp_strip_all_tags( (string) ( $module->name ?? Document::type_name( $slug ) ) ),
				'type' => self::type_4( $slug, $module ),
			);
			if ( ! empty( $module->child_slug ) ) {
				$row['child'] = (string) $module->child_slug;
			}
			if ( 'child' === ( $module->type ?? '' ) && ! empty( $module->parent_slug ) ) {
				$row['parent'] = (string) $module->parent_slug;
			}
			if ( 0 !== strpos( $slug, 'et_pb_' ) ) {
				$row['add_on'] = true;
			}
			$rows[] = $row;
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$order   = array(
					'structure' => 0,
					'module'    => 1,
					'fullwidth' => 2,
					'child'     => 3,
				);
				$by_type = $order[ $a['type'] ] <=> $order[ $b['type'] ];
				return 0 !== $by_type ? $by_type : strcmp( $a['slug'], $b['slug'] );
			}
		);
		return $rows;
	}

	private static function type_4( string $slug, object $module ): string {
		if ( in_array( $slug, array( 'et_pb_section', 'et_pb_row', 'et_pb_row_inner', 'et_pb_column', 'et_pb_column_inner' ), true ) ) {
			return 'structure';
		}
		if ( 'child' === ( $module->type ?? '' ) ) {
			return 'child';
		}
		if ( ! empty( $module->fullwidth ) || 0 === strpos( $slug, 'et_pb_fullwidth_' ) ) {
			return 'fullwidth';
		}
		return 'module';
	}

	/**
	 * The Divi 4 field definitions of one module (name => definition),
	 * or null when the module is unknown. Responsive and hover variants
	 * (type "skip") are left out: they follow from the base field.
	 *
	 * @return array<string, array>|null
	 */
	public static function fields_4( string $slug ): ?array {
		/**
		 * Filters the field definitions of a Divi 4 module before Divi is
		 * asked; a non-null array is used as is (tests, stand-ins).
		 *
		 * @param array|null $fields Definitions, or null to ask Divi.
		 * @param string     $slug   Module slug.
		 */
		$override = apply_filters( 'wproadie_divi4_module_fields', null, $slug );
		if ( is_array( $override ) ) {
			$fields = $override;
		} else {
			if ( ! self::load_4() || ! isset( self::$modules_4[ $slug ] ) || ! is_object( self::$modules_4[ $slug ] ) ) {
				return null;
			}
			$module = self::$modules_4[ $slug ];
			try {
				$fields = is_array( $module->fields_unprocessed ?? null ) ? $module->fields_unprocessed : array();
				if ( ! $fields && method_exists( $module, 'get_complete_fields' ) ) {
					$fields = (array) $module->get_complete_fields();
				}
			} catch ( Throwable $e ) {
				return null;
			}
		}
		$out = array();
		foreach ( $fields as $name => $field ) {
			if ( is_array( $field ) && ! in_array( $field['type'] ?? '', self::SKIP_TYPES, true ) ) {
				$out[ (string) $name ] = $field;
			}
		}
		return $out;
	}

	/**
	 * One Divi 4 field, reduced to what is needed to set it.
	 */
	public static function compact_4( string $name, array $field ): array {
		$item = array(
			'name' => $name,
			'type' => (string) ( $field['type'] ?? 'text' ),
		);
		if ( ! empty( $field['label'] ) && is_string( $field['label'] ) ) {
			$item['label'] = wp_strip_all_tags( $field['label'] );
		}
		if ( isset( $field['default'] ) && is_scalar( $field['default'] ) && '' !== (string) $field['default'] ) {
			$item['default'] = (string) $field['default'];
		}
		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) && count( $field['options'] ) <= 40 ) {
			$item['options'] = array_map( 'strval', array_keys( $field['options'] ) );
		}
		if ( ! empty( $field['allowed_units'] ) && is_array( $field['allowed_units'] ) ) {
			$item['units'] = array_values( array_map( 'strval', $field['allowed_units'] ) );
		}
		if ( ! empty( $field['range_settings'] ) && is_array( $field['range_settings'] ) ) {
			$item['range'] = array_intersect_key( $field['range_settings'], array_flip( array( 'min', 'max', 'step' ) ) );
		}
		if ( ! empty( $field['mobile_options'] ) || ! empty( $field['responsive'] ) ) {
			$item['responsive'] = true;
		}
		if ( ! empty( $field['hover'] ) ) {
			$item['hover'] = true;
		}
		if ( ! empty( $field['sticky'] ) ) {
			$item['sticky'] = true;
		}
		if ( ! empty( $field['dynamic_content'] ) ) {
			$item['dynamic'] = (string) $field['dynamic_content'];
		}
		if ( ! empty( $field['show_if'] ) && is_array( $field['show_if'] ) ) {
			$json = wp_json_encode( $field['show_if'] );
			if ( false !== $json && strlen( $json ) <= 120 ) {
				$item['when'] = $field['show_if'];
			}
		}
		return $item;
	}

	/**
	 * A module's fields grouped by settings tab and toggle, as the
	 * builder's panel shows them.
	 *
	 * @param array<string, array> $fields From fields_4().
	 * @param array                $toggles The module's settings_modal_toggles, for labels.
	 * @return array[] Each: tab, toggle, label, fields.
	 */
	public static function grouped_4( array $fields, array $toggles = array() ): array {
		$groups = array();
		foreach ( $fields as $name => $field ) {
			$tab    = (string) ( $field['tab_slug'] ?? 'general' );
			$toggle = (string) ( $field['toggle_slug'] ?? '' );
			$key    = $tab . '/' . $toggle;
			if ( ! isset( $groups[ $key ] ) ) {
				$label = $toggles[ $tab ]['toggles'][ $toggle ] ?? '';
				if ( is_array( $label ) ) {
					$label = $label['title'] ?? '';
				}
				$groups[ $key ] = array(
					'tab'    => $tab,
					'toggle' => $toggle,
					'label'  => wp_strip_all_tags( (string) $label ),
					'fields' => array(),
				);
			}
			$groups[ $key ]['fields'][] = self::compact_4( (string) $name, $field );
		}
		$order = array(
			'general'    => 0,
			'advanced'   => 1,
			'custom_css' => 2,
		);
		uasort(
			$groups,
			static function ( $a, $b ) use ( $order ) {
				$by_tab = ( $order[ $a['tab'] ] ?? 9 ) <=> ( $order[ $b['tab'] ] ?? 9 );
				return 0 !== $by_tab ? $by_tab : strcmp( $a['toggle'], $b['toggle'] );
			}
		);
		return array_values( $groups );
	}

	/**
	 * Divi 4 attribute names a module would ignore: not one of its fields,
	 * not a responsive, hover or sticky variant of one, not Divi
	 * bookkeeping.
	 *
	 * @param array<string, array> $fields From fields_4().
	 * @param array                $attrs  Attributes to check.
	 * @return string[]
	 */
	public static function unknown_4( array $fields, array $attrs ): array {
		$unknown = array();
		foreach ( array_keys( $attrs ) as $key ) {
			$key = (string) $key;
			if ( ! self::is_known_4( $fields, $key ) ) {
				$unknown[] = $key;
			}
		}
		return $unknown;
	}

	private static function is_known_4( array $fields, string $key ): bool {
		if ( '' === $key || '_' === $key[0] || isset( $fields[ $key ] ) || in_array( $key, self::ALWAYS_ALLOWED, true ) ) {
			return true;
		}
		foreach ( self::SUFFIXES as $suffix ) {
			$length = strlen( $suffix );
			if ( strlen( $key ) > $length && substr( $key, -$length ) === $suffix ) {
				$base = substr( $key, 0, -$length );
				if ( isset( $fields[ $base ] ) || in_array( $base, self::ALWAYS_ALLOWED, true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * For each unknown name, the field it most likely meant, when close.
	 *
	 * @param array<string, array> $fields  From fields_4().
	 * @param string[]             $unknown Unknown names.
	 * @return array<string, string>
	 */
	public static function suggest( array $fields, array $unknown ): array {
		$names = array_map( 'strval', array_keys( $fields ) );
		$out   = array();
		foreach ( $unknown as $key ) {
			$best  = null;
			$score = PHP_INT_MAX;
			foreach ( $names as $name ) {
				$distance = levenshtein( substr( $key, 0, 255 ), substr( $name, 0, 255 ) );
				if ( $distance < $score ) {
					$score = $distance;
					$best  = $name;
				}
			}
			if ( null !== $best && $score <= max( 3, (int) ( strlen( $key ) / 3 ) ) ) {
				$out[ $key ] = $best;
			}
		}
		return $out;
	}

	/**
	 * Field names by toggle, compact enough for an error message.
	 *
	 * @param array<string, array> $fields From fields_4().
	 * @return array<string, string[]>
	 */
	public static function outline_4( array $fields ): array {
		$out = array();
		foreach ( self::grouped_4( $fields ) as $group ) {
			$out[ $group['tab'] . '/' . $group['toggle'] ] = array_column( $group['fields'], 'name' );
		}
		return $out;
	}

	// Divi 5.


	/** Whether this site's Divi has the Divi 5 module registry. */
	public static function has_5(): bool {
		return class_exists( '\\ET\\Builder\\Packages\\ModuleLibrary\\ModuleRegistration' );
	}

	/**
	 * Divi 5 modules from Divi's registry: name, title, category and the
	 * children each accepts.
	 *
	 * @return array[]
	 */
	public static function catalog_5(): array {
		if ( ! self::has_5() ) {
			return array();
		}
		try {
			$all = (array) \ET\Builder\Packages\ModuleLibrary\ModuleRegistration::get_all_core_modules_metadata();
		} catch ( Throwable $e ) {
			return array();
		}
		$rows = array();
		foreach ( $all as $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['name'] ) || ! is_string( $meta['name'] ) ) {
				continue;
			}
			$row = array(
				'name'  => $meta['name'],
				'title' => wp_strip_all_tags( (string) ( $meta['title'] ?? '' ) ),
				'type'  => (string) ( $meta['category'] ?? 'module' ),
			);
			if ( ! empty( $meta['childrenName'] ) && is_array( $meta['childrenName'] ) ) {
				$row['children'] = array_values( array_map( 'strval', $meta['childrenName'] ) );
			}
			$rows[] = $row;
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['type'] . ' ' . $a['name'], $b['type'] . ' ' . $b['name'] );
			}
		);
		return $rows;
	}

	/**
	 * One Divi 5 module's settable attribute paths, from its metadata:
	 * every node that names an attrName, with its label and whether it
	 * takes dynamic content. Paths are written as divi-add-module takes
	 * them, with ".<breakpoint>.value" to add.
	 *
	 * @return array{name: string, title: string, type: string, children: string[], attributes: array[], style_groups: string[]}|null
	 */
	public static function module_5( string $name ): ?array {
		if ( ! self::has_5() ) {
			return null;
		}
		try {
			$meta = (array) \ET\Builder\Packages\ModuleLibrary\ModuleRegistration::get_core_module_metadata( $name );
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! $meta || ( $meta['name'] ?? '' ) !== $name ) {
			return null;
		}
		$attributes = array();
		self::walk_5( (array) ( $meta['attributes'] ?? array() ), '', $attributes );
		$groups = array();
		foreach ( (array) ( $meta['attributes'] ?? array() ) as $group => $node ) {
			if ( is_array( $node ) && isset( $node['settings']['decoration'] ) && is_array( $node['settings']['decoration'] ) ) {
				foreach ( array_keys( $node['settings']['decoration'] ) as $style ) {
					$groups[] = $group . '.decoration.' . $style;
				}
			}
		}
		return array(
			'name'         => $name,
			'title'        => wp_strip_all_tags( (string) ( $meta['title'] ?? '' ) ),
			'type'         => (string) ( $meta['category'] ?? 'module' ),
			'children'     => array_values( array_map( 'strval', (array) ( $meta['childrenName'] ?? array() ) ) ),
			'attributes'   => $attributes,
			'style_groups' => $groups,
		);
	}

	private static function walk_5( array $node, string $path, array &$out, int $depth = 0 ): void {
		if ( $depth > 12 ) {
			return;
		}
		if ( isset( $node['attrName'] ) && is_string( $node['attrName'] ) ) {
			$item = array( 'path' => $node['attrName'] );
			if ( ! empty( $node['label'] ) && is_string( $node['label'] ) ) {
				$item['label'] = wp_strip_all_tags( $node['label'] );
			}
			if ( ! empty( $node['features']['dynamicContent'] ) ) {
				$item['dynamic'] = true;
			}
			if ( isset( $node['component']['name'] ) && is_string( $node['component']['name'] ) ) {
				$item['control'] = $node['component']['name'];
			}
			$out[ $node['attrName'] ] = $item;
		}
		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) && 'decoration' !== $key ) {
				self::walk_5( $child, '' === $path ? (string) $key : $path . '.' . $key, $out, $depth + 1 );
			}
		}
	}

	/** Tests only. */
	public static function reset(): void {
		self::$modules_4 = null;
	}
}
