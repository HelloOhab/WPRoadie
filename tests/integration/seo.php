<?php
/**
 * SEO (Rank Math) tools. Rank Math is not installed here: the tools are
 * switched on with the wproadie_seo_available filter and work on the
 * meta rows Rank Math reads, exactly as it stores them.
 *
 * @package WPRoadie
 */

use WPRoadie\Abilities\Seo\RankMath;
use WPRoadie\Log\Undo;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

add_filter( 'wproadie_seo_available', '__return_true' );
wproadie_test_enable_group( 'seo' );

wproadie_test(
	'seo-get reads stored and effective values and names the issues',
	function () {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Mentoring',
				'post_content' => '',
			)
		);
		try {
			$seo = wproadie_ok( wproadie_call( 'seo-get', array( 'id' => $id ) ) );
			wproadie_assert_same( 'Mentoring', $seo['title'] );
			wproadie_assert_same( 'template', $seo['effective']['source']['title'] );
			wproadie_assert_same( array( 'index' ), $seo['effective']['robots'] );
			wproadie_assert_same( array( 'no custom title', 'no description', 'no focus keyword' ), $seo['issues'] );

			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => '<p>Weekly <strong>mentoring</strong> for young athletes in Knoxville.</p>',
				)
			);
			$seo = wproadie_ok( wproadie_call( 'seo-get', array( 'id' => $id ) ) );
			wproadie_assert_same( 'Weekly mentoring for young athletes in Knoxville.', $seo['effective']['description'], 'the template\'s %excerpt% comes from the content' );
			wproadie_assert_same( 'template', $seo['effective']['source']['description'] );
			wproadie_assert( in_array( 'description from template', $seo['issues'], true ) );

			update_post_meta( $id, 'rank_math_title', 'Youth mentoring in Knoxville' );
			update_post_meta( $id, 'rank_math_description', str_repeat( 'Long description. ', 12 ) );
			update_post_meta( $id, 'rank_math_focus_keyword', 'youth mentoring,knoxville' );
			update_post_meta( $id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
			update_post_meta( $id, 'rank_math_pillar_content', 'on' );
			update_post_meta( $id, 'rank_math_seo_score', '72' );
			update_post_meta( $id, 'rank_math_schema_Article', array( '@type' => 'Article' ) );
			clean_post_cache( $id );
			$seo = wproadie_ok( wproadie_call( 'seo-get', array( 'id' => $id ) ) );
			wproadie_assert_same( 'Youth mentoring in Knoxville', $seo['stored']['title'] );
			wproadie_assert_same( array( 'youth mentoring', 'knoxville' ), $seo['stored']['focus_keywords'] );
			wproadie_assert_same( true, $seo['stored']['pillar_content'] );
			wproadie_assert_same( 72, $seo['stored']['seo_score'] );
			wproadie_assert_same( array( 'Article' ), $seo['stored']['schemas'] );
			wproadie_assert_same( 'custom', $seo['effective']['source']['title'] );
			wproadie_assert_same( array( 'noindex', 'nofollow' ), $seo['effective']['robots'] );
			wproadie_assert_same( array( 'noindex' ), $seo['issues'], 'noindex is the only issue that matters' );

			delete_post_meta( $id, 'rank_math_robots' );
			$seo = wproadie_ok( wproadie_call( 'seo-get', array( 'id' => $id ) ) );
			wproadie_assert_same( array( 'description too long' ), $seo['issues'] );

			wproadie_assert_error( wproadie_call( 'seo-get', array( 'id' => 999999 ) ), 'wproadie_seo_not_found' );
			wproadie_assert_error( wproadie_call( 'seo-get', array( 'term_id' => 1 ) ), 'wproadie_seo_bad_taxonomy' );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'seo-update stores fields as Rank Math does, validates, warns and undoes',
	function () {
		$id   = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'News',
			)
		);
		$term = wp_insert_term( 'Programs ' . wp_generate_password( 4, false ), 'category' );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array(
							'title'                => ' Youth mentoring in Knoxville <b>bold</b> ',
							'description'          => str_repeat( 'Words. ', 30 ),
							'focus_keywords'       => array( ' youth mentoring ', 'knoxville', 'youth mentoring' ),
							'robots'               => array( 'noindex', 'NOFOLLOW' ),
							'advanced_robots'      => array(
								'max-snippet'       => -1,
								'max-image-preview' => 'large',
							),
							'canonical_url'        => 'https://noathlete.org/news/',
							'pillar_content'       => true,
							'twitter_use_facebook' => false,
							'facebook_title'       => 'Share title',
						),
					)
				)
			);
			wproadie_assert_same( 'Youth mentoring in Knoxville bold', get_post_meta( $id, 'rank_math_title', true ), 'tags stripped, trimmed' );
			wproadie_assert_same( 'youth mentoring,knoxville', get_post_meta( $id, 'rank_math_focus_keyword', true ), 'comma, no space, deduplicated' );
			wproadie_assert_same( array( 'noindex', 'nofollow' ), get_post_meta( $id, 'rank_math_robots', true ) );
			wproadie_assert_same(
				array(
					'max-snippet'       => -1,
					'max-image-preview' => 'large',
				),
				get_post_meta( $id, 'rank_math_advanced_robots', true )
			);
			wproadie_assert_same( 'on', get_post_meta( $id, 'rank_math_pillar_content', true ) );
			wproadie_assert_same( 'off', get_post_meta( $id, 'rank_math_twitter_use_facebook', true ) );
			wproadie_assert( ! empty( $result['warnings'] ) && false !== strpos( $result['warnings'][0], '160' ), 'description length warning' );
			wproadie_assert_same( 'custom', $result['effective']['source']['title'] );
			$undo = (int) $result['undo_id'];

			// Removing: null deletes the row; pillar off deletes; twitter_use_facebook true deletes.
			$second = wproadie_ok(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array(
							'description'          => null,
							'pillar_content'       => false,
							'twitter_use_facebook' => true,
							'robots'               => array(),
						),
					)
				)
			);
			wproadie_assert_same( array( 'description', 'pillar_content', 'twitter_use_facebook', 'robots' ), $second['removed'] );
			wproadie_assert( ! metadata_exists( 'post', $id, 'rank_math_description' ) );
			wproadie_assert( ! metadata_exists( 'post', $id, 'rank_math_pillar_content' ) );
			wproadie_assert( ! metadata_exists( 'post', $id, 'rank_math_robots' ) );
			wproadie_ok( Undo::revert( (int) $second['undo_id'] ) );
			wproadie_assert_same( 'on', get_post_meta( $id, 'rank_math_pillar_content', true ), 'undo brings the rows back' );
			wproadie_ok( Undo::revert( $undo ) );
			wproadie_assert( ! metadata_exists( 'post', $id, 'rank_math_title' ), 'undo of the first write removes everything' );

			// Validation.
			wproadie_assert_error(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array( 'robots' => array( 'index', 'noindex' ) ),
					)
				),
				'wproadie_seo_bad_value'
			);
			wproadie_assert_error(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array( 'robots' => array( 'follow' ) ),
					)
				),
				'wproadie_seo_bad_value'
			);
			wproadie_assert_error(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array( 'focus_keywords' => array( 'a', 'b', 'c', 'd', 'e', 'f' ) ),
					)
				),
				'wproadie_seo_bad_value'
			);
			wproadie_assert_error(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array( 'advanced_robots' => array( 'max-image-preview' => 'huge' ) ),
					)
				),
				'wproadie_seo_bad_value'
			);
			wproadie_assert_error(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array( 'canonical_url' => 'not a url' ),
					)
				),
				'wproadie_seo_bad_value'
			);
			wproadie_assert_error(
				wproadie_call(
					'seo-update',
					array(
						'id'  => $id,
						'set' => array( 'twitter_card_type' => 'player' ),
					)
				),
				'wproadie_seo_bad_value'
			);
			wproadie_assert( ! metadata_exists( 'post', $id, 'rank_math_title' ), 'a refused call writes nothing' );

			// Terms take the same fields.
			$term_result = wproadie_ok(
				wproadie_call(
					'seo-update',
					array(
						'term_id'  => (int) $term['term_id'],
						'taxonomy' => 'category',
						'set'      => array(
							'title'  => 'Programs | NALB',
							'robots' => array( 'noindex' ),
						),
					)
				)
			);
			wproadie_assert_same( 'Programs | NALB', get_term_meta( (int) $term['term_id'], 'rank_math_title', true ) );
			wproadie_assert_same( array( 'noindex' ), $term_result['effective']['robots'] );
			wproadie_ok( Undo::revert( (int) $term_result['undo_id'] ) );
			wproadie_assert( ! metadata_exists( 'term', (int) $term['term_id'], 'rank_math_title' ) );
		} finally {
			wp_delete_post( $id, true );
			wp_delete_term( (int) $term['term_id'], 'category' );
		}
	}
);

