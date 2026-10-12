<?php
/**
 * Widget areas (sidebars) and the widgets in them.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListWidgets extends AbilityBase {

	public function slug(): string {
		return 'list-widgets';
	}

	public function label(): string {
		return __( 'List widgets', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the widget areas (sidebars) the active theme registers, in order, with the widgets in each: widget id (e.g. "block-3"), id_base (widget type, e.g. "block" or "text"), name, and for block widgets their block content, for classic widgets their settings. Also lists wp_inactive_widgets (removed widgets kept with their settings) and any areas that hold widgets but are no longer registered. Block themes usually register no widget areas; their layout is edited in templates instead.', 'wproadie' );
	}

	public function group(): string {
		return 'navigation';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		global $wp_registered_sidebars, $wp_registered_widgets;

		$assigned = $this->sidebars_widgets();
		$areas    = array();

		foreach ( (array) $wp_registered_sidebars as $id => $sidebar ) {
			$areas[] = array(
				'id'          => (string) $id,
				'name'        => (string) $sidebar['name'],
				'description' => (string) ( $sidebar['description'] ?? '' ),
				'registered'  => true,
				'widgets'     => $this->widgets( (array) ( $assigned[ $id ] ?? array() ), (array) $wp_registered_widgets ),
			);
		}

		foreach ( $assigned as $id => $widget_ids ) {
			if ( isset( $wp_registered_sidebars[ $id ] ) || ! is_array( $widget_ids ) ) {
				continue;
			}
			$areas[] = array(
				'id'          => (string) $id,
				'name'        => 'wp_inactive_widgets' === $id ? __( 'Inactive widgets', 'wproadie' ) : (string) $id,
				'description' => 'wp_inactive_widgets' === $id ? __( 'Widgets removed from every area, kept with their settings.', 'wproadie' ) : __( 'Widget area no longer registered by the active theme.', 'wproadie' ),
				'registered'  => false,
				'widgets'     => $this->widgets( $widget_ids, (array) $wp_registered_widgets ),
			);
		}

		$result = array( 'sidebars' => $areas );
		if ( ! $wp_registered_sidebars && function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$result['note'] = __( 'The active theme is a block theme and registers no widget areas. Edit its layout through templates and template parts instead.', 'wproadie' );
		}
		return $result;
	}

	/**
	 * @param string[] $widget_ids Widget ids in one area.
	 * @param array    $registered Registered widget instances.
	 */
	private function widgets( array $widget_ids, array $registered ): array {
		$widgets = array();
		foreach ( $widget_ids as $widget_id ) {
			$widget_id = (string) $widget_id;
			$id_base   = $widget_id;
			$number    = null;
			if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $m ) ) {
				$id_base = $m[1];
				$number  = (int) $m[2];
			}

			$entry = array(
				'id'      => $widget_id,
				'id_base' => $id_base,
				'name'    => isset( $registered[ $widget_id ]['name'] ) ? (string) $registered[ $widget_id ]['name'] : $id_base,
			);

			$settings = null;
			if ( null !== $number ) {
				$all      = get_option( 'widget_' . $id_base, array() );
				$settings = is_array( $all ) && isset( $all[ $number ] ) ? $all[ $number ] : null;
			}

			if ( 'block' === $id_base ) {
				$entry['content'] = is_array( $settings ) ? (string) ( $settings['content'] ?? '' ) : '';
			} else {
				$entry['settings'] = is_array( $settings ) ? $settings : new \stdClass();
			}
			$widgets[] = $entry;
		}
		return $widgets;
	}

	/**
	 * The stored widget assignments. Read from the option rather than
	 * wp_get_sidebars_widgets(), whose per-request cache goes stale when
	 * an undo restores the option in a long-running (STDIO) session.
	 */
	private function sidebars_widgets(): array {
		$sidebars = get_option( 'sidebars_widgets', array() );
		$sidebars = is_array( $sidebars ) ? $sidebars : array();
		unset( $sidebars['array_version'] );
		return $sidebars;
	}
}
