<?php
/**
 * Ability: forms-list-entries (WPForms Pro).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Forms;

use Throwable;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListEntries extends FormsAbility {

	const CAP = 'view_entries_form_single';

	public function slug(): string {
		return 'forms-list-entries';
	}

	public function label(): string {
		return __( 'List form entries', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the newest submissions of one WPForms form (WPForms Pro stores them; Lite only emails them): entry id, date, status, read and starred flags, and the submitted values by field label. Filter by status (spam, trash) or search for a value. Submissions are personal data: read what the task needs and do not copy them elsewhere.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function is_available(): bool {
		return WPForms::is_active() && ( null !== WPForms::entries() || (bool) apply_filters( 'wproadie_forms_entries_available', false ) );
	}

	public function unavailable_reason(): string {
		return WPForms::is_active() ? __( 'This WPForms does not store entries (Lite); they only arrive by email.', 'wproadie' ) : WPForms::unavailable_reason();
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'form_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Form ID from forms-list.', 'wproadie' ),
				),
				'number'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
				'offset'  => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
				'status'  => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'spam', 'trash' ),
					'description' => __( 'Default: real submissions (not spam or trash).', 'wproadie' ),
				),
				'search'  => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Only entries containing this text in a field value.', 'wproadie' ),
				),
			),
			'required'             => array( 'form_id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$post = WPForms::post( (int) $input['form_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$entries = WPForms::entries();
		if ( ! $entries || ! method_exists( $entries, 'get_entries' ) ) {
			return WPForms::error( 'no_entries', $this->unavailable_reason(), 404 );
		}
		$data   = WPForms::data( $post );
		$labels = array();
		if ( ! is_wp_error( $data ) ) {
			foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
				if ( is_array( $field ) ) {
					$labels[ (string) ( $field['id'] ?? '' ) ] = (string) ( $field['label'] ?? '' );
				}
			}
		}
		$args = array(
			'form_id' => (int) $post->ID,
			'number'  => (int) ( $input['number'] ?? 20 ),
			'offset'  => (int) ( $input['offset'] ?? 0 ),
			'orderby' => 'entry_id',
			'order'   => 'DESC',
		);
		if ( ! empty( $input['status'] ) ) {
			$args['status'] = 'any' === $input['status'] ? 'any' : (string) $input['status'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['value']         = (string) $input['search'];
			$args['value_compare'] = 'contains';
		}
		try {
			$rows  = (array) $entries->get_entries( $args );
			$total = (int) $entries->get_entries( array_diff_key( $args, array_flip( array( 'number', 'offset', 'orderby', 'order' ) ) ), true );
		} catch ( Throwable $e ) {
			return WPForms::error( 'failed', $e->getMessage(), 500 );
		}

		$out = array();
		foreach ( $rows as $entry ) {
			$fields = function_exists( 'wpforms_decode' ) ? wpforms_decode( (string) ( $entry->fields ?? '' ) ) : json_decode( (string) ( $entry->fields ?? '' ), true );
			$values = array();
			foreach ( (array) $fields as $id => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$label            = $labels[ (string) $id ] ?? (string) ( $field['name'] ?? $id );
				$values[ $label ] = is_scalar( $field['value'] ?? null ) ? (string) $field['value'] : ( $field['value'] ?? '' );
			}
			$out[] = array(
				'id'      => (int) ( $entry->entry_id ?? 0 ),
				'date'    => (string) ( $entry->date ?? '' ),
				'status'  => '' === (string) ( $entry->status ?? '' ) ? 'submitted' : (string) $entry->status,
				'read'    => ! empty( $entry->viewed ),
				'starred' => ! empty( $entry->starred ),
				'values'  => $values ? $values : new \stdClass(),
			);
		}
		return array(
			'form_id' => (int) $post->ID,
			'title'   => $post->post_title,
			'total'   => $total,
			'entries' => $out,
		);
	}
}
