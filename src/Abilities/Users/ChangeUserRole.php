<?php
/**
 * Changes a user's role.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Users;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ChangeUserRole extends AbilityBase {

	public function slug(): string {
		return 'change-user-role';
	}

	public function label(): string {
		return __( 'Change user role', 'wproadie' );
	}

	public function description(): string {
		return __( 'Gives a user a new role, replacing their current roles, e.g. promoting an author to editor. Refuses to change your own role, to assign administrator or other admin-level roles (roles that can manage options, edit users or promote users), and to change the role of anyone who is currently an administrator; an administrator must do those in wp-admin. Returns the old and new roles. Returns an undo_id; pass it to undo-change to restore the old roles.', 'wproadie' );
	}

	public function group(): string {
		return 'users';
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'User id from list-users.', 'wproadie' ),
				),
				'role'    => array(
					'type'        => 'string',
					'description' => __( 'New role slug, e.g. "editor".', 'wproadie' ),
				),
			),
			'required'             => array( 'user_id', 'role' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		return current_user_can( 'promote_user', (int) ( $input['user_id'] ?? 0 ) );
	}

	protected function execute( array $input ) {
		$user_id = (int) $input['user_id'];
		$role    = (string) $input['role'];
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'user_not_found', __( 'No user with that id.', 'wproadie' ), 404 );
		}
		if ( get_current_user_id() === $user_id ) {
			return $this->error( 'own_role_refused', __( 'You cannot change your own role through WPRoadie.', 'wproadie' ), 403 );
		}

		$allow_admin = (bool) apply_filters( 'wproadie_allow_admin_role_assignment', false );
		if ( ! $allow_admin && ( is_super_admin( $user_id ) || user_can( $user, 'manage_options' ) || user_can( $user, 'promote_users' ) || user_can( $user, 'edit_users' ) ) ) {
			return $this->error( 'admin_user_refused', __( 'That user is an administrator (or has admin-level capabilities). WPRoadie does not change administrators\' roles; do it in wp-admin.', 'wproadie' ), 403 );
		}

		$role_check = CreateUser::check_role( $role );
		if ( is_wp_error( $role_check ) ) {
			return $role_check;
		}

		$old = array_values( $user->roles );
		if ( array( $role ) === $old ) {
			return array(
				'user_id'   => $user_id,
				'old_roles' => $old,
				'roles'     => $old,
				'changed'   => false,
			);
		}

		$this->add_undo( Undo::user_roles( $user_id ) );
		$user->set_role( $role );

		$user = get_userdata( $user_id );
		return array(
			'user_id'   => $user_id,
			'old_roles' => $old,
			'roles'     => array_values( $user->roles ),
			'changed'   => true,
		);
	}
}
