<?php
/**
 * Elementor tools.
 *
 * With Elementor installed and active, these run against the real plugin
 * (its document, widget and CSS APIs). Without it, the tools are switched
 * on with the wproadie_elementor_active filter and registered for this
 * run, so the same tests exercise the storage, tree and undo logic on the
 * fixture in fixtures/elementor-page.json; the few checks that need
 * Elementor's own API are skipped.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride -- Test file; the helper fakes the init actions.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests read fixtures and write throwaway CSS files directly.

use WPRoadie\Abilities\Elementor\ElementorHelper;
use WPRoadie\Abilities\Elementor\Kit;
use WPRoadie\Abilities\Elementor\Tree;
use WPRoadie\Abilities\Registry;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;

$GLOBALS['wproadie_el_real'] = ElementorHelper::is_active() && ElementorHelper::is_loaded();

if ( ! $GLOBALS['wproadie_el_real'] ) {
	// No Elementor: offer the tools anyway and register them for this run.
	add_filter( 'wproadie_elementor_active', '__return_true' );
	global $wp_current_filter;
	$category = Registry::category( 'elementor' );
	if ( ! wp_has_ability_category( $category ) ) {
		$wp_current_filter[] = 'wp_abilities_api_categories_init';
		wp_register_ability_category(
			$category,
			array(
				'label'       => 'Elementor',
				'description' => 'Elementor',
			)
		);
		array_pop( $wp_current_filter );
	}
	foreach ( ( new Registry() )->enabled() as $ability ) {
		if ( 'elementor' === $ability->group() && ! wp_has_ability( $ability->name() ) ) {
			$wp_current_filter[] = 'wp_abilities_api_init';
			$ability->register( $category );
			array_pop( $wp_current_filter );
		}
	}
	WP_CLI::log( '  (Elementor is not active: running the Elementor tests on fixtures only)' );
} else {
	WP_CLI::log( '  (running against Elementor ' . ElementorHelper::version() . ')' );
}

/**
 * The fixture page tree.
 */
function wproadie_el_fixture(): array {
	return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/elementor-page.json' ), true );
}

/**
 * Creates an Elementor page with the given tree, stored as Elementor does.
 *
 * @param array|null $tree Elements; the fixture by default.
 * @param array      $args Post fields.
 */
function wproadie_el_page( ?array $tree = null, array $args = array() ): int {
	$id = (int) wp_insert_post(
		array_merge(
			array(
				'post_title'   => 'Elementor fixture',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Fixture plain text',
			),
			$args
		)
	);
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', 'wp-page' );
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( null === $tree ? wproadie_el_fixture() : $tree ) ) );
	return $id;
}

/**
 * Stored raw _elementor_data.
 */
function wproadie_el_raw( int $id ): string {
	return (string) get_post_meta( $id, '_elementor_data', true );
}

/**
 * Stored decoded tree.
 */
function wproadie_el_tree( int $id ): array {
	return json_decode( wproadie_el_raw( $id ), true );
}

/**
 * Runs $callback as a user without unfiltered_html.
 */
function wproadie_el_without_unfiltered_html( callable $callback ): void {
	$filter = static function ( $caps, $cap ) {
		return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $filter, 10, 2 );
	try {
		$callback();
	} finally {
		remove_filter( 'map_meta_cap', $filter, 10 );
	}
}

/**
 * Makes sure the elementor_library post type exists for template tests.
 *
 * @return bool Whether it was registered here (and must be removed).
 */
function wproadie_el_library_type(): bool {
	if ( post_type_exists( 'elementor_library' ) ) {
		return false;
	}
	register_post_type(
		'elementor_library',
		array(
			'public'  => false,
			'show_ui' => true,
		)
	);
	return true;
}

/**
 * Element ids in a tree, in order.
 *
 * @return string[]
 */
function wproadie_el_ids( array $tree ): array {
	return array_keys( Tree::ids( $tree ) );
}

wproadie_test(
	'elementor tools are offered only while Elementor is active',
	function () {
		$off = static function () {
			return false;
		};
		add_filter( 'wproadie_elementor_active', $off, 99 );
		try {
			$registry = new Registry();
			$found    = 0;
			foreach ( $registry->all() as $ability ) {
				if ( 'elementor' !== $ability->group() ) {
					continue;
				}
				++$found;
				wproadie_assert( 0 === strpos( $ability->slug(), 'elementor-' ), $ability->slug() );
				wproadie_assert_same( false, $ability->is_available(), $ability->slug() );
				wproadie_assert( false !== strpos( $ability->unavailable_reason(), 'Elementor' ), $ability->unavailable_reason() );
			}
			wproadie_assert_same( 15, $found );
			wproadie_assert( ! preg_grep( '#^wproadie/elementor-#', $registry->enabled_names() ), 'Unavailable tools must not be exposed.' );
		} finally {
			remove_filter( 'wproadie_elementor_active', $off, 99 );
		}
		wproadie_assert( (bool) preg_grep( '#^wproadie/elementor-#', ( new Registry() )->enabled_names() ), 'Tools are exposed while Elementor is active.' );
		wproadie_assert_same( true, Settings::GROUPS['elementor']['default'] );
		wproadie_assert_same( 'Elementor', Settings::group_label( 'elementor' ) );
	}
);

wproadie_test(
	'elementor reads work read-only, edits in production, kit changes only in staging',
	function () {
		$expect = array(
			Mode::READ_ONLY  => array( 'elementor-list-pages', 'elementor-get-page', 'elementor-get-element', 'elementor-get-widget-schema', 'elementor-list-templates', 'elementor-get-kit' ),
			Mode::PRODUCTION => array( 'elementor-list-pages', 'elementor-get-page', 'elementor-get-element', 'elementor-get-widget-schema', 'elementor-update-element', 'elementor-add-element', 'elementor-remove-element', 'elementor-move-element', 'elementor-duplicate-element', 'elementor-replace-text', 'elementor-list-templates', 'elementor-insert-template', 'elementor-get-kit', 'elementor-regenerate-css' ),
		);
		try {
			foreach ( $expect as $mode => $slugs ) {
				Settings::set( 'mode', $mode );
				$names = array_values( preg_grep( '#^wproadie/elementor-#', ( new Registry() )->enabled_names() ) );
				sort( $names );
				$want = array_map(
					static function ( $slug ) {
						return 'wproadie/' . $slug;
					},
					$slugs
				);
				sort( $want );
				wproadie_assert_same( $want, $names, $mode );
			}
			// A long-running session re-checks the mode on every call.
			Settings::set( 'mode', Mode::READ_ONLY );
			wproadie_assert_same(
				false,
				wp_get_ability( 'wproadie/elementor-update-element' )->check_permissions(
					array(
						'post_id'  => 1,
						'id'       => 'x',
						'settings' => array( 'a' => 1 ),
					)
				)
			);
			Settings::set( 'mode', Mode::PRODUCTION );
			wproadie_assert_same( false, wp_get_ability( 'wproadie/elementor-update-kit' )->check_permissions( array( 'settings' => array( 'a' => 1 ) ) ) );
		} finally {
			Settings::set( 'mode', '' );
		}
	}
);

