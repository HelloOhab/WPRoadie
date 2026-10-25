<?php
/**
 * Forms (WPForms) tools. WPForms is not installed here: the tools are
 * switched on with the wproadie_forms_available filter and work on the
 * wpforms post and its JSON exactly as WPForms stores them.
 *
 * @package WPRoadie
 */

use WPRoadie\Log\Undo;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

add_filter( 'wproadie_forms_available', '__return_true' );
if ( ! post_type_exists( 'wpforms' ) ) {
	register_post_type(
		'wpforms',
		array(
			'public'   => false,
			'supports' => array( 'title', 'author', 'revisions' ),
		)
	);
}
wproadie_test_enable_group( 'forms' );

/**
 * A form stored as WPForms stores it (slashed JSON in post_content).
 */
function wproadie_forms_fixture(): int {
	$id   = wp_insert_post(
		array(
			'post_type'   => 'wpforms',
			'post_status' => 'publish',
			'post_title'  => 'Contact "Us"',
		)
	);
	$data = array(
		'id'       => $id,
		'field_id' => 4,
		'fields'   => array(
			'0' => array(
				'id'       => '0',
				'type'     => 'name',
				'label'    => 'Name',
				'format'   => 'first-last',
				'required' => '1',
				'size'     => 'medium',
			),
			'1' => array(
				'id'       => '1',
				'type'     => 'email',
				'label'    => 'Email',
				'required' => '1',
			),
			'2' => array(
				'id'      => '2',
				'type'    => 'select',
				'label'   => 'Topic',
				'choices' => array(
					'1' => array(
						'label' => 'Donate',
						'value' => '',
					),
					'2' => array(
						'label' => 'Volunteer',
						'value' => '',
					),
				),
			),
			'3' => array(
				'id'          => '3',
				'type'        => 'textarea',
				'label'       => 'Message',
				'placeholder' => 'How can we help?',
			),
		),
		'settings' => array(
			'form_title'          => 'Contact "Us"',
			'form_desc'           => '',
			'submit_text'         => 'Send',
			'notification_enable' => '1',
			'notifications'       => array(
				'1' => array(
					'notification_name' => 'Default',
					'email'             => '{admin_email}',
					'subject'           => 'New Entry: Contact',
					'sender_name'       => 'NALB',
					'sender_address'    => '{admin_email}',
					'message'           => '{all_fields}',
				),
			),
			'confirmations'       => array(
				'1' => array(
					'type'           => 'message',
					'message'        => 'Thanks!',
					'message_scroll' => '1',
				),
			),
			'antispam_v3'         => '1',
		),
		'meta'     => array( 'template' => 'simple-contact-form-template' ),
	);
	wp_update_post(
		array(
			'ID'           => $id,
			'post_content' => wp_slash( wp_json_encode( $data ) ),
		)
	);
	return $id;
}

function wproadie_forms_data( int $id ): array {
	return json_decode( get_post( $id )->post_content, true );
}

