<?php
/**
 * Who may connect to WPRoadie. Replaces the adapter's default check, which
 * admits any logged-in user.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Server;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class Permission {

	/**
	 * Granted per user, never per role, so client admins on the sites we
	 * manage do not get AI access just by being administrators.
	 */
	const CAPABILITY = 'wproadie_connect';

	/**
	 * Whether a user may use WPRoadie: an administrator who has also been
	 * granted the WPRoadie capability.
	 */
	public static function can_connect( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		return user_can( $user_id, 'manage_options' ) && user_can( $user_id, self::CAPABILITY );
	}

	/**
	 * Managing WPRoadie (the admin screen, granting access) needs the same
	 * pair of capabilities as connecting.
	 */
	public static function can_manage(): bool {
		return self::can_connect();
	}

	/**
	 * Transport permission callback for the MCP server's HTTP endpoint.
	 *
	 * @param mixed $request The REST request (unused).
	 * @return true|WP_Error
	 */
	public static function check_transport( $request = null ) {
		unset( $request );

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'wproadie_not_authenticated', __( 'Authenticate with a WordPress Application Password.', 'wproadie' ), array( 'status' => 401 ) );
		}
		if ( ! self::can_connect() ) {
			return new WP_Error( 'wproadie_forbidden', __( 'This user is not allowed to connect to WPRoadie.', 'wproadie' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function grant( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! $user->has_cap( 'manage_options' ) ) {
			return false;
		}
		$user->add_cap( self::CAPABILITY );
		return true;
	}

	public static function revoke( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		$user->remove_cap( self::CAPABILITY );
		return true;
	}

	/**
	 * Users who have been granted the WPRoadie capability directly.
	 *
	 * @return WP_User[]
	 */
	public static function granted_users(): array {
		global $wpdb;

		return get_users(
			array(
				'meta_key'     => $wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '"' . self::CAPABILITY . '"', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'LIKE',
			)
		);
	}
}