wproadie_test(
	'elementor-list-pages lists Elementor pages only',
	function () {
		$el    = wproadie_el_page( null, array( 'post_title' => 'Zebracorn Elementor page' ) );
		$plain = (int) wp_insert_post(
			array(
				'post_title'  => 'Zebracorn plain page',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		try {
			$result = wproadie_ok( wproadie_call( 'elementor-list-pages', array( 'search' => 'Zebracorn' ) ) );
			wproadie_assert_same( 1, $result['total'] );
			$item = $result['items'][0];
			wproadie_assert_same( $el, $item['id'] );
			wproadie_assert_same( 'page', $item['type'] );
			wproadie_assert_same( Tree::count( wproadie_el_fixture() ), $item['element_count'] );
			wproadie_assert( false !== strpos( $item['edit_url'], 'action=elementor' ), $item['edit_url'] );
			$posts = wproadie_ok(
				wproadie_call(
					'elementor-list-pages',
					array(
						'search'    => 'Zebracorn',
						'post_type' => 'post',
					)
				)
			);
			wproadie_assert_same( 0, $posts['total'] );
			wproadie_assert_error( wproadie_call( 'elementor-list-pages', array( 'post_type' => 'revision' ) ), 'wproadie_invalid_post_type' );
		} finally {
			wp_delete_post( $el, true );
			wp_delete_post( $plain, true );
		}
	}
);

wproadie_test(
	'elementor-get-page returns a compact outline with summaries, depth, limit and root_id',
	function () {
		$id = wproadie_el_page();
		update_post_meta( $id, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
		try {
			$page = wproadie_ok( wproadie_call( 'elementor-get-page', array( 'post_id' => $id ) ) );
			wproadie_assert_same( $id, $page['id'] );
			wproadie_assert_same( 18, $page['element_count'] );
			wproadie_assert_same( false, $page['truncated'] );
			wproadie_assert_same( 'yes', $page['page_settings']['hide_title'] );
			wproadie_assert( ! isset( $page['outline'][0]['settings'] ), 'The outline must not carry settings.' );

			$top = $page['outline'][0];
			wproadie_assert_same( 'a1b2c3d', $top['id'] );
			wproadie_assert_same( 'container', $top['elType'] );
			wproadie_assert_same( 'column, boxed, 6 children', $top['summary'] );

			$kids = array_column( $top['children'], null, 'id' );
			wproadie_assert_same( 'heading', $kids['4f5e6d7']['widgetType'] );
			wproadie_assert_same( 'Welcome to Café Rhythm — "best" coffee in town', $kids['4f5e6d7']['summary'] );
			wproadie_assert_same( '0.0', $kids['4f5e6d7']['path'] );
			wproadie_assert_same( 'Call 555-0100 → tel:5550100', $kids['b0a1c2e']['summary'] );
			wproadie_assert_same( 'https://example.com/wp-content/uploads/2024/05/shop-front.jpg alt: Our shop front #42', $kids['c3d4e5f']['summary'] );
			wproadie_assert( 0 === strpos( $kids['9e8d7c6']['summary'], 'Call us at 555-0100 or visit' ), $kids['9e8d7c6']['summary'] );
			wproadie_assert( '…' === mb_substr( $kids['9e8d7c6']['summary'], -1 ), 'Long text is cut.' );
			wproadie_assert_same( 'Open 7am – 5pm | Phone 555-0100', $kids['d6e7f80']['children'][0]['summary'] );
			wproadie_assert_same( 'Contact (2 fields)', $kids['d6e7f80']['children'][1]['summary'] );
			wproadie_assert_same( '0.4.1', $kids['d6e7f80']['children'][1]['path'] );
			wproadie_assert_same( 'Atomic heading', $page['outline'][2]['children'][0]['summary'] );
			wproadie_assert_same( '50%, 1 child', $page['outline'][1]['children'][0]['summary'] );

			$shallow = wproadie_ok(
				wproadie_call(
					'elementor-get-page',
					array(
						'post_id' => $id,
						'depth'   => 0,
					)
				)
			);
			wproadie_assert_same( 3, count( $shallow['outline'] ) );
			wproadie_assert_same( 6, $shallow['outline'][0]['children_count'] );
			wproadie_assert( ! isset( $shallow['outline'][0]['children'] ), 'depth 0 shows no children' );

			$limited = wproadie_ok(
				wproadie_call(
					'elementor-get-page',
					array(
						'post_id' => $id,
						'limit'   => 3,
					)
				)
			);
			wproadie_assert_same( true, $limited['truncated'] );

			$part = wproadie_ok(
				wproadie_call(
					'elementor-get-page',
					array(
						'post_id' => $id,
						'root_id' => 'c01a002',
					)
				)
			);
			wproadie_assert_same( 1, count( $part['outline'] ) );
			wproadie_assert_same( '1.1', $part['outline'][0]['path'] );
			wproadie_assert_same( '1.1.0.0.0', $part['outline'][0]['children'][0]['children'][0]['children'][0]['path'] );
			wproadie_assert_same( '7e57ed2', $part['outline'][0]['children'][0]['children'][0]['children'][0]['id'] );
			wproadie_assert_error(
				wproadie_call(
					'elementor-get-page',
					array(
						'post_id' => $id,
						'root_id' => 'nope123',
					)
				),
				'wproadie_element_not_found'
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-get-element returns full settings, unknown keys and children',
	function () {
		$id = wproadie_el_page();
		try {
			$container = wproadie_ok(
				wproadie_call(
					'elementor-get-element',
					array(
						'post_id' => $id,
						'id'      => 'a1b2c3d',
					)
				)
			);
			wproadie_assert_same( null, $container['parent_id'] );
			wproadie_assert_same( '#F4F1EA', $container['settings']['background_color'] );
			wproadie_assert_same(
				array(
					'kept' => true,
					'list' => array( 1, 2.5, 'three' ),
				),
				$container['other']['customKey']
			);
			wproadie_assert_same( 6, count( $container['children'] ) );
			wproadie_assert_same( 2, $container['children'][4]['children_count'] );

			$button = wproadie_ok(
				wproadie_call(
					'elementor-get-element',
					array(
						'post_id'          => $id,
						'id'               => 'b0a1c2e',
						'include_controls' => true,
					)
				)
			);
			wproadie_assert_same( wproadie_el_fixture()[0]['elements'][2]['settings'], $button['settings'] );
			wproadie_assert_same( 'a1b2c3d', $button['parent_id'] );
			wproadie_assert_same( '0.2', $button['path'] );
			if ( $GLOBALS['wproadie_el_real'] ) {
				$names = array_column( $button['controls'], 'name' );
				wproadie_assert( in_array( 'text', $names, true ) && in_array( 'link', $names, true ), 'Button controls listed.' );
				wproadie_assert( ! in_array( 'align_mobile', $names, true ), 'Responsive copies are left out.' );
			} else {
				wproadie_assert( isset( $button['controls_note'] ), 'No controls without Elementor.' );
			}
			wproadie_assert_error(
				wproadie_call(
					'elementor-get-element',
					array(
						'post_id' => $id,
						'id'      => 'zzz9999',
					)
				),
				'wproadie_element_not_found'
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor tools refuse posts not built with Elementor, the kit, and missing posts',
	function () {
		$plain = (int) wp_insert_post(
			array(
				'post_title'  => 'Plain',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		// Elementor refuses to delete a real kit without confirmation, so
		// use the site's own kit when there is one.
		$kit      = ElementorHelper::active_kit_id();
		$fake_kit = ! $kit || ! get_post( $kit );
		if ( $fake_kit ) {
			$kit = (int) wp_insert_post(
				array(
					'post_title'  => 'Fake kit',
					'post_type'   => 'elementor_library',
					'post_status' => 'publish',
				)
			);
			update_post_meta( $kit, '_elementor_template_type', 'kit' );
			update_post_meta( $kit, '_elementor_edit_mode', 'builder' );
		}
		try {
			$error = wproadie_call( 'elementor-get-page', array( 'post_id' => $plain ) );
			wproadie_assert_error( $error, 'wproadie_not_elementor' );
			wproadie_assert( false !== strpos( $error->get_error_message(), 'update-post' ), 'Points to the right tool.' );
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $plain,
						'id'       => 'a1b2c3d',
						'settings' => array( 'title' => 'x' ),
					)
				),
				'wproadie_not_elementor'
			);
			wproadie_assert_error( wproadie_call( 'elementor-get-page', array( 'post_id' => $kit ) ), 'wproadie_is_kit' );
			wproadie_assert_error( wproadie_call( 'elementor-get-page', array( 'post_id' => 99999999 ) ), 'wproadie_not_found' );
			wproadie_assert_same( '', get_post_meta( $plain, '_elementor_data', true ), 'Nothing written to the plain page.' );
		} finally {
			wp_delete_post( $plain, true );
			if ( $fake_kit ) {
				wp_delete_post( $kit, true );
			}
		}
	}
);

wproadie_test(
	'elementor tools need edit_post on the page',
	function () {
		$id     = wproadie_el_page();
		$filter = static function ( $caps, $cap, $user_id, $args ) use ( $id ) {
			if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $id ) {
				return array( 'do_not_allow' );
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $filter, 10, 4 );
		try {
			wproadie_assert_error( wproadie_call( 'elementor-get-page', array( 'post_id' => $id ) ), 'ability_invalid_permissions' );
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '4f5e6d7',
						'settings' => array( 'title' => 'Hacked' ),
					)
				),
				'ability_invalid_permissions'
			);
			$replace = wproadie_ok(
				wproadie_call(
					'elementor-replace-text',
					array(
						'search'    => 'Café Rhythm',
						'replace'   => 'Hacked',
						'all_pages' => true,
						'dry_run'   => true,
					)
				)
			);
			wproadie_assert( ! in_array( $id, array_column( $replace['posts'], 'post_id' ), true ), 'Pages the user cannot edit are skipped.' );
			wproadie_assert_same( wp_json_encode( wproadie_el_fixture() ), wproadie_el_raw( $id ) );
			remove_filter( 'map_meta_cap', $filter, 10 );

			if ( $GLOBALS['wproadie_el_real'] ) {
				// Elementor's Role Manager: "No access to editor" for the role.
				update_option( 'elementor_exclude_user_roles', wp_get_current_user()->roles );
				try {
					wproadie_assert_error( wproadie_call( 'elementor-get-page', array( 'post_id' => $id ) ), 'ability_invalid_permissions' );
				} finally {
					delete_option( 'elementor_exclude_user_roles' );
				}
				wproadie_ok( wproadie_call( 'elementor-get-page', array( 'post_id' => $id ) ) );
			}
		} finally {
			remove_filter( 'map_meta_cap', $filter, 10 );
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor round trip: a no-op write keeps the stored data identical',
	function () {
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$tree   = ElementorHelper::read_tree( $id );
			wproadie_assert_same( true, ElementorHelper::write_tree( $id, $tree ) );
			wproadie_assert_same( $before, wproadie_el_raw( $id ), 'Byte-identical after decode and re-encode.' );
			wproadie_assert_same( wproadie_el_fixture(), wproadie_el_tree( $id ) );

			// Changing one widget leaves every other element exactly as it was,
			// including the unregistered form widget, unknown keys and atomic data.
			wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '4f5e6d7',
						'settings' => array( 'title' => 'Changed' ),
					)
				)
			);
			$expected                                        = wproadie_el_fixture();
			$expected[0]['elements'][0]['settings']['title'] = 'Changed';
			wproadie_assert_same( $expected, wproadie_el_tree( $id ) );
			wproadie_assert_same( wp_json_encode( $expected ), wproadie_el_raw( $id ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-update-element merges settings and undo restores them exactly',
	function () {
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$result = wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => 'b0a1c2e',
						'settings' => array(
							'text'        => 'Ring us: 555-0199',
							'link'        => array( 'url' => 'tel:5550199' ),
							'__dynamic__' => null,
							'size'        => 'lg',
						),
					)
				)
			);
			wproadie_assert_same( true, $result['changed'] );
			wproadie_assert_same( 'Ring us: 555-0199 → tel:5550199', $result['summary'] );
			wproadie_assert( ! empty( $result['undo_id'] ), 'undo_id missing' );
			$button = wproadie_el_tree( $id )[0]['elements'][2]['settings'];
			wproadie_assert_same(
				array(
					'url'               => 'tel:5550199',
					'is_external'       => '',
					'nofollow'          => '',
					'custom_attributes' => '',
				),
				$button['link'],
				'Objects merge.'
			);
			wproadie_assert( ! isset( $button['__dynamic__'] ), 'null removes a key.' );
			wproadie_assert_same( 'lg', $button['size'] );
			wproadie_assert_same(
				array(
					'value'   => 'fas fa-phone',
					'library' => 'fa-solid',
				),
				$button['selected_icon']
			);

			// Lists are replaced whole.
			wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => 'e1f2a3b',
						'settings' => array(
							'icon_list' => array(
								array(
									'text' => 'Only item',
									'_id'  => 'abc1234',
								),
							),
						),
					)
				)
			);
			wproadie_assert_same(
				array(
					array(
						'text' => 'Only item',
						'_id'  => 'abc1234',
					),
				),
				wproadie_el_tree( $id )[0]['elements'][4]['elements'][0]['settings']['icon_list']
			);

			$second = wproadie_el_latest_undo_id();
			wproadie_ok( Undo::revert( $second ) );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( $before, wproadie_el_raw( $id ), 'Undo restores the exact bytes.' );

			$same = wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '4f5e6d7',
						'settings' => array( 'header_size' => 'h1' ),
					)
				)
			);
			wproadie_assert_same( false, $same['changed'] );
			wproadie_assert( ! isset( $same['undo_id'] ), 'Nothing saved, nothing to undo.' );
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '4f5e6d7',
						'settings' => array(),
					)
				),
				'ability_invalid_input'
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor edits keep the plain-text copy in post_content in step, like the editor',
	function () {
		if ( ! $GLOBALS['wproadie_el_real'] ) {
			return; // Rendering the plain text needs Elementor's widgets.
		}
		// Classic widgets only: atomic (v4) widgets render through Twig.
		$id = wproadie_el_page( array_slice( wproadie_el_fixture(), 0, 2 ) );
		try {
			$content = get_post( $id )->post_content;
			$result  = wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '4f5e6d7',
						'settings' => array( 'title' => 'Fresh \\ "roast"' ),
					)
				)
			);
			$text    = get_post( $id )->post_content;
			wproadie_assert( false !== strpos( $text, 'Fresh \\ "roast"' ), $text );
			wproadie_assert( false !== strpos( $text, 'Ring 555-0100 to book' ), 'Plain text covers the whole page.' );
			wproadie_assert( false === strpos( $text, 'roadieTracking' ), 'Scripts are not copied into post_content.' );
			wproadie_assert( ! empty( $result['undo_id'] ), 'undo_id missing' );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( $content, get_post( $id )->post_content, 'Undo restores the plain-text copy.' );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

