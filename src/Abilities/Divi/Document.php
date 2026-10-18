<?php
/**
 * A parsed Divi page in either storage format, with module addressing,
 * outlines, summaries and structural edits.
 *
 * Paths count child elements from the top: "0.1.0.2" is section 0, row 1,
 * column 0, module 2. The Divi 5 wrapper block divi/placeholder is not
 * counted. Children of parent modules (tabs, slides, accordion items…)
 * continue the path: "0.1.0.2.0" is the first tab of module 2.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Document {

	/**
	 * 4 (shortcodes) or 5 (blocks).
	 *
	 * @var int
	 */
	public $format;

	/**
	 * Holds the whole content: its parts are the top-level elements.
	 *
	 * @var Node
	 */
	public $root;

	/**
	 * Human names for module types whose slug does not read well.
	 */
	const NAMES = array(
		'cta'                         => 'Call To Action',
		'team_member'                 => 'Person',
		'team-member'                 => 'Person',
		'signup'                      => 'Email Optin',
		'counters'                    => 'Bar Counters',
		'counter'                     => 'Bar Counter',
		'social_media_follow_network' => 'Social Network',
		'social-media-follow-network' => 'Social Network',
		'global-layout'               => 'Global Layout (reference)',
	);

	/**
	 * Divi 4 attributes worth showing in a one-line summary, in order.
	 */
	const SUMMARY_ATTRS = array( 'title', 'heading', 'subhead', 'name', 'author', 'position', 'job_title', 'company_name', 'button_text', 'button_one_text', 'button_two_text', 'button_url', 'button_link', 'button_one_url', 'url', 'link_url', 'src', 'image', 'alt', 'title_text', 'number', 'percent', 'sum', 'email', 'menu_id', 'type', 'fullwidth', 'specialty' );

	public function __construct( string $content, int $format ) {
		$this->format = $format;
		$this->root   = 5 === $format ? Blocks::parse( $content ) : Shortcodes::parse( $content );
	}

	public function to_string(): string {
		return $this->root->to_string();
	}

	/**
	 * The element whose children are path level 0: the Divi 5 placeholder
	 * wrapper when the page has one, else the content itself.
	 */
	public function top(): Node {
		if ( 5 === $this->format ) {
			$nodes = $this->root->children();
			if ( 1 === count( $nodes ) && 'divi/placeholder' === $nodes[0]->name && null !== $nodes[0]->parts ) {
				return $nodes[0];
			}
		}
		return $this->root;
	}

	// Walking and addressing.


	/**
	 * An empty Divi 5 page is a single self-closing placeholder block;
	 * content goes inside that wrapper, so open it into a pair before
	 * something is inserted at the top level. Only callers that are about
	 * to insert do this (find_container), so reads stay byte-exact.
	 */
	private function open_empty_placeholder(): void {
		if ( 5 !== $this->format ) {
			return;
		}
		$nodes = $this->root->children();
		if ( 1 === count( $nodes ) && 'divi/placeholder' === $nodes[0]->name && $nodes[0]->void ) {
			Blocks::ensure_closed( $nodes[0] );
		}
	}

	/**
	 * Every element, depth first, with its path, parent and depth.
	 *
	 * @return array<int, array{node: Node, path: string, parent: Node, depth: int, global: string}>
	 */
	public function all(): array {
		$out = array();
		$this->collect( $this->top(), '', 0, '', $out );
		return $out;
	}

	private function collect( Node $container, string $prefix, int $depth, string $global_id, array &$out ): void {
		foreach ( $container->children() as $index => $node ) {
			$path  = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$own   = $this->global_id( $node );
			$inner = '' !== $global_id ? $global_id : $own;
			$out[] = array(
				'node'   => $node,
				'path'   => $path,
				'parent' => $container,
				'depth'  => $depth,
				'global' => $inner,
			);
			$this->collect( $node, $path, $depth + 1, $inner, $out );
		}
	}

	/**
	 * Finds an element by path, admin label or CSS ID.
	 *
	 * @param array $ref Keys path, label or css_id.
	 * @return array|WP_Error An entry as returned by all().
	 */
	public function find( array $ref ) {
		$all = $this->all();
		if ( isset( $ref['path'] ) && '' !== (string) $ref['path'] ) {
			$path = trim( (string) $ref['path'] );
			foreach ( $all as $entry ) {
				if ( $entry['path'] === $path ) {
					return $entry;
				}
			}
			return Divi::error(
				'module_not_found',
				/* translators: %s: module path. */
				sprintf( __( 'Nothing at path %s. Paths count from 0, e.g. 0.1.0.2 = section 0, row 1, column 0, module 2; use divi-get-page to see them.', 'wproadie' ), $path ),
				404
			);
		}

		$by    = isset( $ref['label'] ) && '' !== (string) $ref['label'] ? 'label' : ( isset( $ref['css_id'] ) && '' !== (string) $ref['css_id'] ? 'css_id' : '' );
		$value = '' !== $by ? trim( (string) $ref[ $by ] ) : '';
		if ( '' === $by ) {
			return Divi::error( 'missing_module', __( 'Say which module: pass path, label (its admin label) or css_id.', 'wproadie' ) );
		}

		$matches = array();
		foreach ( $all as $entry ) {
			$have = 'label' === $by ? $this->admin_label( $entry['node'] ) : $this->css_id( $entry['node'] );
			if ( '' !== $have && 0 === strcasecmp( $have, $value ) ) {
				$matches[] = $entry;
			}
		}
		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}
		if ( ! $matches ) {
			return Divi::error(
				'module_not_found',
				/* translators: 1: "label" or "css_id", 2: value. */
				sprintf( __( 'No module with %1$s "%2$s". Use divi-get-page to see labels and paths.', 'wproadie' ), $by, $value ),
				404
			);
		}
		return Divi::error(
			'module_ambiguous',
			sprintf(
				/* translators: 1: "label" or "css_id", 2: value, 3: comma-separated paths. */
				__( 'More than one module has %1$s "%2$s" (paths %3$s). Pass the path instead.', 'wproadie' ),
				$by,
				$value,
				implode( ', ', wp_list_pluck( $matches, 'path' ) )
			)
		);
	}

	/**
	 * Finds a container to insert into: "" means the page itself (for
	 * sections).
	 *
	 * @return array|WP_Error Entry, or array with node = top() for the page.
	 */
	public function find_container( string $path ) {
		$path = trim( $path );
		if ( '' === $path ) {
			$this->open_empty_placeholder();
			return array(
				'node'   => $this->top(),
				'path'   => '',
				'parent' => null,
				'depth'  => -1,
				'global' => '',
			);
		}
		return $this->find( array( 'path' => $path ) );
	}

	/**
	 * Whether $maybe_child is $node or inside it.
	 */
	public static function contains( Node $node, Node $maybe_child ): bool {
		if ( $node === $maybe_child ) {
			return true;
		}
		foreach ( $node->children() as $child ) {
			if ( self::contains( $child, $maybe_child ) ) {
				return true;
			}
		}
		return false;
	}

	// Describing elements.


	/**
	 * "section", "row", "column", "placeholder" or "module".
	 */
	public function role( Node $node ): string {
		$map = 5 === $this->format ? Blocks::STRUCTURE : Shortcodes::STRUCTURE;
		return $map[ strtolower( $node->name ) ] ?? 'module';
	}

	/**
	 * Human name for a type: "Text", "Call To Action", "Fullwidth Header".
	 */
	public static function type_name( string $type ): string {
		$slug = preg_replace( '/^(et_pb_|divi\/)/', '', $type );
		if ( isset( self::NAMES[ $slug ] ) ) {
			return self::NAMES[ $slug ];
		}
		return ucwords( trim( str_replace( array( '_', '-', '/' ), ' ', $slug ) ) );
	}

	public function admin_label( Node $node ): string {
		if ( 5 === $this->format ) {
			$value = Blocks::get_path( $node->attrs, 'module.meta.adminLabel.desktop.value' );
			return is_string( $value ) ? $value : '';
		}
		$value = Shortcodes::attr( $node, 'admin_label' );
		return null === $value ? '' : Shortcodes::decode( $value );
	}

	public function css_id( Node $node ): string {
		if ( 5 === $this->format ) {
			$value = Blocks::get_path( $node->attrs, 'module.advanced.htmlAttributes.desktop.value.id' );
			return is_string( $value ) ? $value : '';
		}
		$value = Shortcodes::attr( $node, 'module_id' );
		return null === $value ? '' : Shortcodes::decode( $value );
	}

	/**
	 * The Divi Library item (et_pb_layout) a global element mirrors, or "".
	 */
	public function global_id( Node $node ): string {
		if ( 5 === $this->format ) {
			$value = is_object( $node->attrs ) && isset( $node->attrs->{Blocks::GLOBAL_MODULE} ) ? $node->attrs->{Blocks::GLOBAL_MODULE} : '';
			return is_scalar( $value ) ? (string) $value : '';
		}
		$value = Shortcodes::attr( $node, 'global_module' );
		return null === $value || '0' === $value ? '' : (string) $value;
	}

	/**
	 * Column width such as "1_2", or "".
	 */
	public function column_type( Node $node ): string {
		if ( 5 === $this->format ) {
			foreach ( array( 'module.advanced.type.desktop.value', 'module.decoration.sizing.desktop.value.flexType' ) as $path ) {
				$value = Blocks::get_path( $node->attrs, $path );
				if ( is_string( $value ) && '' !== $value ) {
					return $value;
				}
			}
			return '';
		}
		return (string) Shortcodes::attr( $node, 'type' );
	}

	/**
	 * Builder version the page was saved with, from its first element that
	 * records one, or "".
	 */
	public function builder_version(): string {
		foreach ( $this->all() as $entry ) {
			$node  = $entry['node'];
			$value = 5 === $this->format
				? ( is_object( $node->attrs ) && isset( $node->attrs->{Blocks::VERSION} ) ? $node->attrs->{Blocks::VERSION} : '' )
				: Shortcodes::attr( $node, '_builder_version' );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		// A page with nothing that records one (e.g. an empty Divi 5 page):
		// stamp new elements with the site's Divi version, as Divi does.
		return Divi::site_builder_version();
	}

	/**
	 * One-line summary: key fields and the start of the text.
	 */
	public function summary( Node $node, int $max = 120 ): string {
		$fields = array();
		$text   = '';
		if ( 5 === $this->format ) {
			foreach ( is_object( $node->attrs ) ? get_object_vars( $node->attrs ) : array() as $key => $group ) {
				if ( in_array( $key, array( 'module', 'builderVersion', 'globalModule', 'localAttrs' ), true ) ) {
					continue;
				}
				$value = Blocks::get_path( $group, 'innerContent.desktop.value' );
				if ( is_string( $value ) ) {
					if ( in_array( $key, array( 'content', 'html', 'code' ), true ) ) {
						$text = $value;
					} elseif ( '' !== trim( $value ) ) {
						$fields[] = $key . ': ' . self::short( self::plain( $value ), 60 );
					}
				} elseif ( is_object( $value ) ) {
					$bits = array();
					foreach ( array( 'text', 'title', 'linkUrl', 'url', 'src', 'alt' ) as $sub ) {
						if ( isset( $value->$sub ) && is_scalar( $value->$sub ) && '' !== (string) $value->$sub ) {
							$bits[] = in_array( $sub, array( 'linkUrl', 'url', 'src' ), true ) ? '-> ' . self::short( (string) $value->$sub, 80 ) : ( 'alt' === $sub ? '(alt: ' . self::short( (string) $value->$sub, 40 ) . ')' : self::short( (string) $value->$sub, 60 ) );
						}
					}
					if ( $bits ) {
						$fields[] = $key . ': ' . implode( ' ', $bits );
					}
				}
			}
			if ( 'divi/global-layout' === $node->name ) {
				$fields[] = 'library item: ' . $this->global_id( $node );
			}
		} else {
			$attrs = Shortcodes::decoded_attrs( $node );
			foreach ( self::SUMMARY_ATTRS as $key ) {
				if ( isset( $attrs[ $key ] ) && '' !== trim( $attrs[ $key ] ) && count( $fields ) < 6 ) {
					$fields[] = $key . ': ' . self::short( $attrs[ $key ], 60 );
				}
			}
			if ( null !== $node->content ) {
				$text = $node->content;
			}
		}

		$text = self::plain( $text );
		if ( '' !== $text ) {
			$fields[] = 'text: ' . self::short( $text, $max );
		}
		return implode( ' | ', $fields );
	}

	/**
	 * HTML to one line of plain text. Shortcodes inside stay visible, so
	 * the AI can see e.g. a contact form shortcode in a Text module.
	 */
	public static function plain( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function short( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Compact description of one element for outlines.
	 *
	 * @param array $entry Entry from all().
	 */
	public function describe( array $entry ): array {
		$node  = $entry['node'];
		$item  = array(
			'path' => $entry['path'],
			'type' => $node->name,
			'name' => self::type_name( $node->name ),
		);
		$label = $this->admin_label( $node );
		if ( '' !== $label ) {
			$item['label'] = $label;
		}
		$css_id = $this->css_id( $node );
		if ( '' !== $css_id ) {
			$item['css_id'] = $css_id;
		}
		$role = $this->role( $node );
		if ( 'column' === $role && '' !== $this->column_type( $node ) ) {
			$item['width'] = $this->column_type( $node );
		}
		if ( '' !== $entry['global'] ) {
			$item['global_layout'] = (int) $entry['global'];
		}
		if ( 'module' === $role || 'section' === $role ) {
			$summary = $this->summary( $node );
			if ( '' !== $summary ) {
				$item['summary'] = $summary;
			}
		}
		return $item;
	}

	/**
	 * Number of modules (not sections, rows or columns).
	 */
	public function module_count(): int {
		$count = 0;
		foreach ( $this->all() as $entry ) {
			if ( 'module' === $this->role( $entry['node'] ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Sections, rows and columns with nothing inside, as paths.
	 *
	 * @return string[]
	 */
	public function empty_structural(): array {
		$paths = array();
		foreach ( $this->all() as $entry ) {
			$role = $this->role( $entry['node'] );
			if ( 'module' === $role || 'placeholder' === $role ) {
				continue;
			}
			$has_child = false;
			foreach ( $entry['node']->children() as $child ) {
				if ( $child instanceof Node ) {
					$has_child = true;
					break;
				}
			}
			if ( ! $has_child ) {
				$paths[] = $entry['path'];
			}
		}
		return $paths;
	}

	/**
	 * Code modules (raw HTML) on the page, as paths.
	 *
	 * @return string[]
	 */
	public function code_modules(): array {
		$paths = array();
		foreach ( $this->all() as $entry ) {
			if ( in_array( strtolower( $entry['node']->name ), array( 'et_pb_code', 'et_pb_fullwidth_code', 'divi/code', 'divi/fullwidth-code' ), true ) ) {
				$paths[] = $entry['path'];
			}
		}
		return $paths;
	}

	// Structural edits.


	/**
	 * Inserts a node among a container's children.
	 */
	public function insert( Node $container, int $index, Node $node ): void {
		if ( 5 === $this->format ) {
			Blocks::ensure_closed( $container );
		} elseif ( $container !== $this->root ) {
			Shortcodes::ensure_closed( $container );
			if ( null === $container->parts ) {
				$container->parts   = '' === (string) $container->content ? array() : array( (string) $container->content );
				$container->content = null;
			}
		}

		$kids  = $container->children();
		$index = max( 0, min( $index, count( $kids ) ) );
		$sep   = $this->separator( $container );

		if ( $index < count( $kids ) ) {
			$pos    = array_search( $kids[ $index ], $container->parts, true );
			$insert = '' !== $sep ? array( $node, $sep ) : array( $node );
			array_splice( $container->parts, (int) $pos, 0, $insert );
		} elseif ( $kids ) {
			$pos    = array_search( end( $kids ), $container->parts, true );
			$insert = '' !== $sep ? array( $sep, $node ) : array( $node );
			array_splice( $container->parts, (int) $pos + 1, 0, $insert );
		} else {
			// Empty container: after any leading whitespace.
			$pos = 0;
			if ( isset( $container->parts[0] ) && is_string( $container->parts[0] ) && '' === trim( $container->parts[0] ) && 5 === $this->format ) {
				$pos = 1;
			}
			array_splice( $container->parts, $pos, 0, array( $node ) );
		}
	}

	/**
	 * Removes a node from its container, and one adjacent separator so
	 * blank lines do not pile up.
	 */
	public function remove( Node $container, Node $node ): void {
		$pos = array_search( $node, (array) $container->parts, true );
		if ( false === $pos ) {
			return;
		}
		$parts = $container->parts;
		$next  = $parts[ $pos + 1 ] ?? null;
		$prev  = $pos > 0 ? $parts[ $pos - 1 ] : null;
		if ( is_string( $next ) && '' === trim( $next ) && ( $parts[ $pos + 2 ] ?? null ) instanceof Node ) {
			array_splice( $parts, $pos, 2 );
		} elseif ( is_string( $prev ) && '' === trim( $prev ) && ( $parts[ $pos - 2 ] ?? null ) instanceof Node ) {
			array_splice( $parts, $pos - 1, 2 );
		} else {
			array_splice( $parts, $pos, 1 );
		}
		$container->parts = $parts;
	}

	/**
	 * The whitespace a container uses between its children, or "".
	 */
	private function separator( Node $container ): string {
		$parts = (array) $container->parts;
		foreach ( $parts as $i => $part ) {
			if ( is_string( $part ) && $i > 0 && $parts[ $i - 1 ] instanceof Node && ( $parts[ $i + 1 ] ?? null ) instanceof Node && '' === trim( $part ) ) {
				return $part;
			}
		}
		// Fewer than two children: the whitespace before the first one, if
		// any (Divi 5 puts each block on its own line; Divi 4 uses none).
		if ( isset( $parts[0], $parts[1] ) && is_string( $parts[0] ) && $parts[1] instanceof Node && '' === trim( $parts[0] ) ) {
			return $parts[0];
		}
		return '';
	}

	// Rules for where things may go.


	/**
	 * Checks a node may be placed inside a container.
	 *
	 * @param Node $container Target container (top() for the page).
	 * @param Node $node      Node to place.
	 * @return true|WP_Error
	 */
	public function check_placement( Node $container, Node $node ) {
		$role           = $this->role( $node );
		$at_top         = $container === $this->top();
		$container_role = $at_top ? 'page' : $this->role( $container );
		$fullwidth      = 'section' === $container_role && $this->is_fullwidth_section( $container );

		$ok = true;
		if ( 'divi/global-layout' === $node->name ) {
			// A link to a library item goes wherever the item's content would.
			$role = 'global';
		}
		switch ( $role ) {
			case 'global':
				$ok = 'row' !== $container_role;
				break;
			case 'section':
				$ok = 'page' === $container_role;
				break;
			case 'row':
				$ok = ( 'section' === $container_role && ! $fullwidth ) || ( 'column' === $container_role ) || ( 5 === $this->format && 'module' === $container_role );
				if ( $ok && 4 === $this->format ) {
					// Divi 4: et_pb_row in a section, et_pb_row_inner in a specialty column.
					$ok = ( 'section' === $container_role ) === ( 'et_pb_row' === $node->name );
				}
				break;
			case 'column':
				$ok = 'row' === $container_role || ( 'section' === $container_role && 4 === $this->format && $this->is_specialty_section( $container ) );
				if ( $ok && 4 === $this->format ) {
					// Divi 4: et_pb_column_inner only in et_pb_row_inner.
					$ok = ( 'et_pb_row_inner' === $container->name ) === ( 'et_pb_column_inner' === $node->name );
				}
				break;
			default:
				$ok = in_array( $container_role, array( 'column', 'module' ), true ) || $fullwidth;
		}
		if ( $ok && 'module' === $container_role && null === $container->parts && ! in_array( $container->name, 5 === $this->format ? Blocks::CONTAINERS : Shortcodes::CONTAINERS, true ) ) {
			$ok = false;
		}
		if ( $ok ) {
			return true;
		}
		return Divi::error(
			'bad_placement',
			sprintf(
				/* translators: 1: element type, 2: container type. */
				__( 'A %1$s cannot go directly inside %2$s. Divi nests section > row > column > module: sections go at the top of the page (container ""), rows in a section, columns in a row, modules in a column (or directly in a fullwidth section). Child items such as tabs or slides go in their parent module.', 'wproadie' ),
				self::type_name( $node->name ),
				'page' === $container_role ? __( 'the page', 'wproadie' ) : self::type_name( $container->name )
			)
		);
	}

	public function is_fullwidth_section( Node $node ): bool {
		if ( 5 === $this->format ) {
			return 'fullwidth' === Blocks::get_path( $node->attrs, 'module.advanced.type.desktop.value' );
		}
		return 'on' === Shortcodes::attr( $node, 'fullwidth' );
	}

	public function is_specialty_section( Node $node ): bool {
		if ( 5 === $this->format ) {
			return 'specialty' === Blocks::get_path( $node->attrs, 'module.advanced.type.desktop.value' );
		}
		return 'on' === Shortcodes::attr( $node, 'specialty' );
	}
}
