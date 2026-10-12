<?php
/**
 * Runs one data-changing SQL statement inside a transaction.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Database;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RunWriteQuery extends AbilityBase {

	public function slug(): string {
		return 'run-write-query';
	}

	public function label(): string {
		return __( 'Run a write SQL query', 'wproadie' );
	}

	public function description(): string {
		global $wpdb;
		return sprintf(
			/* translators: %s: database table prefix, e.g. "wp_". */
			__( 'Runs one INSERT, UPDATE, DELETE or REPLACE statement against the WordPress database (table prefix "%s"). UPDATE and DELETE need a WHERE clause; schema changes (CREATE, ALTER, DROP, TRUNCATE) are refused. Prefer the dedicated tools (posts, options, meta, users) whenever one fits: they validate input, clear caches and can be undone. THIS CHANGE CANNOT BE UNDONE with WPRoadie undo. Always run with dry_run: true first to see how many rows would change, then run for real with expected_rows set to that number: if the real count differs, the statement is rolled back and nothing changes. The statement runs in a transaction (START TRANSACTION, then COMMIT or ROLLBACK); rollback only works on transactional tables such as InnoDB, so dry_run and expected_rows are refused when a table involved uses another engine (e.g. MyISAM). Returns rows_affected, insert_id and whether the change was committed. Note that MySQL counts an UPDATE row only if a value actually changed, and REPLACE counts a replaced row twice. The object cache is flushed after a commit, since raw SQL bypasses it.', 'wproadie' ),
			$wpdb->prefix
		);
	}

	public function group(): string {
		return 'database_write';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sql'           => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'One INSERT, UPDATE, DELETE or REPLACE statement. UPDATE and DELETE must have a WHERE clause.', 'wproadie' ),
				),
				'expected_rows' => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many rows the statement should affect. If the actual number differs, the change is rolled back and nothing is changed. Get the number from a dry run.', 'wproadie' ),
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Run the statement, report how many rows it affected, then roll it back. Nothing is changed.', 'wproadie' ),
				),
			),
			'required'             => array( 'sql' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		global $wpdb;

		$sql      = trim( (string) ( $input['sql'] ?? '' ) );
		$dry_run  = ! empty( $input['dry_run'] );
		$expected = isset( $input['expected_rows'] ) ? (int) $input['expected_rows'] : null;

		$allowed = Guardrails::check_write_query( $sql );
		if ( true === $allowed ) {
			// Quote- and comment-aware second check, see Sql::lex().
			$allowed = Sql::check_write( $sql );
		}
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$sql = rtrim( $sql, "; \t\n\r" );

		// A dry run or row check only means something if the change can be
		// rolled back. MyISAM and friends ignore ROLLBACK.
		$needs_rollback = $dry_run || null !== $expected;
		if ( $needs_rollback ) {
			$blocking = Sql::non_transactional( Sql::mentioned_table_engines( $sql ) );
			if ( $blocking ) {
				return $this->error(
					'not_transactional',
					sprintf(
						/* translators: %s: list of table names. */
						__( 'These tables cannot roll back a change (not InnoDB), so dry_run and expected_rows cannot be honoured: %s. Nothing was run. Check the affected rows with a SELECT via run-query instead.', 'wproadie' ),
						implode( ', ', $blocking )
					),
					409
				);
			}
		}

		if ( ! Sql::control( 'START TRANSACTION' ) ) {
			return $this->error(
				'no_transaction',
				/* translators: %s: database error message. */
				sprintf( __( 'Could not start a transaction, so the statement was not run: %s', 'wproadie' ), Sql::last_error() ),
				500
			);
		}

		$committed = false;
		$finished  = false;
		$suppress  = $wpdb->suppress_errors( true );
		try {
			$result    = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- SQL from the AI by design; checked by Guardrails::check_write_query() and run in a transaction.
			$error     = Sql::last_error();
			$affected  = (int) $wpdb->rows_affected;
			$insert_id = (int) $wpdb->insert_id;
			$wpdb->suppress_errors( $suppress );

			if ( false === $result || '' !== $error ) {
				$rolled   = $this->rollback();
				$finished = true;
				return $this->error(
					'sql_error',
					sprintf(
						/* translators: 1: database error message, 2: rollback status sentence. */
						__( 'The database returned an error: %1$s %2$s', 'wproadie' ),
						'' !== $error ? $error : __( 'Unknown error.', 'wproadie' ),
						$this->rollback_sentence( $rolled )
					),
					400
				);
			}

			if ( null !== $expected && $affected !== $expected ) {
				$rolled   = $this->rollback();
				$finished = true;
				return $this->error(
					'unexpected_row_count',
					sprintf(
						/* translators: 1: rows affected, 2: rows expected, 3: rollback status sentence. */
						__( 'The statement affected %1$d rows, not the expected %2$d. %3$s', 'wproadie' ),
						$affected,
						$expected,
						$this->rollback_sentence( $rolled )
					),
					409
				);
			}

			if ( $dry_run ) {
				$rolled   = $this->rollback();
				$finished = true;
				if ( ! $rolled ) {
					return $this->error(
						'rollback_failed',
						sprintf(
							/* translators: %d: rows affected. */
							__( 'Dry run affected %d rows but the rollback was not complete: some changes may have been kept. Check the data with run-query.', 'wproadie' ),
							$affected
						),
						500
					);
				}
				return array(
					'dry_run'       => true,
					'rows_affected' => $affected,
					'insert_id'     => $insert_id ? $insert_id : null,
					'committed'     => false,
					'message'       => sprintf(
						/* translators: %d: rows affected. */
						__( 'Dry run: the statement would affect %d rows. It was rolled back; nothing changed. Run it again with dry_run false and expected_rows set to this number.', 'wproadie' ),
						$affected
					),
				);
			}

			$committed = Sql::control( 'COMMIT' );
			$finished  = true;
			if ( ! $committed ) {
				$commit_error = Sql::last_error();
				$this->rollback();
				return $this->error(
					'commit_failed',
					/* translators: %s: database error message. */
					sprintf( __( 'The commit failed, so the change was rolled back: %s', 'wproadie' ), $commit_error ),
					500
				);
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
			if ( ! $finished ) {
				// An exception escaped: never leave the transaction open.
				$this->rollback();
			}
		}

		// Raw SQL bypasses the object cache, which may now be stale.
		wp_cache_flush();

		return array(
			'dry_run'       => false,
			'rows_affected' => $affected,
			'insert_id'     => $insert_id ? $insert_id : null,
			'committed'     => true,
			'undoable'      => false,
			'message'       => sprintf(
				/* translators: %d: rows affected. */
				__( 'Committed. %d rows affected. The object cache was flushed. This change cannot be undone with WPRoadie undo.', 'wproadie' ),
				$affected
			),
		);
	}

	/**
	 * Rolls back and reports whether everything was undone. MySQL warns
	 * (1196) when non-transactional tables kept their changes.
	 */
	private function rollback(): bool {
		global $wpdb;
		if ( ! Sql::control( 'ROLLBACK' ) ) {
			return false;
		}
		if ( Sql::is_emulated() ) {
			return true;
		}
		$suppress = $wpdb->suppress_errors( true );
		$warnings = $wpdb->get_results( 'SHOW WARNINGS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the rollback.
		$wpdb->suppress_errors( $suppress );
		foreach ( (array) $warnings as $warning ) {
			if ( isset( $warning['Code'] ) && 1196 === (int) $warning['Code'] ) {
				return false;
			}
		}
		return true;
	}

	private function rollback_sentence( bool $rolled ): string {
		return $rolled
			? __( 'The transaction was rolled back; nothing changed.', 'wproadie' )
			: __( 'The rollback was NOT complete: some tables do not support transactions and may have kept changes. Check the data with run-query.', 'wproadie' );
	}
}
