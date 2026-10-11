<?php
/**
 * Users tools: list-users, create-user, change-user-role.
 *
 * The users group is off by default; enable it for these tests with
 * `wp eval 'WPRoadie\Safety\Settings::set_group("users", true);'`.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Log\Undo;

/**
 * Deletes a test user if it still exists.
 */
function wproadie_users_test_cleanup( string $login ): void {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
}

wproadie_test(
	'list-users returns users without secrets',
	function () {
		$result = wproadie_ok( wproadie_call( 'list-users', array( 'per_page' => 5 ) ) );
		wproadie_assert( $result['total'] >= 1, 'at least one user' );
		$first = $result['users'][0];
		foreach ( array( 'id', 'login', 'display_name', 'email', 'roles', 'registered', 'post_count' ) as $key ) {
			wproadie_assert( array_key_exists( $key, $first ), "has {$key}" );
		}
		$json = wp_json_encode( $result );
		wproadie_assert( false === strpos( $json, 'user_pass' ) && false === strpos( $json, '$P$' ) && false === strpos( $json, '$wp$' ) && false === strpos( $json, 'session_tokens' ), 'no password hashes or sessions' );

		$admins = wproadie_ok( wproadie_call( 'list-users', array( 'role' => 'administrator' ) ) );
		foreach ( $admins['users'] as $user ) {
			wproadie_assert( in_array( 'administrator', $user['roles'], true ), 'role filter' );
		}
	}
);

wproadie_test(
	'create-user creates an account and undo deletes it',
	function () {
		$login = 'wproadie_test_author';
		wproadie_users_test_cleanup( $login );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'create-user',
					array(
						'login'             => $login,
						'email'             => 'wproadie-author@example.com',
						'role'              => 'author',
						'first_name'        => 'Ada',
						'last_name'         => 'Lovelace',
						'send_notification' => false,
					)
				)
			);
			$user   = get_userdata( $result['id'] );
			wproadie_assert_same( array( 'author' ), array_values( $user->roles ) );
			wproadie_assert_same( 'Ada Lovelace', $user->display_name );
			wproadie_assert( ! isset( $result['password'] ) && false === strpos( wp_json_encode( $result ), 'pass' ), 'password never returned' );

			wproadie_assert_error(
				wproadie_call(
					'create-user',
					array(
						'login' => $login,
						'email' => 'other@example.com',
						'role'  => 'author',
					)
				),
				'wproadie_login_exists'
			);

			Undo::revert( $result['undo_id'] );
			wproadie_assert( ! get_userdata( $result['id'] ), 'user deleted by undo' );
		} finally {
			wproadie_users_test_cleanup( $login );
		}
	}
);

wproadie_test(
	'create-user refuses admin-like and unknown roles',
	function () {
		$base = array(
			'login'             => 'wproadie_test_refused',
			'email'             => 'wproadie-refused@example.com',
			'send_notification' => false,
		);
		add_role(
			'wproadie_test_manager',
			'WPRoadie test manager',
			array(
				'read'          => true,
				'promote_users' => true,
			)
		);
		try {
			wproadie_assert_error( wproadie_call( 'create-user', $base + array( 'role' => 'administrator' ) ), 'wproadie_admin_role_refused' );
			wproadie_assert_error( wproadie_call( 'create-user', $base + array( 'role' => 'wproadie_test_manager' ) ), 'wproadie_admin_role_refused' );
			wproadie_assert_error( wproadie_call( 'create-user', $base + array( 'role' => 'no_such_role' ) ), 'wproadie_unknown_role' );
			wproadie_assert_error(
				wproadie_call(
					'create-user',
					array_merge(
						$base,
						array(
							'role'  => 'author',
							'email' => 'not-an-email',
						)
					)
				),
				'wproadie_invalid_email'
			);
			wproadie_assert( ! get_user_by( 'login', 'wproadie_test_refused' ), 'no user created' );
		} finally {
			remove_role( 'wproadie_test_manager' );
			wproadie_users_test_cleanup( 'wproadie_test_refused' );
		}
	}
);

wproadie_test(
	'change-user-role changes roles with guardrails and undo',
	function () {
		$login    = 'wproadie_test_promote';
		$admin_lg = 'wproadie_test_admin2';
		wproadie_users_test_cleanup( $login );
		wproadie_users_test_cleanup( $admin_lg );
		$user_id  = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => 'wproadie-promote@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			)
		);
		$admin_id = wp_insert_user(
			array(
				'user_login' => $admin_lg,
				'user_email' => 'wproadie-admin2@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		try {
			$result = wproadie_ok(
				wproadie_call(
					'change-user-role',
					array(
						'user_id' => $user_id,
						'role'    => 'editor',
					)
				)
			);
			wproadie_assert_same( array( 'author' ), $result['old_roles'] );
			wproadie_assert_same( array( 'editor' ), array_values( get_userdata( $user_id )->roles ) );

			wproadie_assert_error(
				wproadie_call(
					'change-user-role',
					array(
						'user_id' => $user_id,
						'role'    => 'administrator',
					)
				),
				'wproadie_admin_role_refused'
			);
			wproadie_assert_error(
				wproadie_call(
					'change-user-role',
					array(
						'user_id' => get_current_user_id(),
						'role'    => 'editor',
					)
				),
				'wproadie_own_role_refused'
			);
			wproadie_assert_error(
				wproadie_call(
					'change-user-role',
					array(
						'user_id' => $admin_id,
						'role'    => 'subscriber',
					)
				),
				'wproadie_admin_user_refused'
			);
			wproadie_assert_same( array( 'administrator' ), array_values( get_userdata( $admin_id )->roles ), 'other admin untouched' );

			Undo::revert( $result['undo_id'] );
			wproadie_assert_same( array( 'author' ), array_values( get_userdata( $user_id )->roles ), 'undo restores role' );

			// The filter lets a site opt in to admin-level assignment.
			add_filter( 'wproadie_allow_admin_role_assignment', '__return_true' );
			try {
				wproadie_ok(
					wproadie_call(
						'change-user-role',
						array(
							'user_id' => $user_id,
							'role'    => 'administrator',
						)
					)
				);
				wproadie_assert_same( array( 'administrator' ), array_values( get_userdata( $user_id )->roles ) );
			} finally {
				remove_filter( 'wproadie_allow_admin_role_assignment', '__return_true' );
			}
		} finally {
			wproadie_users_test_cleanup( $login );
			wproadie_users_test_cleanup( $admin_lg );
		}
	}
);