wproadie_test(
	'seo-audit lists published items with their issues first; seo-get-settings reads the templates',
	function () {
		$good  = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Good page',
			)
		);
		$bad   = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Bad page',
			)
		);
		$draft = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => 'Draft page',
			)
		);
		update_post_meta( $good, 'rank_math_title', 'A fine title' );
		update_post_meta( $good, 'rank_math_description', 'A description that is long enough to count as a real one here.' );
		update_post_meta( $good, 'rank_math_focus_keyword', 'fine' );
		update_option(
			'rank-math-options-titles',
			array(
				'title_separator'       => '&ndash;',
				'pt_page_title'         => '%title% %sep% %sitename%',
				'pt_page_custom_robots' => 'on',
				'pt_page_robots'        => array( 'index' ),
				'knowledgegraph_name'   => 'NALB',
			)
		);
		update_option( 'rank_math_modules', array( 'sitemap', 'rich-snippet' ) );
		try {
			$audit = wproadie_ok(
				wproadie_call(
					'seo-audit',
					array(
						'post_type' => 'page',
						'per_page'  => 500,
					)
				)
			);
			$ids   = array_column( $audit['items'], 'id' );
			wproadie_assert( in_array( $bad, $ids, true ) && ! in_array( $good, $ids, true ) && ! in_array( $draft, $ids, true ), 'only published pages with issues' );
			$row = $audit['items'][ array_search( $bad, $ids, true ) ];
			wproadie_assert_same( array( 'no custom title', 'no description', 'no focus keyword' ), $row['issues'] );
			wproadie_assert_same( 'post type setting', $row['source']['robots'] );
			wproadie_assert( $audit['issue_counts']['no focus keyword'] >= 1 );
			$all = wproadie_ok(
				wproadie_call(
					'seo-audit',
					array(
						'post_type'   => 'page',
						'only_issues' => false,
						'per_page'    => 500,
					)
				)
			);
			wproadie_assert( in_array( $good, array_column( $all['items'], 'id' ), true ) );
			wproadie_assert_error( wproadie_call( 'seo-audit', array( 'post_type' => 'nope' ) ), 'wproadie_seo_bad_post_type' );

			$settings = wproadie_ok( wproadie_call( 'seo-get-settings' ) );
			wproadie_assert_same( '–', $settings['separator'] );
			wproadie_assert_same( array( 'index' ), $settings['post_types']['page']['robots'] );
			wproadie_assert_same( 'site default', $settings['post_types']['post']['robots'] );
			wproadie_assert_same( 'NALB', $settings['knowledge_graph']['name'] );
			wproadie_assert_same( true, $settings['sitemap']['enabled'] );
		} finally {
			foreach ( array( $good, $bad, $draft ) as $p ) {
				wp_delete_post( $p, true );
			}
			delete_option( 'rank-math-options-titles' );
			delete_option( 'rank_math_modules' );
		}
	}
);

