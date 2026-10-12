<?php
/**
 * Inserts a Divi Library item (layout, section, row or module) into a page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use stdClass;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class InsertLayout extends DiviAbility {

	public function slug(): string {
		return 'divi-insert-layout';
	}

	public function label(): string {
		return __( 'Insert Divi Library layout', 'wproadie' );
	}

	public function description(): string {
		return __( 'Inserts a Divi Library item (from divi-list-layouts) into a Divi page: a whole layout or a section goes at the top level (container "" or omitted), a row into a section, a module into a column. index is the position among the container\'s children (0 = first; default: last). Global library items are inserted as a link to the library item (edits to the item then show everywhere) unless link_global is false, which inserts an independent copy. The library item and the page must use the same Divi format (4 or 5). Saves a revision; returns the inserted elements\' paths and an undo_id for undo-change.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => self::id_property(),
				'layout_id'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the Divi Library item.', 'wproadie' ),
				),
				'container'   => array(
					'type'        => 'string',
					'pattern'     => '^([0-9]+(\.[0-9]+)*)?$',
					'default'     => '',
					'description' => __( 'Path to insert into; "" for the page itself (layouts and sections).', 'wproadie' ),
				),
				'index'       => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'link_global' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'For global library items: insert a link (true) or an independent copy (false).', 'wproadie' ),
				),
			),
			'required'             => array( 'id', 'layout_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		if ( ! parent::permission( $input ) ) {
			return false;
		}
		$layout = get_post( (int) ( $input['layout_id'] ?? 0 ) );
		return ! $layout || current_user_can( 'edit_post', $layout->ID );
	}

	protected function execute( array $input ) {
		$layout = get_post( (int) $input['layout_id'] );
		if ( ! $layout || 'et_pb_layout' !== $layout->post_type ) {
			return Divi::error( 'layout_not_found', __( 'No Divi Library item with that ID. Use divi-list-layouts.', 'wproadie' ), 404 );
		}
		$loaded = Divi::load( (int) $input['id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$doc    = $loaded['doc'];
		$source = Divi::load( (int) $layout->ID );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$from = $source['doc'];
		if ( $from->format !== $doc->format ) {
			return Divi::error(
				'format_mismatch',
				/* translators: 1: layout format, 2: page format. */
				sprintf( __( 'The library item is Divi %1$d and the page is Divi %2$d; they cannot be mixed. Open both in the Divi Builder to convert one.', 'wproadie' ), $from->format, $doc->format )
			);
		}

		$nodes = array();
		foreach ( $from->top()->children() as $node ) {
			$nodes[] = $node->copy();
		}
		if ( ! $nodes ) {
			return Divi::error( 'empty_layout', __( 'That library item is empty.', 'wproadie' ) );
		}

		$global = 'global' === ListLayouts::term_slug( (int) $layout->ID, 'scope' );
		$link   = $global && ( ! isset( $input['link_global'] ) || $input['link_global'] );
		if ( $link ) {
			$nodes = $this->link( $doc, $nodes, (int) $layout->ID );
			if ( is_wp_error( $nodes ) ) {
				return $nodes;
			}
		}

		if ( ! Divi::unfiltered() ) {
			$markup = Node::join( $nodes );
			$clean  = wp_kses_post( $markup );
			$same   = 5 === $doc->format ? Divi::same_blocks( parse_blocks( $markup ), parse_blocks( $clean ) ) : $clean === $markup;
			if ( ! $same ) {
				return Divi::error( 'layout_not_allowed', __( 'This library item contains HTML your account may not add to a page (scripts, iframes or embed code). Nothing was changed.', 'wproadie' ), 403 );
			}
		}

		$container = $doc->find_container( (string) ( $input['container'] ?? '' ) );
		if ( is_wp_error( $container ) ) {
			return $container;
		}
		$check = $this->check_not_global( $doc, $container );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		foreach ( $nodes as $node ) {
			$placement = $doc->check_placement( $container['node'], $node );
			if ( is_wp_error( $placement ) ) {
				return $placement;
			}
		}

		$count = count( $container['node']->children() );
		$index = isset( $input['index'] ) ? min( (int) $input['index'], $count ) : $count;
		foreach ( $nodes as $offset => $node ) {
			$doc->insert( $container['node'], $index + $offset, $node );
		}

		$saved = $this->save( $loaded['post'], $doc );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$inserted = array();
		foreach ( $doc->all() as $entry ) {
			if ( in_array( $entry['node'], $nodes, true ) ) {
				$inserted[] = $doc->describe( $entry );
			}
		}
		return array_merge(
			$saved,
			array(
				'inserted' => $inserted,
				'linked'   => $link,
			)
		);
	}

	/**
	 * Turns copies of a global item into links to it: Divi 4 marks each
	 * top element with global_module, Divi 5 uses one divi/global-layout
	 * block.
	 *
	 * @param Document $doc       Target page.
	 * @param Node[]   $nodes     Copies.
	 * @param int      $layout_id Library item.
	 * @return Node[]|\WP_Error
	 */
	private function link( Document $doc, array $nodes, int $layout_id ) {
		if ( 4 === $doc->format ) {
			foreach ( $nodes as $node ) {
				if ( null === Shortcodes::attr( $node, 'global_module' ) ) {
					Shortcodes::set_attr( $node, 'global_module', (string) $layout_id );
				}
			}
			return $nodes;
		}
		$attrs                          = new stdClass();
		$attrs->{Blocks::GLOBAL_MODULE} = (string) $layout_id;
		$version                        = $doc->builder_version();
		if ( '' !== $version ) {
			$attrs->{Blocks::VERSION} = $version;
		}
		$block = Blocks::build( 'divi/global-layout', $attrs );
		if ( is_wp_error( $block ) ) {
			return $block;
		}
		return array( $block );
	}
}
