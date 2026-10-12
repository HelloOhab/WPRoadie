<?php
/**
 * Creates a classic navigation menu.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Navigation;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class CreateMenu extends AbilityBase {

	public function slug(): string {
		return 'create-menu';
	}

	public function label(): string {
		return __( 'Create menu', 'wproadie' );
	}

	public function description(): string {
		return __( 'Creates an empty classic navigation menu and returns its id and slug. Add items with add-menu-item and show it on the site with assign-menu-location. Menu names must be unique. Block themes do not display classic menus in their own navigation (see list-menus). Returns an undo_id; pass it to undo-change to delete the menu again.', 'wproadie' );
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
				'name' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Menu name, e.g. "Main menu".', 'wproadie' ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
	}

	protected function execute( array $input ) {
		$name = sanitize_text_field( $input['name'] );
		if ( '' === $name ) {
			return $this->error( 'invalid_name', __( 'The menu name is empty.', 'wproadie' ) );
		}

		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			return $this->error( 'menu_create_failed', $menu_id->get_error_message() );
		}
		$this->add_undo( Undo::term_created( (int) $menu_id, 'nav_menu' ) );

		$menu = wp_get_nav_menu_object( $menu_id );
		return array(
			'id'   => (int) $menu_id,
			'name' => $menu ? $menu->name : $name,
			'slug' => $menu ? $menu->slug : '',
		);
	}
}
