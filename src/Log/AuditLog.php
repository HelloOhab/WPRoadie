<?php
/**
 * Records every ability call: who, what, when, from where and how it ended.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Log;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'wproadie_db_version';

	const STATUS_SUCCESS = 'success';
	const STATUS_ERROR   = 'error';

	/**
	 * Inputs and results are stored for review, capped so a large post body
	 * or file listing cannot bloat the table.
	 */
	const MAX_FIELD_BYTES = 8192;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wproadie_log';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ability varchar(191) NOT NULL,
				status varchar(20) NOT NULL,
				input longtext NULL,
				result longtext NULL,
				error_code varchar(191) NULL,
				ip varchar(45) NULL,
				transport varchar(20) NULL,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY user_id (user_id),
				KEY ability (ability)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Writes one log entry.
	 *
	 * @param string $ability     Ability name.
	 * @param mixed  $input       The ability input.
	 * @param mixed  $result      The ability result or WP_Error.
	 * @param int    $duration_ms How long the call took.
	 */
	public static function record( string $ability, $input, $result, int $duration_ms ): void {
		global $wpdb;

		$is_error = is_wp_error( $result );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'user_id'     => get_current_user_id(),
				'ability'     => $ability,
				'status'      => $is_error ? self::STATUS_ERROR : self::STATUS_SUCCESS,
				'input'       => self::encode( $input ),
				'result'      => $is_error ? $result->get_error_message() : self::encode( $result ),
				'error_code'  => $is_error ? (string) $result->get_error_code() : null,
				'ip'          => self::client_ip(),
				'transport'   => self::transport(),
				'duration_ms' => max( 0, $duration_ms ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Most recent entries first.
	 *
	 * @return object[]
	 */
	public static function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name is not user input.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );

		return is_array( $rows ) ? $rows : array();
	}

	public static function drop(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * @param mixed $value
	 */
	private static function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			return null;
		}
		if ( strlen( $json ) > self::MAX_FIELD_BYTES ) {
			$json = substr( $json, 0, self::MAX_FIELD_BYTES ) . '…[truncated]';
		}
		return $json;
	}

	private static function client_ip(): ?string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return null;
		}
		// REMOTE_ADDR only: forwarded headers are client-controlled. Hosts
		// behind a proxy can correct this with the filter.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'wproadie_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	private static function transport(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'http';
		}
		return 'other';
	}
}
