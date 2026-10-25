<?php
/**
 * Ability: forms-update.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Forms;

use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateForm extends FormsAbility {

	const CAP = 'edit_form_single';

	public function slug(): string {
		return 'forms-update';
	}

	public function label(): string {
		return __( 'Update form', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes settings of one WPForms form, saved through WPForms itself. set is {path: value} with dotted paths: fields.<id>.label / description / placeholder / required (true/false) / default_value / size / choices (a list of {label, value}) / choices.<key>.label; settings.form_title / form_desc / submit_text / submit_text_processing / notification_enable / ajax_submit / antispam_v3; settings.notifications.<id>.email / subject / sender_name / sender_address / replyto / carboncopy / message / notification_name; settings.confirmations.<id>.type (message, page, redirect) / message / page (page ID) / redirect (URL). Read forms-get first for the ids. null removes an optional value. Adding, removing or retyping fields is done in the form builder. Returns the updated form and an undo_id for undo-change (the previous version is also kept as a revision).', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Form ID from forms-list.', 'wproadie' ),
				),
				'set' => array(
					'type'          => 'object',
					'minProperties' => 1,
					'maxProperties' => 50,
					'description'   => __( 'Dotted paths to values, e.g. {"settings.notifications.1.email": "hello@example.com", "fields.3.required": true, "settings.confirmations.1.message": "<p>Thanks! We reply within a day.</p>"}.', 'wproadie' ),
				),
			),
			'required'             => array( 'id', 'set' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$post = WPForms::post( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'trash' === $post->post_status ) {
			return WPForms::error( 'trashed', __( 'This form is in the trash; restore it in WPForms first.', 'wproadie' ) );
		}
		$data = WPForms::data( $post );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$before = $data;
		foreach ( (array) $input['set'] as $path => $value ) {
			$path = (string) $path;
			if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,120}$/', $path ) ) {
				return WPForms::error( 'bad_path', sprintf( '"%s" is not a valid path.', $path ) );
			}
			$data = WPForms::apply( $data, $path, $value );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}
		if ( $data === $before ) {
			return GetForm::describe( $post, $data ) + array(
				'changed' => false,
				'note'    => __( 'The form already had these values, so nothing was saved.', 'wproadie' ),
			);
		}

		$this->add_undo( Undo::post( (int) $post->ID ) );
		$saved = WPForms::save( $post, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		clean_post_cache( (int) $post->ID );
		$fresh = get_post( (int) $post->ID );
		$data  = WPForms::data( $fresh );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return GetForm::describe( $fresh, $data ) + array(
			'changed' => true,
			'paths'   => array_keys( (array) $input['set'] ),
		);
	}
}
