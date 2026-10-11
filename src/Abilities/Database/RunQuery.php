<?php
/**
 * Runs one read-only SQL statement.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Database;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RunQuery extends AbilityBase {

	const DEFAULT_ROWS = 100;
	const MAX_ROWS     = 1000;

	/**
	 * Server-side time limit for the statement, in seconds, where the
	 * server supports one (MySQL 5.7.8+ for SELECT, MariaDB 10.1+).
	 */
	const TIMEOUT = 30;

	public function slug(): string {
		return 'run-query';
	}

	public function label(): string {
		return __( 'Run a read-only SQL query', 'wproadie' );
	}

	public function description(): string {
		global $wpdb;
		return sprintf(
			/* translators: %s: database table prefix, e.g. "wp_". */
			__( 'Runs one read-only SQL statement (SELECT, SHOW, DESCRIBE or EXPLAIN) against the WordPress database and returns the columns and rows. Use it to answer questions the other tools cannot, e.g. finding posts by a meta value or checking what a plugin stored. The table prefix on this site is "%1$s" (so posts are in %1$sposts); call list-tables first if unsure of table names. Only a single statement is allowed: no semicolons, no INTO OUTFILE, no locking reads, no SLEEP. On MySQL and MariaDB the query runs inside a READ ONLY transaction that is always rolled back; the result says whether that protection was in effect (read_only_transaction). Returns at most max_rows rows (truncated is true when there were more), but the database still computes the whole result, so add a LIMIT and avoid scanning huge tables. Secrets are masked as "[hidden]": the user_pass and user_activation_key columns, anything that looks like a password hash, and session_tokens/_application_passwords meta values. Very long values are cut. Not for changes: use run-write-query.', 'wproadie' ),
			$wpdb->prefix
		);
	}

	public function group(): string {
		return 'database';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	protected function annotations(): array {
		return array(
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'openWorldHint' => false,
		);
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sql'      => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'One SELECT, SHOW, DESCRIBE or EXPLAIN statement, with real table names including the prefix.', 'wproadie' ),
				),
				'max_rows' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_ROWS,
					'default'     => self::DEFAULT_ROWS,
					'description' => __( 'Most rows to return (1-1000).', 'wproadie' ),
				),
			),
			'required'             => array( 'sql' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		global $wpdb;

		$sql     = trim( (string) ( $input['sql'] ?? '' ) );
		$max     = max( 1, min( self::MAX_ROWS, (int) ( $input['max_rows'] ?? self::DEFAULT_ROWS ) ) );
		$allowed = Guardrails::check_read_query( $sql );
		if ( true === $allowed ) {
			// Quote- and comment-aware second check, see Sql::lex().
			$allowed = Sql::check_read( $sql );
		}
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// The guardrail allows a trailing semicolon; send the statement
		// without it.
		$sql = rtrim( $sql, "; \t\n\r" );

		$emulated = Sql::is_emulated();
		$restore  = $emulated ? null : $this->set_timeout();

		// A READ ONLY transaction makes the server refuse any write the
		// guardrail missed. If the server rejects it, still open a plain
		// transaction so the ROLLBACK below undoes whatever it can.
		$read_only = Sql::control( 'START TRANSACTION READ ONLY' );
		$in_trx    = $read_only || Sql::control( 'START TRANSACTION' );
		// Emulation layers accept READ ONLY without enforcing it.
		$enforced = $read_only && ! $emulated;

		$started  = microtime( true );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$rows    = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL from the AI by design; checked by Guardrails::check_read_query() and run in a read-only transaction.
			$error   = Sql::last_error();
			$columns = $this->columns( is_array( $rows ) ? $rows : array() );
		} finally {
			$wpdb->suppress_errors( $suppress );
			if ( $in_trx ) {
				Sql::control( 'ROLLBACK' );
			}
			if ( null !== $restore ) {
				Sql::control( $restore );
			}
		}
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( '' !== $error ) {
			return $this->error(
				'sql_error',
				/* translators: %s: database error message. */
				sprintf( __( 'The database returned an error: %s', 'wproadie' ), $error ),
				400
			);
		}

		$rows      = is_array( $rows ) ? $rows : array();
		$row_count = count( $rows );
		$truncated = $row_count > $max;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $max );
		}
		$masked = Sql::mask_rows( $rows );

		$notes = array();
		if ( ! $enforced ) {
			$notes[] = $emulated
				? __( 'This database is an emulation layer (e.g. SQLite) that does not enforce read-only transactions; the statement ran in a transaction that was rolled back.', 'wproadie' )
				: __( 'The server did not accept a read-only transaction; only the statement check protected this query.', 'wproadie' );
		}
		if ( $masked['masked'] ) {
			$notes[] = __( 'Some values were masked as [hidden] because they hold passwords, password hashes or login sessions.', 'wproadie' );
		}
		if ( $masked['cut'] ) {
			$notes[] = __( 'Some long values were cut; select a SUBSTRING to read a specific part.', 'wproadie' );
		}

		return array(
			'columns'               => $columns,
			'rows'                  => $masked['rows'],
			'row_count'             => $row_count,
			'returned'              => count( $masked['rows'] ),
			'truncated'             => $truncated,
			'masked_values'         => $masked['masked'],
			'read_only_transaction' => $enforced,
			'rolled_back'           => $in_trx,
			'duration_ms'           => $duration,
			'notes'                 => $notes,
		);
	}

	/**
	 * Column names, from the result set where possible so a query with
	 * no rows still reports them.
	 *
	 * @param array[] $rows Result rows.
	 * @return string[]
	 */
	private function columns( array $rows ): array {
		global $wpdb;
		if ( $rows ) {
			return array_map( 'strval', array_keys( (array) reset( $rows ) ) );
		}
		$names = null;
		try {
			$names = $wpdb->get_col_info( 'name' );
		} catch ( \Throwable $e ) {
			$names = null;
		}
		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Sets a server-side time limit for the next statement where the
	 * server supports one.
	 *
	 * @return string|null Statement that restores the previous limit.
	 */
	private function set_timeout(): ?string {
		global $wpdb;
		$seconds  = (int) apply_filters( 'wproadie_db_query_timeout', self::TIMEOUT );
		$mariadb  = Sql::is_mariadb();
		$variable = $mariadb ? 'max_statement_time' : 'max_execution_time';
		if ( $seconds <= 0 ) {
			return null;
		}

		$suppress = $wpdb->suppress_errors( true );
		$previous = $wpdb->get_var( "SELECT @@SESSION.{$variable}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed variable name.
		$wpdb->suppress_errors( $suppress );
		if ( null === $previous || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		// MariaDB counts seconds, MySQL milliseconds.
		$value = $mariadb ? $seconds : $seconds * 1000;
		if ( ! Sql::control( "SET SESSION {$variable} = " . (int) $value ) ) {
			return null;
		}
		$restore = $mariadb ? (string) (float) $previous : (string) (int) $previous;
		return "SET SESSION {$variable} = {$restore}";
	}
}
