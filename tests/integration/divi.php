<?php
/**
 * Divi tools, against Divi 4 (shortcode) and Divi 5 (block) fixtures.
 *
 * Divi is commercial and not installed in CI, so these tests force the
 * tools on with the wproadie_divi_available filter, register Divi's post
 * types and taxonomies themselves, and stand in for Divi's cache class.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride, Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Classes.ClassFileName -- Test file; the stand-in Divi class must use Divi's own name.

use WPRoadie\Abilities\Divi\Blocks;
use WPRoadie\Abilities\Divi\Divi;
use WPRoadie\Abilities\Divi\Document;
use WPRoadie\Abilities\Registry;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;

// Stand-ins for Divi's cache helpers, recording what was cleared.
$GLOBALS['wproadie_divi_cleared'] = array();
if ( ! class_exists( 'ET_Core_PageResource' ) ) {
	/**
	 * Records calls like Divi's ET_Core_PageResource.
	 */
	class ET_Core_PageResource {
		/**
		 * @param int|string $post_id Post ID or "all".
		 * @param string     $owner   Owner.
		 */
		public static function remove_static_resources( $post_id, $owner = 'core' ) {
			$GLOBALS['wproadie_divi_cleared'][] = array( $post_id, $owner );
		}
	}
}

add_filter( 'wproadie_divi_available', '__return_true' );

if ( ! function_exists( 'wproadie_test_enable_group' ) ) {
	/**
	 * Switches a group on and, if its abilities were not registered at
	 * init (the group was off then), registers them for this run.
	 *
	 * @param string $group Group key.
	 */
	function wproadie_test_enable_group( string $group ): void {
		global $wp_current_filter;
		Settings::set_group( $group, true );
		$category = str_replace( '_', '-', Registry::category( $group ) );
		foreach ( ( new Registry() )->enabled() as $ability ) {
			if ( $ability->group() !== $group || wp_has_ability( $ability->name() ) ) {
				continue;
			}
			if ( ! wp_has_ability_category( $category ) ) {
				$wp_current_filter[] = 'wp_abilities_api_categories_init';
				wp_register_ability_category(
					$category,
					array(
						'label'       => $category,
						'description' => $category,
					)
				);
				array_pop( $wp_current_filter );
			}
			$wp_current_filter[] = 'wp_abilities_api_init';
			$ability->register( $category );
			array_pop( $wp_current_filter );
		}
	}
}

// Divi registers these; CI has no Divi.
foreach ( array( 'et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_template', 'et_theme_builder' ) as $wproadie_divi_type ) {
	if ( ! post_type_exists( $wproadie_divi_type ) ) {
		register_post_type(
			$wproadie_divi_type,
			array(
				'public'          => false,
				'show_ui'         => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'revisions' ),
			)
		);
	}
}
foreach ( array( 'layout_type', 'scope', 'layout_category' ) as $wproadie_divi_tax ) {
	if ( ! taxonomy_exists( $wproadie_divi_tax ) ) {
		register_taxonomy( $wproadie_divi_tax, 'et_pb_layout', array( 'public' => false ) );
	}
}
wproadie_test_enable_group( 'divi' );

const WPROADIE_DIVI4_PAGE = <<<'D4'
[et_pb_section fb_built="1" admin_label="Hero" _builder_version="4.27.4" global_colors_info="{}"][et_pb_row column_structure="1_2,1_2" _builder_version="4.27.4"][et_pb_column type="1_2" _builder_version="4.27.4"][et_pb_text admin_label="Intro" _builder_version="4.27.4" text_font="Open Sans|700|||||||" custom_css_main_element="content: %22hi%22;||color: red;" global_colors_info="{}"]<p>Hello "world" &amp; <a href="https://x.test/?a=1&b=2" class="Acme">Acme link</a></p>
<p>[contact-form-7 id="12" title="Contact Acme"]</p>[/et_pb_text][et_pb_button button_text="Say %22hi%22 %91now%93" button_url="https://acme.test/a?x=1&y=2" url_new_window="on" module_id="cta-main" _builder_version="4.27.4"][/et_pb_button][/et_pb_column][et_pb_column type="1_2" _builder_version="4.27.4"][et_pb_image src="https://acme.test/img.jpg" alt="Acme image" title_text='single quoted' empty="" /][et_pb_code _builder_version="4.27.4"]<script>console.log("x [not a shortcode]");</script>[/et_pb_code][et_pb_divider show_divider="on"][/et_pb_divider][et_pb_text]C:\path\to %92 file — ünïcode ‘quotes’[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]
[et_pb_section specialty="on" _builder_version="4.27.4"][et_pb_column type="1_3"][et_pb_blurb title="Fast & safe" url="/fast" image="https://acme.test/b.png"]<p>Blurb body</p>[/et_pb_blurb][/et_pb_column][et_pb_column type="2_3" specialty_columns="2"][et_pb_row_inner][et_pb_column_inner type="1_2"][et_pb_tabs][et_pb_tab title="Tab A"]A body[/et_pb_tab][et_pb_tab title="Tab B"]B body[/et_pb_tab][/et_pb_tabs][/et_pb_column_inner][et_pb_column_inner type="1_2"][/et_pb_column_inner][/et_pb_row_inner][/et_pb_column][/et_pb_section]

[et_pb_section fullwidth="on"][et_pb_fullwidth_header title="Full header" button_one_text="Go" button_one_url="#go"]Header <em>text</em>[/et_pb_fullwidth_header][/et_pb_section][et_pb_section global_module="GLOBALID"][et_pb_row][et_pb_column type="4_4"][et_pb_text]Global text[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]
D4;

const WPROADIE_DIVI5_PAGE = <<<'D5'
<!-- wp:divi/placeholder -->
<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}},"decoration":{"background":{"desktop":{"value":{"color":"#0f172a"}},"tablet":{"value":{"color":"#111"}}}}},"builderVersion":"5.11.1"} -->
<!-- wp:divi/row {"module":{"advanced":{"flexColumnStructure":{"desktop":{"value":"equal-columns_2"}}}},"builderVersion":"5.11.1"} -->
<!-- wp:divi/column {"module":{"decoration":{"sizing":{"desktop":{"value":{"flexType":"12_24"}}}}},"builderVersion":"5.11.1"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Caf\u00e9 \u201cAcme\u201d \/ slash"}}},"builderVersion":"5.11.1"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eHello \u003cstrong class=\u0022Acme\u0022\u003eAcme\u003c/strong\u003e \u0026amp; [contact-form-7 id=\u002212\u0022]\u003c/p\u003e"},"tablet":{"value":"\u003cp\u003eTablet Acme\u003c/p\u003e"}}},"module":{"meta":{"adminLabel":{"desktop":{"value":"Intro"}}},"advanced":{"htmlAttributes":{"desktop":{"value":{"id":"intro-text","class":"a b"}}}}},"empty":{},"list":[],"builderVersion":"5.11.1"} /-->
<!-- /wp:divi/column -->
<!-- wp:divi/column {"builderVersion":"5.11.1"} -->
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Get Started","linkUrl":"https://acme.test/start?a=1\u0026b=2","linkTarget":"off"}}},"decoration":{"background":{"desktop":{"value":{"color":"#2563eb"},"hover":{"color":"#1e4fd0"}}}}},"builderVersion":"5.11.1"} /-->
<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"https://acme.test/i.jpg","alt":"Alt \u0022text\u0022"}}}},"builderVersion":"5.11.1"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->

<!-- wp:divi/section {"builderVersion":"5.11.1"} -->
<!-- wp:divi/row {"builderVersion":"5.11.1"} --><!-- wp:divi/column {"builderVersion":"5.11.1"} --><!-- wp:divi/group {"builderVersion":"5.11.1"} --><!-- wp:divi/blurb {"title":{"innerContent":{"desktop":{"value":{"text":"Fast","url":"/fast"}}}},"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eBlazing\u003c/p\u003e"}}},"builderVersion":"5.11.1"} /--><!-- /wp:divi/group --><!-- /wp:divi/column --><!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- wp:divi/global-layout {"globalModule":"GLOBALID","builderVersion":"5.11.1"} /-->
<!-- /wp:divi/placeholder -->
D5;

