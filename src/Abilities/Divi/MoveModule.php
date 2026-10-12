<?php
/**
 * Moves an element of a Divi page to another place on the same page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class MoveModule extends DiviAbility {

	public function slug(): string {
		return 'divi-move-module';
	}

	public function label(): string {
		return __( 'Move Divi module', 'wproadie' );
	}

	public function description(): string {
		return __( 'Moves one element (module, column, row or section, found by path, admin label or CSS ID) to another place on the same Divi page: container is the path of the new parent as it is now, before the move ("" for the page itself, for sections), index the position among its children, 0 = first (default: last). To reorder within the same column, pass that column as container. The element keeps all its settings and bytes. Saves a revision; returns the new path and an undo_id for undo-change.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array( 'id' => self::id_property() ),
				self::module_properties(),
				array(
					'container' => array(
						'type'        => 'string',
						'pattern'     => '^([0-9]+(\.[0-9]+)*)?$',
						'description' => __( 'Path of the new parent (as it is before the move); "" for the page.', 'wproadie' ),
					),
					'index'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __( 'Position among the new parent\'s children after the move, 0 = first. Default: last.', 'wproadie' ),
					),
				)
			),
			'required'             => array( 'id', 'container' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = Divi::load( (int) $input['id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$doc   = $loaded['doc'];
		$entry = $doc->find( $input );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$target = $doc->find_container( (string) $input['container'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		foreach ( array( $this->check_not_global( $doc, $entry, true ), $this->check_not_global( $doc, $target ) ) as $check ) {
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		if ( Document::contains( $entry['node'], $target['node'] ) ) {
			return Divi::error( 'bad_move', __( 'An element cannot be moved into itself.', 'wproadie' ) );
		}
		$placement = $doc->check_placement( $target['node'], $entry['node'] );
		if ( is_wp_error( $placement ) ) {
			return $placement;
		}

		$from = $entry['path'];
		$doc->remove( $entry['parent'], $entry['node'] );
		$count = count( $target['node']->children() );
		$index = isset( $input['index'] ) ? min( (int) $input['index'], $count ) : $count;
		$doc->insert( $target['node'], $index, $entry['node'] );

		$saved = $this->save( $loaded['post'], $doc );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$moved = null;
		foreach ( $doc->all() as $other ) {
			if ( $other['node'] === $entry['node'] ) {
				$moved = $doc->describe( $other );
				break;
			}
		}
		return array_merge(
			$saved,
			array(
				'from'   => $from,
				'module' => $moved,
			)
		);
	}
}
