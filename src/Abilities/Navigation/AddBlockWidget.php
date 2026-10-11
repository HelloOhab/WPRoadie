<?php
/**
 * Adds a block widget to a widget area.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class AddBlockWidget extends AbilityBase {

	public function slug(): string {
		return 'add-block-widget';
	}

	public function label(): string {
		return __( 'Add block widget', 'wproadie' );
	}

	public function description(): string {
		return __( 'Adds a block widget to a widget area (sidebar) of a classic theme. content is block markup (e.g. <!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->); anything without block comments is wrapped in a Custom HTML block as-is. position is the 1-based place in the area; omit to append. Use list-widgets for the area ids. Returns the new widget id. Returns an undo_id; pass it to undo-change to remove it again.', 'wproadie' );
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
				'sidebar_id' => array(
					'type'        => 'string',
					'description' => __( 'Widget area id from list-widgets, e.g. "sidebar-1".', 'wproadie' ),
				),
				'content'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Block markup, or HTML to wrap in a Custom HTML block.', 'wproadie' ),
				),
				'position'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '1-based position in the area. Omit to append.', 'wproadie' ),
				),
			),
			'required'             => array( 'sidebar_id', 'content' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		global $wp_registered_sidebars;

		$sidebar_id = (string) $input['sidebar_id'];
		if ( ! isset( $wp_registered_sidebars[ $sidebar_id ] ) ) {
			return $this->error(
				'unknown_sidebar',
				sprintf(
					/* translators: %s: comma-separated widget area ids. */
					__( 'The active theme has no such widget area. Registered areas: %s.', 'wproadie' ),
					$wp_registered_sidebars ? implode( ', ', array_keys( $wp_registered_sidebars ) ) : __( 'none', 'wproadie' )
				)
			);
		}

		$content = trim( (string) $input['content'] );
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			$content = "<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->";
		}
		// Same rule as the block widget's own save.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$content = wp_kses_post( $content );
		}

		$instances = get_option( 'widget_block', array() );
		$instances = is_array( $instances ) ? $instances : array();
		$numbers   = array_filter( array_keys( $instances ), 'is_int' );
		$number    = max( 1, $numbers ? max( $numbers ) : 1 ) + 1;
		$widget_id = 'block-' . $number;

		$sidebars = $this->sidebars_widgets();
		$area     = isset( $sidebars[ $sidebar_id ] ) && is_array( $sidebars[ $sidebar_id ] ) ? array_values( $sidebars[ $sidebar_id ] ) : array();
		$index    = isset( $input['position'] ) ? max( 0, min( (int) $input['position'] - 1, count( $area ) ) ) : count( $area );
		array_splice( $area, $index, 0, array( $widget_id ) );
		$sidebars[ $sidebar_id ] = $area;

		$this->add_undo( Undo::option( 'widget_block' ) );
		$this->add_undo( Undo::option( 'sidebars_widgets' ) );

		$instances[ $number ]      = array( 'content' => $content );
		$instances['_multiwidget'] = 1;
		update_option( 'widget_block', $instances );
		wp_set_sidebars_widgets( $sidebars );

		return array(
			'widget_id'  => $widget_id,
			'sidebar_id' => $sidebar_id,
			'position'   => $index + 1,
			'content'    => $content,
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
