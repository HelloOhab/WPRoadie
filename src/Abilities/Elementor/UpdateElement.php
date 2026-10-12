<?php
/**
 * Changes settings of one Elementor element.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateElement extends ElementorAbility {

	public function slug(): string {
		return 'elementor-update-element';
	}

	public function label(): string {
		return __( 'Update Elementor element', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes settings of one element on an Elementor page, e.g. a heading\'s text, a button\'s link, an image, a color or spacing. Send only the keys to change in settings: each key is set, null removes a key (back to the widget default), objects merge into the current value (so {"link": {"url": "https://…"}} keeps the link\'s other options) and lists such as icon_list items are replaced whole (send the full list). Everything else on the page is left exactly as it was. Use elementor-get-element first to see the current settings (and include_controls for valid keys). Returns the element\'s new summary and settings, plus an undo_id; pass it to undo-change to restore the previous version.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'  => self::post_id_schema(),
				'id'       => self::element_id_schema( __( 'Element id from elementor-get-page.', 'wproadie' ) ),
				'settings' => array(
					'type'          => 'object',
					'minProperties' => 1,
					'description'   => __( 'Settings to change, e.g. {"title": "New heading"} or {"text": "Book now", "link": {"url": "https://example.com/book"}}. null removes a key.', 'wproadie' ),
				),
			),
			'required'             => array( 'post_id', 'id', 'settings' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$changes = (array) $input['settings'];
		$json    = wp_json_encode( $changes );
		if ( false === $json || strlen( $json ) > ElementorHelper::MAX_ELEMENT_BYTES ) {
			return $this->error( 'too_large', __( 'The settings are too large (or not valid JSON).', 'wproadie' ), 413 );
		}

		$loaded = $this->load( (int) $input['post_id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		list( $post, $tree ) = $loaded;

		$found = Tree::find( $tree, (string) $input['id'] );
		if ( ! $found ) {
			return $this->element_not_found();
		}
		$element  = $found['element'];
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$merged   = Tree::merge_settings( $settings, ElementorHelper::sanitize( $changes ) );

		if ( $merged === $settings ) {
			$result            = Tree::node( $element, $found['path'] );
			$result['post_id'] = (int) $post->ID;
			$result['changed'] = false;
			$result['note']    = __( 'The element already had these settings, so nothing was saved.', 'wproadie' );
			return $result;
		}

		$element['settings'] = $merged;
		$saved               = $this->save_tree( $post, Tree::set_at( $tree, $found['path'], $element ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$result             = Tree::node( $element, $found['path'] );
		$result['post_id']  = (int) $post->ID;
		$result['changed']  = true;
		$cut                = false;
		$result['settings'] = $merged ? ContentHelper::meta_value( $merged, 20000, $cut ) : new \stdClass();
		if ( ! current_user_can( 'unfiltered_html' ) && ElementorHelper::sanitize( $changes ) !== $changes ) {
			$result['note'] = __( 'Some HTML was removed because your account may not post unfiltered HTML (scripts, iframes and similar).', 'wproadie' );
		}
		return array_merge( $result, $saved );
	}
}
