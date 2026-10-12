<?php
/**
 * Settings tools: get-options and update-options.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Log\Undo;

wproadie_test(
	'get-options reads allowed options and lists the rest separately',
	function () {
		$result = wproadie_ok( wproadie_call( 'get-options', array( 'names' => array( 'blogname', 'admin_email', 'auth_key', 'no_such_option_xyz' ) ) ) );
		wproadie_assert_same( get_option( 'blogname' ), $result['options']['blogname'] );
		wproadie_assert_same( get_option( 'admin_email' ), $result['options']['admin_email'] );
		wproadie_assert( ! isset( $result['options']['auth_key'] ), 'auth_key not returned' );
		wproadie_assert_same( array( 'auth_key', 'no_such_option_xyz' ), array_column( $result['unavailable'], 'name' ) );
		wproadie_assert( in_array( 'blogname', $result['writable'], true ), 'writable list returned' );
		wproadie_assert( ! in_array( 'admin_email', $result['writable'], true ), 'admin_email not writable' );

		$all = wproadie_ok( wproadie_call( 'get-options' ) );
		wproadie_assert( isset( $all['options']['blogdescription'] ), 'defaults to all readable' );
		wproadie_assert( ! isset( $all['options']['active_plugins'] ), 'never returns unlisted options' );
	}
);

wproadie_test(
	'update-options changes several options and undo restores them',
	function () {
		$old_name = get_option( 'blogname' );
		$old_desc = get_option( 'blogdescription' );
		$old_ppp  = get_option( 'posts_per_page' );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'update-options',
					array(
						'options' => array(
							'blogname'        => 'WPRoadie <b>Test</b>',
							'blogdescription' => 'Tagline',
							'posts_per_page'  => '7',
						),
					)
				)
			);
			wproadie_assert_same( 'WPRoadie &lt;b&gt;Test&lt;/b&gt;', get_option( 'blogname' ), 'sanitized like the Settings screen' );
			wproadie_assert_same( 7, (int) get_option( 'posts_per_page' ) );
			wproadie_assert_same( $old_name, $result['changed']['blogname']['old'] );
			wproadie_assert( ! empty( $result['undo_id'] ), 'undo_id returned' );

			Undo::revert( $result['undo_id'] );
			wproadie_assert_same( $old_name, get_option( 'blogname' ) );
			wproadie_assert_same( $old_desc, get_option( 'blogdescription' ) );
			wproadie_assert_same( (string) $old_ppp, (string) get_option( 'posts_per_page' ) );
		} finally {
			update_option( 'blogname', $old_name );
			update_option( 'blogdescription', $old_desc );
			update_option( 'posts_per_page', $old_ppp );
		}
	}
);

wproadie_test(
	'update-options refuses blocked options without changing anything',
	function () {
		$old_name = get_option( 'blogname' );
		$result   = wproadie_call(
			'update-options',
			array(
				'options' => array(
					'blogname'    => 'Should not be saved',
					'admin_email' => 'evil@example.com',
				),
			)
		);
		wproadie_assert_error( $result, 'wproadie_option_not_writable' );
		wproadie_assert_same( $old_name, get_option( 'blogname' ), 'nothing changed' );

		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'default_role' => 'administrator' ) ) ), 'wproadie_option_not_writable' );
		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'users_can_register' => 1 ) ) ), 'wproadie_option_not_writable' );
		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'siteurl' => 'https://evil.example' ) ) ), 'wproadie_option_not_writable' );
	}
);

wproadie_test(
	'update-options validates values',
	function () {
		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'timezone_string' => 'Mars/Olympus' ) ) ), 'wproadie_invalid_timezone' );
		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'WPLANG' => 'xx_XX' ) ) ), 'wproadie_language_not_installed' );
		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'show_on_front' => 'sideways' ) ) ), 'wproadie_invalid_value' );
		wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'permalink_structure' => '/blog/' ) ) ), 'wproadie_invalid_permalink' );

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Not a page',
				'post_status' => 'publish',
			)
		);
		$draft   = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Draft page',
				'post_status' => 'draft',
			)
		);
		try {
			wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'page_on_front' => $post_id ) ) ), 'wproadie_invalid_page' );
			wproadie_assert_error(
				wproadie_call(
					'update-options',
					array(
						'options' => array(
							'show_on_front' => 'page',
							'page_on_front' => $draft,
						),
					)
				),
				'wproadie_invalid_front_page'
			);
			if ( ! (int) get_option( 'page_on_front' ) ) {
				wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'show_on_front' => 'page' ) ) ), 'wproadie_invalid_front_page' );
			}
		} finally {
			wp_delete_post( $post_id, true );
			wp_delete_post( $draft, true );
		}
	}
);

wproadie_test(
	'update-options sets a static front page',
	function () {
		$saved = array(
			'show_on_front'  => get_option( 'show_on_front' ),
			'page_on_front'  => get_option( 'page_on_front' ),
			'page_for_posts' => get_option( 'page_for_posts' ),
		);
		$front = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Front',
				'post_status' => 'publish',
			)
		);
		$blog  = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Blog',
				'post_status' => 'publish',
			)
		);
		try {
			wproadie_assert_error(
				wproadie_call(
					'update-options',
					array(
						'options' => array(
							'page_on_front'  => $front,
							'page_for_posts' => $front,
						),
					)
				),
				'wproadie_invalid_page'
			);
			$result = wproadie_ok(
				wproadie_call(
					'update-options',
					array(
						'options' => array(
							'show_on_front'  => 'page',
							'page_on_front'  => $front,
							'page_for_posts' => $blog,
						),
					)
				)
			);
			wproadie_assert_same( 'page', get_option( 'show_on_front' ) );
			wproadie_assert_same( $front, (int) get_option( 'page_on_front' ) );
			Undo::revert( $result['undo_id'] );
			wproadie_assert_same( $saved['show_on_front'], get_option( 'show_on_front' ) );
			wproadie_assert_same( (int) $saved['page_on_front'], (int) get_option( 'page_on_front' ) );
		} finally {
			foreach ( $saved as $name => $value ) {
				update_option( $name, $value );
			}
			wp_delete_post( $front, true );
			wp_delete_post( $blog, true );
		}
	}
);

wproadie_test(
	'update-options timezone and permalinks',
	function () {
		$saved = array(
			'timezone_string'     => get_option( 'timezone_string' ),
			'gmt_offset'          => get_option( 'gmt_offset' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
		);
		try {
			wproadie_ok( wproadie_call( 'update-options', array( 'options' => array( 'timezone_string' => 'Europe/London' ) ) ) );
			wproadie_assert_same( 'Europe/London', get_option( 'timezone_string' ) );
			wproadie_assert_error( wproadie_call( 'update-options', array( 'options' => array( 'gmt_offset' => 2 ) ) ), 'wproadie_timezone_conflict' );
			wproadie_ok(
				wproadie_call(
					'update-options',
					array(
						'options' => array(
							'timezone_string' => '',
							'gmt_offset'      => 2,
						),
					)
				)
			);
			wproadie_assert_same( 2.0, (float) get_option( 'gmt_offset' ) );

			$result = wproadie_ok( wproadie_call( 'update-options', array( 'options' => array( 'permalink_structure' => '/wproadie-%postname%/' ) ) ) );
			wproadie_assert( $result['rewrite_flushed'], 'rewrite rules flushed' );
			$rules = (array) get_option( 'rewrite_rules' );
			$found = false;
			foreach ( array_keys( $rules ) as $regex ) {
				if ( 0 === strpos( $regex, 'wproadie-' ) ) {
					$found = true;
					break;
				}
			}
			wproadie_assert( $found, 'new structure in the rewrite rules' );
			Undo::revert( $result['undo_id'] );
			wproadie_assert_same( $saved['permalink_structure'], get_option( 'permalink_structure' ) );
		} finally {
			foreach ( $saved as $name => $value ) {
				update_option( $name, $value );
			}
			global $wp_rewrite;
			$wp_rewrite->init();
			flush_rewrite_rules( false );
		}
	}
);
