<?php
/**
 * Shared helpers for the database tools: driver detection, transaction
 * control, error cleanup and masking of secrets in results.
 *
 * Not an ability. The SQL these helpers run is fixed (transaction control,
 * session settings, table status); the AI's own SQL is run by the tools
 * after the Guardrails checks.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Database;

defined( 'ABSPATH' ) || exit;

final class Sql {

	const MASK = '[hidden]';

	/**
	 * Result columns that are always masked, by name (case-insensitive).
	 */
	const MASKED_COLUMNS = array( 'user_pass', 'user_activation_key' );

	/**
	 * Meta keys whose meta_value is masked when the row also has a
	 * meta_key column: login sessions and application passwords.
	 */
	const MASKED_META_KEYS = array( 'session_tokens', '_application_passwords' );

	/**
	 * Engines that can roll a change back.
	 */
	const TRANSACTIONAL_ENGINES = array( 'innodb', 'xtradb', 'tokudb', 'rocksdb', 'ndbcluster', 'ndb' );

	/**
	 * Longest string returned per cell, in bytes.
	 */
	const MAX_CELL_BYTES = 10000;

	/**
	 * Statement check for reads. The quote- and comment-aware check lives
	 * in Guardrails; kept here for the database tools' call sites.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_read( string $sql ) {
		return \WPRoadie\Safety\Guardrails::check_read_query( $sql );
	}

	/**
	 * Statement check for writes.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_write( string $sql ) {
		return \WPRoadie\Safety\Guardrails::check_write_query( $sql );
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function lex( string $sql ) {
		return \WPRoadie\Safety\Guardrails::lex_sql( $sql );
	}

	/**
	 * Whether the database is not a real MySQL/MariaDB server, e.g. the
	 * SQLite Database Integration plugin. Emulation layers may accept
	 * statements such as START TRANSACTION READ ONLY without enforcing
	 * them, so the tools never claim guarantees on them.
	 */
	public static function is_emulated(): bool {
		global $wpdb;
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			return true;
		}
		if ( false !== stripos( get_class( $wpdb ), 'sqlite' ) ) {
			return true;
		}
		return false !== stripos( self::server_info(), 'sqlite' );
	}

	public static function is_mariadb(): bool {
		return false !== stripos( self::server_info(), 'mariadb' );
	}

	public static function server_info(): string {
		global $wpdb;
		$info = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '';
		return is_string( $info ) ? $info : '';
	}

	/**
	 * Runs a fixed control statement (transaction, session setting)
	 * without printing errors.
	 *
	 * @param string $sql Fixed SQL, never input from the AI.
	 * @return bool Whether it succeeded.
	 */
	public static function control( string $sql ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fixed transaction/session statement.
		$failed   = false === $result || '' !== (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		return ! $failed;
	}

	/**
	 * The last database error as one line of plain text. Some drivers
	 * (the SQLite integration) put HTML and a backtrace in last_error.
	 */
	public static function last_error(): string {
		global $wpdb;
		return self::clean_error( (string) $wpdb->last_error );
	}

	public static function clean_error( string $error ): string {
		if ( '' === trim( $error ) ) {
			return '';
		}
		$text = html_entity_decode( wp_strip_all_tags( str_replace( array( '<br>', '<br />', '</p>', '</li>', '</div>' ), "\n", $error ) ), ENT_QUOTES );
		// Drop any backtrace and keep the last meaningful line.
		$cut = strpos( $text, 'Backtrace:' );
		if ( false !== $cut ) {
			$text = substr( $text, 0, $cut );
		}
		$lines = array_values(
			array_filter(
				array_map( 'trim', preg_split( '/\R/', $text ) ),
				static function ( $line ) {
					return '' !== $line;
				}
			)
		);
		$line  = $lines ? end( $lines ) : trim( $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $line, 0, 1000 ) : substr( $line, 0, 1000 );
	}

	/**
	 * Engines of the tables the SQL mentions, by table name. A table
	 * counts as mentioned when its name appears as a whole word, which
	 * may over-match (safe: it only makes the write tool more cautious).
	 *
	 * @return array<string,string> Table name => lower-case engine ('' if unknown).
	 */
	public static function mentioned_table_engines( string $sql ): array {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$status   = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema information.
		$wpdb->suppress_errors( $suppress );

		$engines = array();
		foreach ( (array) $status as $row ) {
			if ( ! isset( $row['Name'] ) ) {
				continue;
			}
			$name = (string) $row['Name'];
			if ( preg_match( '/(?<![A-Za-z0-9_$])' . preg_quote( $name, '/' ) . '(?![A-Za-z0-9_$])/i', $sql ) ) {
				$engines[ $name ] = strtolower( (string) ( $row['Engine'] ?? '' ) );
			}
		}
		return $engines;
	}

	/**
	 * Table names from the list whose engine cannot roll back. Views and
	 * unknown engines count as non-transactional, on the safe side.
	 *
	 * @param array<string,string> $engines From mentioned_table_engines().
	 * @return string[]
	 */
	public static function non_transactional( array $engines ): array {
		$tables = array();
		foreach ( $engines as $name => $engine ) {
			if ( ! in_array( $engine, self::TRANSACTIONAL_ENGINES, true ) ) {
				$tables[] = $name;
			}
		}
		return $tables;
	}

	/**
	 * Masks secrets in result rows: password and activation-key columns,
	 * values that look like password hashes, and session/application
	 * password meta. Long values are cut to MAX_CELL_BYTES.
	 *
	 * Best effort, not a security boundary: a determined query can still
	 * transform a value (e.g. SUBSTRING) past the masking.
	 *
	 * @param array[] $rows Rows as associative arrays.
	 * @return array{rows: array[], masked: int, cut: int}
	 */
	public static function mask_rows( array $rows ): array {
		$masked = 0;
		$cut    = 0;
		foreach ( $rows as $i => $row ) {
			$lower      = array_change_key_case( (array) $row, CASE_LOWER );
			$secret_row = isset( $lower['meta_key'] ) && in_array( (string) $lower['meta_key'], self::MASKED_META_KEYS, true );

			foreach ( (array) $row as $column => $value ) {
				$name = strtolower( (string) $column );
				if (
					( null !== $value && in_array( $name, self::MASKED_COLUMNS, true ) )
					|| ( $secret_row && 'meta_value' === $name )
					|| ( is_string( $value ) && self::looks_like_hash( $value ) )
				) {
					$rows[ $i ][ $column ] = self::MASK;
					++$masked;
					continue;
				}
				if ( is_string( $value ) && strlen( $value ) > self::MAX_CELL_BYTES ) {
					$rows[ $i ][ $column ] = substr( $value, 0, self::MAX_CELL_BYTES ) . '...[cut]';
					++$cut;
				}
			}
		}
		return array(
			'rows'   => $rows,
			'masked' => $masked,
			'cut'    => $cut,
		);
	}

	/**
	 * Password hashes as WordPress stores them: phpass ($P$, $H$),
	 * WordPress 6.8+ bcrypt ($wp$2y$), plain bcrypt/argon2, and
	 * "timestamp:hash" activation keys.
	 */
	public static function looks_like_hash( string $value ): bool {
		return (bool) preg_match( '/^(\d{9,}:)?\$(P|H|wp|2[abxy]|argon2id?|generic)\$\S{20,}$/', $value );
	}
}
