<?php
/**
 * Assigns a classic menu to a theme menu location.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class AssignMenuLocation extends AbilityBase {

	public function slug(): string {
		return 'assign-menu-location';
	}

	public function label(): string {
		return __( 'Assign menu location', 'wproadie' );
	}

	public function description(): string {
		return __( 'Shows a classic menu in one of the active theme\'s menu locations (e.g. "primary"), replacing whatever menu was there, or empties the location with menu_id 0. Use list-menus for the registered locations; block themes usually have none (their navigation is a wp_navigation post). The assignment belongs to the active theme. Returns the previous and new menu. Returns an undo_id; pass it to undo-change to reverse this.', 'wproadie' );
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
				'location' => array(
					'type'        => 'string',
					'description' => __( 'Location slug registered by the theme.', 'wproadie' ),
				),
				'menu_id'  => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Menu id, or 0 to leave the location empty.', 'wproadie' ),
				),
			),
			'required'             => array( 'location', 'menu_id' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$location   = (string) $input['location'];
		$menu_id    = (int) $input['menu_id'];
		$registered = get_registered_nav_menus();

		if ( ! isset( $registered[ $location ] ) ) {
			return $this->error(
				'unknown_location',
				sprintf(
					/* translators: %s: comma-separated location slugs. */
					__( 'The active theme has no such menu location. Registered locations: %s.', 'wproadie' ),
					$registered ? implode( ', ', array_keys( $registered ) ) : __( 'none', 'wproadie' )
				)
			);
		}
		if ( $menu_id && ! is_nav_menu( $menu_id ) ) {
			return $this->error( 'menu_not_found', __( 'No menu with that id. Use list-menus to see the menus.', 'wproadie' ), 404 );
		}

		$locations = get_theme_mod( 'nav_menu_locations' );
		$locations = is_array( $locations ) ? $locations : array();
		$previous  = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;

		$this->add_undo( Undo::theme_mod( 'nav_menu_locations' ) );
		if ( $menu_id ) {
			$locations[ $location ] = $menu_id;
		} else {
			unset( $locations[ $location ] );
		}
		set_theme_mod( 'nav_menu_locations', $locations );

		return array(
			'location'         => $location,
			'previous_menu_id' => $previous,
			'menu_id'          => $menu_id,
		);
	}
}
