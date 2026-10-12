<?php
/**
 * Content tools: posts, meta and terms.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Log\Undo;

/**
 * Creates a throwaway post directly, bypassing the abilities.
 */
function wproadie_content_fixture( array $args = array() ): int {
	return (int) wp_insert_post(
		array_merge(
			array(
				'post_title'   => 'WPRoadie fixture',
				'post_content' => 'Fixture body',
				'post_status'  => 'draft',
			),
			$args
		)
	);
}

/**
 * Runs $fn with edit_post (and delete_post) denied for one post.
 */
function wproadie_content_deny( int $post_id, callable $callback ): void {
	$filter = static function ( $caps, $cap, $user_id, $args ) use ( $post_id ) {
		if ( in_array( $cap, array( 'edit_post', 'delete_post', 'edit_post_meta' ), true ) && isset( $args[0] ) && (int) $args[0] === $post_id ) {
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
	'create-post creates a draft and undo trashes it',
	function () {
		$result = wproadie_ok(
			wproadie_call(
				'create-post',
				array(
					'title'   => 'Roadie "quoted" \\ title',
					'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				)
			)
		);
		$id     = $result['id'];
		try {
			wproadie_assert_same( 'draft', $result['status'] );
			wproadie_assert_same( 'Roadie "quoted" \\ title', get_post( $id )->post_title, 'Slashes must survive.' );
			wproadie_assert( ! empty( $result['undo_id'] ), 'undo_id missing' );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( 'trash', get_post_status( $id ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'create-post schedules with a future date and rejects bad input',
	function () {
		$result = wproadie_ok(
			wproadie_call(
				'create-post',
				array(
					'title'     => 'Scheduled',
					'post_type' => 'page',
					'status'    => 'future',
					'date'      => gmdate( 'Y-m-d\TH:i:s', time() + WEEK_IN_SECONDS ),
				)
			)
		);
		wp_delete_post( $result['id'], true );
		wproadie_assert_same( 'future', $result['status'] );
		wproadie_assert_error( wproadie_call( 'create-post', array( 'status' => 'future' ) ), 'wproadie_missing_date' );
		wproadie_assert_error( wproadie_call( 'create-post', array( 'post_type' => 'nope' ) ), 'wproadie_invalid_post_type' );
		wproadie_assert_error( wproadie_call( 'create-post', array( 'post_type' => 'attachment' ) ), 'wproadie_invalid_post_type' );
		wproadie_assert_error( wproadie_call( 'create-post', array( 'status' => 'trash' ) ), 'ability_invalid_input' );
		wproadie_assert_error( wproadie_call( 'create-post', array( 'date' => 'next tuesday' ) ), 'wproadie_invalid_date' );
		wproadie_assert_error( wproadie_call( 'create-post', array( 'parent' => 99999999 ) ), 'wproadie_invalid_parent' );
	}
);

wproadie_test(
	'create-post needs the publish capability to publish',
	function () {
		$filter = static function ( $allcaps ) {
			$allcaps['publish_posts'] = false;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $filter );
		try {
			wproadie_assert_error( wproadie_call( 'create-post', array( 'status' => 'publish' ) ), 'ability_invalid_permissions' );
		} finally {
			remove_filter( 'user_has_cap', $filter );
		}
	}
);

wproadie_test(
	'get-post returns content, terms and public meta only',
	function () {
		$id = wproadie_content_fixture( array( 'post_content' => '<p>Body</p>' ) );
		try {
			update_post_meta( $id, 'roadie_public', 'yes' );
			update_post_meta( $id, '_roadie_secret', 'no' );
			$result = wproadie_ok( wproadie_call( 'get-post', array( 'id' => $id ) ) );
			wproadie_assert_same( '<p>Body</p>', $result['content'] );
			wproadie_assert_same( 'yes', $result['meta']->roadie_public );
			wproadie_assert( ! isset( $result['meta']->_roadie_secret ), 'Protected meta leaked.' );
			wproadie_assert( isset( $result['terms']->category ), 'Default category missing.' );
			wproadie_assert_error( wproadie_call( 'get-post', array( 'id' => 99999999 ) ), 'wproadie_not_found' );
			wproadie_content_deny(
				$id,
				function () use ( $id ) {
					wproadie_assert_error( wproadie_call( 'get-post', array( 'id' => $id ) ), 'ability_invalid_permissions' );
				}
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'list-posts filters by type, status and search',
	function () {
		$id = wproadie_content_fixture( array( 'post_title' => 'Zebracorn listing test' ) );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'list-posts',
					array(
						'search' => 'Zebracorn',
						'status' => 'draft',
					)
				)
			);
			wproadie_assert_same( 1, $result['total'] );
			wproadie_assert_same( $id, $result['items'][0]['id'] );
			$pages = wproadie_ok(
				wproadie_call(
					'list-posts',
					array(
						'post_type' => 'page',
						'search'    => 'Zebracorn',
					)
				)
			);
			wproadie_assert_same( 0, $pages['total'] );
			$any = wproadie_ok(
				wproadie_call(
					'list-posts',
					array(
						'post_type' => 'any',
						'search'    => 'Zebracorn',
						'per_page'  => 5,
					)
				)
			);
			wproadie_assert_same( 1, $any['total'] );
			wproadie_assert_error( wproadie_call( 'list-posts', array( 'per_page' => 500 ) ), 'ability_invalid_input' );
			wproadie_assert_error( wproadie_call( 'list-posts', array( 'post_type' => 'revision' ) ), 'wproadie_invalid_post_type' );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'update-post changes fields and undo restores them',
	function () {
		$id = wproadie_content_fixture(
			array(
				'post_title'   => 'Before',
				'post_content' => 'Old body',
			)
		);
		try {
			$result = wproadie_ok(
				wproadie_call(
					'update-post',
					array(
						'id'      => $id,
						'title'   => 'After',
						'content' => 'New body',
						'status'  => 'publish',
					)
				)
			);
			wproadie_assert_same( 'publish', $result['status'] );
			wproadie_assert_same( 'After', get_post( $id )->post_title );
			wproadie_assert_same( 'New body', get_post( $id )->post_content );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			$post = get_post( $id );
			wproadie_assert_same( 'Before', $post->post_title );
			wproadie_assert_same( 'Old body', $post->post_content );
			wproadie_assert_same( 'draft', $post->post_status );
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'update-post validation and permission',
	function () {
		$id = wproadie_content_fixture();
		try {
			wproadie_assert_error( wproadie_call( 'update-post', array( 'id' => $id ) ), 'wproadie_nothing_to_update' );
			wproadie_assert_error(
				wproadie_call(
					'update-post',
					array(
						'id'     => $id,
						'parent' => $id,
					)
				),
				'wproadie_invalid_parent'
			);
			wproadie_assert_error(
				wproadie_call(
					'update-post',
					array(
						'id'    => 99999999,
						'title' => 'x',
					)
				),
				'wproadie_not_found'
			);
			wproadie_content_deny(
				$id,
				function () use ( $id ) {
					wproadie_assert_error(
						wproadie_call(
							'update-post',
							array(
								'id'    => $id,
								'title' => 'x',
							)
						),
						'ability_invalid_permissions'
					);
				}
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'trash-post trashes and undo restores the status',
	function () {
		$id = wproadie_content_fixture( array( 'post_status' => 'publish' ) );
		try {
			$result = wproadie_ok( wproadie_call( 'trash-post', array( 'id' => $id ) ) );
			wproadie_assert_same( 'trash', get_post_status( $id ) );
			wproadie_assert_same( 'publish', $result['previous_status'] );
			wproadie_assert_error( wproadie_call( 'trash-post', array( 'id' => $id ) ), 'wproadie_already_trashed' );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( 'publish', get_post_status( $id ) );
			wproadie_content_deny(
				$id,
				function () use ( $id ) {
					wproadie_assert_error( wproadie_call( 'trash-post', array( 'id' => $id ) ), 'ability_invalid_permissions' );
				}
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'post meta: read, write, delete, protected keys and undo',
	function () {
		$id = wproadie_content_fixture();
		try {
			update_post_meta( $id, 'roadie_color', 'red' );
			update_post_meta( $id, '_roadie_internal', 'x' );

			$all = wproadie_ok( wproadie_call( 'get-post-meta', array( 'id' => $id ) ) );
			wproadie_assert_same( 'red', $all['meta']->roadie_color );
			wproadie_assert( ! isset( $all['meta']->_roadie_internal ) );
			wproadie_assert( $all['hidden_keys'] >= 1 );

			$set = wproadie_ok(
				wproadie_call(
					'update-post-meta',
					array(
						'id'    => $id,
						'key'   => 'roadie_color',
						'value' => array(
							'shade' => 'blue',
							'n'     => 2,
						),
					)
				)
			);
			wproadie_assert_same(
				array(
					'shade' => 'blue',
					'n'     => 2,
				),
				get_post_meta( $id, 'roadie_color', true )
			);
			wproadie_ok( Undo::revert( $set['undo_id'] ) );
			wproadie_assert_same( 'red', get_post_meta( $id, 'roadie_color', true ) );

			$new = wproadie_ok(
				wproadie_call(
					'update-post-meta',
					array(
						'id'    => $id,
						'key'   => 'roadie_new',
						'value' => 'it\'s "new"',
					)
				)
			);
			wproadie_assert_same( 'it\'s "new"', get_post_meta( $id, 'roadie_new', true ) );
			wproadie_ok( Undo::revert( $new['undo_id'] ) );
			wproadie_assert( ! metadata_exists( 'post', $id, 'roadie_new' ), 'Undo should remove a new key.' );

			$del = wproadie_ok(
				wproadie_call(
					'update-post-meta',
					array(
						'id'     => $id,
						'key'    => 'roadie_color',
						'delete' => true,
					)
				)
			);
			wproadie_assert( ! metadata_exists( 'post', $id, 'roadie_color' ) );
			wproadie_ok( Undo::revert( $del['undo_id'] ) );
			wproadie_assert_same( 'red', get_post_meta( $id, 'roadie_color', true ) );

			$one = wproadie_ok(
				wproadie_call(
					'get-post-meta',
					array(
						'id'  => $id,
						'key' => 'roadie_color',
					)
				)
			);
			wproadie_assert_same( 'red', $one['value'] );

			// Allow-listed protected key.
			wproadie_ok(
				wproadie_call(
					'update-post-meta',
					array(
						'id'    => $id,
						'key'   => '_wp_page_template',
						'value' => 'default',
					)
				)
			);
			wproadie_assert_error(
				wproadie_call(
					'update-post-meta',
					array(
						'id'    => $id,
						'key'   => '_roadie_internal',
						'value' => 'y',
					)
				),
				'wproadie_protected_meta'
			);
			wproadie_assert_error(
				wproadie_call(
					'get-post-meta',
					array(
						'id'  => $id,
						'key' => '_edit_lock',
					)
				),
				'wproadie_protected_meta'
			);
			wproadie_assert_error(
				wproadie_call(
					'update-post-meta',
					array(
						'id'  => $id,
						'key' => 'roadie_color',
					)
				),
				'wproadie_missing_value'
			);
			wproadie_content_deny(
				$id,
				function () use ( $id ) {
					wproadie_assert_error(
						wproadie_call(
							'update-post-meta',
							array(
								'id'    => $id,
								'key'   => 'roadie_color',
								'value' => 'x',
							)
						),
						'ability_invalid_permissions'
					);
				}
			);
		} finally {
			wp_delete_post( $id, true );
		}
	}
);

wproadie_test(
	'terms: list, create and undo',
	function () {
		$result  = wproadie_ok(
			wproadie_call(
				'create-term',
				array(
					'taxonomy'    => 'category',
					'name'        => 'Roadie Test Cat',
					'description' => 'Made by a test',
				)
			)
		);
		$term_id = $result['id'];
		try {
			$list = wproadie_ok(
				wproadie_call(
					'list-terms',
					array(
						'taxonomy' => 'category',
						'search'   => 'Roadie Test',
					)
				)
			);
			wproadie_assert_same( 1, $list['total'] );
			wproadie_assert_same( $term_id, $list['items'][0]['id'] );
			wproadie_assert( in_array( 'post_tag', $list['available_taxonomies'], true ) );

			wproadie_assert_error(
				wproadie_call(
					'create-term',
					array(
						'taxonomy' => 'category',
						'name'     => 'Roadie Test Cat',
					)
				),
				'wproadie_term_exists'
			);
			wproadie_assert_error(
				wproadie_call(
					'create-term',
					array(
						'taxonomy' => 'post_tag',
						'name'     => 'Child',
						'parent'   => $term_id,
					)
				),
				'wproadie_invalid_parent'
			);
			wproadie_assert_error( wproadie_call( 'list-terms', array( 'taxonomy' => 'nope' ) ), 'wproadie_invalid_taxonomy' );
			wproadie_assert_error( wproadie_call( 'list-terms', array() ), 'ability_invalid_input' );

			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert( ! term_exists( $term_id, 'category' ), 'Undo should delete the term.' );
		} finally {
			if ( term_exists( $term_id, 'category' ) ) {
				wp_delete_term( $term_id, 'category' );
			}
		}
	}
);

wproadie_test(
	'set-post-terms replaces, appends, creates and undoes',
	function () {
		$id     = wproadie_content_fixture();
		$tag_a  = wp_insert_term( 'Roadie Tag A', 'post_tag' );
		$tag_id = (int) $tag_a['term_id'];
		wp_set_object_terms( $id, array( $tag_id ), 'post_tag' );
		try {
			wproadie_assert_error(
				wproadie_call(
					'set-post-terms',
					array(
						'id'       => $id,
						'taxonomy' => 'post_tag',
						'terms'    => array( 'Roadie Missing Tag' ),
					)
				),
				'wproadie_term_not_found'
			);

			$result = wproadie_ok(
				wproadie_call(
					'set-post-terms',
					array(
						'id'             => $id,
						'taxonomy'       => 'post_tag',
						'terms'          => array( 'Roadie Brand New Tag' ),
						'create_missing' => true,
					)
				)
			);
			wproadie_assert_same( 1, count( $result['terms'] ) );
			$new_id = $result['terms'][0]['id'];
			wproadie_assert_same( 'Roadie Brand New Tag', $result['terms'][0]['name'] );

			$appended = wproadie_ok(
				wproadie_call(
					'set-post-terms',
					array(
						'id'       => $id,
						'taxonomy' => 'post_tag',
						'terms'    => array( 'roadie-tag-a' ),
						'append'   => true,
					)
				)
			);
			wproadie_assert_same( 2, count( $appended['terms'] ) );
			wproadie_ok( Undo::revert( $appended['undo_id'] ) );

			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			$ids = wp_get_object_terms( $id, 'post_tag', array( 'fields' => 'ids' ) );
			wproadie_assert_same( array( $tag_id ), array_map( 'intval', $ids ) );
			wproadie_assert( ! term_exists( $new_id, 'post_tag' ), 'Undo should delete the created tag.' );

			$page = wproadie_content_fixture( array( 'post_type' => 'page' ) );
			wproadie_assert_error(
				wproadie_call(
					'set-post-terms',
					array(
						'id'       => $page,
						'taxonomy' => 'post_tag',
						'terms'    => array( $tag_id ),
					)
				),
				'wproadie_invalid_taxonomy'
			);
			wp_delete_post( $page, true );

			wproadie_content_deny(
				$id,
				function () use ( $id, $tag_id ) {
					wproadie_assert_error(
						wproadie_call(
							'set-post-terms',
							array(
								'id'       => $id,
								'taxonomy' => 'post_tag',
								'terms'    => array( $tag_id ),
							)
						),
						'ability_invalid_permissions'
					);
				}
			);
		} finally {
			wp_delete_post( $id, true );
			wp_delete_term( $tag_id, 'post_tag' );
		}
	}
);
