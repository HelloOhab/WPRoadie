<?php
/**
 * Removes a widget from its widget area.
 *
 * The widget moves to wp_inactive_widgets with its settings intact, as the
 * Widgets screen does, rather than being deleted.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RemoveWidget extends AbilityBase {

	public function slug(): string {
		return 'remove-widget';
	}

	public function label(): string {
		return __( 'Remove widget', 'wproadie' );
	}

	public function description(): string {
		return __( 'Removes a widget (e.g. "block-3" or "text-2", ids from list-widgets) from the widget area it is in so it no longer shows on the site. Like the Widgets screen, it moves the widget to Inactive widgets with its settings kept, rather than deleting it. Returns the area it was removed from. Returns an undo_id; pass it to undo-change to put it back in place.', 'wproadie' );
	}

	public function group(): string {
		return 'navigation';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_id' => array(
					'type'        => 'string',
					'description' => __( 'Widget id, e.g. "block-3".', 'wproadie' ),
				),
			),
			'required'             => array( 'widget_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$widget_id = (string) $input['widget_id'];
		$sidebars  = $this->sidebars_widgets();

		$found    = null;
		$position = 0;
		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
				continue;
			}
			$index = array_search( $widget_id, $widget_ids, true );
			if ( false !== $index ) {
				$found    = (string) $sidebar_id;
				$position = (int) $index;
				break;
			}
		}

		if ( null === $found ) {
			$inactive = isset( $sidebars['wp_inactive_widgets'] ) && in_array( $widget_id, (array) $sidebars['wp_inactive_widgets'], true );
			return $inactive
				? $this->error( 'widget_inactive', __( 'That widget is already inactive.', 'wproadie' ) )
				: $this->error( 'widget_not_found', __( 'No widget with that id is in a widget area. Use list-widgets to see widget ids.', 'wproadie' ), 404 );
		}

		$this->add_undo( Undo::option( 'sidebars_widgets' ) );

		$sidebars[ $found ]              = array_values(
			array_filter(
				$sidebars[ $found ],
				static function ( $id ) use ( $widget_id ) {
					return $id !== $widget_id;
				}
			)
		);
		$inactive                        = isset( $sidebars['wp_inactive_widgets'] ) && is_array( $sidebars['wp_inactive_widgets'] ) ? $sidebars['wp_inactive_widgets'] : array();
		$inactive[]                      = $widget_id;
		$sidebars['wp_inactive_widgets'] = $inactive;
		wp_set_sidebars_widgets( $sidebars );

		return array(
			'widget_id'     => $widget_id,
			'removed_from'  => $found,
			'position'      => $position + 1,
			'moved_to'      => 'wp_inactive_widgets',
			'settings_kept' => true,
		);
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
