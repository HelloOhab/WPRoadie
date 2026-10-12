<?php
/**
 * Removes a module, row, column or section from a Divi page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class RemoveModule extends DiviAbility {

	public function slug(): string {
		return 'divi-remove-module';
	}

	public function label(): string {
		return __( 'Remove Divi module', 'wproadie' );
	}

	public function description(): string {
		return __( 'Removes one element (module, column, row or whole section, with everything inside it) from a Divi page, found by path, admin label or CSS ID from divi-get-page. Paths after it shift up by one. Removing a global element removes only its use on this page, not the Divi Library item. Saves a revision; returns what was removed and an undo_id for undo-change.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge( array( 'id' => self::id_property() ), self::module_properties() ),
			'required'             => array( 'id' ),
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
		$global = $this->check_not_global( $doc, $entry, true );
		if ( is_wp_error( $global ) ) {
			return $global;
		}

		$removed = $doc->describe( $entry );
		$inside  = 0;
		foreach ( $doc->all() as $other ) {
			if ( 0 === strpos( $other['path'], $entry['path'] . '.' ) ) {
				++$inside;
			}
		}
		$doc->remove( $entry['parent'], $entry['node'] );

		$saved = $this->save( $loaded['post'], $doc );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$result = array_merge( $saved, array( 'removed' => $removed ) );
		if ( $inside ) {
			$result['removed_inside'] = $inside;
		}
		return $result;
	}
}
