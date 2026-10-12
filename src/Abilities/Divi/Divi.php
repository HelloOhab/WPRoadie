<?php
/**
 * Shared helpers for the Divi abilities: is Divi active, is a post built
 * with Divi and in which format, loading and saving pages safely, cache
 * clearing and sanitizing values from the AI.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WP_Error;
use WP_Post;
use WPRoadie\Abilities\Content\ContentHelper;

defined( 'ABSPATH' ) || exit;

final class Divi {

	/**
	 * Post types Divi always builds with: the Divi Library and the Theme
	 * Builder's header, body and footer layouts.
	 */
	const LAYOUT_TYPES = array( 'et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout' );

	/**
	 * Largest page the tools will parse or save, in bytes.
	 */
	const MAX_CONTENT = 4194304;

	// Availability.


	/**
	 * Whether Divi (the Divi or Extra theme, or the Divi Builder plugin) is
	 * active on this site.
	 */
	public static function is_active(): bool {
		$active = defined( 'ET_BUILDER_VERSION' )
			|| defined( 'ET_BUILDER_PLUGIN_VERSION' )
			|| function_exists( 'et_setup_theme' )
			|| function_exists( 'et_divi_builder_init_plugin' )
			|| in_array( strtolower( (string) get_template() ), array( 'divi', 'extra' ), true );

		/**
		 * Filters whether WPRoadie treats Divi as active, which decides
		 * whether the Divi tools are offered. For tests and unusual setups
		 * (e.g. a renamed Divi theme folder).
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'wproadie_divi_available', $active );
	}

	public static function unavailable_reason(): string {
		return __( 'Divi is not active on this site: no Divi or Extra theme and no Divi Builder plugin.', 'wproadie' );
	}

	public static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wproadie_divi_' . $code, $message, array( 'status' => $status ) );
	}

	// Posts.


	/**
	 * Post types the Divi tools work with.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		return array_values( array_unique( array_merge( ContentHelper::content_types(), self::LAYOUT_TYPES ) ) );
	}

	/**
	 * The given post types the current user can edit items of.
	 *
	 * @param string[] $types Post types.
	 * @return string[]
	 */
	public static function editable_types( array $types ): array {
		return array_values(
			array_filter(
				$types,
				static function ( $name ) {
					$object = get_post_type_object( $name );
					// Divi's own types are registered by Divi; without it (e.g.
					// in tests) allow them to people who can edit pages.
					return $object ? current_user_can( $object->cap->edit_posts ) : current_user_can( 'edit_pages' );
				}
			)
		);
	}

	/**
	 * Which Divi format a post is built with: 4 (shortcodes), 5 (blocks)
	 * or 0 when it is not built with Divi.
	 */
	public static function format_of( WP_Post $post ): int {
		$content = (string) $post->post_content;
		// Divi 4 shortcodes at the top level win: turning the Divi 4 builder
		// on for a page that already had Divi 5 blocks wraps those blocks in
		// a Text module, and they are then just that module's content.
		if ( self::starts_with_shortcodes( $content ) ) {
			return 4;
		}
		if ( false !== strpos( $content, '<!-- wp:divi/' ) ) {
			return 5;
		}
		$builder = in_array( $post->post_type, self::LAYOUT_TYPES, true ) || 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true );
		if ( ! $builder ) {
			return 0;
		}
		if ( false !== strpos( $content, '[et_pb_' ) ) {
			return 4;
		}
		// An empty builder page: the format the site's Divi writes.
		return 'on' === get_post_meta( $post->ID, '_et_pb_use_divi_5', true ) ? 5 : 4;
	}

	/**
	 * True when the content opens with a Divi 4 shortcode (after whitespace).
	 */
	public static function starts_with_shortcodes( string $content ): bool {
		return 1 === preg_match( '/^\s*\[et_pb_[a-z0-9_]+[\s\]\/]/', $content );
	}

	/**
	 * The Divi version this site runs, for elements added to a page that
	 * records none yet, or "" when unknown.
	 */
	public static function site_builder_version(): string {
		foreach ( array( 'ET_BUILDER_PRODUCT_VERSION', 'ET_BUILDER_VERSION' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
				return (string) constant( $constant );
			}
		}
		return '';
	}

	/**
	 * Loads a Divi page for reading or editing.
	 *
	 * @return array{post: WP_Post, doc: Document}|WP_Error
	 */
	public static function load( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::error( 'not_found', __( 'No post with that ID.', 'wproadie' ), 404 );
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return self::error(
				'wrong_type',
				/* translators: %s: post type. */
				sprintf( __( 'Items of type "%s" are not pages the Divi tools work with.', 'wproadie' ), $post->post_type )
			);
		}
		$format = self::format_of( $post );
		if ( ! $format ) {
			return self::error(
				'not_divi',
				__( 'This post is not built with Divi (the Divi Builder is not enabled for it and it has no Divi content). Use get-post and update-post for normal content; use divi-list-pages to find Divi pages.', 'wproadie' )
			);
		}
		if ( strlen( (string) $post->post_content ) > self::MAX_CONTENT ) {
			return self::error( 'too_large', __( 'This page is too large for the Divi tools to edit safely.', 'wproadie' ), 413 );
		}
		return array(
			'post' => $post,
			'doc'  => new Document( (string) $post->post_content, $format ),
		);
	}

	/**
	 * IDs of Divi posts (builder on, Divi 5 content, or a Divi layout type),
	 * newest change first. One query instead of loading every post.
	 *
	 * @param string[] $types    Post types.
	 * @param string[] $statuses Post statuses.
	 * @param string   $search   Text the title must contain, or "".
	 * @return array{total: int, ids: int[]}
	 */
	public static function query_ids( array $types, array $statuses, string $search, int $limit, int $offset ): array {
		global $wpdb;
		if ( ! $types || ! $statuses ) {
			return array(
				'total' => 0,
				'ids'   => array(),
			);
		}
		$type_in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$layouts   = array_values( array_intersect( $types, self::LAYOUT_TYPES ) );
		$layout_in = $layouts ? implode( ', ', array_fill( 0, count( $layouts ), '%s' ) ) : "''";

		$where = "p.post_type IN ($type_in) AND p.post_status IN ($status_in)"
			. " AND ( p.post_type IN ($layout_in) OR p.post_content LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_et_pb_use_builder' AND m.meta_value = 'on' ) )";
		$args  = array_merge( array_values( $types ), array_values( $statuses ), $layouts, array( '%' . $wpdb->esc_like( '<!-- wp:divi/' ) . '%' ) );
		if ( '' !== $search ) {
			$where .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are built above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where", $args ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE $where ORDER BY p.post_modified DESC, p.ID DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $limit, $offset ) )
			)
		);
		// phpcs:enable

		return array(
			'total' => $total,
			'ids'   => array_map( 'intval', (array) $ids ),
		);
	}

	/**
	 * Short facts about a Divi post for lists.
	 */
	public static function brief( WP_Post $post, ?Document $doc = null ): array {
		$format = $doc ? $doc->format : self::format_of( $post );
		$item   = array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'format'   => $format,
			'modified' => ContentHelper::date( $post->post_modified ),
		);
		if ( $doc ) {
			$item['modules'] = $doc->module_count();
		}
		$link = get_permalink( $post );
		if ( $link && ! in_array( $post->post_type, self::LAYOUT_TYPES, true ) ) {
			$item['link'] = (string) $link;
		}
		$edit = get_edit_post_link( $post->ID, 'raw' );
		if ( $edit ) {
			$item['edit_link'] = (string) $edit;
		}
		return $item;
	}

	// Sanitizing.


	/**
	 * Whether the current user may save unfiltered HTML (scripts, iframes).
	 */
	public static function unfiltered(): bool {
		return current_user_can( 'unfiltered_html' );
	}

	/**
	 * Sanitizes a value from the AI for users without unfiltered_html:
	 * every string goes through wp_kses_post(), the filter WordPress applies
	 * to their post content anyway.
	 *
	 * @param mixed $value   Value (strings, arrays, objects).
	 * @param bool  $changed Set to true when anything was removed.
	 * @return mixed
	 */
	public static function clean( $value, bool &$changed = false ) {
		if ( self::unfiltered() ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$clean = wp_kses_post( $value );
			if ( $clean !== $value ) {
				$changed = true;
			}
			return $clean;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::clean( $item, $changed );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = self::clean( $item, $changed );
			}
		}
		return $value;
	}

	/**
	 * Sanitizes a Divi 4 attribute value for users without unfiltered_html.
	 * Attribute values are plain text to Divi; HTML inside a shortcode
	 * attribute would also not survive WordPress's own filtering, so tags
	 * are removed.
	 *
	 * @param string $value   Value.
	 * @param bool   $changed Set to true when anything was removed.
	 */
	public static function clean_attr( string $value, bool &$changed = false ): string {
		if ( self::unfiltered() ) {
			return $value;
		}
		$clean = wp_kses_post( $value );
		if ( false !== strpos( $clean, '<' ) ) {
			$clean = wp_strip_all_tags( $clean );
		}
		if ( $clean !== $value ) {
			$changed = true;
		}
		return $clean;
	}

	/**
	 * Checks WordPress's save filters (kses for users without
	 * unfiltered_html, entity and tag balancing) would store the content
	 * as given, so an edit cannot silently change other parts of the page,
	 * e.g. strip an embed an administrator added.
	 *
	 * For Divi 5, kses re-encodes every block's attributes; that changes
	 * bytes but not meaning, so blocks are compared after parsing.
	 *
	 * @return true|WP_Error
	 */
	public static function check_save_filters( string $content, int $format ) {
		$filtered = wp_unslash( apply_filters( 'content_save_pre', wp_slash( $content ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, run as wp_insert_post() will.
		if ( $filtered === $content ) {
			return true;
		}
		if ( 5 === $format && self::same_blocks( parse_blocks( $content ), parse_blocks( $filtered ) ) ) {
			return true;
		}
		return self::error(
			'save_would_alter',
			self::unfiltered()
				? __( 'WordPress\'s save filters would change other parts of this page (e.g. tag balancing), so nothing was changed. Edit it in the Divi Builder instead.', 'wproadie' )
				: __( 'This page contains HTML your account may not save (such as scripts, iframes or embed code added by an administrator). Saving it as you would make WordPress strip that HTML, so nothing was changed. Ask someone with the unfiltered_html capability (an administrator on a single site) to make this change.', 'wproadie' ),
			409
		);
	}

	/**
	 * Whether two parse_blocks() results have the same blocks, attributes
	 * and HTML.
	 */
	public static function same_blocks( array $a, array $b ): bool {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $i => $block ) {
			$other = $b[ $i ];
			if ( $block['blockName'] !== $other['blockName'] || $block['attrs'] != $other['attrs'] || $block['innerHTML'] !== $other['innerHTML'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same keys and values in any order.
				return false;
			}
			if ( ! self::same_blocks( $block['innerBlocks'], $other['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	// Caches.


	/**
	 * Clears Divi's generated CSS and any page cache Divi knows about for a
	 * post, or for the whole site with "all". Does nothing when Divi's
	 * classes are not loaded.
	 *
	 * @param int|string $post_id Post ID or "all".
	 * @return string[] What was cleared.
	 */
	public static function clear_cache( $post_id ): array {
		$done = array();
		if ( class_exists( '\ET_Core_PageResource' ) && method_exists( '\ET_Core_PageResource', 'remove_static_resources' ) ) {
			\ET_Core_PageResource::remove_static_resources( $post_id, 'all' );
			$done[] = 'divi_static_css';
		}
		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			if ( 'all' === $post_id ) {
				et_core_clear_wp_cache();
			} else {
				et_core_clear_wp_cache( $post_id );
			}
			$done[] = 'page_cache';
		}
		/**
		 * Fires after WPRoadie clears Divi caches, e.g. to purge a host's
		 * own page cache.
		 *
		 * @param int|string $post_id Post ID or "all".
		 */
		do_action( 'wproadie_divi_cache_cleared', $post_id );
		return $done;
	}

	/**
	 * Hooked to wproadie_undo_record_reverted: after a post is restored, clear the
	 * Divi caches for it so the old version shows straight away.
	 *
	 * @param array $record Undo record.
	 */
	public static function after_undo( $record ): void {
		if ( ! is_array( $record ) || 'post' !== ( $record['kind'] ?? '' ) || ! self::is_active() ) {
			return;
		}
		$post = get_post( (int) ( $record['post_id'] ?? 0 ) );
		if ( $post && self::format_of( $post ) ) {
			self::clear_cache( (int) $post->ID );
		}
	}
}