/**
 * Creates a Divi page directly and checks WordPress stored it verbatim.
 *
 * @param string $content Content.
 * @param array  $args    Extra wp_insert_post args; meta is set as post meta.
 */
function wproadie_divi_fixture( string $content, array $args = array() ): int {
	$meta = $args['meta'] ?? array( '_et_pb_use_builder' => 'on' );
	unset( $args['meta'] );
	$id = (int) wp_insert_post(
		wp_slash(
			array_merge(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'WPRoadie Divi fixture',
					'post_content' => $content,
				),
				$args
			)
		)
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	wproadie_assert_same( $content, wproadie_divi_content( $id ), 'Fixture stored verbatim.' );
	return $id;
}

function wproadie_divi_content( int $id ): string {
	clean_post_cache( $id );
	return (string) get_post_field( 'post_content', $id, 'raw' );
}

/**
 * A global Divi Library item plus pages that use it.
 *
 * @return array{layout: int, d4: int, d5: int}
 */
function wproadie_divi_pages(): array {
	$layout = (int) wp_insert_post(
		array(
			'post_type'    => 'et_pb_layout',
			'post_status'  => 'publish',
			'post_title'   => 'Global CTA',
			'post_content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]Global text[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
		)
	);
	return array(
		'layout' => $layout,
		'd4'     => wproadie_divi_fixture( str_replace( 'GLOBALID', (string) $layout, WPROADIE_DIVI4_PAGE ) ),
		'd5'     => wproadie_divi_fixture( str_replace( 'GLOBALID', (string) $layout, WPROADIE_DIVI5_PAGE ), array( 'meta' => array() ) ),
	);
}

function wproadie_divi_cleanup( array $ids ): void {
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

/**
 * Runs $fn as a user without unfiltered_html (e.g. a multisite admin).
 */
function wproadie_divi_without_unfiltered( callable $callback ): void {
	$filter = static function ( $allcaps ) {
		$allcaps['unfiltered_html'] = false;
		return $allcaps;
	};
	add_filter( 'user_has_cap', $filter );
	kses_init();
	try {
		$callback();
	} finally {
		remove_filter( 'user_has_cap', $filter );
		kses_init();
	}
}

/**
 * Asserts $after is $before with exactly one substring replaced.
 */
function wproadie_divi_assert_only( string $before, string $after, string $original, string $replacement ): void {
	wproadie_assert_same( 1, substr_count( $before, $original ), 'The changed part is unique in the fixture.' );
	wproadie_assert_same( str_replace( $original, $replacement, $before ), $after, 'Only the targeted element changed.' );
}

wproadie_test(
	'divi tools are unavailable without Divi and the group is on by default',
	function () {
		wproadie_assert_same( true, Settings::GROUPS['divi']['default'] );
		wproadie_assert_same( Mode::RISK_WRITE, Settings::GROUPS['divi']['risk'] );
		wproadie_assert_same( 'Divi', Settings::group_label( 'divi' ) );
		add_filter( 'wproadie_divi_available', '__return_false', 99 );
		try {
			$names = ( new Registry() )->enabled_names();
			wproadie_assert( ! in_array( 'wproadie/divi-get-page', $names, true ), 'Hidden without Divi.' );
			$ability = ( new Registry() )->find( 'wproadie/divi-get-page' );
			wproadie_assert( ! $ability->is_available() );
			wproadie_assert( false !== strpos( $ability->unavailable_reason(), 'Divi' ) );
		} finally {
			remove_filter( 'wproadie_divi_available', '__return_false', 99 );
		}
		wproadie_assert( in_array( 'wproadie/divi-get-page', ( new Registry() )->enabled_names(), true ) );
		wproadie_assert_same( '', ( new Registry() )->find( 'wproadie/divi-get-page' )->unavailable_reason() );
	}
);

wproadie_test(
	'divi modes: production allows edits, read-only only reads',
	function () {
		$id = wproadie_divi_fixture( WPROADIE_DIVI4_PAGE );
		try {
			Settings::set( 'mode', Mode::PRODUCTION );
			$names = ( new Registry() )->enabled_names();
			wproadie_assert( in_array( 'wproadie/divi-update-module', $names, true ), 'Edits in production.' );
			wproadie_assert( in_array( 'wproadie/divi-clear-cache', $names, true ) );

			Settings::set( 'mode', Mode::READ_ONLY );
			$names = ( new Registry() )->enabled_names();
			foreach ( array( 'divi-list-pages', 'divi-get-page', 'divi-get-module', 'divi-list-layouts', 'divi-list-theme-builder' ) as $read ) {
				wproadie_assert( in_array( 'wproadie/' . $read, $names, true ), "$read in read-only" );
			}
			foreach ( array( 'divi-update-module', 'divi-add-module', 'divi-remove-module', 'divi-move-module', 'divi-duplicate-module', 'divi-replace-text', 'divi-insert-layout', 'divi-clear-cache' ) as $write ) {
				wproadie_assert( ! in_array( 'wproadie/' . $write, $names, true ), "$write hidden in read-only" );
			}
			wproadie_ok( wproadie_call( 'divi-get-page', array( 'id' => $id ) ) );
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0.0.0.0',
						'set'  => array( 'admin_label' => 'x' ),
					)
				),
				'ability_invalid_permissions'
			);
		} finally {
			Settings::set( 'mode', '' );
			wproadie_divi_cleanup( array( $id ) );
		}
	}
);

wproadie_test(
	'divi 4 parser round-trips nasty content byte for byte',
	function () {
		$fixtures = array(
			WPROADIE_DIVI4_PAGE,
			str_replace( "\n", "\r\n", WPROADIE_DIVI4_PAGE ),
			'',
			'plain text, no shortcodes [not one] ] [',
			'[et_pb_section][et_pb_row][et_pb_column type="4_4" /][/et_pb_row][/et_pb_section]',
			'[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_image src="x.jpg"][et_pb_text]unclosed image before[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
			'[[et_pb_text]] escaped [/et_pb_section] stray closer [et_pb_text a=1 b=\'2\' "positional" c="%22%91%93%92"]x[/et_pb_text]',
			"[et_pb_section]\n\t[et_pb_row]\n\t\t[et_pb_column type=\"4_4\"]\n\t\t\t[et_pb_text]multi\nline[/et_pb_text]\n\t\t[/et_pb_column]\n\t[/et_pb_row]\n[/et_pb_section]\n",
			'[et_pb_section][et_pb_row][et_pb_column][et_pb_text]<!-- wp:paragraph --><p>Block inside text</p><!-- /wp:paragraph -->[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
		);
		foreach ( $fixtures as $i => $content ) {
			$doc = new Document( $content, 4 );
			wproadie_assert_same( $content, $doc->to_string(), "Fixture $i round-trips." );
		}

		$doc   = new Document( WPROADIE_DIVI4_PAGE, 4 );
		$paths = array();
		foreach ( $doc->all() as $entry ) {
			$paths[ $entry['path'] ] = $entry['node']->name;
		}
		wproadie_assert_same( 'et_pb_text', $paths['0.0.0.0'] );
		wproadie_assert_same( 'et_pb_button', $paths['0.0.0.1'] );
		wproadie_assert_same( 'et_pb_image', $paths['0.0.1.0'] );
		wproadie_assert_same( 'et_pb_code', $paths['0.0.1.1'] );
		wproadie_assert_same( 'et_pb_column_inner', $paths['1.1.0.0'] );
		wproadie_assert_same( 'et_pb_tab', $paths['1.1.0.0.0.1'], 'Tabs are children of the Tabs module.' );
		wproadie_assert_same( 'et_pb_fullwidth_header', $paths['2.0'] );
		wproadie_assert( ! isset( $paths['0.0.0.0.0'] ), 'A shortcode inside a Text module stays text.' );
		wproadie_assert( ! isset( $paths['0.0.1.1.0'] ), 'Brackets inside a Code module stay text.' );
		wproadie_assert_same( 12, $doc->module_count() );
	}
);

