<?php
/**
 * Copies an element of a Divi page right after itself.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class DuplicateModule extends DiviAbility {

	public function slug(): string {
		return 'divi-duplicate-module';
	}

	public function label(): string {
		return __( 'Duplicate Divi module', 'wproadie' );
	}

	public function description(): string {
		return __( 'Copies one element (module, column, row or section, with everything inside it) found by path, admin label or CSS ID, and inserts the exact copy right after the original. Then change the copy with divi-update-module (e.g. a second button or a new testimonial in the same style). A copied global element stays linked to its Divi Library item. Copies with a CSS ID get the same ID; change it to keep IDs unique. Saves a revision; returns the copy\'s path and an undo_id for undo-change.', 'wproadie' );
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

		$copy     = $entry['node']->copy();
		$siblings = $entry['parent']->children();
		$index    = (int) array_search( $entry['node'], $siblings, true );
		$doc->insert( $entry['parent'], $index + 1, $copy );

		$saved = $this->save( $loaded['post'], $doc );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$described = null;
		foreach ( $doc->all() as $other ) {
			if ( $other['node'] === $copy ) {
				$described = $doc->describe( $other );
				break;
			}
		}
		$result = array_merge( $saved, array( 'copy' => $described ) );
		if ( '' !== $doc->css_id( $copy ) ) {
			$result['css_id_note'] = __( 'The copy has the same CSS ID as the original; give one of them a new ID.', 'wproadie' );
		}
		return $result;
	}
}
