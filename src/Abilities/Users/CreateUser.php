<?php
/**
 * Creates a user account.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Users;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class CreateUser extends AbilityBase {

	/**
	 * Capabilities that make a role "admin-like": holding any of them lets
	 * a user take over the site or other accounts.
	 */
	const ADMIN_CAPS = array( 'manage_options', 'promote_users', 'edit_users' );

	public function slug(): string {
		return 'create-user';
	}

	public function label(): string {
		return __( 'Create user', 'wproadie' );
	}

	public function description(): string {
		return __( 'Creates a user account with a role, e.g. an editor or author. The account gets a random strong password that is never returned; by default WordPress emails the new user a link to set their own password (send_notification false skips the email, and they then use "Lost your password"). Refuses administrator and other admin-level roles (any role that can manage options, edit users or promote users); an administrator must create those in wp-admin. Use list-users for the role slugs. Returns the new user id. Returns an undo_id; pass it to undo-change to delete the account (its content, if any, goes to whoever runs the undo).', 'wproadie' );
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
				'login'             => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 60,
					'description' => __( 'Username. Cannot be changed later.', 'wproadie' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'Email address; must not belong to another user.', 'wproadie' ),
				),
				'role'              => array(
					'type'        => 'string',
					'description' => __( 'Role slug, e.g. "editor", "author", "contributor", "subscriber".', 'wproadie' ),
				),
				'display_name'      => array(
					'type'        => 'string',
					'description' => __( 'Name shown publicly. Defaults to the login, or first and last name if given.', 'wproadie' ),
				),
				'first_name'        => array(
					'type'        => 'string',
					'description' => __( 'First name.', 'wproadie' ),
				),
				'last_name'         => array(
					'type'        => 'string',
					'description' => __( 'Last name.', 'wproadie' ),
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Email the user a link to set their password.', 'wproadie' ),
				),
			),
			'required'             => array( 'login', 'email', 'role' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'create_users' );
	}

	protected function execute( array $input ) {
		$login = sanitize_user( (string) $input['login'], true );
		$email = sanitize_email( (string) $input['email'] );
		$role  = (string) $input['role'];

		if ( '' === $login || ! validate_username( $login ) || $login !== $input['login'] ) {
			return $this->error( 'invalid_login', __( 'That username has characters WordPress does not allow. Use letters, numbers, spaces and . - _ @.', 'wproadie' ) );
		}
		if ( username_exists( $login ) ) {
			return $this->error( 'login_exists', __( 'That username is already taken.', 'wproadie' ), 409 );
		}
		if ( ! is_email( $email ) ) {
			return $this->error( 'invalid_email', __( 'That is not a valid email address.', 'wproadie' ) );
		}
		if ( email_exists( $email ) ) {
			return $this->error( 'email_exists', __( 'Another user already has that email address.', 'wproadie' ), 409 );
		}

		$role_check = self::check_role( $role );
		if ( is_wp_error( $role_check ) ) {
			return $role_check;
		}

		$first = isset( $input['first_name'] ) ? sanitize_text_field( $input['first_name'] ) : '';
		$last  = isset( $input['last_name'] ) ? sanitize_text_field( $input['last_name'] ) : '';
		$name  = isset( $input['display_name'] ) ? sanitize_text_field( $input['display_name'] ) : trim( $first . ' ' . $last );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'role'         => $role,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => '' !== $name ? $name : $login,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $this->error( 'user_create_failed', $user_id->get_error_message() );
		}
		$user_id = (int) $user_id;
		$this->add_undo( Undo::user_created( $user_id ) );

		$notify = ! isset( $input['send_notification'] ) || $input['send_notification'];
		if ( $notify ) {
			wp_send_new_user_notifications( $user_id, 'user' );
		}

		$user = get_userdata( $user_id );
		return array(
			'id'                => $user_id,
			'login'             => $user->user_login,
			'email'             => $user->user_email,
			'display_name'      => $user->display_name,
			'roles'             => array_values( $user->roles ),
			'notification_sent' => $notify,
		);
	}

	/**
	 * Whether a role exists, may be assigned by the current user, and is
	 * not admin-like. Shared with change-user-role.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_role( string $role ) {
		$object = get_role( $role );
		if ( ! $object ) {
			return new \WP_Error(
				'wproadie_unknown_role',
				sprintf(
					/* translators: %s: comma-separated role slugs. */
					__( 'No such role. Roles: %s.', 'wproadie' ),
					implode( ', ', array_keys( wp_roles()->get_names() ) )
				),
				array( 'status' => 400 )
			);
		}

		if ( self::is_admin_role( $role ) && ! apply_filters( 'wproadie_allow_admin_role_assignment', false ) ) {
			return new \WP_Error( 'wproadie_admin_role_refused', __( 'WPRoadie does not assign administrator or other admin-level roles (roles that can manage options, edit users or promote users). An administrator must do that in wp-admin.', 'wproadie' ), array( 'status' => 403 ) );
		}

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		if ( ! array_key_exists( $role, get_editable_roles() ) ) {
			return new \WP_Error( 'wproadie_role_not_editable', __( 'You are not allowed to assign that role.', 'wproadie' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public static function is_admin_role( string $role ): bool {
		if ( 'administrator' === $role ) {
			return true;
		}
		$object = get_role( $role );
		if ( ! $object ) {
			return false;
		}
		foreach ( self::ADMIN_CAPS as $cap ) {
			if ( $object->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}
}