wproadie_test(
	'divi 5 parser round-trips and matches parse_blocks',
	function () {
		$fixtures = array(
			WPROADIE_DIVI5_PAGE,
			str_replace( "\n", "\r\n", WPROADIE_DIVI5_PAGE ),
			'<!-- wp:divi/placeholder /-->',
			"<p>freeform</p>\n<!-- wp:paragraph --><p>core</p><!-- /wp:paragraph -->\n<!-- wp:divi/text {\"bad json\" /-->",
			'<!-- wp:divi/section --><!-- wp:divi/row -->unclosed',
			'<!-- /wp:divi/section --> closer first',
			'<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"}} --\u003e \u002d\u002d tricky"}}}} /-->',
		);
		foreach ( $fixtures as $i => $content ) {
			$doc = new Document( $content, 5 );
			wproadie_assert_same( $content, $doc->to_string(), "Fixture $i round-trips." );
		}

		// Same blocks and attributes as WordPress's own parser.
		$flatten = static function ( array $blocks ) use ( &$flatten ): array {
			$out = array();
			foreach ( $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$out[] = array( $block['blockName'], $block['attrs'] );
				$out   = array_merge( $out, $flatten( $block['innerBlocks'] ) );
			}
			return $out;
		};
		$mine    = array();
		$doc     = new Document( WPROADIE_DIVI5_PAGE, 5 );
		$stack   = array( $doc->root );
		$walk    = static function ( WPRoadie\Abilities\Divi\Node $node ) use ( &$walk, &$mine ) {
			foreach ( $node->children() as $child ) {
				$mine[] = array( $child->name, json_decode( (string) wp_json_encode( $child->attrs ? $child->attrs : new stdClass() ), true ) );
				$walk( $child );
			}
		};
		$walk( $doc->root );
		unset( $stack );
		wproadie_assert_same( $flatten( parse_blocks( WPROADIE_DIVI5_PAGE ) ), $mine, 'Same blocks as parse_blocks().' );

		$paths = array();
		foreach ( $doc->all() as $entry ) {
			$paths[ $entry['path'] ] = $entry['node']->name;
		}
		wproadie_assert_same( 'divi/section', $paths['0'], 'The placeholder is not counted.' );
		wproadie_assert_same( 'divi/text', $paths['0.0.0.1'] );
		wproadie_assert_same( 'divi/image', $paths['0.0.1.1'] );
		wproadie_assert_same( 'divi/blurb', $paths['1.0.0.0.0'] );
		wproadie_assert_same( 'divi/global-layout', $paths['2'] );
	}
);

wproadie_test(
	'divi-list-pages finds Divi 4 and 5 pages, not plain ones',
	function () {
		$pages = wproadie_divi_pages();
		$plain = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'WPRoadie Divi fixture plain',
				'post_content' => '[et_pb_section]builder off[/et_pb_section]',
			)
		);
		try {
			$result = wproadie_ok(
				wproadie_call(
					'divi-list-pages',
					array(
						'search'   => 'WPRoadie Divi fixture',
						'per_page' => 50,
					)
				)
			);
			$by_id  = array_column( $result['items'], null, 'id' );
			wproadie_assert_same( 4, $by_id[ $pages['d4'] ]['format'] );
			wproadie_assert_same( 5, $by_id[ $pages['d5'] ]['format'] );
			wproadie_assert_same( 12, $by_id[ $pages['d4'] ]['modules'] );
			wproadie_assert( ! isset( $by_id[ $plain ] ), 'Builder-off page not listed.' );
			wproadie_assert( ! isset( $by_id[ $pages['layout'] ] ), 'Library items need post_type et_pb_layout.' );
			wproadie_assert( ! empty( $by_id[ $pages['d4'] ]['link'] ) );

			$layouts = wproadie_ok( wproadie_call( 'divi-list-pages', array( 'post_type' => 'et_pb_layout' ) ) );
			wproadie_assert( in_array( $pages['layout'], array_column( $layouts['items'], 'id' ), true ) );
			wproadie_assert_error( wproadie_call( 'divi-list-pages', array( 'post_type' => 'attachment' ) ), 'wproadie_divi_wrong_type' );
		} finally {
			wproadie_divi_cleanup( array_merge( array_values( $pages ), array( $plain ) ) );
		}
	}
);

wproadie_test(
	'divi-get-page outlines both formats with paths, labels and summaries',
	function () {
		$pages = wproadie_divi_pages();
		try {
			$d4    = wproadie_ok( wproadie_call( 'divi-get-page', array( 'id' => $pages['d4'] ) ) );
			$items = array_column( $d4['items'], null, 'path' );
			wproadie_assert_same( 4, $d4['format'] );
			wproadie_assert_same( '4.27.4', $d4['builder_version'] );
			wproadie_assert_same( 'Hero', $items['0']['label'] );
			wproadie_assert_same( 'Text', $items['0.0.0.0']['name'] );
			wproadie_assert_same( 'Intro', $items['0.0.0.0']['label'] );
			wproadie_assert( false !== strpos( $items['0.0.0.0']['summary'], 'Hello "world" & Acme link' ), 'Text summary: ' . $items['0.0.0.0']['summary'] );
			wproadie_assert( false !== strpos( $items['0.0.0.1']['summary'], 'button_text: Say "hi" [now]' ), 'Escapes decoded: ' . $items['0.0.0.1']['summary'] );
			wproadie_assert( false !== strpos( $items['0.0.0.1']['summary'], 'button_url: https://acme.test/a?x=1&y=2' ) );
			wproadie_assert_same( 'cta-main', $items['0.0.0.1']['css_id'] );
			wproadie_assert_same( '1_2', $items['0.0']['width'] ?? $items['0.0.0']['width'] );
			wproadie_assert( false !== strpos( $items['0.0.1.0']['summary'], 'alt: Acme image' ) );
			wproadie_assert_same( 'Call To Action', Document::type_name( 'et_pb_cta' ) );
			wproadie_assert_same( 'Fullwidth Header', $items['2.0']['name'] );
			wproadie_assert_same( $pages['layout'], $items['3']['global_layout'] );
			wproadie_assert_same( $pages['layout'], $items['3.0.0.0']['global_layout'], 'Inside a global section.' );

			$d5    = wproadie_ok( wproadie_call( 'divi-get-page', array( 'id' => $pages['d5'] ) ) );
			$items = array_column( $d5['items'], null, 'path' );
			wproadie_assert_same( 5, $d5['format'] );
			wproadie_assert_same( '5.11.1', $d5['builder_version'] );
			wproadie_assert_same( 'Heading', $items['0.0.0.0']['name'] );
			wproadie_assert( false !== strpos( $items['0.0.0.0']['summary'], 'Café “Acme” / slash' ), 'Unicode decoded: ' . $items['0.0.0.0']['summary'] );
			wproadie_assert_same( 'intro-text', $items['0.0.0.1']['css_id'] );
			wproadie_assert( false !== strpos( $items['0.0.1.0']['summary'], 'button: Get Started -> https://acme.test/start?a=1&b=2' ), $items['0.0.1.0']['summary'] );
			wproadie_assert_same( '12_24', $items['0.0.0']['width'] );
			wproadie_assert_same( $pages['layout'], $items['2']['global_layout'] );

			$top = wproadie_ok(
				wproadie_call(
					'divi-get-page',
					array(
						'id'    => $pages['d4'],
						'depth' => 1,
					)
				)
			);
			wproadie_assert_same( array( '0', '1', '2', '3' ), array_column( $top['items'], 'path' ) );
			wproadie_assert_same( 1, $top['items'][0]['children'] );

			$sub = wproadie_ok(
				wproadie_call(
					'divi-get-page',
					array(
						'id'   => $pages['d4'],
						'path' => '1.1',
					)
				)
			);
			wproadie_assert_same( '1.1', $sub['items'][0]['path'] );
			wproadie_assert( in_array( 'et_pb_tab', array_column( $sub['items'], 'type' ), true ) );
			wproadie_assert_same( 7, count( $sub['items'] ) );

			$limited = wproadie_ok(
				wproadie_call(
					'divi-get-page',
					array(
						'id'    => $pages['d4'],
						'limit' => 3,
					)
				)
			);
			wproadie_assert_same( 3, count( $limited['items'] ) );
			wproadie_assert( ! empty( $limited['truncated'] ) );
			wproadie_assert_error( wproadie_call( 'divi-get-page', array( 'id' => 99999999 ) ), 'wproadie_divi_not_found' );
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

wproadie_test(
	'divi tools refuse non-Divi posts and respect edit_post',
	function () {
		$plain = (int) wp_insert_post(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Not Divi',
				'post_content' => '<p>Hello</p>',
			)
		);
		$id    = wproadie_divi_fixture( WPROADIE_DIVI4_PAGE );
		try {
			wproadie_assert_error( wproadie_call( 'divi-get-page', array( 'id' => $plain ) ), 'wproadie_divi_not_divi' );
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'      => $plain,
						'path'    => '0',
						'content' => 'x',
					)
				),
				'wproadie_divi_not_divi'
			);
			wproadie_content_deny_divi(
				$id,
				function () use ( $id ) {
					wproadie_assert_error( wproadie_call( 'divi-get-page', array( 'id' => $id ) ), 'ability_invalid_permissions' );
					wproadie_assert_error(
						wproadie_call(
							'divi-update-module',
							array(
								'id'      => $id,
								'path'    => '0.0.0.0',
								'content' => 'x',
							)
						),
						'ability_invalid_permissions'
					);
					$list = wproadie_ok( wproadie_call( 'divi-list-pages', array( 'per_page' => 100 ) ) );
					wproadie_assert( ! in_array( $id, array_column( $list['items'], 'id' ), true ), 'Hidden from the list.' );
				}
			);
			wproadie_assert_same( WPROADIE_DIVI4_PAGE, wproadie_divi_content( $id ) );
		} finally {
			wproadie_divi_cleanup( array( $plain, $id ) );
		}
	}
);

