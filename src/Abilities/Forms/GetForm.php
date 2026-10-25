<?php
/**
 * Ability: forms-get.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Forms;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetForm extends FormsAbility {

	const CAP = 'view_form_single';

	public function slug(): string {
		return 'forms-get';
	}

	public function label(): string {
		return __( 'Get form', 'wproadie' );
	}

	public function description(): string {
		return __( 'Reads one WPForms form: its fields in order (id, type, label, required, placeholder, description, choices), notifications (who gets emailed, subject, sender, reply-to, message), confirmations (message, page or redirect), submit button text and the spam and AJAX toggles, plus the smart tags notifications may use. The ids are what forms-update paths refer to.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Form ID from forms-list.', 'wproadie' ),
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$post = WPForms::post( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$data = WPForms::data( $post );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::describe( $post, $data );
	}

	/** The form as the tools describe it. */
	public static function describe( \WP_Post $post, array $data ): array {
		$settings = (array) ( $data['settings'] ?? array() );
		$fields   = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = WPForms::describe_field( $field );
			}
		}
		$notifications = array();
		foreach ( (array) ( $settings['notifications'] ?? array() ) as $id => $notification ) {
			if ( ! is_array( $notification ) ) {
				continue;
			}
			$row = array( 'id' => (string) $id );
			foreach ( WPForms::NOTIFICATION_KEYS as $key ) {
				if ( isset( $notification[ $key ] ) && '' !== $notification[ $key ] ) {
					$row[ $key ] = (string) $notification[ $key ];
				}
			}
			if ( ! empty( $notification['conditional_logic'] ) ) {
				$row['conditional_logic'] = true;
			}
			$notifications[] = $row;
		}
		$confirmations = array();
		foreach ( (array) ( $settings['confirmations'] ?? array() ) as $id => $confirmation ) {
			if ( ! is_array( $confirmation ) ) {
				continue;
			}
			$row = array( 'id' => (string) $id );
			foreach ( WPForms::CONFIRMATION_KEYS as $key ) {
				if ( isset( $confirmation[ $key ] ) && '' !== $confirmation[ $key ] ) {
					$row[ $key ] = 'message_scroll' === $key ? (bool) $confirmation[ $key ] : $confirmation[ $key ];
				}
			}
			if ( isset( $row['page'] ) ) {
				$row['page_link'] = (string) get_permalink( (int) $row['page'] );
			}
			$confirmations[] = $row;
		}
		$toggles = array();
		foreach ( array( 'notification_enable', 'ajax_submit', 'antispam_v3', 'honeypot', 'store_spam_entries' ) as $key ) {
			$toggles[ $key ] = ! empty( $settings[ $key ] ) && '0' !== (string) $settings[ $key ];
		}
		$out = array(
			'id'            => (int) $post->ID,
			'title'         => $post->post_title,
			'status'        => $post->post_status,
			'description'   => (string) ( $settings['form_desc'] ?? $post->post_excerpt ),
			'shortcode'     => sprintf( '[wpforms id="%d"]', $post->ID ),
			'submit_text'   => (string) ( $settings['submit_text'] ?? 'Submit' ),
			'fields'        => $fields,
			'notifications' => $notifications,
			'confirmations' => $confirmations,
			'toggles'       => $toggles,
			'smart_tags'    => WPForms::SMART_TAGS,
			'modified'      => mysql2date( 'c', $post->post_modified_gmt, false ),
		);
		if ( ! empty( $data['meta']['template'] ) ) {
			$out['template'] = (string) $data['meta']['template'];
		}
		$count = WPForms::entry_count( (int) $post->ID );
		if ( null !== $count ) {
			$out['entries'] = $count;
		}
		return $out;
	}
}