/**
 * Newest undoable log entry id (the call just made).
 */
function wproadie_el_latest_undo_id(): int {
	global $wpdb;
	$table = WPRoadie\Log\AuditLog::table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE undo_data IS NOT NULL AND undone_at IS NULL ORDER BY id DESC LIMIT 1" );
}

wproadie_test(
	'elementor-add-element adds widgets and containers with fresh ids and checks placement',
	function () {
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$old    = wproadie_el_ids( wproadie_el_fixture() );
			$added  = wproadie_ok(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'     => $id,
						'parent_id'   => 'd6e7f80',
						'position'    => 0,
						'widget_type' => 'heading',
						'settings'    => array(
							'title'       => 'Opening hours',
							'header_size' => 'h2',
						),
					)
				)
			);
			$new_id = $added['added'][0]['id'];
			wproadie_assert( (bool) preg_match( '/^[0-9a-f]{7}$/', $new_id ), $new_id );
			wproadie_assert( ! in_array( $new_id, $old, true ), 'Fresh id.' );
			wproadie_assert_same( '0.4.0', $added['added'][0]['path'] );
			wproadie_assert_same( 'Opening hours', $added['added'][0]['summary'] );
			$stored = wproadie_el_tree( $id )[0]['elements'][4]['elements'][0];
			wproadie_assert_same(
				array(
					'id'         => $new_id,
					'elType'     => 'widget',
					'settings'   => array(
						'title'       => 'Opening hours',
						'header_size' => 'h2',
					),
					'elements'   => array(),
					'widgetType' => 'heading',
				),
				$stored
			);

			// A widget at the top level is wrapped in a container.
			$top   = wproadie_ok(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'     => $id,
						'position'    => 0,
						'widget_type' => 'spacer',
					)
				)
			);
			$first = wproadie_el_tree( $id )[0];
			wproadie_assert_same( 'container', $first['elType'] );
			wproadie_assert_same( 'spacer', $first['elements'][0]['widgetType'] );
			wproadie_assert( isset( $top['note'] ), 'Wrapping is explained.' );

			// A container with nested content.
			$nested = wproadie_ok(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id' => $id,
						'element' => array(
							'id'       => 'a1b2c3d',
							'elType'   => 'container',
							'settings' => array( 'flex_direction' => 'row' ),
							'elements' => array(
								array(
									'id'         => '4f5e6d7',
									'elType'     => 'widget',
									'widgetType' => 'button',
									'settings'   => array( 'text' => 'One' ),
								),
								array(
									'elType'   => 'container',
									'elements' => array(
										array(
											'elType'     => 'widget',
											'widgetType' => 'text-editor',
											'settings'   => array( 'editor' => '<p>Two</p>' ),
										),
									),
								),
							),
						),
					)
				)
			);
			$tree   = wproadie_el_tree( $id );
			$last   = end( $tree );
			wproadie_assert_same( $nested['added'][0]['id'], $last['id'] );
			wproadie_assert_same( false, $last['isInner'] );
			wproadie_assert_same( true, $last['elements'][1]['isInner'] );
			$all = wproadie_el_ids( $tree );
			wproadie_assert_same( count( $all ), count( array_unique( $all ) ), 'Ids stay unique; supplied ids are replaced.' );
			wproadie_assert_same( Tree::count( $tree ), count( $all ) );

			wproadie_assert_error(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'   => $id,
						'parent_id' => 'd6e7f80',
						'element'   => array( 'elType' => 'column' ),
					)
				),
				'wproadie_invalid_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'     => $id,
						'parent_id'   => '4f5e6d7',
						'widget_type' => 'heading',
					)
				),
				'wproadie_invalid_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'   => $id,
						'parent_id' => '5ec7104',
						'element'   => array(
							'elType'     => 'widget',
							'widgetType' => 'heading',
						),
					)
				),
				'wproadie_invalid_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'     => $id,
						'parent_id'   => 'nope123',
						'widget_type' => 'heading',
					)
				),
				'wproadie_parent_not_found'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id' => $id,
						'element' => array( 'settings' => array() ),
					)
				),
				'wproadie_invalid_element'
			);
			wproadie_assert_error( wproadie_call( 'elementor-add-element', array( 'post_id' => $id ) ), 'wproadie_missing_element' );
			if ( $GLOBALS['wproadie_el_real'] ) {
				wproadie_assert_error(
					wproadie_call(
						'elementor-add-element',
						array(
							'post_id'     => $id,
							'parent_id'   => 'd6e7f80',
							'widget_type' => 'no-such-widget',
						)
					),
					'wproadie_unknown_type'
				);
			}

			wproadie_ok( Undo::revert( $nested['undo_id'] ) );
			wproadie_ok( Undo::revert( $top['undo_id'] ) );
			wproadie_ok( Undo::revert( $added['undo_id'] ) );
			wproadie_assert_same( $before, wproadie_el_raw( $id ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-remove-element removes a subtree and undo puts it back',
	function () {
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$result = wproadie_ok(
				wproadie_call(
					'elementor-remove-element',
					array(
						'post_id' => $id,
						'id'      => 'd6e7f80',
					)
				)
			);
			wproadie_assert_same( 3, $result['elements_removed'] );
			wproadie_assert_same( 'a1b2c3d', $result['parent_id'] );
			$tree = wproadie_el_tree( $id );
			wproadie_assert_same( null, Tree::find( $tree, 'f9a8b7c' ) );
			wproadie_assert_same( 5, count( $tree[0]['elements'] ) );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( $before, wproadie_el_raw( $id ) );
			wproadie_assert_error(
				wproadie_call(
					'elementor-remove-element',
					array(
						'post_id' => $id,
						'id'      => 'gone123',
					)
				),
				'wproadie_element_not_found'
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-move-element reorders and reparents within the nesting rules',
	function () {
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$first  = wproadie_ok(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'  => $id,
						'id'       => 'b0a1c2e',
						'position' => 0,
					)
				)
			);
			wproadie_assert_same( '0.0', $first['moved']['path'] );
			wproadie_assert_same( '0.2', $first['from_path'] );
			wproadie_assert_same( array( 'b0a1c2e', '4f5e6d7', '9e8d7c6' ), array_slice( array_column( wproadie_el_tree( $id )[0]['elements'], 'id' ), 0, 3 ) );

			// A heading from a section's column into the container.
			$second = wproadie_ok(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'   => $id,
						'id'        => '7e57ed1',
						'parent_id' => 'a1b2c3d',
						'position'  => 1,
					)
				)
			);
			wproadie_assert_same( '0.1', $second['moved']['path'] );
			wproadie_assert_same( array(), wproadie_el_tree( $id )[1]['elements'][0]['elements'] );

			// The section to the top of the page.
			$third = wproadie_ok(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'   => $id,
						'id'        => '5ec7104',
						'parent_id' => 'root',
						'position'  => 0,
					)
				)
			);
			wproadie_assert_same( '5ec7104', wproadie_el_tree( $id )[0]['id'] );

			// An inner section moved to the top level stops being inner.
			$fourth = wproadie_ok(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'   => $id,
						'id'        => '1a5ec71',
						'parent_id' => 'root',
					)
				)
			);
			$tree   = wproadie_el_tree( $id );
			$inner  = end( $tree );
			wproadie_assert_same( '1a5ec71', $inner['id'] );
			wproadie_assert_same( false, $inner['isInner'] );
			wproadie_assert_same( false, $inner['elements'][0]['isInner'] );

			wproadie_assert_error(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'   => $id,
						'id'        => '5ec7104',
						'parent_id' => 'c01a001',
					)
				),
				'wproadie_invalid_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'   => $id,
						'id'        => 'a1b2c3d',
						'parent_id' => 'c01a001',
					)
				),
				'wproadie_invalid_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'   => $id,
						'id'        => '4f5e6d7',
						'parent_id' => 'root',
					)
				),
				'wproadie_invalid_placement'
			);
			$same = wproadie_ok(
				wproadie_call(
					'elementor-move-element',
					array(
						'post_id'  => $id,
						'id'       => 'b0a1c2e',
						'position' => 0,
					)
				)
			);
			wproadie_assert_same( false, $same['changed'] );

			foreach ( array( $fourth, $third, $second, $first ) as $step ) {
				wproadie_ok( Undo::revert( $step['undo_id'] ) );
			}
			wproadie_assert_same( $before, wproadie_el_raw( $id ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-duplicate-element places a copy with fresh ids after the original',
	function () {
		$id = wproadie_el_page();
		try {
			$before   = wproadie_el_raw( $id );
			$copy     = wproadie_ok(
				wproadie_call(
					'elementor-duplicate-element',
					array(
						'post_id' => $id,
						'id'      => 'd6e7f80',
					)
				)
			);
			$tree     = wproadie_el_tree( $id );
			$original = $tree[0]['elements'][4];
			$dup      = $tree[0]['elements'][5];
			wproadie_assert_same( 'd6e7f80', $original['id'] );
			wproadie_assert_same( $copy['added'][0]['id'], $dup['id'] );
			wproadie_assert_same( '0.5', $copy['added'][0]['path'] );
			wproadie_assert_same( $original['elements'][1]['settings'], $dup['elements'][1]['settings'] );
			wproadie_assert_same( true, $dup['isInner'] );
			$ids = wproadie_el_ids( $tree );
			wproadie_assert_same( count( $ids ), count( array_unique( $ids ) ) );
			wproadie_assert_same( 21, count( $ids ) );

			// Atomic (v4) local styles follow the new id.
			$atomic = wproadie_ok(
				wproadie_call(
					'elementor-duplicate-element',
					array(
						'post_id' => $id,
						'id'      => 'a70a1c1',
					)
				)
			);
			$tree   = wproadie_el_tree( $id );
			$child  = $tree[3]['elements'][0];
			wproadie_assert( 'a70a1c2' !== $child['id'], 'Child id refreshed.' );
			$key = 'e-' . $child['id'] . '-1';
			wproadie_assert_same( array( $key ), array_keys( $child['styles'] ) );
			wproadie_assert_same( $key, $child['styles'][ $key ]['id'] );
			wproadie_assert_same( array( $key ), $child['settings']['classes']['value'] );
			wproadie_assert_same( array( 'e-a70a1c2-1' ), $tree[2]['elements'][0]['settings']['classes']['value'], 'Original untouched.' );

			wproadie_ok( Undo::revert( $atomic['undo_id'] ) );
			wproadie_ok( Undo::revert( $copy['undo_id'] ) );
			wproadie_assert_same( $before, wproadie_el_raw( $id ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-replace-text dry run lists matches and changes nothing',
	function () {
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$result = wproadie_ok(
				wproadie_call(
					'elementor-replace-text',
					array(
						'post_id' => $id,
						'search'  => '555-0100',
						'replace' => '555-0199',
					)
				)
			);
			wproadie_assert_same( true, $result['dry_run'] );
			wproadie_assert_same( $before, wproadie_el_raw( $id ) );
			wproadie_assert( ! isset( $result['posts'][0]['undo_id'] ), 'A dry run has nothing to undo.' );
			$keys = array();
			foreach ( $result['posts'][0]['matches'] as $match ) {
				$keys[] = $match['element_id'] . ':' . $match['key'];
			}
			sort( $keys );
			wproadie_assert_same( array( '7e57ed2:testimonial_content', '9e8d7c6:editor', 'b0a1c2e:text', 'e1f2a3b:icon_list.1.text', 'f9a8b7c:form_fields.1.field_label' ), $keys );
			wproadie_assert_same( 5, $result['replacements'] );
			wproadie_assert( isset( $result['next_step'] ), 'Explains how to apply.' );
			wproadie_assert_error(
				wproadie_call(
					'elementor-replace-text',
					array(
						'search'  => '555-0100',
						'replace' => 'x',
					)
				),
				'wproadie_missing_scope'
			);
			$insensitive = wproadie_ok(
				wproadie_call(
					'elementor-replace-text',
					array(
						'post_id'        => $id,
						'search'         => 'CAFÉ RHYTHM',
						'replace'        => 'x',
						'case_sensitive' => false,
					)
				)
			);
			wproadie_assert_same( 1, $insensitive['replacements'] );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-replace-text changes every page and one undo reverts them all',
	function () {
		$one = wproadie_el_page();
		$two = wproadie_el_page(
			array(
				array(
					'id'       => 'b2b2b2b',
					'elType'   => 'container',
					'settings' => array(),
					'elements' => array(
						array(
							'id'         => 'c3c3c3c',
							'elType'     => 'widget',
							'settings'   => array(
								'text' => 'Call 5550100 / tel',
								'link' => array( 'url' => 'tel:5550100' ),
							),
							'elements'   => array(),
							'widgetType' => 'button',
						),
					),
					'isInner'  => false,
				),
			),
			array( 'post_status' => 'draft' )
		);
		try {
			$raw_one = wproadie_el_raw( $one );
			$raw_two = wproadie_el_raw( $two );
			$result  = wproadie_ok(
				wproadie_call(
					'elementor-replace-text',
					array(
						'search'    => '5550100',
						'replace'   => '5550199',
						'all_pages' => true,
						'dry_run'   => false,
					)
				)
			);
			$changed = array_column( $result['posts'], 'post_id' );
			wproadie_assert( in_array( $one, $changed, true ) && in_array( $two, $changed, true ), wp_json_encode( $changed ) );
			wproadie_assert( ! empty( $result['undo_id'] ), 'One undo for the whole call.' );
			wproadie_assert_same( 'tel:5550199', wproadie_el_tree( $two )[0]['elements'][0]['settings']['link']['url'] );
			wproadie_assert_same( 'tel:5550199', wproadie_el_tree( $one )[0]['elements'][2]['settings']['link']['url'] );
			wproadie_assert_same( wproadie_el_fixture()[0]['elements'][2]['settings']['__dynamic__'], wproadie_el_tree( $one )[0]['elements'][2]['settings']['__dynamic__'], 'Dynamic tags untouched.' );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( $raw_one, wproadie_el_raw( $one ) );
			wproadie_assert_same( $raw_two, wproadie_el_raw( $two ) );
		} finally {
			wp_delete_post( $one, true );
			wp_delete_post( $two, true );
		}
	}
);

wproadie_test(
	'elementor tools strip scripts for users without unfiltered_html and keep existing HTML',
	function () {
		$id = wproadie_el_page();
		try {
			wproadie_el_without_unfiltered_html(
				function () use ( $id ) {
					$result = wproadie_ok(
						wproadie_call(
							'elementor-update-element',
							array(
								'post_id'        => $id,
								'id'             => '9e8d7c6',
								'settings'       => array( 'editor' => '<p>Hi<script>alert(1)</script><img src="x" onerror="alert(2)"></p>' ),
								'allow_raw_html' => true,
							)
						)
					);
					$editor = wproadie_el_tree( $id )[0]['elements'][1]['settings']['editor'];
					wproadie_assert( false === stripos( $editor, '<script' ) && false === stripos( $editor, 'onerror' ), $editor );
					wproadie_assert( false !== strpos( $editor, '<p>Hi' ), $editor );
					wproadie_assert( isset( $result['note'] ), 'The user is told HTML was removed.' );

					// The admin's script in the untouched HTML widget survives.
					wproadie_assert( false !== strpos( wproadie_el_raw( $id ), 'window.roadieTracking' ), 'Existing content is not re-filtered.' );

					wproadie_ok(
						wproadie_call(
							'elementor-add-element',
							array(
								'post_id'        => $id,
								'parent_id'      => 'a1b2c3d',
								'widget_type'    => 'html',
								'settings'       => array( 'html' => '<script>evil()</script><b>ok</b>' ),
								'allow_raw_html' => true,
							)
						)
					);
					$tree = wproadie_el_tree( $id );
					$last = end( $tree[0]['elements'] );
					wproadie_assert_same( '<b>ok</b>', str_replace( 'evil()', '', $last['settings']['html'] ) );
					wproadie_assert( false === strpos( $last['settings']['html'], '<script' ), $last['settings']['html'] );

					wproadie_ok(
						wproadie_call(
							'elementor-replace-text',
							array(
								'post_id' => $id,
								'search'  => 'Our story',
								'replace' => '<script>x()</script>Story',
								'dry_run' => false,
							)
						)
					);
					$title = wproadie_el_tree( $id )[1]['elements'][0]['elements'][0]['settings']['title'];
					wproadie_assert( false === strpos( $title, '<script' ), $title );
				}
			);
			// Users with unfiltered_html keep what they send, like in Elementor.
			wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'        => $id,
						'id'             => '0badc0d',
						'settings'       => array( 'html' => '<script>ok()</script>' ),
						'allow_raw_html' => true,
					)
				)
			);
			wproadie_assert_same( '<script>ok()</script>', wproadie_el_tree( $id )[0]['elements'][5]['settings']['html'] );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor tools refuse oversized elements and pages',
	function () {
		$id = wproadie_el_page();
		try {
			$big = str_repeat( 'x', ElementorHelper::MAX_ELEMENT_BYTES + 10 );
			wproadie_assert_error(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'     => $id,
						'parent_id'   => 'a1b2c3d',
						'widget_type' => 'text-editor',
						'settings'    => array( 'editor' => $big ),
					)
				),
				'wproadie_too_large'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '9e8d7c6',
						'settings' => array( 'editor' => $big ),
					)
				),
				'wproadie_too_large'
			);

			// A page already near the limit cannot grow past it.
			$tree   = wproadie_el_fixture();
			$chunk  = str_repeat( 'y', 200000 );
			$filler = array();
			$needed = (int) ceil( ElementorHelper::MAX_PAGE_BYTES / 200000 );
			for ( $i = 0; $i < $needed; $i++ ) {
				$filler[] = array(
					'id'         => sprintf( 'f%06x', $i ),
					'elType'     => 'widget',
					'settings'   => array( 'editor' => $chunk ),
					'elements'   => array(),
					'widgetType' => 'text-editor',
				);
			}
			$tree[0]['elements'] = array_merge( $tree[0]['elements'], $filler );
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
			$before = wproadie_el_raw( $id );
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '4f5e6d7',
						'settings' => array( 'title' => 'More' ),
					)
				),
				'wproadie_too_large'
			);
			wproadie_assert_same( $before, wproadie_el_raw( $id ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor edits and undo clear the page\'s generated CSS',
	function () {
		$id = wproadie_el_page();
		try {
			update_post_meta(
				$id,
				'_elementor_css',
				array(
					'status' => 'file',
					'time'   => 1,
				)
			);
			update_post_meta( $id, '_elementor_element_cache', array( 'stale' => true ) );
			$result = wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => '9e8d7c6',
						'settings' => array( 'text_color' => '#123456' ),
					)
				)
			);
			wproadie_assert_same( '', get_post_meta( $id, '_elementor_css', true ) );
			wproadie_assert_same( '', get_post_meta( $id, '_elementor_element_cache', true ) );

			if ( $GLOBALS['wproadie_el_real'] ) {
				// Elementor rebuilds the CSS from the new data.
				$css = \Elementor\Core\Files\CSS\Post::create( $id );
				$css->update();
				wproadie_assert( false !== stripos( $css->get_content(), '#123456' ), 'Elementor CSS has the new color.' );
				$document = \Elementor\Plugin::$instance->documents->get( $id, false );
				wproadie_assert_same( wproadie_el_tree( $id ), $document->get_elements_data(), 'Elementor reads the saved data.' );
			}

			update_post_meta(
				$id,
				'_elementor_css',
				array(
					'status' => 'file',
					'time'   => 2,
				)
			);
			$fired = 0;
			$count = static function () use ( &$fired ) {
				++$fired;
			};
			add_action( 'wproadie_undo_record_reverted', $count );
			try {
				wproadie_ok( Undo::revert( $result['undo_id'] ) );
			} finally {
				remove_action( 'wproadie_undo_record_reverted', $count );
			}
			wproadie_assert( $fired >= 1, 'The undo hook fires per record.' );
			wproadie_assert_same( '', get_post_meta( $id, '_elementor_css', true ), 'No stale CSS after undo.' );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor-regenerate-css clears one page or the whole site',
	function () {
		$one = wproadie_el_page();
		$two = wproadie_el_page();
		try {
			update_post_meta( $one, '_elementor_css', array( 'time' => 1 ) );
			update_post_meta( $two, '_elementor_css', array( 'time' => 1 ) );
			$result = wproadie_ok( wproadie_call( 'elementor-regenerate-css', array( 'post_id' => $one ) ) );
			wproadie_assert_same( 'post', $result['scope'] );
			wproadie_assert_same( '', get_post_meta( $one, '_elementor_css', true ) );
			wproadie_assert( '' !== get_post_meta( $two, '_elementor_css', true ), 'Only the one page.' );
			$site = wproadie_ok( wproadie_call( 'elementor-regenerate-css' ) );
			wproadie_assert_same( 'site', $site['scope'] );
			wproadie_assert_same( '', get_post_meta( $two, '_elementor_css', true ) );
		} finally {
			wp_delete_post( $one, true );
			wp_delete_post( $two, true );
		}
	}
);

wproadie_test(
	'elementor-list-templates and elementor-insert-template copy a template with fresh ids',
	function () {
		$registered = wproadie_el_library_type();
		$fixture    = wproadie_el_fixture();
		$section    = wproadie_el_page(
			array( $fixture[1] ),
			array(
				'post_type'  => 'elementor_library',
				'post_title' => 'Zebracorn story section',
			)
		);
		update_post_meta( $section, '_elementor_template_type', 'section' );
		$box = wproadie_el_page(
			array( $fixture[0]['elements'][4] ),
			array(
				'post_type'  => 'elementor_library',
				'post_title' => 'Zebracorn info box',
			)
		);
		update_post_meta( $box, '_elementor_template_type', 'container' );
		$page = wproadie_el_page();
		try {
			$list = wproadie_ok( wproadie_call( 'elementor-list-templates', array( 'search' => 'Zebracorn' ) ) );
			wproadie_assert_same( 2, $list['total'] );
			$types = array_column( $list['items'], 'template_type', 'id' );
			wproadie_assert_same( 'section', $types[ $section ] );
			$only = wproadie_ok(
				wproadie_call(
					'elementor-list-templates',
					array(
						'search'        => 'Zebracorn',
						'template_type' => 'container',
					)
				)
			);
			wproadie_assert_same( array( $box ), array_column( $only['items'], 'id' ) );

			$before = wproadie_el_raw( $page );
			$result = wproadie_ok(
				wproadie_call(
					'elementor-insert-template',
					array(
						'post_id'     => $page,
						'template_id' => $section,
						'position'    => 0,
					)
				)
			);
			$tree   = wproadie_el_tree( $page );
			wproadie_assert_same( 4, count( $tree ) );
			wproadie_assert_same( 'section', $tree[0]['elType'] );
			wproadie_assert( '5ec7104' !== $tree[0]['id'], 'Fresh id.' );
			wproadie_assert_same( $fixture[1]['elements'][0]['elements'][0]['settings'], $tree[0]['elements'][0]['elements'][0]['settings'] );
			wproadie_assert_same( 'section', $result['template']['template_type'] );
			$ids = wproadie_el_ids( $tree );
			wproadie_assert_same( count( $ids ), count( array_unique( $ids ) ) );

			wproadie_assert_error(
				wproadie_call(
					'elementor-insert-template',
					array(
						'post_id'     => $page,
						'template_id' => $section,
						'parent_id'   => 'a1b2c3d',
					)
				),
				'wproadie_invalid_placement'
			);
			$inner    = wproadie_ok(
				wproadie_call(
					'elementor-insert-template',
					array(
						'post_id'     => $page,
						'template_id' => $box,
						'parent_id'   => 'a1b2c3d',
						'position'    => 0,
					)
				)
			);
			$inserted = wproadie_el_tree( $page )[1]['elements'][0];
			wproadie_assert_same( 'form', $inserted['elements'][1]['widgetType'], 'Unregistered widgets in templates are copied intact.' );
			wproadie_assert_same( true, $inserted['isInner'] );
			wproadie_assert_error(
				wproadie_call(
					'elementor-insert-template',
					array(
						'post_id'     => $page,
						'template_id' => $page,
					)
				),
				'wproadie_template_not_found'
			);

			wproadie_ok( Undo::revert( $inner['undo_id'] ) );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( $before, wproadie_el_raw( $page ) );
		} finally {
			wp_delete_post( $section, true );
			wp_delete_post( $box, true );
			wp_delete_post( $page, true );
			if ( $registered ) {
				unregister_post_type( 'elementor_library' );
			}
		}
	}
);

wproadie_test(
	'elementor-get-kit and elementor-update-kit change global colors and fonts with undo',
	function () {
		$registered = wproadie_el_library_type();
		$old_option = get_option( 'elementor_active_kit', null );
		$fake_kit   = 0;
		if ( ! $GLOBALS['wproadie_el_real'] || ! ElementorHelper::active_kit_id() ) {
			$fake_kit = (int) wp_insert_post(
				array(
					'post_title'  => 'Default Kit',
					'post_type'   => 'elementor_library',
					'post_status' => 'publish',
				)
			);
			update_post_meta( $fake_kit, '_elementor_template_type', 'kit' );
			update_post_meta(
				$fake_kit,
				'_elementor_page_settings',
				array(
					'system_colors'     => array(
						array(
							'_id'   => 'primary',
							'title' => 'Primary',
							'color' => '#6EC1E4',
						),
						array(
							'_id'   => 'accent',
							'title' => 'Accent',
							'color' => '#61CE70',
						),
					),
					'custom_colors'     => array(),
					'system_typography' => array(
						array(
							'_id'                    => 'primary',
							'title'                  => 'Primary',
							'typography_typography'  => 'custom',
							'typography_font_family' => 'Roboto',
							'typography_font_weight' => '600',
						),
					),
					'container_width'   => array(
						'unit' => 'px',
						'size' => 1140,
					),
				)
			);
			update_option( 'elementor_active_kit', $fake_kit );
		}
		$kit_id  = ElementorHelper::active_kit_id();
		$existed = metadata_exists( 'post', $kit_id, '_elementor_page_settings' );
		$stored  = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$page    = wproadie_el_page();
		try {
			$kit = wproadie_ok( wproadie_call( 'elementor-get-kit' ) );
			wproadie_assert_same( $kit_id, $kit['kit_id'] );
			$colors = array_column( $kit['colors'], null, 'id' );
			wproadie_assert( isset( $colors['primary'] ) && true === $colors['primary']['system'], wp_json_encode( $kit['colors'] ) );
			wproadie_assert_same( 'globals/colors?id=primary', $colors['primary']['global'] );
			wproadie_assert( in_array( 'primary', array_column( $kit['fonts'], 'id' ), true ), 'Fonts listed.' );

			update_post_meta( $page, '_elementor_css', array( 'time' => 1 ) );
			$result = wproadie_ok(
				wproadie_call(
					'elementor-update-kit',
					array(
						'colors' => array(
							array(
								'id'    => 'primary',
								'color' => '#112233',
							),
							array(
								'id'    => 'sand',
								'title' => 'Brand Sand',
								'color' => '#F4F1EA',
							),
						),
						'fonts'  => array(
							array(
								'id'     => 'primary',
								'family' => 'Lora',
								'weight' => 700,
								'size'   => 18,
							),
						),
					)
				)
			);
			wproadie_assert_same( true, $result['changed'] );
			wproadie_assert_same( array( 'sand' ), $result['added'] );
			$colors = array_column( $result['colors'], null, 'id' );
			wproadie_assert_same( '#112233', $colors['primary']['color'] );
			wproadie_assert_same( 'Brand Sand', $colors['sand']['title'] );
			wproadie_assert_same( false, $colors['sand']['system'] );
			$fonts = array_column( $result['fonts'], null, 'id' );
			wproadie_assert_same( 'Lora', $fonts['primary']['family'] );
			wproadie_assert_same( '18px', $fonts['primary']['size'] );
			$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
			wproadie_assert_same( 'custom', $settings['system_typography'][0]['typography_typography'] );
			wproadie_assert_same( '', get_post_meta( $page, '_elementor_css', true ), 'Site CSS is cleared after a kit change.' );

			$removed = wproadie_ok( wproadie_call( 'elementor-update-kit', array( 'remove' => array( 'sand' ) ) ) );
			wproadie_assert( ! in_array( 'sand', array_column( $removed['colors'], 'id' ), true ), 'Custom color removed.' );

			wproadie_assert_error( wproadie_call( 'elementor-update-kit', array( 'remove' => array( 'primary' ) ) ), 'wproadie_system_global' );
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-kit',
					array(
						'colors' => array(
							array(
								'id'    => 'primary',
								'color' => 'red;}body{display:none',
							),
						),
					)
				),
				'wproadie_invalid_color'
			);
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-kit',
					array(
						'colors' => array(
							array(
								'id'    => 'nosuch',
								'color' => '#000000',
							),
						),
					)
				),
				'wproadie_global_not_found'
			);
			wproadie_assert_error( wproadie_call( 'elementor-update-kit', array( 'settings' => array( 'system_colors' => array() ) ) ), 'wproadie_use_colors_fonts' );
			wproadie_assert_error( wproadie_call( 'elementor-update-kit', array() ), 'wproadie_nothing_to_update' );

			update_post_meta( $page, '_elementor_css', array( 'time' => 2 ) );
			wproadie_ok( Undo::revert( $removed['undo_id'] ) );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( $existed, metadata_exists( 'post', $kit_id, '_elementor_page_settings' ) );
			wproadie_assert_same( $stored, get_post_meta( $kit_id, '_elementor_page_settings', true ), 'Undo restores the kit exactly.' );
			wproadie_assert_same( '', get_post_meta( $page, '_elementor_css', true ), 'Site CSS is cleared after undo too.' );
		} finally {
			wp_delete_post( $page, true );
			if ( $fake_kit ) {
				wp_delete_post( $fake_kit, true );
				if ( null === $old_option ) {
					delete_option( 'elementor_active_kit' );
				} else {
					update_option( 'elementor_active_kit', $old_option );
				}
			}
			if ( $registered ) {
				unregister_post_type( 'elementor_library' );
			}
		}
	}
);

