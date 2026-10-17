<?php
/**
 * Records every ability call: who, what, when, from where and how it ended.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Log;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	const DB_VERSION        = '2';
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
				undo_data longtext NULL,
				undone_at datetime NULL,
				undone_by bigint(20) unsigned NULL,
				undo_of bigint(20) unsigned NULL,
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
	 * @param array  $undo        Undo records from WPRoadie\Log\Undo.
	 * @param int    $undo_of     Log ID this entry reverts, if it is an undo.
	 * @return int Log entry ID, or 0 if it could not be written.
	 */
	public static function record( string $ability, $input, $result, int $duration_ms, array $undo = array(), int $undo_of = 0 ): int {
		global $wpdb;

		$is_error = is_wp_error( $result );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$inserted = $wpdb->insert(
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
				// Undo data is never truncated: a cut-off snapshot could not be restored.
				'undo_data'   => $undo ? wp_json_encode( $undo ) : null,
				'undo_of'     => $undo_of ? $undo_of : null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return object|null The log row.
	 */
	public static function get( int $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	public static function mark_undone( int $id, int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'undone_at' => current_time( 'mysql', true ),
				'undone_by' => $user_id,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Filtered, paged log entries, most recent first.
	 *
	 * @param array $filters Optional: user_id, ability, status, undoable (bool).
	 * @return array{rows: object[], total: int}
	 */
	public static function query( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['ability'] ) ) {
			$where[]  = 'ability = %s';
			$params[] = (string) $filters['ability'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['undoable'] ) ) {
			$where[] = 'undo_data IS NOT NULL AND undone_at IS NULL';
		}

		$table     = self::table();
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name is ours; clauses are built from fixed fragments with placeholders.
		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $limit, $offset ) ) ) );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Who made a logged call, for display: a login, "HQ" for signed HQ
	 * commands (which run with no WordPress user), or "#<id>" for a
	 * deleted user.
	 *
	 * @param object $row Log row.
	 */
	public static function actor( $row ): string {
		$user = get_userdata( (int) $row->user_id );
		if ( $user ) {
			return $user->user_login;
		}
		if ( 0 === (int) $row->user_id && 0 === strpos( (string) $row->ability, 'hq/' ) ) {
			return 'HQ';
		}
		return '#' . $row->user_id;
	}

	/**
	 * AI activity for HQ's heartbeat: the last tool call and 24-hour
	 * counts. Counts only WPRoadie tool calls, not HQ commands or undos
	 * made from wp-admin.
	 *
	 * @return array{last_call_at: string|null, last_call_user: string|null, last_call_tool: string|null, calls_24h: int, errors_24h: int, users_24h: string[]}
	 */
	public static function activity_summary(): array {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name is not user input.
		$like   = $wpdb->esc_like( 'wproadie/' ) . '%';
		$last   = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, user_id, ability FROM {$table} WHERE ability LIKE %s AND ability <> 'wproadie/undo' ORDER BY id DESC LIMIT 1", $like ) );
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS calls, SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors FROM {$table} WHERE ability LIKE %s AND ability <> 'wproadie/undo' AND created_at >= %s", $like, $since ) );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE ability LIKE %s AND ability <> 'wproadie/undo' AND created_at >= %s", $like, $since ) );
		// phpcs:enable

		$login = static function ( $user_id ) {
			$user = get_userdata( (int) $user_id );
			return $user ? $user->user_login : null;
		};

		return array(
			'last_call_at'   => $last ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $last->created_at . ' UTC' ) ) : null,
			'last_call_user' => $last ? $login( $last->user_id ) : null,
			'last_call_tool' => $last ? (string) $last->ability : null,
			'calls_24h'      => $counts ? (int) $counts->calls : 0,
			'errors_24h'     => $counts ? (int) $counts->errors : 0,
			'users_24h'      => array_values( array_filter( array_map( $login, is_array( $ids ) ? $ids : array() ) ) ),
		);
	}

	/**
	 * Deletes entries older than the retention period.
	 */
	public static function prune(): void {
		global $wpdb;
		$days = (int) apply_filters( 'wproadie_log_retention_days', 365 );
		if ( $days <= 0 ) {
			return;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );

		// File backups only serve undo for log entries, so they expire with them.
		$backups = glob( trailingslashit( \WPRoadie\Safety\Guardrails::own_dir() ) . 'backups/*.bak' );
		foreach ( is_array( $backups ) ? $backups : array() as $file ) {
			if ( filemtime( $file ) < time() - $days * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
		\WPRoadie\Abilities\Extensions\SafeUpdate::prune( $days );
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