wproadie_test(
	'forms-list and forms-get describe forms as WPForms stores them',
	function () {
		$id = wproadie_forms_fixture();
		try {
			$list = wproadie_ok( wproadie_call( 'forms-list' ) );
			$row  = $list['forms'][ array_search( $id, array_column( $list['forms'], 'id' ), true ) ];
			wproadie_assert_same( 'Contact "Us"', $row['title'] );
			wproadie_assert_same( 4, $row['fields'] );
			wproadie_assert_same( '[wpforms id="' . $id . '"]', $row['shortcode'] );
			wproadie_assert_same( array( '{admin_email}' ), $row['notifies'] );
			wproadie_assert( ! isset( $row['entries'] ), 'no entry count without Pro' );

			$form = wproadie_ok( wproadie_call( 'forms-get', array( 'id' => $id ) ) );
			wproadie_assert_same( 'Send', $form['submit_text'] );
			wproadie_assert_same(
				array(
					'id'       => '0',
					'type'     => 'name',
					'label'    => 'Name',
					'required' => true,
					'size'     => 'medium',
					'format'   => 'first-last',
				),
				$form['fields'][0]
			);
			wproadie_assert_same(
				array(
					array(
						'key'   => '1',
						'label' => 'Donate',
					),
					array(
						'key'   => '2',
						'label' => 'Volunteer',
					),
				),
				$form['fields'][2]['choices']
			);
			wproadie_assert_same( '{all_fields}', $form['notifications'][0]['message'] );
			wproadie_assert_same( 'message', $form['confirmations'][0]['type'] );
			wproadie_assert_same( true, $form['toggles']['antispam_v3'] );
			wproadie_assert_same( false, $form['toggles']['ajax_submit'] );
			wproadie_assert_same( 'simple-contact-form-template', $form['template'] );
			wproadie_assert_error( wproadie_call( 'forms-get', array( 'id' => 999999 ) ), 'wproadie_forms_not_found' );
			wproadie_assert( null === wp_get_ability( 'wproadie/forms-list-entries' ), 'entries tool is not offered without Pro' );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'forms-update changes allowed paths, stores toggles as WPForms does, refuses the rest, and undoes',
	function () {
		$id = wproadie_forms_fixture();
		try {
			$before = get_post( $id )->post_content;
			$result = wproadie_ok(
				wproadie_call(
					'forms-update',
					array(
						'id'  => $id,
						'set' => array(
							'fields.3.required'    => true,
							'fields.3.placeholder' => 'Tell us "more"',
							'fields.0.required'    => false,
							'fields.2.choices'     => array(
								array( 'label' => 'Donate' ),
								array(
									'label'   => 'Sponsor',
									'value'   => 'sponsor',
									'default' => true,
								),
							),
							'settings.submit_text' => 'Send message',
							'settings.ajax_submit' => true,
							'settings.notifications.1.email' => 'hello@noathlete.org',
							'settings.notifications.1.subject' => 'Website: {field_id="2"}',
							'settings.confirmations.1.message' => '<p>Thanks! <strong>We reply</strong> within a day.</p>',
							'settings.form_title'  => 'Contact NALB',
						),
					)
				)
			);
			wproadie_assert_same( true, $result['changed'] );
			$data = wproadie_forms_data( $id );
			wproadie_assert_same( '1', $data['fields']['3']['required'] );
			wproadie_assert( ! isset( $data['fields']['0']['required'] ), 'off toggles are removed, not stored as 0' );
			wproadie_assert_same( 'Tell us "more"', $data['fields']['3']['placeholder'] );
			wproadie_assert_same(
				array(
					'1' => array(
						'label' => 'Donate',
						'value' => '',
					),
					'2' => array(
						'label'   => 'Sponsor',
						'value'   => 'sponsor',
						'default' => '1',
					),
				),
				$data['fields']['2']['choices']
			);
			wproadie_assert_same( '1', $data['settings']['ajax_submit'] );
			wproadie_assert_same( 'hello@noathlete.org', $data['settings']['notifications']['1']['email'] );
			wproadie_assert_same( 'Website: {field_id="2"}', $data['settings']['notifications']['1']['subject'] );
			wproadie_assert_same( '<p>Thanks! <strong>We reply</strong> within a day.</p>', $data['settings']['confirmations']['1']['message'] );
			wproadie_assert_same( $id, $data['id'] );
			wproadie_assert_same( 'Contact NALB', get_post( $id )->post_title, 'the title follows form_title' );
			wproadie_assert_same( 'Contact NALB', $result['title'] );

			$same = wproadie_ok(
				wproadie_call(
					'forms-update',
					array(
						'id'  => $id,
						'set' => array( 'settings.submit_text' => 'Send message' ),
					)
				)
			);
			wproadie_assert_same( false, $same['changed'] );

			wproadie_ok( Undo::revert( (int) $result['undo_id'] ) );
			wproadie_assert_same( $before, get_post( $id )->post_content, 'undo restores the definition byte for byte' );
			wproadie_assert_same( 'Contact "Us"', get_post( $id )->post_title );

			foreach ( array(
				array( 'fields.9.label' => 'x' ),
				array( 'fields.3.type' => 'email' ),
				array( 'settings.honeypot_v9' => '1' ),
				array( 'settings.notifications.5.email' => 'x@y.test' ),
				array( 'settings.confirmations.1.type' => 'popup' ),
				array( 'settings.confirmations.1.page' => 999999 ),
				array( 'meta.template' => 'x' ),
				array( 'id' => 5 ),
			) as $bad ) {
				wproadie_assert_error(
					wproadie_call(
						'forms-update',
						array(
							'id'  => $id,
							'set' => $bad,
						)
					),
					null
				);
			}
			wproadie_assert_same( $before, get_post( $id )->post_content, 'refused calls write nothing' );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'teardown: forms tools back to detecting WPForms',
	function () {
		remove_filter( 'wproadie_forms_available', '__return_true' );
		wproadie_assert( ! in_array( 'wproadie/forms-list', ( new \WPRoadie\Abilities\Registry() )->enabled_names(), true ), 'Later tests see this site without WPForms.' );
	}
);
