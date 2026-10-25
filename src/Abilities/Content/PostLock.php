<?php
/**
 * A short per-post lock for read-modify-write tools.
 *
 * Several calls editing the same page at once (a fleet job, two chats, a
 * person in the builder) each read the page, change their part and write
 * the whole page back; without a lock the last write silently drops the
 * others. The lock is an option row, which add_option() creates
 * atomically; it expires on its own so a crashed call never blocks a
 * page for long.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

defined( 'ABSPATH' ) || exit;

final class PostLock {

	const PREFIX = 'wproadie_lock_post_';
	/** A lock older than this was left behind by a failed call. */
	const STALE_SECONDS = 30;
	/** How long a call waits for another one to finish. */
	const WAIT_MS = 5000;

	/**
	 * Locks held by this request, released on shutdown if a call dies.
	 *
	 * @var array<int, true>
	 */
	private static $held = array();

	/**
	 * Takes the lock for a post, waiting up to $wait_ms for the holder to
	 * finish. False when another call still holds it after waiting.
	 */
	public static function acquire( int $post_id, int $wait_ms = self::WAIT_MS ): bool {
		$key      = self::PREFIX . $post_id;
		$deadline = microtime( true ) + $wait_ms / 1000;
		do {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$since = get_option( $key );
			if ( false === $since || (int) $since < time() - self::STALE_SECONDS ) {
				if ( false !== $since ) {
					delete_option( $key );
				}
				if ( add_option( $key, (string) time(), '', false ) ) {
					self::$held[ $post_id ] = true;
					if ( 1 === count( self::$held ) ) {
						register_shutdown_function( array( self::class, 'release_all' ) );
					}
					return true;
				}
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 100000 );
		} while ( true );
	}

	public static function release( int $post_id ): void {
		if ( isset( self::$held[ $post_id ] ) ) {
			unset( self::$held[ $post_id ] );
			delete_option( self::PREFIX . $post_id );
		}
	}

	public static function release_all(): void {
		foreach ( array_keys( self::$held ) as $post_id ) {
			self::release( (int) $post_id );
		}
	}

	/**
	 * The post's content as the database has it right now, bypassing the
	 * object cache, to tell whether it changed since a tool read it.
	 */
	public static function current_content( int $post_id ): ?string {
		global $wpdb;
		// get_row, not get_var: get_var() answers null for an empty string too.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point is to bypass the cache.
		return $row ? (string) $row['post_content'] : null;
	}

	/**
	 * One post meta value as the database has it right now.
	 */
	public static function current_meta( int $post_id, string $key ): ?string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1", $post_id, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point is to bypass the cache.
		return $row ? (string) $row['meta_value'] : null;
	}

	/** The error every tool returns when the page changed under it. */
	public static function busy_error( string $prefix, string $what ): \WP_Error {
		return new \WP_Error(
			'wproadie_' . $prefix . 'busy',
			sprintf(
				/* translators: %s: "page" or similar. */
				__( 'Another change to this %s is being saved right now. Nothing was changed; read it again and retry in a moment.', 'wproadie' ),
				$what
			),
			array( 'status' => 409 )
		);
	}

	public static function changed_error( string $prefix, string $what, string $reread ): \WP_Error {
		return new \WP_Error(
			'wproadie_' . $prefix . 'changed',
			sprintf(
				/* translators: 1: "page" or similar, 2: tool to read it again with. */
				__( 'This %1$s was changed by another call (or a person) after this call read it, so writing would have dropped that change. Nothing was changed; read it again with %2$s and retry.', 'wproadie' ),
				$what,
				$reread
			),
			array( 'status' => 409 )
		);
	}
}
