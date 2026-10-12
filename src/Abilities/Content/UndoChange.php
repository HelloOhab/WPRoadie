<?php
/**
 * Reverses an earlier WPRoadie change by its undo ID.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Registry;
use WPRoadie\Log\AuditLog;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UndoChange extends AbilityBase {

	public function slug(): string {
		return 'undo-change';
	}

	public function label(): string {
		return __( 'Undo a change', 'wproadie' );
	}

	public function description(): string {
		return __( 'Reverses an earlier WPRoadie change. Pass the undo_id that the original tool returned (or a log ID from activity-log). Restores posts, meta, terms, options, menus, widgets, files, user roles, plugin activation and theme switches to how they were before that call. Raw SQL writes, PHP snippets and plugin/theme installs or updates cannot be undone. If something changed the same item since, that later change is overwritten.', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'undo_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The undo_id returned by the tool whose change you want to reverse.', 'wproadie' ),
				),
			),
			'required'             => array( 'undo_id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$log_id = (int) $input['undo_id'];
		$row    = AuditLog::get( $log_id );
		if ( ! $row ) {
			return $this->error( 'undo_not_found', __( 'No change with that undo ID.', 'wproadie' ), 404 );
		}

		// Reverting a change is as risky as making it: a file write done on
		// staging cannot be reverted by an AI on a site now in production.
		$original = ( new Registry() )->find( (string) $row->ability );
		if ( $original && ! Mode::allows( $original->risk() ) ) {
			return $this->error(
				'undo_not_allowed',
				sprintf(
					/* translators: %s: mode name. */
					__( 'That change cannot be undone by an AI in %s mode. Undo it from the WPRoadie activity log in wp-admin.', 'wproadie' ),
					Mode::label( Mode::current() )
				),
				403
			);
		}

		// This call is logged as undo-change; no second log entry.
		return Undo::revert( $log_id, false );
	}
}