/**
 * Runs $fn with edit_post denied for one post.
 */
function wproadie_content_deny_divi( int $post_id, callable $callback ): void {
	$filter = static function ( $caps, $cap, $user_id, $args ) use ( $post_id ) {
		if ( 'edit_post' === $cap && isset( $args[0] ) && (int) $args[0] === $post_id ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	};
	add_filter( 'map_meta_cap', $filter, 10, 4 );
	try {
		$callback();
	} finally {
		remove_filter( 'map_meta_cap', $filter, 10 );
	}
}

wproadie_test(
	'divi-get-module reads attributes by path, label and CSS ID',
	function () {
		$pages = wproadie_divi_pages();
		try {
			$button = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'     => $pages['d4'],
						'css_id' => 'cta-main',
						'raw'    => true,
					)
				)
			);
			wproadie_assert_same( '0.0.0.1', $button['path'] );
			wproadie_assert_same( 'Say "hi" [now]', $button['attributes']['button_text'] );
			wproadie_assert_same( 'https://acme.test/a?x=1&y=2', $button['attributes']['button_url'] );
			wproadie_assert( 0 === strpos( $button['raw'], '[et_pb_button button_text="Say %22hi%22' ) );

			$text = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'    => $pages['d4'],
						'label' => 'intro',
					)
				)
			);
			wproadie_assert( 0 === strpos( $text['content'], '<p>Hello "world"' ) );
			wproadie_assert_same( 'content: "hi";||color: red;', $text['attributes']['custom_css_main_element'] );

			$tabs = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'   => $pages['d4'],
						'path' => '1.1.0.0.0',
					)
				)
			);
			wproadie_assert_same( array( 'Tab', 'Tab' ), array_column( $tabs['children'], 'name' ) );

			$d5 = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'    => $pages['d5'],
						'label' => 'Intro',
					)
				)
			);
			wproadie_assert_same( '<p>Hello <strong class="Acme">Acme</strong> &amp; [contact-form-7 id="12"]</p>', $d5['attributes']->content->innerContent->desktop->value );
			wproadie_assert( $d5['attributes']->empty instanceof stdClass, 'Empty objects stay objects.' );

			$global = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'   => $pages['d4'],
						'path' => '3.0.0.0',
					)
				)
			);
			wproadie_assert( ! empty( $global['global_note'] ) );

			$dup = wproadie_divi_fixture( '[et_pb_section][et_pb_row][et_pb_column][et_pb_text admin_label="Same"]a[/et_pb_text][et_pb_text admin_label="same"]b[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]' );
			wproadie_assert_error(
				wproadie_call(
					'divi-get-module',
					array(
						'id'    => $dup,
						'label' => 'Same',
					)
				),
				'wproadie_divi_module_ambiguous'
			);
			wp_delete_post( $dup, true );
			wproadie_assert_error(
				wproadie_call(
					'divi-get-module',
					array(
						'id'   => $pages['d4'],
						'path' => '9.9',
					)
				),
				'wproadie_divi_module_not_found'
			);
			wproadie_assert_error( wproadie_call( 'divi-get-module', array( 'id' => $pages['d4'] ) ), 'wproadie_divi_missing_module' );
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

wproadie_test(
	'divi-update-module (Divi 4) changes only the target, encodes, and undo restores exactly',
	function () {
		$pages                            = wproadie_divi_pages();
		$id                               = $pages['d4'];
		$before                           = wproadie_divi_content( $id );
		$GLOBALS['wproadie_divi_cleared'] = array();
		try {
			$result = wproadie_ok(
				wproadie_call(
					'divi-update-module',
					array(
						'id'     => $id,
						'css_id' => 'cta-main',
						'set'    => array(
							'button_text' => 'Book "now" [today] \\ ok',
							'admin_label' => 'Hero CTA',
							'new_flag'    => true,
						),
						'remove' => array( 'url_new_window', 'not_there' ),
					)
				)
			);
			wproadie_assert( ! empty( $result['undo_id'] ), 'undo_id' );
			wproadie_assert( ! empty( $result['revision_id'] ), 'revision saved' );
			wproadie_assert_same( array( 'not_there' ), $result['not_found'] );
			wproadie_assert_same( 'Hero CTA', $result['module']['label'] );
			wproadie_assert( in_array( array( $id, 'all' ), $GLOBALS['wproadie_divi_cleared'], true ), 'Divi cache cleared for the page.' );

			$after = wproadie_divi_content( $id );
			wproadie_divi_assert_only(
				$before,
				$after,
				'[et_pb_button button_text="Say %22hi%22 %91now%93" button_url="https://acme.test/a?x=1&y=2" url_new_window="on" module_id="cta-main" _builder_version="4.27.4"]',
				'[et_pb_button button_text="Book %22now%22 %91today%93 %92 ok" button_url="https://acme.test/a?x=1&y=2" module_id="cta-main" _builder_version="4.27.4" admin_label="Hero CTA" new_flag="on"]'
			);

			// Content of a Text module, with a third-party shortcode kept.
			wproadie_ok(
				wproadie_call(
					'divi-update-module',
					array(
						'id'      => $id,
						'path'    => '0.0.0.0',
						'content' => '<p>New intro [contact-form-7 id="12"]</p>',
					)
				)
			);
			$text = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'   => $id,
						'path' => '0.0.0.0',
					)
				)
			);
			wproadie_assert_same( '<p>New intro [contact-form-7 id="12"]</p>', $text['content'] );

			// A self-closing image stays self-closing; single quotes stay.
			wproadie_ok(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0.0.1.0',
						'set'  => array( 'alt' => 'New alt' ),
					)
				)
			);
			wproadie_assert( false !== strpos( wproadie_divi_content( $id ), '[et_pb_image src="https://acme.test/img.jpg" alt="New alt" title_text=\'single quoted\' empty="" /]' ), 'Self-closing kept, single quotes kept.' );

			// Undo the first change only after reverting the later ones.
			$log = wproadie_divi_last_undo_ids( 3 );
			foreach ( $log as $undo_id ) {
				wproadie_ok( wproadie_call( 'undo-change', array( 'undo_id' => $undo_id ) ) );
			}
			wproadie_assert_same( $before, wproadie_divi_content( $id ), 'Undo restores byte for byte.' );

			// Errors.
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '3.0.0.0',
						'set'  => array( 'admin_label' => 'x' ),
					)
				),
				'wproadie_divi_global_module'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0',
						'set'  => array( 'global_module' => '5' ),
					)
				),
				'wproadie_divi_protected_attribute'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0',
						'set'  => array( 'a.b' => 'x' ),
					)
				),
				'wproadie_divi_bad_attribute'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'      => $id,
						'path'    => '0.0',
						'content' => 'x',
					)
				),
				'wproadie_divi_container_content'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0',
					)
				),
				'wproadie_divi_nothing_to_update'
			);
			wproadie_assert_same( $before, wproadie_divi_content( $id ), 'Failed calls change nothing.' );
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