wproadie_test(
	'elementor tree helpers: merge, placement, ids and summaries',
	function () {
		wproadie_assert_same(
			array(
				'a' => array(
					'x' => 1,
					'y' => 3,
				),
				'l' => array( 9 ),
			),
			Tree::merge_settings(
				array(
					'a' => array(
						'x' => 1,
						'y' => 2,
						'z' => 0,
					),
					'l' => array( 1, 2 ),
					'r' => 'gone',
				),
				array(
					'a' => array(
						'y' => 3,
						'z' => null,
					),
					'l' => array( 9 ),
					'r' => null,
				)
			)
		);
		$taken = array( 'abc1234' => true );
		$id    = Tree::new_id( $taken );
		wproadie_assert( (bool) preg_match( '/^[0-9a-f]{7}$/', $id ) && isset( $taken[ $id ] ), $id );
		wproadie_assert_same( true, Tree::check_placement( null, array( 'elType' => 'section' ) ) );
		wproadie_assert_error( Tree::check_placement( array( 'elType' => 'section' ), array( 'elType' => 'widget' ) ), 'wproadie_invalid_placement' );
		wproadie_assert_error(
			Tree::check_placement(
				array(
					'elType'  => 'column',
					'isInner' => true,
				),
				array( 'elType' => 'section' )
			),
			'wproadie_invalid_placement'
		);
		wproadie_assert_same( true, Tree::check_placement( array( 'elType' => 'e-div-block' ), array( 'elType' => 'widget' ) ) );
		wproadie_assert_same(
			'Text',
			Tree::text(
				array(
					'$$type' => 'string',
					'value'  => '<b>Text</b>',
				)
			)
		);
		wproadie_assert_same( Kit::valid_color( '#abc' ), true );
		wproadie_assert_same( Kit::valid_color( 'rgba(0, 0, 0, 0.5)' ), true );
		wproadie_assert_same( Kit::valid_color( 'url(x)' ), false );
	}
);

