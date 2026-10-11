<?php
/**
 * Per-user rate limit on ability calls.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Safety;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	const DEFAULT_PER_MINUTE = 120;

	/**
	 * Calls allowed per user per minute. 0 turns the limit off.
	 */
	public static function per_minute(): int {
		$limit = Settings::get( 'rate_limit' );
		$limit = is_numeric( $limit ) ? (int) $limit : self::DEFAULT_PER_MINUTE;
		return max( 0, (int) apply_filters( 'wproadie_rate_limit', $limit ) );
	}

	/**
	 * Counts one call for the user in the current minute.
	 *
	 * The counter is a transient, so with a persistent object cache it is
	 * shared across requests cheaply; without one it is an options row.
	 * It is not atomic: two simultaneous calls can both pass at the edge
	 * of the limit, which is acceptable for a runaway-agent brake.
	 *
	 * @return true|WP_Error
	 */
	public static function hit( int $user_id ) {
		$limit = self::per_minute();
		if ( 0 === $limit ) {
			return true;
		}

		$window = (int) floor( time() / MINUTE_IN_SECONDS );
		$key    = 'wproadie_rl_' . $user_id . '_' . $window;
		$count  = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'wproadie_rate_limited',
				sprintf(
					/* translators: %d: calls per minute. */
					__( 'Rate limit reached: at most %d WPRoadie calls per minute. Wait a minute and try again.', 'wproadie' ),
					$limit
				),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return true;
	}
}
