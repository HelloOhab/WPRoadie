<?php
/**
 * Recent WPRoadie activity, so the AI can find what to undo.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\AuditLog;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ActivityLog extends AbilityBase {

	public function slug(): string {
		return 'activity-log';
	}

	public function label(): string {
		return __( 'Activity log', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists recent WPRoadie calls on this site, newest first: who ran which tool, when, whether it succeeded, and whether it can still be undone (pass its id to undo-change). Use it to review what was done, or to find a change to reverse.', 'wproadie' );
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'mine'          => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only show calls made by the connected user.', 'wproadie' ),
				),
				'undoable_only' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only show changes that can still be undone.', 'wproadie' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => __( 'How many entries to return.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$filters = array();
		if ( ! empty( $input['mine'] ) ) {
			$filters['user_id'] = get_current_user_id();
		}
		if ( ! empty( $input['undoable_only'] ) ) {
			$filters['undoable'] = true;
		}

		$page    = AuditLog::query( $filters, (int) ( $input['limit'] ?? 20 ) );
		$entries = array();
		foreach ( $page['rows'] as $row ) {
			$user      = get_userdata( (int) $row->user_id );
			$entries[] = array(
				'id'       => (int) $row->id,
				'time'     => gmdate( 'c', (int) strtotime( $row->created_at . ' UTC' ) ),
				'user'     => $user ? $user->user_login : null,
				'tool'     => $row->ability,
				'status'   => $row->status,
				'input'    => wp_html_excerpt( (string) $row->input, 300, '…' ),
				'error'    => 'error' === $row->status ? (string) $row->result : null,
				'undoable' => Undo::is_undoable( $row ),
				'undone'   => ! empty( $row->undone_at ),
			);
		}

		return array(
			'entries' => $entries,
			'total'   => $page['total'],
		);
	}
}