/**
 * Newest undo IDs not yet undone, from the audit log.
 *
 * @param int $count How many.
 * @return int[]
 */
function wproadie_divi_last_undo_ids( int $count ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'wproadie_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test.
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE undo_data IS NOT NULL AND undo_data <> '' AND undone_at IS NULL AND status = 'success' ORDER BY id DESC LIMIT %d", $count ) ) );
}

wproadie_test(
	'divi-update-module (Divi 5) rewrites only the target block and undo restores exactly',
	function () {
		$pages  = wproadie_divi_pages();
		$id     = $pages['d5'];
		$before = wproadie_divi_content( $id );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'divi-update-module',
					array(
						'id'      => $id,
						'label'   => 'Intro',
						'content' => '<p>New <em>intro</em> & “more”</p>',
						'set'     => array(
							'module.meta.adminLabel.desktop.value' => 'Intro 2',
							'content.decoration.bodyFont.body.font.desktop.value' => array( 'color' => '#fff' ),
							'extra.obj' => array(),
						),
						'remove'  => array( 'content.innerContent.tablet' ),
					)
				)
			);
			wproadie_assert( ! empty( $result['undo_id'] ) );
			$after = wproadie_divi_content( $id );

			$old_line = '';
			foreach ( explode( "\n", $before ) as $line ) {
				if ( false !== strpos( $line, 'wp:divi/text' ) ) {
					$old_line = $line;
				}
			}
			$new_line = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eNew \u003cem\u003eintro\u003c/em\u003e \u0026 “more”\u003c/p\u003e"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#fff"}}}}}}},"module":{"meta":{"adminLabel":{"desktop":{"value":"Intro 2"}}},"advanced":{"htmlAttributes":{"desktop":{"value":{"id":"intro-text","class":"a b"}}}}},"empty":{},"list":[],"builderVersion":"5.11.1","extra":{"obj":{}}} /-->';
			wproadie_divi_assert_only( $before, $after, $old_line, $new_line );

			// Button text through a dotted path.
			wproadie_ok(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0.0.1.0',
						'set'  => array( 'button.innerContent.desktop.value.text' => 'Start now' ),
					)
				)
			);
			$button = wproadie_ok(
				wproadie_call(
					'divi-get-module',
					array(
						'id'   => $id,
						'path' => '0.0.1.0',
					)
				)
			);
			wproadie_assert_same( 'Start now', $button['attributes']->button->innerContent->desktop->value->text );
			wproadie_assert_same( 'https://acme.test/start?a=1&b=2', $button['attributes']->button->innerContent->desktop->value->linkUrl );

			foreach ( wproadie_divi_last_undo_ids( 2 ) as $undo_id ) {
				wproadie_ok( Undo::revert( $undo_id ) );
			}
			wproadie_assert_same( $before, wproadie_divi_content( $id ), 'Undo restores byte for byte.' );

			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '2',
						'set'  => array( 'globalModule' => '1' ),
					)
				),
				'wproadie_divi_global_module'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0.0.0.0',
						'set'  => array( 'title.innerContent.desktop.value.x' => 'y' ),
					)
				),
				'wproadie_divi_bad_path'
			);
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

wproadie_test(
	'divi edits filter HTML for users without unfiltered_html',
	function () {
		$d4   = wproadie_divi_fixture( '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Old</p>[/et_pb_text][et_pb_button button_text="Go"][/et_pb_button][/et_pb_column][/et_pb_row][/et_pb_section]' );
		$d5   = wproadie_divi_fixture( '<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eOld\u003c/p\u003e"}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->' );
		$ifr  = wproadie_divi_fixture( '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Old</p>[/et_pb_text][et_pb_code]<iframe src="https://maps.test/embed"></iframe><script>track()</script>[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]' );
		$ifr5 = wproadie_divi_fixture( str_replace( 'GLOBALID', '1', WPROADIE_DIVI5_PAGE ) . '<!-- wp:divi/code {"content":{"innerContent":{"desktop":{"value":"\u003cscript\u003ealert(1)\u003c/script\u003e"}}}} /-->' );
		try {
			wproadie_divi_without_unfiltered(
				function () use ( $d4, $d5, $ifr, $ifr5 ) {
					$result = wproadie_ok(
						wproadie_call(
							'divi-update-module',
							array(
								'id'      => $d4,
								'path'    => '0.0.0.0',
								'content' => '<p onclick="x()">Hi<script>alert(1)</script></p>',
								'set'     => array( 'admin_label' => '<img src=x onerror=alert(1)>Label' ),
							)
						)
					);
					wproadie_assert( ! empty( $result['sanitized'] ) );
					$content = wproadie_divi_content( $d4 );
					wproadie_assert( false === strpos( $content, '<script' ) && false === strpos( $content, 'onclick' ) && false === strpos( $content, 'onerror' ), 'Scripts removed: ' . $content );
					wproadie_assert( false !== strpos( $content, '<p>Hi' ), 'Allowed HTML kept.' );

					wproadie_ok(
						wproadie_call(
							'divi-update-module',
							array(
								'id'      => $d5,
								'path'    => '0.0.0.0',
								'content' => '<p>Ok</p><script>alert(1)</script>',
							)
						)
					);
					wproadie_assert( false === strpos( wproadie_divi_content( $d5 ), 'script' ), 'Divi 5 scripts removed.' );

					wproadie_ok(
						wproadie_call(
							'divi-add-module',
							array(
								'id'        => $d4,
								'container' => '0.0.0',
								'type'      => 'text',
								'content'   => '<script>alert(2)</script><p>Added</p>',
							)
						)
					);
					wproadie_assert( false === strpos( wproadie_divi_content( $d4 ), '<script' ), 'Added content filtered.' );
					wproadie_assert( false !== strpos( wproadie_divi_content( $d4 ), '<p>Added</p>' ) );

					// Another module holds HTML this user may not save: refuse.
					$before = wproadie_divi_content( $ifr );
					wproadie_assert_error(
						wproadie_call(
							'divi-update-module',
							array(
								'id'      => $ifr,
								'path'    => '0.0.0.0',
								'content' => '<p>New</p>',
							)
						),
						'wproadie_divi_save_would_alter'
					);
					wproadie_assert_same( $before, wproadie_divi_content( $ifr ), 'Embed page untouched.' );
					$before5 = wproadie_divi_content( $ifr5 );
					wproadie_assert_error(
						wproadie_call(
							'divi-update-module',
							array(
								'id'      => $ifr5,
								'label'   => 'Intro',
								'content' => '<p>New</p>',
							)
						),
						'wproadie_divi_save_would_alter'
					);
					wproadie_assert_same( $before5, wproadie_divi_content( $ifr5 ) );

					wproadie_assert_error(
						wproadie_call(
							'divi-replace-text',
							array(
								'id'      => $d4,
								'search'  => 'Hi',
								'replace' => '<script>x</script>',
								'dry_run' => false,
							)
						),
						'wproadie_divi_replace_not_allowed'
					);
				}
			);
		} finally {
			wproadie_divi_cleanup( array( $d4, $d5, $ifr, $ifr5 ) );
		}
	}
);

