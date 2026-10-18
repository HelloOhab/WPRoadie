<?php
/**
 * Shared helpers for the content and media abilities: which post types
 * and meta keys the AI may touch, compact post summaries and term lookup.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class ContentHelper {

	/**
	 * Protected meta keys (leading underscore) the meta tools may still
	 * read and write. Kept deliberately small: each is a plain value that
	 * core understands and that the editor itself lets a user change.
	 *
	 * - _wp_page_template: the page template slug.
	 * - _thumbnail_id: the featured image attachment ID.
	 * - _wp_attachment_image_alt: an attachment's alt text.
	 * - Divi's page settings: builder on/off, page layout, title and side nav.
	 * - Elementor's page markers: edit mode and template type.
	 * Values for these are checked against META_ALLOWED_VALUES.
	 */
	const META_ALLOW_LIST = array(
		'_wp_page_template',
		'_thumbnail_id',
		'_wp_attachment_image_alt',
		'_et_pb_use_builder',
		'_et_pb_page_layout',
		'_et_pb_show_title',
		'_et_pb_side_nav',
		'_elementor_edit_mode',
		'_elementor_template_type',
	);

	/** The values each allow-listed builder key accepts, as the builders store them. */
	const META_ALLOWED_VALUES = array(
		'_et_pb_use_builder'       => array( 'on', 'off' ),
		'_et_pb_page_layout'       => array( 'et_full_width_page', 'et_no_sidebar', 'et_right_sidebar', 'et_left_sidebar' ),
		'_et_pb_show_title'        => array( 'on', 'off' ),
		'_et_pb_side_nav'          => array( 'on', 'off' ),
		'_elementor_edit_mode'     => array( 'builder', '' ),
		'_elementor_template_type' => array( 'wp-page', 'wp-post' ),
	);

	/**
	 * Post types the content tools never handle. Attachments have their own
	 * media tools; the rest are internal records.
	 */
	const EXCLUDED_TYPES = array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face' );

	/**
	 * Statuses an AI may set when creating or updating a post.
	 */
	const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish', 'private', 'future' );

	/**
	 * Post types the content tools work with: shown in wp-admin or public,
	 * minus the excluded internal types.
	 *
	 * @return string[]
	 */
	public static function content_types(): array {
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( in_array( $type->name, self::EXCLUDED_TYPES, true ) ) {
				continue;
			}
			if ( $type->show_ui || $type->public ) {
				$types[] = $type->name;
			}
		}
		return $types;
	}

	/**
	 * Content types the current user can edit posts of.
	 *
	 * @return string[]
	 */
	public static function editable_types(): array {
		return array_values(
			array_filter(
				self::content_types(),
				static function ( $name ) {
					$type = get_post_type_object( $name );
					return $type && current_user_can( $type->cap->edit_posts );
				}
			)
		);
	}

	/**
	 * Checks a post type is one the content tools handle.
	 *
	 * @return true|WP_Error
	 */
	public static function check_type( string $post_type ) {
		if ( ! in_array( $post_type, self::content_types(), true ) ) {
			return new WP_Error(
				'wproadie_invalid_post_type',
				sprintf(
					/* translators: 1: post type, 2: comma-separated list of post types. */
					__( 'Post type "%1$s" is not available. Use one of: %2$s.', 'wproadie' ),
					$post_type,
					implode( ', ', self::editable_types() )
				),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Whether a status needs the publish capability of its post type.
	 */
	public static function needs_publish_cap( string $status ): bool {
		return in_array( $status, array( 'publish', 'future', 'private' ), true );
	}

	/**
	 * Whether the meta tools may read or write a key: unprotected keys and
	 * the small allow-list.
	 */
	public static function meta_key_allowed( string $key ): bool {
		return in_array( $key, self::META_ALLOW_LIST, true ) || ! is_protected_meta( $key, 'post' );
	}

	/**
	 * Whether a value is acceptable for an allow-listed key: the builders'
	 * own vocabularies, and for _wp_page_template a template the theme (or
	 * Elementor) really offers, so a typo cannot leave a page unrenderable.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value to store.
	 * @return true|WP_Error
	 */
	public static function meta_value_check( string $key, $value ) {
		if ( isset( self::META_ALLOWED_VALUES[ $key ] ) ) {
			if ( ! is_string( $value ) || ! in_array( $value, self::META_ALLOWED_VALUES[ $key ], true ) ) {
				return new WP_Error(
					'wproadie_invalid_value',
					sprintf(
						/* translators: 1: meta key, 2: comma-separated list of allowed values. */
						__( '%1$s takes one of: %2$s.', 'wproadie' ),
						$key,
						implode( ', ', array_map( static fn( $v ) => '' === $v ? '"" (empty)' : $v, self::META_ALLOWED_VALUES[ $key ] ) )
					),
					array( 'status' => 400 )
				);
			}
			return true;
		}
		if ( '_wp_page_template' === $key ) {
			if ( ! is_string( $value ) || ! in_array( $value, self::page_templates(), true ) ) {
				return new WP_Error(
					'wproadie_invalid_value',
					sprintf(
						/* translators: %s: comma-separated list of templates. */
						__( 'That page template is not offered by the active theme. Available: %s.', 'wproadie' ),
						implode( ', ', self::page_templates() )
					),
					array( 'status' => 400 )
				);
			}
		}
		return true;
	}

	/**
	 * Page template slugs this site accepts: "default", the theme's page
	 * templates, and Elementor's when it is active.
	 *
	 * @return string[]
	 */
	public static function page_templates(): array {
		$templates = array( 'default' );
		foreach ( wp_get_theme()->get_page_templates( null, 'page' ) as $file => $name ) {
			$templates[] = (string) $file;
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			array_push( $templates, 'elementor_header_footer', 'elementor_canvas', 'elementor_theme' );
		}
		return array_values( array_unique( $templates ) );
	}

	/**
	 * A short plain-text excerpt: the manual excerpt if set, else the start
	 * of the content.
	 */
	public static function short_excerpt( WP_Post $post, int $words = 25 ): string {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		$text = excerpt_remove_blocks( strip_shortcodes( $text ) );
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		return wp_trim_words( $text, $words, '…' );
	}

	/**
	 * MySQL datetime to "Y-m-d\TH:i:s", or null for an unset date.
	 */
	public static function date( string $mysql ): ?string {
		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return null;
		}
		return mysql_to_rfc3339( $mysql );
	}

	/**
	 * Parses a date the AI sent, in site time unless it has an offset.
	 *
	 * @return array{0: string, 1: string}|WP_Error Local and GMT MySQL datetimes.
	 */
	public static function parse_date( string $date ) {
		$dates = rest_get_date_with_gmt( $date );
		if ( ! $dates ) {
			return new WP_Error( 'wproadie_invalid_date', __( 'Invalid date. Use the site\'s local time as YYYY-MM-DDTHH:MM:SS, or add a UTC offset.', 'wproadie' ), array( 'status' => 400 ) );
		}
		return $dates;
	}

	/**
	 * A meta value for output: unserialized already by WordPress, then cut
	 * to a preview string if its JSON is longer than $max bytes.
	 *
	 * @param mixed $value     Meta value.
	 * @param int   $max       Maximum JSON length to return as is.
	 * @param bool  $truncated Set to true when the value was cut.
	 * @return mixed
	 */
	public static function meta_value( $value, int $max, bool &$truncated = false ) {
		if ( is_object( $value ) && ! $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			$truncated = true;
			return __( '[value cannot be shown as JSON]', 'wproadie' );
		}
		if ( strlen( $json ) <= $max ) {
			return $value;
		}
		$truncated = true;
		return sprintf(
			/* translators: 1: size in bytes, 2: start of the value. */
			__( '[truncated, %1$d bytes] %2$s', 'wproadie' ),
			strlen( $json ),
			mb_strcut( is_string( $value ) ? $value : $json, 0, $max )
		);
	}

	/**
	 * Terms of a post grouped by taxonomy, for taxonomies shown in wp-admin.
	 *
	 * @return array<string, array[]>
	 */
	public static function post_terms( WP_Post $post ): array {
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->show_ui && ! $taxonomy->public ) {
				continue;
			}
			$found = get_the_terms( $post, $taxonomy->name );
			if ( ! $found || is_wp_error( $found ) ) {
				continue;
			}
			$terms[ $taxonomy->name ] = array_map( array( self::class, 'term_brief' ), $found );
		}
		return $terms;
	}

	/**
	 * @param \WP_Term $term Term.
	 */
	public static function term_brief( $term ): array {
		return array(
			'id'   => (int) $term->term_id,
			'name' => $term->name,
			'slug' => $term->slug,
		);
	}

	/**
	 * Taxonomies the content tools work with: shown in wp-admin, and the
	 * current user can assign or manage their terms.
	 *
	 * @return string[]
	 */
	public static function available_taxonomies(): array {
		$names = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			if ( current_user_can( $taxonomy->cap->assign_terms ) || current_user_can( $taxonomy->cap->manage_terms ) ) {
				$names[] = $taxonomy->name;
			}
		}
		return $names;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function check_taxonomy( string $taxonomy ) {
		if ( ! in_array( $taxonomy, self::available_taxonomies(), true ) ) {
			return new WP_Error(
				'wproadie_invalid_taxonomy',
				sprintf(
					/* translators: 1: taxonomy, 2: comma-separated list of taxonomies. */
					__( 'Taxonomy "%1$s" is not available. Use one of: %2$s.', 'wproadie' ),
					$taxonomy,
					implode( ', ', self::available_taxonomies() )
				),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Finds a term by ID, or by slug and then name for a string.
	 *
	 * @param int|string $ref      Term ID, slug or name.
	 * @param string     $taxonomy Taxonomy.
	 * @return \WP_Term|null
	 */
	public static function find_term( $ref, string $taxonomy ) {
		if ( is_int( $ref ) || ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
			$term = get_term( (int) $ref, $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? $term : null;
		}
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		foreach ( array( 'slug', 'name' ) as $field ) {
			$term = get_term_by( $field, $ref, $taxonomy );
			if ( $term ) {
				return $term;
			}
		}
		return null;
	}
}
