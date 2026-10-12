<?php
/**
 * Reads and writes Elementor data the way Elementor stores it, and keeps
 * Elementor's caches in step.
 *
 * Saving: Elementor's own Document::save() rebuilds every element through
 * its registered widget classes, which drops widgets that are not
 * registered at that moment (e.g. Elementor Pro or add-on widgets while
 * that plugin is off), drops element keys it does not know and throws on
 * atomic data it cannot validate. A tool that changes one heading must
 * never do that to the rest of the page, so WPRoadie writes
 * _elementor_data itself, encoded exactly as Elementor encodes it
 * (wp_slash( wp_json_encode() ) via update_metadata()), then does what
 * Document::save() does afterwards: deletes the post's CSS file and
 * element cache, and re-syncs the plain-text copy in post_content (which
 * also creates a WordPress revision, with Elementor's data attached by
 * Elementor's revision hooks).
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use Throwable;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class ElementorHelper {

	const DATA_KEY          = '_elementor_data';
	const EDIT_MODE_KEY     = '_elementor_edit_mode';
	const PAGE_SETTINGS_KEY = '_elementor_page_settings';
	const TEMPLATE_TYPE_KEY = '_elementor_template_type';
	const LIBRARY_TYPE      = 'elementor_library';
	const ACTIVE_KIT_OPTION = 'elementor_active_kit';

	/**
	 * Per-post caches Elementor rebuilds on the next page view.
	 */
	const CACHE_META_KEYS = array( '_elementor_css', '_elementor_element_cache', '_elementor_page_assets' );

	/**
	 * Largest element (or template insert) the AI may send, in bytes of JSON.
	 */
	const MAX_ELEMENT_BYTES = 262144;

	/**
	 * Largest page WPRoadie will write, in bytes of JSON.
	 */
	const MAX_PAGE_BYTES = 5242880;

	/**
	 * Registers hooks that must run even when no ability is called, e.g.
	 * an undo started from the activity log in wp-admin.
	 */
	public static function hooks(): void {
		add_action( 'wproadie_undo_record_reverted', array( self::class, 'after_undo' ) );
	}

	/**
	 * Whether Elementor is active, so the Elementor tools are offered.
	 */
	public static function is_active(): bool {
		$active = defined( 'ELEMENTOR_VERSION' ) && did_action( 'elementor/loaded' );

		/**
		 * Filters whether WPRoadie treats Elementor as active. For tests and
		 * unusual setups.
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'wproadie_elementor_active', $active );
	}

	/**
	 * Whether Elementor's PHP API is loaded (not just faked by the filter).
	 */
	public static function is_loaded(): bool {
		return class_exists( '\Elementor\Plugin', false ) && isset( \Elementor\Plugin::$instance ) && did_action( 'elementor/loaded' );
	}

	public static function version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}

	public static function unavailable_reason(): string {
		return __( 'Elementor is not active on this site. Install and activate the Elementor plugin to use the Elementor tools.', 'wproadie' );
	}

	public static function is_builder( int $post_id ): bool {
		return 'builder' === get_post_meta( $post_id, self::EDIT_MODE_KEY, true );
	}

	public static function template_type( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::TEMPLATE_TYPE_KEY, true );
	}

	public static function active_kit_id(): int {
		return (int) get_option( self::ACTIVE_KIT_OPTION );
	}

	public static function is_kit( int $post_id ): bool {
		return ( $post_id && self::active_kit_id() === $post_id ) || 'kit' === self::template_type( $post_id );
	}

	/**
	 * Whether the current user may edit a post with Elementor: edit_post,
	 * plus Elementor's Role Manager exclusions when Elementor is loaded.
	 */
	public static function can_edit( int $post_id ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( self::is_loaded() && class_exists( '\Elementor\User' ) && method_exists( '\Elementor\User', 'is_current_user_in_editing_black_list' ) ) {
			// Despite its name, true means the user's role may use the editor
			// (Elementor > Role Manager "No access to editor" is not set).
			return (bool) \Elementor\User::is_current_user_in_editing_black_list();
		}
		return true;
	}

	/**
	 * Loads a post the element tools may work on: exists, not trashed,
	 * built with Elementor, and not the kit.
	 *
	 * @return WP_Post|WP_Error
	 */
	public static function get_page( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_type, array( 'revision', 'attachment', 'nav_menu_item' ), true ) ) {
			return self::error( 'not_found', __( 'No post or page with that ID.', 'wproadie' ), 404 );
		}
		if ( 'trash' === $post->post_status ) {
			return self::error( 'trashed', __( 'That post is in the trash. Restore it before editing it.', 'wproadie' ), 409 );
		}
		if ( self::is_kit( $post->ID ) ) {
			return self::error( 'is_kit', __( 'That ID is the Elementor kit (global site settings). Use elementor-get-kit and elementor-update-kit for it.', 'wproadie' ) );
		}
		if ( ! self::is_builder( $post->ID ) ) {
			return self::error(
				'not_elementor',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d is not built with Elementor, so the Elementor tools leave it alone. Use get-post and update-post for its content, or open it in Elementor first. elementor-list-pages lists the pages that are built with Elementor.', 'wproadie' ),
					$post->ID
				),
				409
			);
		}
		return $post;
	}

	/**
	 * Raw stored _elementor_data as Elementor reads it (unslashed JSON).
	 *
	 * @return mixed
	 */
	public static function raw_data( int $post_id ) {
		return get_post_meta( $post_id, self::DATA_KEY, true );
	}

	/**
	 * The decoded element tree of a post.
	 *
	 * @return array|WP_Error
	 */
	public static function read_tree( int $post_id ) {
		$raw = self::raw_data( $post_id );
		if ( is_array( $raw ) ) {
			// Very old data stored as a serialized array.
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$tree = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return self::error( 'corrupt_data', __( 'This page\'s Elementor data is not valid JSON, so WPRoadie will not touch it. Open it in the Elementor editor to repair it.', 'wproadie' ), 500 );
		}
		return $tree;
	}

	/**
	 * Encodes a tree as Elementor does and checks its size.
	 *
	 * @return string|WP_Error
	 */
	public static function encode( array $tree ) {
		$json = wp_json_encode( array_values( $tree ) );
		if ( false === $json ) {
			return self::error( 'encode_failed', __( 'The changed page could not be encoded as JSON (invalid characters?). Nothing was saved.', 'wproadie' ), 500 );
		}
		if ( strlen( $json ) > self::MAX_PAGE_BYTES ) {
			return self::error(
				'too_large',
				sprintf(
					/* translators: %s: size limit, e.g. "5 MB". */
					__( 'The page would be larger than %s of Elementor data, which WPRoadie does not write. Nothing was saved.', 'wproadie' ),
					size_format( self::MAX_PAGE_BYTES )
				),
				413
			);
		}
		return $json;
	}

	/**
	 * Writes a tree to a post and refreshes Elementor's caches. The caller
	 * records undo first (see ElementorAbility::save_tree()).
	 *
	 * @return true|WP_Error
	 */
	public static function write_tree( int $post_id, array $tree ) {
		$json = self::encode( $tree );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		// Same call Elementor's Document::save_elements() makes: slashed,
		// because update_metadata() unslashes.
		update_metadata( 'post', $post_id, self::DATA_KEY, wp_slash( $json ) );
		if ( self::raw_data( $post_id ) !== $json ) {
			return self::error( 'save_failed', __( 'WordPress did not store the Elementor data (a plugin may have blocked it).', 'wproadie' ), 500 );
		}

		self::clear_post_css( $post_id );
		if ( self::is_loaded() ) {
			self::sync_plain_text( $post_id, $tree );
		}
		return true;
	}

	/**
	 * Whether writes also re-sync post_content (and so change post fields
	 * that undo must restore).
	 */
	public static function syncs_plain_text(): bool {
		return self::is_loaded();
	}

	/**
	 * Updates post_content with the page's plain text, as Elementor does on
	 * save, so search, excerpts and SEO tools see the new text. Failures
	 * are ignored: the Elementor data is already saved.
	 */
	private static function sync_plain_text( int $post_id, array $tree ): void {
		try {
			$plugin  = \Elementor\Plugin::$instance;
			$dynamic = $plugin->dynamic_tags ?? null;
			$mode    = null;
			$text    = null;
			if ( $dynamic && method_exists( $dynamic, 'get_parsing_mode' ) ) {
				$mode = $dynamic->get_parsing_mode();
				$dynamic->set_parsing_mode( 'remove' );
			}
			$level = ob_get_level();
			ob_start();
			try {
				$text = $plugin->db->get_plain_text_from_data( $tree );
			} finally {
				// A widget that throws leaves Elementor's own buffer open too.
				while ( ob_get_level() > $level ) {
					ob_end_clean();
				}
				if ( $dynamic && null !== $mode ) {
					$dynamic->set_parsing_mode( $mode );
				}
			}
			$post = get_post( $post_id );
			if ( $post && is_string( $text ) && $text !== $post->post_content ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $text,
						)
					)
				);
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Deletes one post's generated CSS and element caches; Elementor
	 * rebuilds them on the next view.
	 */
	public static function clear_post_css( int $post_id ): void {
		if ( self::is_loaded() && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
		$uploads = wp_upload_dir( null, false );
		$file    = trailingslashit( $uploads['basedir'] ) . 'elementor/css/post-' . $post_id . '.css';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
		foreach ( self::CACHE_META_KEYS as $key ) {
			delete_post_meta( $post_id, $key );
		}
		clean_post_cache( $post_id );
	}

	/**
	 * Deletes every generated Elementor CSS file and cache, as Elementor's
	 * Tools > Regenerate files & data does. Needed after kit changes.
	 */
	public static function clear_all_css(): void {
		if ( self::is_loaded() && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
				return;
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
		foreach ( self::CACHE_META_KEYS as $key ) {
			delete_post_meta_by_key( $key );
		}
		delete_option( '_elementor_global_css' );
		$uploads = wp_upload_dir( null, false );
		foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . 'elementor/css/*.css' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Hooked to wproadie_undo_record_reverted: after undo restores
	 * Elementor data or settings, drop the CSS built from the undone
	 * version.
	 *
	 * @param array $record Undo record that was reverted.
	 */
	public static function after_undo( $record ): void {
		if ( ! is_array( $record ) || 'meta' !== ( $record['kind'] ?? '' ) || 'post' !== ( $record['object_type'] ?? '' ) ) {
			return;
		}
		if ( ! in_array( $record['key'] ?? '', array( self::DATA_KEY, self::PAGE_SETTINGS_KEY ), true ) ) {
			return;
		}
		$post_id = (int) ( $record['object_id'] ?? 0 );
		if ( self::is_kit( $post_id ) ) {
			self::clear_all_css();
		} else {
			self::clear_post_css( $post_id );
		}
	}

	/**
	 * Applies kses to every string in a value when the current user may not
	 * post unfiltered HTML, like Elementor and WordPress do on save.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function sanitize( $value ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}
		return Tree::map_strings( $value, 'wp_kses_post' );
	}

	/**
	 * The kses filter for changed text values, or null when the user may
	 * post unfiltered HTML.
	 */
	public static function text_filter(): ?callable {
		return current_user_can( 'unfiltered_html' ) ? null : 'wp_kses_post';
	}

	/**
	 * Whether Elementor uses flexbox containers on this site (new widgets
	 * at the top level go in a container rather than a section).
	 */
	public static function containers_active(): bool {
		if ( self::is_loaded() && isset( \Elementor\Plugin::$instance->experiments ) ) {
			try {
				return (bool) \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' );
			} catch ( Throwable $e ) {
				return true;
			}
		}
		return true;
	}

	/**
	 * Wraps an element that cannot sit at the top level (a widget) in a new
	 * container, or a section and column on sites without containers.
	 *
	 * @param array               $element Element.
	 * @param array<string, true> $taken   IDs in use.
	 */
	public static function wrap_for_top_level( array $element, array &$taken ): array {
		if ( self::containers_active() ) {
			return array(
				'id'       => Tree::new_id( $taken ),
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array( $element ),
				'isInner'  => false,
			);
		}
		return array(
			'id'       => Tree::new_id( $taken ),
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => Tree::new_id( $taken ),
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array( $element ),
					'isInner'  => false,
				),
			),
			'isInner'  => false,
		);
	}

	/**
	 * Checks that every element type in a new element is registered with
	 * Elementor, so the AI does not add widgets that would not render.
	 * Skipped when Elementor's API is not loaded.
	 *
	 * @return true|WP_Error
	 */
	public static function check_types( array $element ) {
		if ( ! self::is_loaded() ) {
			return true;
		}
		$missing = array();
		Tree::walk(
			array( $element ),
			static function ( $el ) use ( &$missing ) {
				$type = (string) ( $el['elType'] ?? '' );
				if ( 'widget' === $type ) {
					$widget = (string) ( $el['widgetType'] ?? '' );
					if ( ! self::widget_type( $widget ) ) {
						$missing[] = $widget;
					}
				} elseif ( ! self::element_type( $type ) ) {
					$missing[] = $type;
				}
			}
		);
		if ( ! $missing ) {
			return true;
		}
		return self::error(
			'unknown_type',
			sprintf(
				/* translators: 1: comma-separated unknown types, 2: comma-separated known widget types. */
				__( 'Elementor on this site has no element or widget type: %1$s. Available widget types include: %2$s.', 'wproadie' ),
				implode( ', ', array_unique( $missing ) ),
				implode( ', ', array_slice( self::widget_names(), 0, 60 ) )
			)
		);
	}

	/**
	 * @return object|null Elementor widget type instance.
	 */
	public static function widget_type( string $name ) {
		try {
			$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $name );
			return $widget ? $widget : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * @return object|null Elementor element type instance.
	 */
	public static function element_type( string $name ) {
		try {
			$element = \Elementor\Plugin::$instance->elements_manager->get_element_types( $name );
			return $element ? $element : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Registered widget type names.
	 *
	 * @return string[]
	 */
	public static function widget_names(): array {
		if ( ! self::is_loaded() ) {
			return array();
		}
		try {
			$names = array_keys( (array) \Elementor\Plugin::$instance->widgets_manager->get_widget_types() );
		} catch ( Throwable $e ) {
			return array();
		}
		// Hide WordPress core widgets wrapped by Elementor; they crowd the list.
		return array_values(
			array_filter(
				$names,
				static function ( $name ) {
					return 0 !== strpos( (string) $name, 'wp-widget-' );
				}
			)
		);
	}

	/**
	 * A compact list of a widget's (or element's) controls: the settings
	 * keys it understands, with type, label, default and options. Skips
	 * layout-only controls and responsive copies (_tablet, _mobile …).
	 *
	 * @param string $el_type     Element type.
	 * @param string $widget_type Widget type for widgets.
	 * @param int    $limit       Maximum controls.
	 * @return array[]
	 */
	public static function controls( string $el_type, string $widget_type, int $limit = 200 ): array {
		if ( ! self::is_loaded() ) {
			return array();
		}
		$type = 'widget' === $el_type ? self::widget_type( $widget_type ) : self::element_type( $el_type );
		if ( ! $type || ! method_exists( $type, 'get_controls' ) ) {
			return array();
		}
		try {
			$controls = (array) $type->get_controls();
		} catch ( Throwable $e ) {
			return array();
		}
		$skip_types = array( 'section', 'tabs', 'tab', 'heading', 'divider', 'raw_html', 'deprecated_notice', 'alert', 'notice', 'button', 'hidden' );
		$out        = array();
		foreach ( $controls as $name => $control ) {
			if ( ! is_array( $control ) || in_array( $control['type'] ?? '', $skip_types, true ) ) {
				continue;
			}
			if ( preg_match( '/_(tablet|mobile|laptop|widescreen|tablet_extra|mobile_extra)$/', (string) $name ) ) {
				continue;
			}
			$item = array(
				'name' => (string) $name,
				'type' => (string) ( $control['type'] ?? '' ),
			);
			if ( ! empty( $control['label'] ) && is_string( $control['label'] ) ) {
				$item['label'] = wp_strip_all_tags( $control['label'] );
			}
			if ( ! empty( $control['tab'] ) && 'content' !== $control['tab'] ) {
				$item['tab'] = (string) $control['tab'];
			}
			if ( isset( $control['default'] ) && '' !== $control['default'] && array() !== $control['default'] ) {
				$json = wp_json_encode( $control['default'] );
				if ( false !== $json && strlen( $json ) <= 200 ) {
					$item['default'] = $control['default'];
				}
			}
			if ( ! empty( $control['options'] ) && is_array( $control['options'] ) && count( $control['options'] ) <= 30 ) {
				$item['options'] = array_map( 'strval', array_keys( $control['options'] ) );
			}
			if ( ! empty( $control['responsive'] ) ) {
				$item['responsive'] = true;
			}
			$out[] = $item;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	public static function edit_url( int $post_id ): string {
		return add_query_arg(
			array(
				'post'   => $post_id,
				'action' => 'elementor',
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * A warning when someone else has the post open in an editor right now:
	 * their next save may overwrite this change.
	 */
	public static function lock_warning( int $post_id ): ?string {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$user_id = wp_check_post_lock( $post_id );
		if ( ! $user_id ) {
			return null;
		}
		$user = get_userdata( $user_id );
		return sprintf(
			/* translators: %s: user display name. */
			__( '%s has this page open in an editor right now. Ask them to reload before saving, or their next save will overwrite this change.', 'wproadie' ),
			$user ? $user->display_name : '#' . $user_id
		);
	}

	/**
	 * Number of elements in stored data, without decoding it.
	 */
	public static function element_count( int $post_id ): int {
		$raw = self::raw_data( $post_id );
		if ( is_array( $raw ) ) {
			return Tree::count( $raw );
		}
		return is_string( $raw ) ? substr_count( $raw, '"elType"' ) : 0;
	}

	/**
	 * Basic facts about a page for list and get output.
	 */
	public static function page_info( WP_Post $post ): array {
		$info     = array(
			'id'       => (int) $post->ID,
			'title'    => $post->post_title,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'link'     => (string) get_permalink( $post ),
			'edit_url' => self::edit_url( (int) $post->ID ),
			'modified' => mysql_to_rfc3339( $post->post_modified ),
		);
		$template = self::template_type( (int) $post->ID );
		if ( '' !== $template ) {
			$info['template_type'] = $template;
		}
		return $info;
	}

	private static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wproadie_' . $code, $message, array( 'status' => $status ) );
	}
}
