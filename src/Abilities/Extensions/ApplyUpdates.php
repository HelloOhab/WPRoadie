<?php
/**
 * Ability: apply-updates (several plugin and theme updates in one call).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ApplyUpdates extends AbilityBase {

	public function slug(): string {
		return 'apply-updates';
	}

	public function label(): string {
		return __( 'Apply updates', 'wproadie' );
	}

	public function description(): string {
		return __( 'Updates several plugins and themes in one call: the ones named, or everything with an update waiting (all: true). Each one is backed up first, updated, and checked: if its main file no longer parses or the site stops answering, the previous version is put back automatically and the item is reported as rolled_back. Stops at the first failure unless stop_on_failure is false. Returns one result per item (updated, rolled_back, failed or skipped, with from and to versions), the site\'s health before and after, and an undo_id that reverts every update that went through. Use dry_run: true to see what would update. On live sites, a person approves the call in HQ first. WPRoadie and the MCP Adapter are never updated from here.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_MAINTAIN;
	}

	public function is_available(): bool {
		return Installer::filesystem_ready( WP_PLUGIN_DIR );
	}

	public function unavailable_reason(): string {
		return Installer::filesystem_reason();
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'plugins'         => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Plugin files to update, e.g. ["akismet/akismet.php"]. See list-plugins.', 'wproadie' ),
				),
				'themes'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Theme folder names to update, e.g. ["Divi"].', 'wproadie' ),
				),
				'all'             => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Update everything with an update waiting (plugins and themes). Ignores the two lists.', 'wproadie' ),
				),
				'dry_run'         => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only report what would update, with from and to versions.', 'wproadie' ),
				),
				'stop_on_failure' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Stop after the first item that fails or is rolled back (default). Set false to continue with the rest.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'update_plugins' ) && current_user_can( 'update_themes' );
	}

	protected function execute( array $input ) {
		$all     = ! empty( $input['all'] );
		$plugins = array_map( 'strval', (array) ( $input['plugins'] ?? array() ) );
		$themes  = array_map( 'strval', (array) ( $input['themes'] ?? array() ) );
		if ( ! $all && ! $plugins && ! $themes ) {
			return $this->error( 'nothing_requested', __( 'Name plugins or themes to update, or set all: true.', 'wproadie' ) );
		}
		if ( ! Installer::filesystem_ready( WP_PLUGIN_DIR ) ) {
			return Installer::filesystem_error();
		}

		Installer::load();
		$warnings = array_filter( array( Installer::refresh_updates( 'plugin' ), Installer::refresh_updates( 'theme' ) ) );
		$plan     = SafeUpdate::plan( $plugins, $themes, $all );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'dry_run' => true,
				'items'   => $plan['items'],
				'skipped' => $plan['skipped'],
				'warning' => $warnings ? implode( ' ', array_unique( $warnings ) ) : null,
			);
		}

		if ( ! $plan['items'] ) {
			return array(
				'results' => array(),
				'skipped' => $plan['skipped'],
				'updated' => 0,
				'warning' => $warnings ? implode( ' ', array_unique( $warnings ) ) : __( 'Nothing to update.', 'wproadie' ),
			);
		}

		$run = SafeUpdate::run(
			$plan['items'],
			! array_key_exists( 'stop_on_failure', $input ) || ! empty( $input['stop_on_failure'] ),
			function ( array $record ) {
				$this->add_undo( $record );
			}
		);

		$counts = array_count_values( array_column( $run['results'], 'status' ) );
		return array(
			'results'     => $run['results'],
			'skipped'     => $plan['skipped'],
			'updated'     => (int) ( $counts['updated'] ?? 0 ),
			'rolled_back' => (int) ( $counts['rolled_back'] ?? 0 ),
			'failed'      => (int) ( $counts['failed'] ?? 0 ) + (int) ( $counts['broken'] ?? 0 ),
			'stopped'     => $run['stopped'],
			'health'      => $run['health'],
			'warning'     => $warnings ? implode( ' ', array_unique( $warnings ) ) : null,
		);
	}
}