wproadie_test(
	'divi-add-module adds modules, sections and rows in both formats',
	function () {
		$pages = wproadie_divi_pages();
		try {
			$id     = $pages['d4'];
			$before = wproadie_divi_content( $id );
			$added  = wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'         => $id,
						'container'  => '0.0.0',
						'index'      => 1,
						'type'       => 'button',
						'attributes' => array(
							'button_text' => 'Call "us"',
							'button_url'  => 'tel:123',
						),
					)
				)
			);
			wproadie_assert_same( '0.0.0.1', $added['module']['path'] );
			wproadie_assert_same( 'et_pb_button', $added['module']['type'] );
			wproadie_divi_assert_only(
				$before,
				wproadie_divi_content( $id ),
				'[/et_pb_text][et_pb_button button_text="Say',
				'[/et_pb_text][et_pb_button _builder_version="4.27.4" button_text="Call %22us%22" button_url="tel:123"][/et_pb_button][et_pb_button button_text="Say'
			);

			$section = wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'         => $id,
						'type'       => 'section',
						'index'      => 0,
						'columns'    => array( '1_3', '2_3' ),
						'attributes' => array( 'admin_label' => 'New top' ),
					)
				)
			);
			wproadie_assert_same( '0', $section['module']['path'] );
			wproadie_assert_same( array( '0.0', '0.0.0', '0.0.1' ), array_column( $section['children'], 'path' ) );
			wproadie_assert( 0 === strpos( wproadie_divi_content( $id ), '[et_pb_section _builder_version="4.27.4" admin_label="New top"][et_pb_row _builder_version="4.27.4" column_structure="1_3,2_3"][et_pb_column _builder_version="4.27.4" type="1_3"][/et_pb_column]' ) );

			// A row in a specialty column becomes et_pb_row_inner.
			$inner = wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'        => $id,
						'container' => '2.1',
						'type'      => 'row',
						'columns'   => array( '1_2', '1_2' ),
					)
				)
			);
			wproadie_assert_same( 'et_pb_row_inner', $inner['module']['type'] );
			wproadie_assert_same( 'et_pb_column_inner', $inner['children'][0]['type'] );

			wproadie_assert_error(
				wproadie_call(
					'divi-add-module',
					array(
						'id'   => $id,
						'type' => 'text',
					)
				),
				'wproadie_divi_bad_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-add-module',
					array(
						'id'        => $id,
						'container' => '0.0.0',
						'type'      => 'section',
					)
				),
				'wproadie_divi_bad_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-add-module',
					array(
						'id'        => $id,
						'container' => '4.0.0',
						'type'      => 'text',
					)
				),
				'wproadie_divi_global_module'
			);

			// Divi 5.
			$id5     = $pages['d5'];
			$before5 = wproadie_divi_content( $id5 );
			$button  = wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'         => $id5,
						'container'  => '0.0.1',
						'index'      => 0,
						'type'       => 'button',
						'attributes' => array(
							'button.innerContent.desktop.value' => array(
								'text'    => 'Call',
								'linkUrl' => 'tel:1',
							),
							'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Call btn' ) ) ) ),
						),
					)
				)
			);
			wproadie_assert_same( '0.0.1.0', $button['module']['path'] );
			wproadie_assert_same( 'Call btn', $button['module']['label'] );
			wproadie_divi_assert_only(
				$before5,
				wproadie_divi_content( $id5 ),
				"<!-- wp:divi/column {\"builderVersion\":\"5.11.1\"} -->\n<!-- wp:divi/button",
				"<!-- wp:divi/column {\"builderVersion\":\"5.11.1\"} -->\n<!-- wp:divi/button {\"button\":{\"innerContent\":{\"desktop\":{\"value\":{\"text\":\"Call\",\"linkUrl\":\"tel:1\"}}}},\"module\":{\"meta\":{\"adminLabel\":{\"desktop\":{\"value\":\"Call btn\"}}}},\"builderVersion\":\"5.11.1\"} /-->\n<!-- wp:divi/button"
			);

			$text = wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'        => $id5,
						'container' => '1.0.0.0',
						'type'      => 'divi/text',
						'content'   => '<p>In group</p>',
					)
				)
			);
			wproadie_assert_same( '1.0.0.0.1', $text['module']['path'] );

			$section5 = wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'      => $id5,
						'type'    => 'section',
						'columns' => array( '1_2', '1_2' ),
					)
				)
			);
			wproadie_assert_same( '3', $section5['module']['path'], 'After the global layout.' );
			wproadie_assert_same( '1_2', $section5['children'][1]['width'] );
			$parsed = parse_blocks( wproadie_divi_content( $id5 ) );
			wproadie_assert_same( 'divi/placeholder', $parsed[0]['blockName'] );
			wproadie_assert_same( 'divi/section', end( $parsed[0]['innerBlocks'] )['blockName'], 'New section inside the placeholder.' );
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