wproadie_test(
	'seo: builder shortcodes never reach a description; the audit skips builder libraries',
	function () {
		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Divi page',
				'post_content' => '[et_pb_section fb_built="1" _builder_version="4.27.5"][et_pb_row][et_pb_column type="4_4"][et_pb_text]Youth sports mentoring in Knoxville, Tennessee.[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
			)
		);
		try {
			$effective = \WPRoadie\Abilities\Seo\RankMath::effective_post( get_post( $page ), array() );
			wproadie_assert_same( 'Youth sports mentoring in Knoxville, Tennessee.', $effective['description'] );
			wproadie_assert_same( 'Divi page - ' . get_bloginfo( 'name' ), $effective['title'] );
			wproadie_assert_same( 'Hello there', \WPRoadie\Abilities\Seo\RankMath::strip_all_shortcodes( 'Hello [gallery ids="1"] there' ), 'registered shortcodes go too' );

			register_post_type( 'et_pb_layout', array( 'public' => true, 'publicly_queryable' => true, 'exclude_from_search' => true ) );
			register_post_type( 'wproadie_hidden', array( 'public' => true, 'exclude_from_search' => true ) );
			$types = \WPRoadie\Abilities\Seo\RankMath::indexable_post_types();
			wproadie_assert( in_array( 'page', $types, true ) && in_array( 'post', $types, true ), 'posts and pages are audited' );
			wproadie_assert( ! in_array( 'et_pb_layout', $types, true ) && ! in_array( 'wproadie_hidden', $types, true ) && ! in_array( 'attachment', $types, true ), 'libraries, unsearchable types and attachments are not: ' . implode( ',', $types ) );
		} finally {
			wp_delete_post( $page, true );
			unregister_post_type( 'et_pb_layout' );
			unregister_post_type( 'wproadie_hidden' );
		}
	}
);

wproadie_test(
	'teardown: seo tools back to detecting Rank Math',
	function () {
		remove_filter( 'wproadie_seo_available', '__return_true' );
		wproadie_assert( ! in_array( 'wproadie/seo-get', ( new \WPRoadie\Abilities\Registry() )->enabled_names(), true ), 'Later tests see this site without Rank Math.' );
	}
);