if ( ! $GLOBALS['wproadie_el_real'] ) {
	wproadie_test(
		'elementor fixture mode leaves the site as it found it',
		function () {
			// Later test files compare the tool list with a fresh MCP process,
			// which has no fake Elementor.
			remove_filter( 'wproadie_elementor_active', '__return_true' );
			foreach ( ( new Registry() )->all() as $ability ) {
				if ( 'elementor' === $ability->group() && wp_has_ability( $ability->name() ) ) {
					wp_unregister_ability( $ability->name() );
				}
			}
			wproadie_assert( ! preg_grep( '#^wproadie/elementor-#', ( new Registry() )->enabled_names() ), 'Elementor tools are gone again.' );
		}
	);
}

wproadie_test(
	'elementor-get-widget-schema lists widgets and the settings one takes',
	function () {
		if ( ! $GLOBALS['wproadie_el_real'] ) {
			// Without Elementor the tool is not offered at all.
			wproadie_assert( null === wp_get_ability( 'wproadie/elementor-get-widget-schema' ), 'not offered without Elementor' );
			return;
		}
		$catalog = wproadie_ok( wproadie_call( 'elementor-get-widget-schema' ) );
		$names   = array_column( $catalog['widgets'], 'name' );
		wproadie_assert( in_array( 'heading', $names, true ) && in_array( 'button', $names, true ), 'core widgets listed' );
		wproadie_assert( ! in_array( 'common', $names, true ) && ! in_array( 'common-optimized', $names, true ), 'editor plumbing hidden' );
		wproadie_assert_same( 'elementor', $catalog['widgets'][ array_search( 'heading', $names, true ) ]['source'] );
		wproadie_assert( in_array( 'container', $catalog['elements'], true ) );
		wproadie_assert_same( count( $names ), $catalog['total'] );

		$heading = wproadie_ok( wproadie_call( 'elementor-get-widget-schema', array( 'widget' => 'heading' ) ) );
		wproadie_assert_same( 'widget', $heading['elType'] );
		$all = array();
		foreach ( $heading['sections'] as $section ) {
			wproadie_assert( ! empty( $section['controls'] ), 'empty sections are left out' );
			foreach ( $section['controls'] as $control ) {
				$all[ $control['name'] ] = $control + array( 'section' => $section['id'] );
			}
		}
		wproadie_assert_same( 'textarea', $all['title']['type'] );
		wproadie_assert_same( 'section_title', $all['title']['section'] );
		wproadie_assert_same( 'h2', $all['header_size']['default'] );
		wproadie_assert( isset( $all['_margin'] ), 'the shared Advanced tab (margin) is included' );
		wproadie_assert( isset( $all['typography_font_size'] ), 'group controls appear as flat keys' );
		wproadie_assert( ! isset( $all['align_tablet'] ), 'responsive copies are left out' );
		wproadie_assert_same( true, $all['title']['dynamic'] );
		wproadie_assert_same( true, $all['title_color']['global'] );
		wproadie_assert_same( count( $all ), $heading['control_count'] );

		$content = wproadie_ok(
			wproadie_call(
				'elementor-get-widget-schema',
				array(
					'widget'        => 'heading',
					'include_style' => false,
				)
			)
		);
		if ( ElementorHelper::controls_are_stripped() ) {
			// WP-CLI runs in Elementor's optimized mode: no labels, options or tabs.
			wproadie_assert( isset( $heading['note'] ), 'the answer says labels are missing' );
			wproadie_assert( $content['control_count'] <= $heading['control_count'] );
		} else {
			wproadie_assert( $content['control_count'] < $heading['control_count'], 'content-only is smaller' );
		}
		$search = wproadie_ok(
			wproadie_call(
				'elementor-get-widget-schema',
				array(
					'widget' => 'heading',
					'search' => 'color',
				)
			)
		);
		foreach ( $search['sections'] as $section ) {
			foreach ( $section['controls'] as $control ) {
				wproadie_assert( false !== stripos( $control['name'] . ( $control['label'] ?? '' ), 'color' ), $control['name'] );
			}
		}
		$container = wproadie_ok( wproadie_call( 'elementor-get-widget-schema', array( 'element' => 'container' ) ) );
		wproadie_assert_same( 'container', $container['elType'] );
		wproadie_assert( $container['control_count'] > 50 );

		wproadie_assert_error( wproadie_call( 'elementor-get-widget-schema', array( 'widget' => 'no-such-widget' ) ), 'wproadie_unknown_type' );
	}
);