wproadie_test(
	'divi remove, move and duplicate keep everything else intact; undo restores',
	function () {
		$pages = wproadie_divi_pages();
		try {
			foreach ( array( 'd4', 'd5' ) as $format ) {
				$id     = $pages[ $format ];
				$before = wproadie_divi_content( $id );

				$dup = wproadie_ok(
					wproadie_call(
						'divi-duplicate-module',
						array(
							'id'   => $id,
							'path' => '0.0.1.0',
						)
					)
				);
				wproadie_assert_same( '0.0.1.1', $dup['copy']['path'] );
				$doc   = new Document( wproadie_divi_content( $id ), 'd4' === $format ? 4 : 5 );
				$first = $doc->find( array( 'path' => '0.0.1.0' ) );
				$copy  = $doc->find( array( 'path' => '0.0.1.1' ) );
				wproadie_assert_same( $first['node']->to_string(), $copy['node']->to_string(), 'Exact copy.' );

				$moved = wproadie_ok(
					wproadie_call(
						'divi-move-module',
						array(
							'id'        => $id,
							'path'      => '0.0.1.1',
							'container' => '0.0.0',
							'index'     => 0,
						)
					)
				);
				wproadie_assert_same( '0.0.0.0', $moved['module']['path'] );

				$removed = wproadie_ok(
					wproadie_call(
						'divi-remove-module',
						array(
							'id'   => $id,
							'path' => '0.0.0.0',
						)
					)
				);
				wproadie_assert_same( $first['node']->name, $removed['removed']['type'] );
				wproadie_assert_same( $before, wproadie_divi_content( $id ), "$format: duplicate + move + remove of the copy is a no-op." );

				// Remove a whole section, then undo.
				$gone = wproadie_ok(
					wproadie_call(
						'divi-remove-module',
						array(
							'id'   => $id,
							'path' => '1',
						)
					)
				);
				wproadie_assert( $gone['removed_inside'] > 0 );
				wproadie_assert( wproadie_divi_content( $id ) !== $before );
				wproadie_ok( wproadie_call( 'undo-change', array( 'undo_id' => $gone['undo_id'] ) ) );
				wproadie_assert_same( $before, wproadie_divi_content( $id ), "$format: undo restores the section." );

				// The global element as a whole may be removed; not its inside.
				$global_path = 'd4' === $format ? '3' : '2';
				if ( 'd4' === $format ) {
					wproadie_assert_error(
						wproadie_call(
							'divi-remove-module',
							array(
								'id'   => $id,
								'path' => '3.0.0.0',
							)
						),
						'wproadie_divi_global_module'
					);
				}
				$removed = wproadie_ok(
					wproadie_call(
						'divi-remove-module',
						array(
							'id'   => $id,
							'path' => $global_path,
						)
					)
				);
				wproadie_ok( Undo::revert( $removed['undo_id'] ) );
				wproadie_assert_same( $before, wproadie_divi_content( $id ) );

				wproadie_assert_error(
					wproadie_call(
						'divi-move-module',
						array(
							'id'        => $id,
							'path'      => '0',
							'container' => '0.0.0',
						)
					),
					'wproadie_divi_bad_move'
				);
				wproadie_assert_error(
					wproadie_call(
						'divi-move-module',
						array(
							'id'        => $id,
							'path'      => '0.0.0.0',
							'container' => '',
						)
					),
					'wproadie_divi_bad_placement'
				);
			}
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

wproadie_test(
	'divi-replace-text dry run, apply, scopes, all pages and undo',
	function () {
		$pages = wproadie_divi_pages();
		try {
			$d4 = wproadie_divi_content( $pages['d4'] );
			$d5 = wproadie_divi_content( $pages['d5'] );

			$dry = wproadie_ok(
				wproadie_call(
					'divi-replace-text',
					array(
						'id'      => $pages['d4'],
						'search'  => 'Acme',
						'replace' => 'Globex',
					)
				)
			);
			wproadie_assert_same( true, $dry['dry_run'] );
			wproadie_assert_same( 3, $dry['matches'], 'Link text, contact-form title in text, image alt; not the class or URL.' );
			wproadie_assert_same( $d4, wproadie_divi_content( $pages['d4'] ), 'Dry run changes nothing.' );

			$done = wproadie_ok(
				wproadie_call(
					'divi-replace-text',
					array(
						'id'      => $pages['d4'],
						'search'  => 'Acme',
						'replace' => 'Globex',
						'dry_run' => false,
					)
				)
			);
			wproadie_assert( ! empty( $done['undo_id'] ) );
			$after = wproadie_divi_content( $pages['d4'] );
			wproadie_assert( false !== strpos( $after, 'class="Acme">Globex link</a>' ), 'Text replaced, tag attribute kept.' );
			wproadie_assert( false !== strpos( $after, 'https://acme.test/img.jpg" alt="Globex image"' ) );
			wproadie_ok( Undo::revert( $done['undo_id'] ) );
			wproadie_assert_same( $d4, wproadie_divi_content( $pages['d4'] ) );

			$urls = wproadie_ok(
				wproadie_call(
					'divi-replace-text',
					array(
						'id'             => $pages['d5'],
						'search'         => 'acme.test',
						'replace'        => 'globex.test',
						'scope'          => 'all',
						'case_sensitive' => false,
						'dry_run'        => false,
					)
				)
			);
			wproadie_assert_same( 2, $urls['matches'] );
			$after5 = wproadie_divi_content( $pages['d5'] );
			wproadie_assert( false !== strpos( $after5, 'https://globex.test/start?a=1\u0026b=2' ), 'URL in Divi 5 JSON replaced.' );
			wproadie_assert( false !== strpos( $after5, 'Caf\u00e9 \u201cAcme\u201d \/ slash' ), 'Untouched blocks keep their bytes.' );

			$all = wproadie_ok(
				wproadie_call(
					'divi-replace-text',
					array(
						'all_pages' => true,
						'search'    => 'Global text',
						'replace'   => 'Shared text',
					)
				)
			);
			$ids = array_column( $all['pages'], 'id' );
			wproadie_assert( in_array( $pages['layout'], $ids, true ), 'The library item is changed.' );
			wproadie_assert( ! in_array( $pages['d4'], $ids, true ), 'Pages using it are skipped.' );
			wproadie_assert( in_array( $pages['layout'], $all['skipped_global_layouts'], true ) );

			$applied = wproadie_ok(
				wproadie_call(
					'divi-replace-text',
					array(
						'all_pages' => true,
						'search'    => 'Tablet Acme',
						'replace'   => 'Tablet Globex',
						'dry_run'   => false,
					)
				)
			);
			wproadie_assert_same( array( $pages['d5'] ), array_column( $applied['pages'], 'id' ) );
			wproadie_ok( Undo::revert( $applied['undo_id'] ) );
			wproadie_ok( Undo::revert( $urls['undo_id'] ) );
			wproadie_assert_same( $d5, wproadie_divi_content( $pages['d5'] ) );

			wproadie_assert_error(
				wproadie_call(
					'divi-replace-text',
					array(
						'search'  => 'a',
						'replace' => 'b',
					)
				),
				'wproadie_divi_replace_target'
			);
		} finally {
			wproadie_divi_cleanup( $pages );
		}
	}
);

wproadie_test(
	'divi-list-layouts and divi-insert-layout',
	function () {
		$pages = wproadie_divi_pages();
		wp_set_object_terms( $pages['layout'], 'section', 'layout_type' );
		wp_set_object_terms( $pages['layout'], 'global', 'scope' );
		$row = (int) wp_insert_post(
			array(
				'post_type'    => 'et_pb_layout',
				'post_status'  => 'publish',
				'post_title'   => 'Two buttons row',
				'post_content' => '[et_pb_row][et_pb_column type="4_4"][et_pb_button button_text="One"][/et_pb_button][/et_pb_column][/et_pb_row]',
			)
		);
		wp_set_object_terms( $row, 'row', 'layout_type' );
		$row5 = (int) wp_insert_post(
			array(
				'post_type'    => 'et_pb_layout',
				'post_status'  => 'publish',
				'post_title'   => 'D5 section',
				'post_content' => '<!-- wp:divi/section {"builderVersion":"5.11.1"} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"Lib"}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
			)
		);
		wp_set_object_terms( $row5, 'section', 'layout_type' );
		wp_set_object_terms( $row5, 'global', 'scope' );
		try {
			$list  = wproadie_ok( wproadie_call( 'divi-list-layouts' ) );
			$by_id = array_column( $list['items'], null, 'id' );
			wproadie_assert_same( true, $by_id[ $pages['layout'] ]['global'] );
			wproadie_assert_same( 'row', $by_id[ $row ]['layout_type'] );
			wproadie_assert_same( 5, $by_id[ $row5 ]['format'] );
			$rows = wproadie_ok( wproadie_call( 'divi-list-layouts', array( 'layout_type' => 'row' ) ) );
			wproadie_assert_same( array( $row ), array_column( $rows['items'], 'id' ) );
			$globals = wproadie_ok( wproadie_call( 'divi-list-layouts', array( 'global' => true ) ) );
			wproadie_assert( ! in_array( $row, array_column( $globals['items'], 'id' ), true ) );

			// Non-global row into a section: an independent copy.
			$before   = wproadie_divi_content( $pages['d4'] );
			$inserted = wproadie_ok(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'        => $pages['d4'],
						'layout_id' => $row,
						'container' => '0',
						'index'     => 0,
					)
				)
			);
			wproadie_assert_same( '0.0', $inserted['inserted'][0]['path'] );
			wproadie_assert_same( false, $inserted['linked'] );
			wproadie_assert_same( 0, strpos( wproadie_divi_content( $pages['d4'] ), '[et_pb_section fb_built="1" admin_label="Hero" _builder_version="4.27.4" global_colors_info="{}"][et_pb_row][et_pb_column type="4_4"][et_pb_button button_text="One"]' ) );

			// Global section at the top: linked with global_module.
			$linked = wproadie_ok(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'        => $pages['d4'],
						'layout_id' => $pages['layout'],
					)
				)
			);
			wproadie_assert_same( true, $linked['linked'] );
			wproadie_assert_same( $pages['layout'], $linked['inserted'][0]['global_layout'] );
			wproadie_ok( Undo::revert( $linked['undo_id'] ) );
			wproadie_ok( Undo::revert( $inserted['undo_id'] ) );
			wproadie_assert_same( $before, wproadie_divi_content( $pages['d4'] ) );

			// Divi 5 global: a divi/global-layout reference.
			$five = wproadie_ok(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'        => $pages['d5'],
						'layout_id' => $row5,
						'index'     => 1,
					)
				)
			);
			wproadie_assert_same( 'divi/global-layout', $five['inserted'][0]['type'] );
			wproadie_assert_same( $row5, $five['inserted'][0]['global_layout'] );
			$copy = wproadie_ok(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'          => $pages['d5'],
						'layout_id'   => $row5,
						'link_global' => false,
					)
				)
			);
			wproadie_assert_same( 'divi/section', $copy['inserted'][0]['type'] );

			wproadie_assert_error(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'        => $pages['d5'],
						'layout_id' => $row,
					)
				),
				'wproadie_divi_format_mismatch'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'        => $pages['d4'],
						'layout_id' => $row,
					)
				),
				'wproadie_divi_bad_placement'
			);
			wproadie_assert_error(
				wproadie_call(
					'divi-insert-layout',
					array(
						'id'        => $pages['d4'],
						'layout_id' => $pages['d5'],
					)
				),
				'wproadie_divi_layout_not_found'
			);
		} finally {
			wproadie_divi_cleanup( array_merge( array_values( $pages ), array( $row, $row5 ) ) );
		}
	}
);

