<?php
/**
 * Outline of a Divi page: sections, rows, columns and modules with paths
 * and one-line summaries.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetPage extends DiviAbility {

	public function slug(): string {
		return 'divi-get-page';
	}

	public function label(): string {
		return __( 'Get Divi page outline', 'wproadie' );
	}

	public function description(): string {
		return __( 'Shows the structure of a page built with Divi 4 or Divi 5 as a flat outline, top to bottom: each item has path (e.g. "0.1.0.2" = section 0, row 1, column 0, module 2), type (e.g. et_pb_button or divi/button), name (e.g. "Button"), label (admin label, if set), css_id, width (columns), global_layout (the Divi Library item a global element mirrors; edit that item instead) and summary (title, button text and URL, image src/alt, start of the text). Use the path, label or css_id with divi-get-module to read every attribute, and with the other divi-* tools to change things. For long pages use depth (e.g. 1 = sections only), path (outline only inside one element) and limit. Works on pages, posts, Divi Library layouts and Theme Builder header/body/footer layouts. Before building or restyling a page, read the divi-build-page skill with get-skill.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => self::id_property(),
				'path'  => array(
					'type'        => 'string',
					'pattern'     => '^[0-9]+(\.[0-9]+)*$',
					'description' => __( 'Only outline inside this element, e.g. "2" for the third section.', 'wproadie' ),
				),
				'depth' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => __( 'How many levels to show below the start: 1 = sections only, 4 = down to modules. Default: everything.', 'wproadie' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 1000,
					'default'     => 300,
					'description' => __( 'Maximum outline items to return.', 'wproadie' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = Divi::load( (int) $input['id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$post = $loaded['post'];
		$doc  = $loaded['doc'];

		$start_path  = isset( $input['path'] ) ? trim( (string) $input['path'] ) : '';
		$start_depth = 0;
		if ( '' !== $start_path ) {
			$start = $doc->find( array( 'path' => $start_path ) );
			if ( is_wp_error( $start ) ) {
				return $start;
			}
			$start_depth = $start['depth'];
		}
		$max_depth = isset( $input['depth'] ) ? (int) $input['depth'] : 0;
		$limit     = (int) ( $input['limit'] ?? 300 );

		$items     = array();
		$total     = 0;
		$truncated = false;
		foreach ( $doc->all() as $entry ) {
			if ( '' !== $start_path && 0 !== strpos( $entry['path'] . '.', $start_path . '.' ) ) {
				continue;
			}
			$level = $entry['depth'] - $start_depth + ( '' === $start_path ? 1 : 0 );
			if ( $max_depth && $level > $max_depth ) {
				continue;
			}
			++$total;
			if ( count( $items ) >= $limit ) {
				$truncated = true;
				continue;
			}
			$item = $doc->describe( $entry );
			if ( $max_depth && $level === $max_depth && $entry['node']->children() ) {
				$item['children'] = count( $entry['node']->children() );
			}
			$items[] = $item;
		}

		$result = array_merge(
			Divi::brief( $post, $doc ),
			array(
				'builder_version' => $doc->builder_version(),
				'items'           => $items,
			)
		);
		if ( $truncated ) {
			$result['truncated'] = sprintf(
				/* translators: 1: items shown, 2: items in total. */
				__( 'Showing %1$d of %2$d items. Use path, depth or a higher limit to see the rest.', 'wproadie' ),
				count( $items ),
				$total
			);
		}
		if ( ! $doc->top()->children() && '' !== trim( $doc->to_string() ) ) {
			$result['note'] = __( 'This page has content but no Divi sections were found in it.', 'wproadie' );
		}
		return $result;
	}
}
