<?php
/**
 * Inserts a module, or a new section or row with columns, into a Divi page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use stdClass;
use WP_Error;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class AddModule extends DiviAbility {

	public function slug(): string {
		return 'divi-add-module';
	}

	public function label(): string {
		return __( 'Add Divi module', 'wproadie' );
	}

	public function description(): string {
		return __( 'Inserts a new element into a Divi page. A module (e.g. type "text", "button", "image", "blurb", "cta" or a full name like et_pb_text / divi/text) goes into a column: container is the column\'s path from divi-get-page, index its position (0 = first; default: last). A new section goes at the top level (container "" or omitted) and a new row into a section; pass columns (e.g. ["1_2","1_2"]) to create the row and empty columns inside, then add modules to them. Divi 4: attributes is {name: text} (e.g. {"button_text": "Call us", "button_url": "tel:123"}), content is the HTML between the tags (Text module body). Divi 5: attributes is the block\'s JSON (e.g. {"button": {"innerContent": {"desktop": {"value": {"text": "Call us", "linkUrl": "tel:123"}}}}}); dotted keys like "title.innerContent.desktop.value" also work; content is shorthand for content.innerContent.desktop.value. The page\'s builder version is added for you. HTML is filtered with wp_kses_post for users without unfiltered_html. To build a whole section in one call, pass children: a tree of {type, attributes, content, width, children} (section → rows → columns → modules; a column takes width, e.g. "1_2"); it is checked and saved as one change with one undo_id. Returns the new element\'s path and an undo_id for undo-change. Code modules, layout HTML inside text and inline CSS are refused unless allow_raw_html / allow_inline_css say the person asked for them. Divi 4 attribute names the module has no field for are refused with the likely right name (divi-get-module-schema lists them). Before building or restyling a page, read the divi-build-page skill with get-skill (and html-to-divi when recreating an HTML design).', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                       => self::id_property(),
				'container'                => array(
					'type'        => 'string',
					'pattern'     => '^([0-9]+(\.[0-9]+)*)?$',
					'default'     => '',
					'description' => __( 'Path of the column (or section, row or parent module) to insert into; "" for the page itself (sections).', 'wproadie' ),
				),
				'index'                    => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Position among the container\'s children, 0 = first. Default: after the last.', 'wproadie' ),
				),
				'type'                     => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_/-]+$',
					'maxLength'   => 100,
					'description' => __( 'Module type: short ("text", "button", "section", "row") or full (et_pb_text for Divi 4, divi/text for Divi 5).', 'wproadie' ),
				),
				'attributes'               => array(
					'type'          => 'object',
					'maxProperties' => 200,
					'default'       => array(),
					'description'   => __( 'Divi 4: attribute name => text. Divi 5: the block\'s attribute JSON (dotted keys allowed).', 'wproadie' ),
				),
				'content'                  => array(
					'type'        => 'string',
					'maxLength'   => 500000,
					'description' => __( 'Divi 4: HTML between the module\'s tags. Divi 5: content.innerContent.desktop.value.', 'wproadie' ),
				),
				'columns'                  => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 6,
					'items'       => array(
						'type'    => 'string',
						'pattern' => self::WIDTH_PATTERN,
					),
					'description' => __( 'For a new section or row: column widths, e.g. ["4_4"], ["1_2","1_2"], ["1_3","2_3"].', 'wproadie' ),
				),
				'width'                    => array(
					'type'        => 'string',
					'pattern'     => self::WIDTH_PATTERN,
					'description' => __( 'For a new column: its width, e.g. "1_2".', 'wproadie' ),
				),
				'allow_raw_html'           => \WPRoadie\Abilities\RawHtml::flags_schema()['allow_raw_html'],
				'allow_inline_css'         => \WPRoadie\Abilities\RawHtml::flags_schema()['allow_inline_css'],
				'allow_unknown_attributes' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Divi 4: store attribute names the module has no field for instead of refusing them (only for names you know an add-on reads).', 'wproadie' ),
				),
				'children'                 => array(
					'type'        => 'array',
					'maxItems'    => 100,
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'Elements to create inside the new one, each {type, attributes, content, width, columns, children}: rows in a section, columns (with width) in a row, modules in a column. Not together with columns.', 'wproadie' ),
				),
			),
			'required'             => array( 'id', 'type' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = Divi::load( (int) $input['id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$doc       = $loaded['doc'];
		$container = $doc->find_container( (string) ( $input['container'] ?? '' ) );
		if ( is_wp_error( $container ) ) {
			return $container;
		}
		$global = $this->check_not_global( $doc, $container );
		if ( is_wp_error( $global ) ) {
			return $global;
		}

		$sanitized           = false;
		$count               = 0;
		$this->allow_unknown = ! empty( $input['allow_unknown_attributes'] );
		$this->flags         = array_intersect_key( $input, \WPRoadie\Abilities\RawHtml::flags_schema() );
		$node                = $this->build_tree( $doc, $container['node'], $input, 0, '', $sanitized, $count );
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$placement = $doc->check_placement( $container['node'], $node );
		if ( is_wp_error( $placement ) ) {
			return $placement;
		}

		$count = count( $container['node']->children() );
		$index = isset( $input['index'] ) ? min( (int) $input['index'], $count ) : $count;
		$doc->insert( $container['node'], $index, $node );

		$saved = $this->save( $loaded['post'], $doc );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$added = null;
		foreach ( $doc->all() as $entry ) {
			if ( $entry['node'] === $node ) {
				$added = $entry;
				break;
			}
		}
		$result = array_merge( $saved, array( 'module' => $added ? $doc->describe( $added ) : null ) );
		if ( $added && $node->children() ) {
			$result['children'] = array();
			foreach ( $doc->all() as $entry ) {
				if ( 0 === strpos( $entry['path'], $added['path'] . '.' ) ) {
					$result['children'][] = $doc->describe( $entry );
				}
			}
		}
		if ( $sanitized ) {
			$result['sanitized'] = __( 'Some HTML was removed because your account cannot save unfiltered HTML (scripts, iframes, event attributes).', 'wproadie' );
		}
		return $result;
	}

	const WIDTH_PATTERN = '^[1-9][0-9]?_[1-9][0-9]?$';

	/** Most elements one call may create, and how deep a tree may go. */
	const MAX_TREE_NODES = 400;
	const MAX_TREE_DEPTH = 6;

	const SPEC_KEYS = array( 'type', 'attributes', 'content', 'columns', 'width', 'children' );

	/** @var bool Whether this call stores attribute names Divi has no field for. */
	private $allow_unknown = false;

	/** @var string Where in the input the element being built sits, for messages. */
	private $where = '';

	/** @var array The call's allow_raw_html / allow_inline_css flags. */
	private $flags = array();

	/**
	 * Builds the new element and, from its children, everything inside it.
	 * Each child is checked against its parent as divi-add-module checks a
	 * single insert, so a tree can only produce what single calls could.
	 *
	 * @param Document $doc       The page.
	 * @param Node     $container Where the element goes.
	 * @param array    $spec      The element: type, attributes, content, width, columns, children.
	 * @param int      $depth     How deep in the tree.
	 * @param string   $where     Location of $spec in the input, for errors ("" = top).
	 * @param bool     $sanitized Set when HTML was filtered.
	 * @param int      $count     Elements built so far.
	 * @return Node|WP_Error
	 */
	private function build_tree( Document $doc, Node $container, array $spec, int $depth, string $where, bool &$sanitized, int &$count ) {
		if ( ++$count > self::MAX_TREE_NODES ) {
			/* translators: %d: maximum number of elements. */
			return Divi::error( 'too_large', sprintf( __( 'One call can create at most %d elements; split the page into sections.', 'wproadie' ), self::MAX_TREE_NODES ) );
		}
		$problem = '' === $where ? null : $this->check_spec( $spec );
		if ( null === $problem && isset( $spec['children'] ) ) {
			if ( ! is_array( $spec['children'] ) || array_values( $spec['children'] ) !== $spec['children'] ) {
				$problem = __( 'children must be a list.', 'wproadie' );
			} elseif ( ! empty( $spec['columns'] ) && $spec['children'] ) {
				$problem = __( 'Use columns or children, not both: give each column as a child with a width.', 'wproadie' );
			} elseif ( $depth >= self::MAX_TREE_DEPTH && $spec['children'] ) {
				$problem = __( 'The tree is nested too deeply.', 'wproadie' );
			}
		}
		if ( null !== $problem ) {
			return Divi::error( 'bad_children', $this->at( $where, $problem ) );
		}

		$spec        = $this->apply_widths( $doc, $container, $spec );
		$this->where = $where;
		$node        = is_wp_error( $spec ) ? $spec : $this->build( $doc, $container, $spec, $sanitized );
		if ( is_wp_error( $node ) ) {
			return '' === $where ? $node : new WP_Error( $node->get_error_code(), $this->at( $where, $node->get_error_message() ), $node->get_error_data() );
		}

		foreach ( (array) ( $spec['children'] ?? array() ) as $i => $child ) {
			$path = ( '' === $where ? '' : $where . '.' ) . 'children[' . $i . ']';
			if ( ! is_array( $child ) ) {
				return Divi::error( 'bad_children', $this->at( $path, __( 'Each child is an object with a type.', 'wproadie' ) ) );
			}
			$built = $this->build_tree( $doc, $node, $child, $depth + 1, $path, $sanitized, $count );
			if ( is_wp_error( $built ) ) {
				return $built;
			}
			$placement = $doc->check_placement( $node, $built );
			if ( is_wp_error( $placement ) ) {
				return new WP_Error( $placement->get_error_code(), $this->at( $path, $placement->get_error_message() ), $placement->get_error_data() );
			}
			$doc->insert( $node, count( $node->children() ), $built );
		}
		return $node;
	}

	private function at( string $where, string $message ): string {
		return '' === $where ? $message : $where . ': ' . $message;
	}

	/**
	 * The input schema checks the top level; children are checked here.
	 */
	private function check_spec( array $spec ): ?string {
		$unknown = array_diff( array_keys( $spec ), self::SPEC_KEYS );
		if ( $unknown ) {
			/* translators: 1: unknown keys, 2: allowed keys. */
			return sprintf( __( 'Unknown key %1$s; a child takes %2$s.', 'wproadie' ), implode( ', ', $unknown ), implode( ', ', self::SPEC_KEYS ) );
		}
		if ( ! isset( $spec['type'] ) || ! is_string( $spec['type'] ) || ! preg_match( '/^[a-z0-9_\/-]{1,100}$/', $spec['type'] ) ) {
			return __( 'type is required: a module name such as "text" or "column".', 'wproadie' );
		}
		if ( isset( $spec['attributes'] ) && ! is_array( $spec['attributes'] ) ) {
			return __( 'attributes must be an object.', 'wproadie' );
		}
		if ( isset( $spec['content'] ) && ! is_string( $spec['content'] ) ) {
			return __( 'content must be text.', 'wproadie' );
		}
		$widths = isset( $spec['columns'] ) ? $spec['columns'] : array();
		if ( ! is_array( $widths ) || count( $widths ) > 6 ) {
			return __( 'columns must be a list of up to 6 widths.', 'wproadie' );
		}
		if ( isset( $spec['width'] ) ) {
			$widths[] = $spec['width'];
		}
		foreach ( $widths as $width ) {
			if ( ! is_string( $width ) || ! preg_match( '/' . self::WIDTH_PATTERN . '/', $width ) ) {
				return __( 'A width looks like "1_2" or "4_4".', 'wproadie' );
			}
		}
		return null;
	}

	/**
	 * Turns a column's width into the attributes Divi stores for it, and
	 * gives a row whose children are all sized columns its structure.
	 *
	 * @return array|WP_Error The spec with attributes filled in.
	 */
	private function apply_widths( Document $doc, Node $container, array $spec ) {
		$attrs = isset( $spec['attributes'] ) ? (array) $spec['attributes'] : array();
		$role  = $doc->role( $this->probe( $this->normalize_type( $doc, $container, (string) $spec['type'] ) ) );

		if ( isset( $spec['width'] ) ) {
			$width = (string) $spec['width'];
			if ( 'column' !== $role ) {
				return Divi::error( 'bad_width', __( 'width only applies to a column.', 'wproadie' ) );
			}
			if ( 5 === $doc->format ) {
				list( $num, $den ) = array_map( 'intval', explode( '_', $width ) );
				$attrs            += array( 'module.advanced.type.desktop.value' => $width );
				if ( $den > 0 && 0 === ( 24 * $num ) % $den ) {
					$attrs += array( 'module.decoration.sizing.desktop.value.flexType' => ( 24 * $num / $den ) . '_24' );
				}
			} else {
				$attrs += array( 'type' => $width );
			}
		} elseif ( 'column' === $role && 4 === $doc->format && ! isset( $attrs['type'] ) ) {
			return Divi::error( 'bad_width', __( 'A new column needs a width, e.g. "1_2".', 'wproadie' ) );
		}

		$children = isset( $spec['children'] ) && is_array( $spec['children'] ) ? $spec['children'] : array();
		$widths   = array();
		foreach ( $children as $child ) {
			if ( is_array( $child ) && isset( $child['width'] ) && is_string( $child['width'] ) ) {
				$widths[] = $child['width'];
			}
		}
		if ( 'row' === $role && $widths && count( $widths ) === count( $children ) ) {
			if ( 5 === $doc->format ) {
				if ( 1 === count( array_unique( $widths ) ) && ! isset( $attrs['module'] ) ) {
					$attrs += array( 'module.advanced.flexColumnStructure.desktop.value' => 'equal-columns_' . count( $widths ) );
				}
			} else {
				$attrs += array( 'column_structure' => implode( ',', $widths ) );
			}
		}
		$spec['attributes'] = $attrs;
		return $spec;
	}

	/**
	 * Builds the new element.
	 *
	 * @return Node|WP_Error
	 */
	private function build( Document $doc, Node $container, array $input, bool &$sanitized ) {
		$type    = $this->normalize_type( $doc, $container, (string) $input['type'] );
		$attrs   = isset( $input['attributes'] ) ? (array) $input['attributes'] : array();
		$columns = isset( $input['columns'] ) ? array_map( 'strval', (array) $input['columns'] ) : array();
		$role    = $doc->role( $this->probe( $type ) );

		$strings = \WPRoadie\Abilities\RawHtml::strings( $attrs );
		if ( isset( $input['content'] ) ) {
			$strings[] = (string) $input['content'];
		}
		$code = \WPRoadie\Abilities\RawHtml::check( $type, $strings, \WPRoadie\Abilities\RawHtml::css_keys( array_keys( $attrs ) ), $this->flags, $this->where, 'divi_' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		if ( $columns && ! in_array( $role, array( 'section', 'row' ), true ) ) {
			return Divi::error( 'bad_columns', __( 'columns only applies to a new section or row.', 'wproadie' ) );
		}
		if ( 4 === $doc->format && $this->is_specialty_or_fullwidth( $attrs ) && $columns ) {
			return Divi::error( 'bad_columns', __( 'columns cannot be combined with a specialty or fullwidth section; add a regular section, or build those in the Divi Builder.', 'wproadie' ) );
		}

		return 5 === $doc->format
			? $this->build_5( $doc, $type, $role, $attrs, $input, $columns, $sanitized )
			: $this->build_4( $doc, $type, $role, $attrs, $input, $columns, $sanitized );
	}

	private function probe( string $type ): Node {
		$node       = new Node();
		$node->name = $type;
		return $node;
	}

	/**
	 * Full type name for the page's format; Divi 4 rows and columns get
	 * their _inner variant inside a specialty column.
	 */
	private function normalize_type( Document $doc, Node $container, string $type ): string {
		if ( 5 === $doc->format ) {
			if ( false === strpos( $type, '/' ) ) {
				$type = 'divi/' . str_replace( '_', '-', $type );
			}
			return $type;
		}
		$type = str_replace( '-', '_', $type );
		if ( self::needs_divi_prefix( $type ) ) {
			$type = 'et_pb_' . $type;
		}
		$in_column = 'column' === $doc->role( $container );
		$in_inner  = 'et_pb_row_inner' === $container->name;
		if ( in_array( $type, array( 'et_pb_row', 'et_pb_row_inner' ), true ) ) {
			$type = $in_column ? 'et_pb_row_inner' : 'et_pb_row';
		} elseif ( in_array( $type, array( 'et_pb_column', 'et_pb_column_inner' ), true ) ) {
			$type = $in_inner ? 'et_pb_column_inner' : 'et_pb_column';
		}
		return $type;
	}

	/**
	 * Divi 4's own module slugs, as they follow et_pb_. A short name is
	 * prefixed only when it is one of these (or a registered et_pb_ module).
	 * Any other name is a third-party module's full shortcode (dsm_button,
	 * dipl_*, ...) and is kept as given: in REST and MCP requests Divi does
	 * not register shortcodes, so shortcode_exists() cannot be trusted to
	 * tell them apart.
	 */
	const DIVI4_MODULES = array(
		'section',
		'row',
		'row_inner',
		'column',
		'column_inner',
		'accordion',
		'accordion_item',
		'audio',
		'blog',
		'blurb',
		'button',
		'circle_counter',
		'code',
		'comments',
		'contact_field',
		'contact_form',
		'countdown_timer',
		'counter',
		'counters',
		'cta',
		'divider',
		'filterable_portfolio',
		'fullwidth_code',
		'fullwidth_header',
		'fullwidth_image',
		'fullwidth_map',
		'fullwidth_menu',
		'fullwidth_portfolio',
		'fullwidth_post_content',
		'fullwidth_post_slider',
		'fullwidth_post_title',
		'fullwidth_slider',
		'gallery',
		'icon',
		'image',
		'login',
		'map',
		'map_pin',
		'menu',
		'number_counter',
		'portfolio',
		'post_content',
		'post_nav',
		'post_slider',
		'post_title',
		'pricing_table',
		'pricing_tables',
		'pricing_item',
		'search',
		'shop',
		'sidebar',
		'signup',
		'signup_custom_field',
		'slide',
		'slider',
		'social_media_follow',
		'social_media_follow_network',
		'tab',
		'tabs',
		'team_member',
		'testimonial',
		'text',
		'toggle',
		'video',
		'video_slider',
		'video_slider_item',
		'heading',
	);

	public static function needs_divi_prefix( string $type ): bool {
		if ( 0 === strpos( $type, 'et_pb_' ) ) {
			return false;
		}
		if ( in_array( $type, self::DIVI4_MODULES, true ) || 0 === strpos( $type, 'wc_' ) ) {
			return true;
		}
		return shortcode_exists( 'et_pb_' . $type ) && ! shortcode_exists( $type );
	}

	private function is_specialty_or_fullwidth( array $attrs ): bool {
		return 'on' === ( $attrs['specialty'] ?? '' ) || 'on' === ( $attrs['fullwidth'] ?? '' );
	}

	/**
	 * Checks the type exists when Divi is loaded (it registers its
	 * shortcodes and blocks); without Divi loaded, any name is accepted.
	 */
	private function check_registered( Document $doc, string $type ): ?WP_Error {
		if ( 5 === $doc->format ) {
			$registry = \WP_Block_Type_Registry::get_instance();
			if ( $registry->is_registered( 'divi/section' ) && ! $registry->is_registered( $type ) && 'divi/global-layout' !== $type ) {
				/* translators: %s: block name. */
				return Divi::error( 'unknown_type', sprintf( __( '"%s" is not a Divi 5 module on this site.', 'wproadie' ), $type ) );
			}
			return null;
		}
		if ( shortcode_exists( 'et_pb_section' ) && ! shortcode_exists( $type ) ) {
			/* translators: %s: shortcode name. */
			return Divi::error( 'unknown_type', sprintf( __( '"%s" is not a Divi 4 module on this site.', 'wproadie' ), $type ) );
		}
		return null;
	}

	/**
	 * @return Node|WP_Error
	 */
	private function build_4( Document $doc, string $type, string $role, array $attrs, array $input, array $columns, bool &$sanitized ) {
		$unknown = $this->check_registered( $doc, $type );
		if ( $unknown ) {
			return $unknown;
		}
		$clean = array();
		foreach ( $attrs as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				/* translators: %s: attribute name. */
				return Divi::error( 'bad_attribute', sprintf( __( '"%s" is not a valid Divi 4 attribute name.', 'wproadie' ), $key ) );
			}
			if ( in_array( strtolower( $key ), UpdateModule::PROTECTED_ATTRS, true ) ) {
				/* translators: %s: attribute name. */
				return Divi::error( 'protected_attribute', sprintf( __( '"%s" cannot be set here; insert library items with divi-insert-layout.', 'wproadie' ), $key ) );
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'on' : 'off';
			} elseif ( ! is_scalar( $value ) ) {
				/* translators: %s: attribute name. */
				return Divi::error( 'bad_value', sprintf( __( 'Divi 4 attribute values are text; "%s" got a list or object.', 'wproadie' ), $key ) );
			}
			$clean[ $key ] = Divi::clean_attr( (string) $value, $sanitized );
		}
		if ( empty( $this->allow_unknown ) ) {
			$known = $this->check_attributes_4( $type, $clean, $this->where );
			if ( is_wp_error( $known ) ) {
				return $known;
			}
		}
		$version = $doc->builder_version();
		if ( '' !== $version && ! isset( $clean['_builder_version'] ) ) {
			$clean = array_merge( array( '_builder_version' => $version ), $clean );
		}

		$children = array();
		if ( $columns ) {
			$cols = array();
			foreach ( $columns as $width ) {
				$col_type = 'section' === $role || 'et_pb_row' === $type ? 'et_pb_column' : 'et_pb_column_inner';
				$col      = Shortcodes::build( $col_type, $this->with_version( array( 'type' => $width ), $version ) );
				if ( is_wp_error( $col ) ) {
					return $col;
				}
				$cols[] = $col;
			}
			$row_attrs = $this->with_version( array( 'column_structure' => implode( ',', $columns ) ), $version );
			if ( 'section' === $role ) {
				$row = Shortcodes::build( 'et_pb_row', $row_attrs, null, $cols );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				$children = array( $row );
			} else {
				if ( ! isset( $clean['column_structure'] ) ) {
					$clean['column_structure'] = implode( ',', $columns );
				}
				$children = $cols;
			}
		}

		$content = isset( $input['content'] ) ? Divi::clean( (string) $input['content'], $sanitized ) : null;
		if ( null !== $content && in_array( $type, Shortcodes::CONTAINERS, true ) ) {
			return Divi::error( 'container_content', __( 'This element holds other elements; add those with divi-add-module instead of content.', 'wproadie' ) );
		}
		return Shortcodes::build( $type, $clean, $content, $children );
	}

	private function with_version( array $attrs, string $version ): array {
		return '' === $version ? $attrs : array_merge( array( '_builder_version' => $version ), $attrs );
	}

	/**
	 * @return Node|WP_Error
	 */
	private function build_5( Document $doc, string $type, string $role, array $attrs, array $input, array $columns, bool &$sanitized ) {
		$unknown = $this->check_registered( $doc, $type );
		if ( $unknown ) {
			return $unknown;
		}
		$node        = new Node();
		$node->attrs = new stdClass();
		$version     = $doc->builder_version();

		foreach ( $attrs as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $key ) ) {
				/* translators: %s: attribute path. */
				return Divi::error( 'bad_attribute', sprintf( __( '"%s" is not a valid Divi 5 attribute name or dotted path.', 'wproadie' ), $key ) );
			}
			if ( in_array( strtolower( explode( '.', $key )[0] ), UpdateModule::PROTECTED_ATTRS, true ) ) {
				/* translators: %s: attribute name. */
				return Divi::error( 'protected_attribute', sprintf( __( '"%s" cannot be set here; insert library items with divi-insert-layout.', 'wproadie' ), $key ) );
			}
			$done = Blocks::set_path( $node, $key, Divi::clean( Blocks::to_json_value( $value ), $sanitized ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		if ( isset( $input['content'] ) ) {
			if ( in_array( $type, Blocks::CONTAINERS, true ) ) {
				return Divi::error( 'container_content', __( 'This element holds other elements; add those with divi-add-module instead of content.', 'wproadie' ) );
			}
			Blocks::set_path( $node, 'content.innerContent.desktop.value', Divi::clean( (string) $input['content'], $sanitized ) );
		}
		if ( '' !== $version && null === Blocks::get_path( $node->attrs, Blocks::VERSION ) ) {
			Blocks::set_path( $node, Blocks::VERSION, $version );
		}

		$children = array();
		if ( $columns ) {
			$cols = array();
			foreach ( $columns as $width ) {
				$col = Blocks::build( 'divi/column', $this->column_attrs_5( $width, $version ) );
				if ( is_wp_error( $col ) ) {
					return $col;
				}
				$cols[] = $col;
			}
			$row_attrs = $this->row_attrs_5( $columns, $version );
			if ( 'section' === $role ) {
				$row = Blocks::build( 'divi/row', $row_attrs, $cols );
				if ( is_wp_error( $row ) ) {
					return $row;
				}
				$children = array( $row );
			} else {
				$structure = Blocks::get_path( $row_attrs, 'module.advanced.flexColumnStructure' );
				if ( null !== $structure && null === Blocks::get_path( $node->attrs, 'module.advanced.flexColumnStructure' ) ) {
					$done = Blocks::set_path( $node, 'module.advanced.flexColumnStructure', $structure );
					if ( is_wp_error( $done ) ) {
						return $done;
					}
				}
				$children = $cols;
			}
		}

		return Blocks::build( $type, $node->attrs, $children );
	}

	/**
	 * Divi 5 column: legacy width ("1_2") plus the flexbox width in 24ths.
	 */
	private function column_attrs_5( string $width, string $version ): stdClass {
		list( $num, $den ) = array_map( 'intval', explode( '_', $width ) );
		$advanced          = (object) array( 'type' => (object) array( 'desktop' => (object) array( 'value' => $width ) ) );
		$module            = (object) array( 'advanced' => $advanced );
		if ( $den > 0 && 0 === ( 24 * $num ) % $den ) {
			$module->decoration = (object) array(
				'sizing' => (object) array(
					'desktop' => (object) array( 'value' => (object) array( 'flexType' => ( 24 * $num / $den ) . '_24' ) ),
				),
			);
		}
		$attrs = (object) array( 'module' => $module );
		if ( '' !== $version ) {
			$attrs->{Blocks::VERSION} = $version;
		}
		return $attrs;
	}

	/**
	 * Divi 5 row: equal columns get flexColumnStructure.
	 *
	 * @param string[] $columns Widths.
	 */
	private function row_attrs_5( array $columns, string $version ): stdClass {
		$attrs = new stdClass();
		if ( 1 === count( array_unique( $columns ) ) ) {
			$attrs->module = (object) array(
				'advanced' => (object) array(
					'flexColumnStructure' => (object) array( 'desktop' => (object) array( 'value' => 'equal-columns_' . count( $columns ) ) ),
				),
			);
		}
		if ( '' !== $version ) {
			$attrs->{Blocks::VERSION} = $version;
		}
		return $attrs;
	}
}
