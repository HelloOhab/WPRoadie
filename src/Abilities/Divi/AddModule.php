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
		return __( 'Inserts a new element into a Divi page. A module (e.g. type "text", "button", "image", "blurb", "cta" or a full name like et_pb_text / divi/text) goes into a column: container is the column\'s path from divi-get-page, index its position (0 = first; default: last). A new section goes at the top level (container "" or omitted) and a new row into a section; pass columns (e.g. ["1_2","1_2"]) to create the row and empty columns inside, then add modules to them. Divi 4: attributes is {name: text} (e.g. {"button_text": "Call us", "button_url": "tel:123"}), content is the HTML between the tags (Text module body). Divi 5: attributes is the block\'s JSON (e.g. {"button": {"innerContent": {"desktop": {"value": {"text": "Call us", "linkUrl": "tel:123"}}}}}); dotted keys like "title.innerContent.desktop.value" also work; content is shorthand for content.innerContent.desktop.value. The page\'s builder version is added for you. HTML is filtered with wp_kses_post for users without unfiltered_html. Returns the new element\'s path and an undo_id for undo-change.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => self::id_property(),
				'container'  => array(
					'type'        => 'string',
					'pattern'     => '^([0-9]+(\.[0-9]+)*)?$',
					'default'     => '',
					'description' => __( 'Path of the column (or section, row or parent module) to insert into; "" for the page itself (sections).', 'wproadie' ),
				),
				'index'      => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Position among the container\'s children, 0 = first. Default: after the last.', 'wproadie' ),
				),
				'type'       => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_/-]+$',
					'maxLength'   => 100,
					'description' => __( 'Module type: short ("text", "button", "section", "row") or full (et_pb_text for Divi 4, divi/text for Divi 5).', 'wproadie' ),
				),
				'attributes' => array(
					'type'          => 'object',
					'maxProperties' => 200,
					'default'       => array(),
					'description'   => __( 'Divi 4: attribute name => text. Divi 5: the block\'s attribute JSON (dotted keys allowed).', 'wproadie' ),
				),
				'content'    => array(
					'type'        => 'string',
					'maxLength'   => 500000,
					'description' => __( 'Divi 4: HTML between the module\'s tags. Divi 5: content.innerContent.desktop.value.', 'wproadie' ),
				),
				'columns'    => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 6,
					'items'       => array(
						'type'    => 'string',
						'pattern' => '^[1-9][0-9]?_[1-9][0-9]?$',
					),
					'description' => __( 'For a new section or row: column widths, e.g. ["4_4"], ["1_2","1_2"], ["1_3","2_3"].', 'wproadie' ),
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

		$sanitized = false;
		$node      = $this->build( $doc, $container['node'], $input, $sanitized );
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
		if ( 0 !== strpos( $type, 'et_pb_' ) && ! shortcode_exists( $type ) ) {
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
