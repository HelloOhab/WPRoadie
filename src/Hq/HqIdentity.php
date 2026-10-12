<?php
/**
 * Which WPRoadie HQ person a WordPress user is.
 *
 * HQ says who is asking (their verified Google email) when it asks for a
 * connection password. The site only issues one if that email is the
 * WordPress user's own email, or the HQ email a site administrator linked
 * to the user. So an HQ account cannot get a password for someone else's
 * WordPress login by typing that login into HQ.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WP_User;

defined( 'ABSPATH' ) || exit;

final class HqIdentity {

	/** User meta: the HQ email linked to this WordPress user. */
	const META = 'wproadie_hq_email';

	/**
	 * Whether provisioning requires a matching email (default yes).
	 */
	public static function required(): bool {
		/**
		 * Filters whether HQ's email must match the WordPress user before a
		 * connection password is issued. Turn off only if you trust every
		 * HQ account to name the right WordPress user.
		 *
		 * @param bool $required Default true.
		 */
		return (bool) apply_filters( 'wproadie_hq_require_email_match', true );
	}

	public static function linked_email( int $user_id ): string {
		$email = get_user_meta( $user_id, self::META, true );
		return is_string( $email ) ? $email : '';
	}

	/**
	 * Links (or with '' unlinks) an HQ email to a user.
	 *
	 * @return bool False if the email is not valid.
	 */
	public static function link( int $user_id, string $email ): bool {
		$email = trim( $email );
		if ( '' === $email ) {
			delete_user_meta( $user_id, self::META );
			return true;
		}
		$clean = sanitize_email( $email );
		if ( '' === $clean || ! is_email( $clean ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META, strtolower( $clean ) );
		return true;
	}

	/**
	 * True if `$hq_email` is the user's email or their linked HQ email.
	 */
	public static function matches( WP_User $user, string $hq_email ): bool {
		$hq_email = strtolower( trim( $hq_email ) );
		if ( '' === $hq_email ) {
			return false;
		}
		if ( strtolower( (string) $user->user_email ) === $hq_email ) {
			return true;
		}
		$linked = self::linked_email( (int) $user->ID );
		return '' !== $linked && strtolower( $linked ) === $hq_email;
	}

	/**
	 * The one user whose linked HQ email is `$email`, if exactly one.
	 */
	public static function user_by_linked_email( string $email ): ?WP_User {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 2,
			)
		);
		return 1 === count( $users ) && $users[0] instanceof WP_User ? $users[0] : null;
	}

	/**
	 * After pairing: links the approving HQ admin's email to the WordPress
	 * user who clicked "Connect to HQ", unless their emails already match
	 * or the user already has a link.
	 */
	public static function link_after_pairing( int $user_id, string $hq_email ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || '' === trim( $hq_email ) || ! is_email( trim( $hq_email ) ) ) {
			return false;
		}
		if ( self::matches( $user, $hq_email ) || '' !== self::linked_email( $user_id ) ) {
			return false;
		}
		return self::link( $user_id, $hq_email );
	}
}
