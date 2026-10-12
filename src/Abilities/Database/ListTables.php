<?php
/**
 * Database tables with row estimates and sizes.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Database;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListTables extends AbilityBase {

	public function slug(): string {
		return 'list-tables';
	}

	public function label(): string {
		return __( 'List database tables', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the tables in the WordPress database with an estimated row count, size in bytes (data plus indexes) and storage engine where the server reports them, and whether each table is a WordPress core table or belongs to a plugin or something else. Also returns the table prefix to use in queries. Use it before writing SQL for run-query so you use real table names. Row counts are estimates on InnoDB; use SELECT COUNT(*) with run-query for an exact count.', 'wproadie' );
	}

	public function group(): string {
		return 'database';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	protected function annotations(): array {
		// Dangerous by group, but this tool itself only reads.
		return array(
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'openWorldHint' => false,
		);
	}

	protected function execute( array $input ) {
		global $wpdb;

		$core = array_values( $wpdb->tables( 'all', true ) );

		// SHOW TABLE STATUS gives sizes and estimates on MySQL and MariaDB.
		// Other drivers (e.g. the SQLite integration) may not support it or
		// may report zeros, so fall back to SHOW TABLES.
		$suppress = $wpdb->suppress_errors( true );
		$status   = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema information.
		$wpdb->suppress_errors( $suppress );

		$tables  = array();
		$details = is_array( $status ) && $status && isset( $status[0]['Name'] );
		if ( $details ) {
			foreach ( $status as $row ) {
				$data_length  = isset( $row['Data_length'] ) ? (int) $row['Data_length'] : null;
				$index_length = isset( $row['Index_length'] ) ? (int) $row['Index_length'] : null;
				$tables[]     = array(
					'name'          => (string) $row['Name'],
					'core'          => in_array( $row['Name'], $core, true ),
					'rows_estimate' => isset( $row['Rows'] ) ? (int) $row['Rows'] : null,
					'size_bytes'    => ( null === $data_length && null === $index_length ) ? null : (int) $data_length + (int) $index_length,
					'engine'        => isset( $row['Engine'] ) ? (string) $row['Engine'] : null,
					'collation'     => isset( $row['Collation'] ) ? (string) $row['Collation'] : null,
				);
			}
		} else {
			$names = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema information.
			foreach ( (array) $names as $name ) {
				$tables[] = array(
					'name'          => (string) $name,
					'core'          => in_array( $name, $core, true ),
					'rows_estimate' => null,
					'size_bytes'    => null,
					'engine'        => null,
					'collation'     => null,
				);
			}
		}

		$emulated = Sql::is_emulated();
		$notes    = array();
		if ( $emulated ) {
			$notes[] = __( 'This site runs on SQLite through a MySQL emulation layer; sizes and row estimates may be reported as zero.', 'wproadie' );
		}
		if ( ! $details ) {
			$notes[] = __( 'The database did not report table status, so sizes and row estimates are unavailable.', 'wproadie' );
		}

		$total = 0;
		foreach ( $tables as $table ) {
			$total += (int) $table['size_bytes'];
		}

		return array(
			'prefix'           => $wpdb->prefix,
			'base_prefix'      => $wpdb->base_prefix,
			'server'           => Sql::server_info(),
			'emulated'         => $emulated,
			'table_count'      => count( $tables ),
			'total_size_bytes' => $details ? $total : null,
			'tables'           => $tables,
			'notes'            => $notes,
		);
	}
}
