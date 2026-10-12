<?php
/**
 * Outline of an Elementor page: its containers, sections, columns and
 * widgets with short summaries.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetPage extends ElementorAbility {

	public function slug(): string {
		return 'elementor-get-page';
	}

	public function label(): string {
		return __( 'Get Elementor page outline', 'wproadie' );
	}

	public function description(): string {
		return __( 'Shows the layout of a page built with Elementor as an outline tree: every container, section, column and widget with its element id, elType, widgetType, path (index path such as "0.2.1") and a short summary (heading text, button text and link, image URL and alt, the start of a text block). Settings are not included; fetch one element\'s full settings with elementor-get-element. For long pages use depth (how many levels to expand) and limit (maximum elements), or root_id to outline one part. Also returns the page\'s Elementor page settings and any warnings. Use the element ids with elementor-update-element, elementor-add-element, elementor-move-element and the other element tools.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => self::post_id_schema(),
				'depth'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 50,
					'default'     => 10,
					'description' => __( 'Levels to expand below the top (0 = top-level elements only). Deeper elements show children_count instead.', 'wproadie' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 2000,
					'default'     => 400,
					'description' => __( 'Maximum number of elements in the outline; truncated is true when cut.', 'wproadie' ),
				),
				'root_id' => self::element_id_schema( __( 'Optional: outline only this element and what is inside it.', 'wproadie' ) ),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = $this->load( (int) $input['post_id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;

		$depth     = (int) ( $input['depth'] ?? 10 );
		$limit     = (int) ( $input['limit'] ?? 400 );
		$truncated = false;
		$count     = 0;

		if ( ! empty( $input['root_id'] ) ) {
			$found = Tree::find( $tree, (string) $input['root_id'] );
			if ( ! $found ) {
				return $this->element_not_found();
			}
			$element  = $found['element'];
			$node     = Tree::node( $element, $found['path'] );
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			$count    = 1;
			if ( $children && $depth > 0 ) {
				// Depth counts levels below the root element.
				$node['children'] = Tree::outline( $children, count( $found['path'] ) - 1 + $depth, $limit, $truncated, $found['path'], $count );
			}
			if ( $children && count( $node['children'] ?? array() ) < count( $children ) ) {
				$node['children_count'] = count( $children );
			}
			$outline = array( $node );
		} else {
			$outline = Tree::outline( $tree, $depth, $limit, $truncated );
		}

		$result                  = ElementorHelper::page_info( $post );
		$result['element_count'] = Tree::count( $tree );
		$settings                = get_post_meta( $post->ID, ElementorHelper::PAGE_SETTINGS_KEY, true );
		if ( $settings ) {
			$cut                     = false;
			$result['page_settings'] = ContentHelper::meta_value( $settings, 3000, $cut );
		}
		$template = get_post_meta( $post->ID, '_wp_page_template', true );
		if ( $template ) {
			$result['page_template'] = $template;
		}
		$result['outline']   = $outline;
		$result['truncated'] = $truncated;
		if ( $truncated ) {
			$result['note'] = __( 'The outline was cut at the limit. Raise limit, lower depth, or pass root_id to see one part.', 'wproadie' );
		}
		$warning = ElementorHelper::lock_warning( (int) $post->ID );
		if ( $warning ) {
			$result['warning'] = $warning;
		}
		return $result;
	}
}