wproadie_test(
	'divi-list-theme-builder shows templates and their layouts',
	function () {
		$ids    = array();
		$header = (int) wp_insert_post(
			array(
				'post_type'    => 'et_header_layout',
				'post_status'  => 'publish',
				'post_title'   => 'Site header',
				'post_content' => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_menu menu_id="2"][/et_pb_menu][/et_pb_column][/et_pb_row][/et_pb_section]',
			)
		);
		$ids[]  = $header;
		$tpl    = (int) wp_insert_post(
			array(
				'post_type'   => 'et_template',
				'post_status' => 'publish',
				'post_title'  => 'Default Website Template',
			)
		);
		$ids[]  = $tpl;
		foreach (
			array(
				'_et_default'               => '1',
				'_et_enabled'               => '1',
				'_et_header_layout_id'      => (string) $header,
				'_et_header_layout_enabled' => '1',
				'_et_body_layout_id'        => '0',
				'_et_body_layout_enabled'   => '1',
				'_et_footer_layout_id'      => '0',
				'_et_footer_layout_enabled' => '0',
			) as $key => $value
		) {
			update_post_meta( $tpl, $key, $value );
		}
		add_post_meta( $tpl, '_et_use_on', 'homepage' );
		$builder = (int) wp_insert_post(
			array(
				'post_type'   => 'et_theme_builder',
				'post_status' => 'publish',
				'post_title'  => 'Theme Builder',
			)
		);
		$ids[]   = $builder;
		add_post_meta( $builder, '_et_template', $tpl );
		try {
			$result = wproadie_ok( wproadie_call( 'divi-list-theme-builder' ) );
			wproadie_assert_same( $builder, $result['theme_builder_id'] );
			$template = $result['templates'][0];
			wproadie_assert_same( true, $template['default'] );
			wproadie_assert_same( array( 'homepage' ), $template['use_on'] );
			wproadie_assert_same( $header, $template['header']['layout_id'] );
			wproadie_assert_same( 'Site header', $template['header']['title'] );
			wproadie_assert_same( 1, $template['header']['modules'] );
			wproadie_assert_same( false, $template['footer']['enabled'] );

			$outline = wproadie_ok( wproadie_call( 'divi-get-page', array( 'id' => $header ) ) );
			wproadie_assert_same( 'Menu', end( $outline['items'] )['name'], 'Theme Builder layouts open with divi-get-page.' );
		} finally {
			wproadie_divi_cleanup( $ids );
		}
	}
);

wproadie_test(
	'divi-clear-cache clears one page or the site; undo clears too',
	function () {
		$id                               = wproadie_divi_fixture( WPROADIE_DIVI4_PAGE );
		$GLOBALS['wproadie_divi_cleared'] = array();
		try {
			$one = wproadie_ok( wproadie_call( 'divi-clear-cache', array( 'id' => $id ) ) );
			wproadie_assert_same( array( 'divi_static_css' ), $one['cleared'] );
			$all = wproadie_ok( wproadie_call( 'divi-clear-cache' ) );
			wproadie_assert_same( 'all', $all['target'] );
			wproadie_assert_same(
				array(
					array( $id, 'all' ),
					array( 'all', 'all' ),
				),
				$GLOBALS['wproadie_divi_cleared']
			);

			$edit                             = wproadie_ok(
				wproadie_call(
					'divi-update-module',
					array(
						'id'   => $id,
						'path' => '0',
						'set'  => array( 'admin_label' => 'Changed' ),
					)
				)
			);
			$GLOBALS['wproadie_divi_cleared'] = array();
			wproadie_ok( Undo::revert( $edit['undo_id'] ) );
			wproadie_assert_same( array( array( $id, 'all' ) ), $GLOBALS['wproadie_divi_cleared'], 'Undo clears the restored page.' );
			wproadie_assert_same( WPROADIE_DIVI4_PAGE, wproadie_divi_content( $id ) );
			wproadie_assert_error( wproadie_call( 'divi-clear-cache', array( 'id' => 99999999 ) ), 'wproadie_divi_not_found' );
		} finally {
			wproadie_divi_cleanup( array( $id ) );
		}
	}
);

wproadie_test(
	'divi helpers: format detection, type names, value conversion',
	function () {
		$d5 = wproadie_divi_fixture( '<!-- wp:divi/placeholder /-->', array( 'meta' => array() ) );
		$e4 = wproadie_divi_fixture( '' );
		$e5 = wproadie_divi_fixture(
			'',
			array(
				'meta' => array(
					'_et_pb_use_builder' => 'on',
					'_et_pb_use_divi_5'  => 'on',
				),
			)
		);
		try {
			wproadie_assert_same( 5, Divi::format_of( get_post( $d5 ) ) );
			wproadie_assert_same( 4, Divi::format_of( get_post( $e4 ) ) );
			wproadie_assert_same( 5, Divi::format_of( get_post( $e5 ) ) );
			$empty = wproadie_ok( wproadie_call( 'divi-get-page', array( 'id' => $e4 ) ) );
			wproadie_assert_same( array(), $empty['items'] );

			// An empty Divi 4 builder page can get its first section.
			wproadie_ok(
				wproadie_call(
					'divi-add-module',
					array(
						'id'      => $e4,
						'type'    => 'section',
						'columns' => array( '4_4' ),
					)
				)
			);
			wproadie_assert_same( '[et_pb_section][et_pb_row column_structure="4_4"][et_pb_column type="4_4"][/et_pb_column][/et_pb_row][/et_pb_section]', wproadie_divi_content( $e4 ) );

			wproadie_assert_same( 'Text', Document::type_name( 'divi/text' ) );
			wproadie_assert_same( 'Person', Document::type_name( 'et_pb_team_member' ) );
			wproadie_assert_same(
				'{"a":{},"b":[1,2],"c":{"d":"x"}}',
				wp_json_encode(
					Blocks::to_json_value(
						array(
							'a' => array(),
							'b' => array( 1, 2 ),
							'c' => array( 'd' => 'x' ),
						)
					)
				)
			);
		} finally {
			wproadie_divi_cleanup( array( $d5, $e4, $e5 ) );
		}
	}
);

wproadie_test(
	'teardown: divi tools back to detecting Divi',
	function () {
		remove_filter( 'wproadie_divi_available', '__return_true' );
		wproadie_assert( ! in_array( 'wproadie/divi-get-page', ( new Registry() )->enabled_names(), true ), 'Later tests see this site without Divi.' );
	}
);
