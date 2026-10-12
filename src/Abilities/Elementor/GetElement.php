<?php
/**
 * Full settings of one Elementor element.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetElement extends ElementorAbility {

	/**
	 * Keys shown separately or not at all in the element output.
	 */
	const OWN_KEYS = array( 'id', 'elType', 'widgetType', 'settings', 'elements', 'isInner' );

	public function slug(): string {
		return 'elementor-get-element';
	}

	public function label(): string {
		return __( 'Get Elementor element', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns one element of an Elementor page by its id: elType, widgetType, path, parent_id, its full settings exactly as stored (only the settings that differ from the widget\'s defaults are stored), other stored keys, and a summary of its direct children. Set include_controls to also list the settings keys this widget type understands (name, type, label, default, options), which tells you which keys elementor-update-element can set, e.g. title_color or typography_font_size. Global colors and fonts are applied through the __globals__ setting, e.g. {"__globals__": {"title_color": "globals/colors?id=primary"}}.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'          => self::post_id_schema(),
				'id'               => self::element_id_schema( __( 'Element id from elementor-get-page.', 'wproadie' ) ),
				'include_controls' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also list the settings keys (controls) this element type supports. Needs Elementor\'s widget registry; responsive copies (_tablet, _mobile) are left out.', 'wproadie' ),
				),
			),
			'required'             => array( 'post_id', 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = $this->load( (int) $input['post_id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;

		$found = Tree::find( $tree, (string) $input['id'] );
		if ( ! $found ) {
			return $this->element_not_found();
		}
		$element = $found['element'];

		$result              = Tree::node( $element, $found['path'] );
		$result['post_id']   = (int) $post->ID;
		$result['parent_id'] = $found['parent'] ? (string) ( $found['parent']['id'] ?? '' ) : null;
		if ( array_key_exists( 'isInner', $element ) ) {
			$result['isInner'] = (bool) $element['isInner'];
		}

		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$cut      = false;
		// An empty settings list is shown as an object, which is what it is.
		$result['settings'] = $settings ? ContentHelper::meta_value( $settings, 100000, $cut ) : new \stdClass();
		if ( $cut ) {
			$result['note'] = __( 'The settings are too large to show in full.', 'wproadie' );
		}

		$other = array_diff_key( $element, array_flip( self::OWN_KEYS ) );
		if ( $other ) {
			$cut             = false;
			$result['other'] = ContentHelper::meta_value( $other, 20000, $cut );
		}

		$children = array();
		foreach ( (array) ( $element['elements'] ?? array() ) as $index => $child ) {
			if ( is_array( $child ) ) {
				$node = Tree::node( $child, array_merge( $found['path'], array( $index ) ) );
				if ( ! empty( $child['elements'] ) ) {
					$node['children_count'] = count( (array) $child['elements'] );
				}
				$children[] = $node;
			}
		}
		if ( $children ) {
			$result['children'] = $children;
		}

		if ( ! empty( $input['include_controls'] ) ) {
			$controls = ElementorHelper::controls( (string) ( $element['elType'] ?? '' ), (string) ( $element['widgetType'] ?? '' ) );
			if ( $controls ) {
				$result['controls'] = $controls;
			} else {
				$result['controls_note'] = __( 'Elementor does not know this element type right now (an add-on may be inactive), so its controls cannot be listed.', 'wproadie' );
			}
		}
		return $result;
	}
}