wproadie_test(
	'elementor writes refuse settings the widget has no control for, naming the right key',
	function () {
		if ( ! $GLOBALS['wproadie_el_real'] ) {
			return;
		}
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$error  = wproadie_call(
				'elementor-add-element',
				array(
					'post_id'     => $id,
					'parent_id'   => 'a1b2c3d',
					'widget_type' => 'heading',
					'settings'    => array(
						'title'        => 'Hello',
						'heading_size' => 'h3',
						'titel_color'  => '#ff0000',
						'align_mobile' => 'center',
						'__globals__'  => array( 'title_colour' => 'globals/colors?id=primary' ),
					),
				)
			);
			wproadie_assert_error( $error, 'wproadie_unknown_setting' );
			$data = $error->get_error_data();
			wproadie_assert_same( array( 'heading_size', 'titel_color', '__globals__.title_colour' ), $data['unknown'] );
			wproadie_assert_same( 'header_size', $data['suggestions']['heading_size'] );
			wproadie_assert_same( 'title_color', $data['suggestions']['titel_color'] );
			wproadie_assert( in_array( 'title', $data['schema']['section_title'], true ), 'the schema names the controls by section' );
			wproadie_assert( false !== strpos( $error->get_error_message(), 'heading_size → header_size' ), $error->get_error_message() );
			wproadie_assert_same( $before, wproadie_el_raw( $id ), 'nothing was saved' );

			// Nested elements are checked too, and the message says where.
			$nested = wproadie_call(
				'elementor-add-element',
				array(
					'post_id' => $id,
					'element' => array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'button',
								'settings'   => array( 'button_text' => 'Go' ),
							),
						),
					),
				)
			);
			wproadie_assert_error( $nested, 'wproadie_unknown_setting' );
			wproadie_assert( false !== strpos( $nested->get_error_message(), 'nested element at 0' ), $nested->get_error_message() );

			// Update: the same check, only on the keys being set.
			$update = wproadie_call(
				'elementor-update-element',
				array(
					'post_id'  => $id,
					'id'       => 'b0a1c2e',
					'settings' => array( 'txt' => 'Go' ),
				)
			);
			wproadie_assert_error( $update, 'wproadie_unknown_setting' );
			wproadie_assert_same( 'text', $update->get_error_data()['suggestions']['txt'] );

			// Known keys, responsive copies and globals pass; the escape hatch stores anything.
			wproadie_ok(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'     => $id,
						'parent_id'   => 'a1b2c3d',
						'widget_type' => 'heading',
						'settings'    => array(
							'title'          => 'Hello',
							'header_size'    => 'h3',
							'align_mobile'   => 'center',
							'_margin_tablet' => array( 'top' => '0' ),
							'__globals__'    => array( 'title_color' => 'globals/colors?id=primary' ),
						),
					)
				)
			);
			$forced = wproadie_ok(
				wproadie_call(
					'elementor-add-element',
					array(
						'post_id'                => $id,
						'parent_id'              => 'a1b2c3d',
						'widget_type'            => 'heading',
						'settings'               => array(
							'title'     => 'Hello',
							'addon_key' => 'x',
						),
						'allow_unknown_settings' => true,
					)
				)
			);
			wproadie_assert( ! empty( $forced['added'] ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'elementor writes refuse an HTML widget, layout HTML and inline CSS unless asked for',
	function () {
		if ( ! $GLOBALS['wproadie_el_real'] ) {
			return;
		}
		$id = wproadie_el_page();
		try {
			$before = wproadie_el_raw( $id );
			$add    = static function ( array $extra ) use ( $id ) {
				return wproadie_call(
					'elementor-add-element',
					array_merge(
						array(
							'post_id'   => $id,
							'parent_id' => 'a1b2c3d',
						),
						$extra
					)
				);
			};
			$html   = $add(
				array(
					'widget_type' => 'html',
					'settings'    => array( 'html' => '<b>x</b>' ),
				)
			);
			wproadie_assert_error( $html, 'wproadie_raw_html' );
			wproadie_assert_same( 'allow_raw_html', $html->get_error_data()['flag'] );

			$layout = $add(
				array(
					'widget_type' => 'text-editor',
					'settings'    => array( 'editor' => '<div class="grid"><div class="card"><p>One</p></div></div>' ),
				)
			);
			wproadie_assert_error( $layout, 'wproadie_raw_html' );
			wproadie_assert_same( array( 'div' ), $layout->get_error_data()['tags'] );

			$style = $add(
				array(
					'widget_type' => 'heading',
					'settings'    => array( 'title' => '<span style="color:red">Hi</span>' ),
				)
			);
			wproadie_assert_error( $style, 'wproadie_inline_css' );
			$css = $add(
				array(
					'widget_type' => 'heading',
					'settings'    => array(
						'title'      => 'Hi',
						'custom_css' => '.x{color:red}',
					),
				)
			);
			wproadie_assert_error( $css, 'wproadie_inline_css' );

			// Nested elements are checked too, with their place.
			$nested = wproadie_call(
				'elementor-add-element',
				array(
					'post_id' => $id,
					'element' => array(
						'elType'   => 'container',
						'elements' => array(
							array(
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array( 'editor' => '<iframe src="https://x.test"></iframe>' ),
							),
						),
					),
				)
			);
			wproadie_assert_error( $nested, 'wproadie_raw_html' );
			wproadie_assert( false !== strpos( $nested->get_error_message(), 'nested element at 0' ), $nested->get_error_message() );
			wproadie_assert_same( $before, wproadie_el_raw( $id ), 'nothing was saved' );

			// Prose is fine; the flags let an asked-for snippet through.
			$prose = $add(
				array(
					'widget_type' => 'text-editor',
					'settings'    => array( 'editor' => '<h3>Hours</h3><p>Mon–Fri <strong>9–5</strong>, <a href="/contact/">call us</a>.</p><ul><li>One</li></ul>' ),
				)
			);
			wproadie_ok( $prose );
			wproadie_ok(
				$add(
					array(
						'widget_type'    => 'text-editor',
						'settings'       => array( 'editor' => '<div>ok</div>' ),
						'allow_raw_html' => true,
					)
				)
			);
			wproadie_ok(
				$add(
					array(
						'widget_type'      => 'heading',
						'settings'         => array( 'title' => '<span style="color:red">Hi</span>' ),
						'allow_inline_css' => true,
					)
				)
			);

			// Update: the same rules, only for what is being set.
			wproadie_assert_error(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => 'b0a1c2e',
						'settings' => array( 'text' => '<div>Go</div>' ),
					)
				),
				'wproadie_raw_html'
			);
			wproadie_ok(
				wproadie_call(
					'elementor-update-element',
					array(
						'post_id'  => $id,
						'id'       => 'b0a1c2e',
						'settings' => array( 'text' => 'Go' ),
					)
				)
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);
