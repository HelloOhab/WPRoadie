<?php
/**
 * Adds a widget, container or section to an Elementor page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Abilities\RawHtml;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class AddElement extends ElementorAbility {

	public function slug(): string {
		return 'elementor-add-element';
	}

	public function label(): string {
		return __( 'Add Elementor element', 'wproadie' );
	}

	public function description(): string {
		return __( 'Adds a new element to an Elementor page. For one widget, send widget_type (e.g. "heading", "text-editor", "button", "image", "icon-list", "spacer") and its settings, e.g. {"title": "Opening hours", "header_size": "h2"}. For anything bigger (a container with widgets inside, a section with columns) send element as Elementor element JSON: {"elType": "container", "settings": {…}, "elements": [{"elType": "widget", "widgetType": "heading", "settings": {…}}]}. IDs are generated fresh. An HTML widget, layout HTML inside text and custom or inline CSS are refused unless allow_raw_html / allow_inline_css say the person asked for them. Settings keys the widget has no control for are refused, with the likely right key; elementor-get-widget-schema lists the keys each widget takes. Place it with parent_id (a container, column or section id from elementor-get-page; omit for the top level of the page) and position (0-based index among the parent\'s children; omit to add at the end). A widget sent to the top level is wrapped in a new container. Elementor\'s rules apply: widgets go in containers or columns, columns only in sections. Returns the new element ids, plus an undo_id; pass it to undo-change to remove it again.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'                => self::post_id_schema(),
				'parent_id'              => self::element_id_schema( __( 'Element id of the container, column or section to add into. Omit for the top level of the page.', 'wproadie' ) ),
				'position'               => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '0-based position among the parent\'s children. Omit to add at the end.', 'wproadie' ),
				),
				'widget_type'            => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9][A-Za-z0-9_.-]*$',
					'description' => __( 'Widget type for a single widget, e.g. "heading". Use with settings. Ignored when element is sent.', 'wproadie' ),
				),
				'settings'               => array(
					'type'        => 'object',
					'description' => __( 'Settings for the widget_type widget.', 'wproadie' ),
				),
				'element'                => array(
					'type'        => 'object',
					'description' => __( 'A full Elementor element (elType, settings, elements, widgetType for widgets), for containers, sections or nested content.', 'wproadie' ),
				),
				'allow_raw_html'         => RawHtml::flags_schema()['allow_raw_html'],
				'allow_inline_css'       => RawHtml::flags_schema()['allow_inline_css'],
				'allow_unknown_settings' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Store settings keys Elementor has no control for instead of refusing them (only for keys you know a plugin reads).', 'wproadie' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		if ( isset( $input['element'] ) ) {
			$raw = $input['element'];
		} elseif ( ! empty( $input['widget_type'] ) ) {
			$raw = array(
				'elType'     => 'widget',
				'settings'   => isset( $input['settings'] ) ? (array) $input['settings'] : array(),
				'elements'   => array(),
				'widgetType' => (string) $input['widget_type'],
			);
		} else {
			return $this->error( 'missing_element', __( 'Send widget_type (with settings) for one widget, or element for a full element.', 'wproadie' ) );
		}

		$element = $this->parse_element( $raw );
		if ( is_wp_error( $element ) ) {
			return $element;
		}
		$code = $this->check_code_tree( $element, $input );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		if ( empty( $input['allow_unknown_settings'] ) ) {
			$known = $this->check_settings_tree( $element );
			if ( is_wp_error( $known ) ) {
				return $known;
			}
		}

		$loaded = $this->load( (int) $input['post_id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;

		$taken   = Tree::ids( $tree );
		$element = Tree::refresh_ids( Tree::map_element_strings( $element, array( ElementorHelper::class, 'sanitize' ) ), $taken );

		return $this->insert_and_save( $post, $tree, array( $element ), self::parent_from_input( $input ), self::position_from_input( $input ), $taken );
	}
}
