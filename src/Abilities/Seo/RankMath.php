<?php
/**
 * Shared helpers for the SEO abilities: Rank Math's storage, read and
 * written the way Rank Math itself does it (verified against 1.0.280).
 *
 * Every per-post and per-term value is a `rank_math_<key>` meta row. An
 * empty value means "inherit the site-wide template"; Rank Math deletes
 * the row rather than storing an empty string, and so do we.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Seo;

use Throwable;
use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class RankMath {

	/** Robots tokens Rank Math stores (follow is implied; only nofollow is stored). */
	const ROBOTS = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	/** Advanced robots keys and what they take. */
	const ADVANCED_ROBOTS = array( 'max-snippet', 'max-video-preview', 'max-image-preview' );

	/** Rank Math's default for focus keywords per item. */
	const MAX_KEYWORDS = 5;

	/** Rank Math's own recommended lengths. */
	const TITLE_LENGTH       = 60;
	const DESCRIPTION_LENGTH = 160;

	/**
	 * The SEO fields the tools read and write, with how each is stored.
	 * Text fields: plain string (Rank Math strips tags). Flags: "on" or the
	 * row deleted. Lists: serialized arrays.
	 */
	const FIELDS = array(
		'title'                => 'text',
		'description'          => 'text',
		'focus_keywords'       => 'keywords',
		'robots'               => 'robots',
		'advanced_robots'      => 'advanced_robots',
		'canonical_url'        => 'url',
		'breadcrumb_title'     => 'text',
		'pillar_content'       => 'flag',
		'facebook_title'       => 'text',
		'facebook_description' => 'text',
		'facebook_image'       => 'image',
		'twitter_use_facebook' => 'on_off',
		'twitter_title'        => 'text',
		'twitter_description'  => 'text',
		'twitter_image'        => 'image',
		'twitter_card_type'    => 'card',
	);

	// Availability.


	/** Whether Rank Math is active on this site. */
	public static function is_active(): bool {
		$active = defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math' ) || class_exists( '\\RankMath\\Helper' );
		/**
		 * Filters whether WPRoadie treats Rank Math as active, which
		 * decides whether the SEO tools are offered (tests, unusual setups).
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'wproadie_seo_available', $active );
	}

	/**
	 * Post types worth auditing: public and searchable, not a builder's
	 * library (Divi layouts, Elementor templates) or attachments. Any type
	 * can still be audited by name.
	 *
	 * @return string[]
	 */
	public static function indexable_post_types(): array {
		$skip  = array( 'attachment', 'et_pb_layout', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_template', 'et_theme_builder', 'et_code_snippet', 'elementor_library', 'e-landing-page', 'e-floating-buttons', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' );
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, $skip, true ) || $type->exclude_from_search ) {
				continue;
			}
			$types[] = $type->name;
		}
		return $types;
	}

	public static function unavailable_reason(): string {
		return __( 'Rank Math SEO is not active on this site.', 'wproadie' );
	}

	public static function version(): string {
		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
	}

	public static function pro_version(): string {
		return defined( 'RANK_MATH_PRO_VERSION' ) ? (string) RANK_MATH_PRO_VERSION : '';
	}

	/** Whether Rank Math's PHP classes are loaded (not only detected). */
	public static function loaded(): bool {
		return class_exists( '\\RankMath\\Helper' ) && function_exists( 'rank_math' );
	}

	public static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wproadie_seo_' . $code, $message, array( 'status' => $status ) );
	}

	// Meta: posts and terms share every key.


	/**
	 * Meta key for a field as Rank Math stores it.
	 */
	public static function meta_key( string $field ): string {
		return 'rank_math_' . ( 'focus_keywords' === $field ? 'focus_keyword' : $field );
	}

	/**
	 * Raw stored value of a field ("" when none).
	 *
	 * @return mixed
	 */
	public static function raw( string $object_type, int $object_id, string $field ) {
		return get_metadata( $object_type, $object_id, self::meta_key( $field ), true );
	}

	/**
	 * The stored SEO fields of a post or term, in the tool's shape: the
	 * keys set, with lists as lists and flags as booleans.
	 *
	 * @return array<string, mixed>
	 */
	public static function read( string $object_type, int $object_id ): array {
		$out = array();
		foreach ( self::FIELDS as $field => $kind ) {
			$raw = self::raw( $object_type, $object_id, $field );
			if ( '' === $raw || null === $raw || false === $raw || array() === $raw ) {
				continue;
			}
			switch ( $kind ) {
				case 'keywords':
					$out[ $field ] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'strlen' ) );
					break;
				case 'robots':
					$out[ $field ] = array_values( array_filter( array_map( 'strval', (array) $raw ), 'strlen' ) );
					break;
				case 'advanced_robots':
					$out[ $field ] = array_filter(
						(array) $raw,
						static function ( $v ) {
							return false !== $v && '' !== $v && null !== $v;
						}
					);
					break;
				case 'flag':
					$out[ $field ] = 'on' === $raw;
					break;
				case 'on_off':
					$out[ $field ] = 'off' !== $raw;
					break;
				default:
					$out[ $field ] = is_scalar( $raw ) ? (string) $raw : $raw;
			}
		}
		if ( 'post' === $object_type ) {
			$score = get_post_meta( $object_id, 'rank_math_seo_score', true );
			if ( '' !== $score ) {
				$out['seo_score'] = (int) $score;
			}
			$legacy = get_post_meta( $object_id, 'rank_math_rich_snippet', true );
			if ( '' !== $legacy ) {
				$out['schema_type'] = (string) $legacy;
			}
			$types = self::schema_types( $object_id );
			if ( $types ) {
				$out['schemas'] = $types;
			}
			$primary = self::primary_term( $object_id );
			if ( $primary ) {
				$out['primary_term'] = $primary;
			}
		}
		return $out;
	}

	/**
	 * The @type of every custom schema on a post (rank_math_schema_<Type>).
	 *
	 * @return string[]
	 */
	public static function schema_types( int $post_id ): array {
		$types = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 === strpos( (string) $key, 'rank_math_schema_' ) ) {
				$types[] = substr( (string) $key, strlen( 'rank_math_schema_' ) );
			}
		}
		return $types;
	}

	/**
	 * The primary term Rank Math records for a post, if any.
	 *
	 * @return array{taxonomy: string, term_id: int, name: string}|null
	 */
	public static function primary_term( int $post_id ): ?array {
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, 'rank_math_primary_' ) ) {
				continue;
			}
			$taxonomy = substr( (string) $key, strlen( 'rank_math_primary_' ) );
			$term     = get_term( (int) ( $values[0] ?? 0 ), $taxonomy );
			if ( $term instanceof WP_Term ) {
				return array(
					'taxonomy' => $taxonomy,
					'term_id'  => (int) $term->term_id,
					'name'     => $term->name,
				);
			}
		}
		return null;
	}

	/**
	 * Validates and normalizes one field's new value into what Rank Math
	 * stores. Null (or an empty value) means "remove the row".
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Value from the AI.
	 * @return array{0: mixed, 1: string[]}|WP_Error Stored value (null to delete) and warnings.
	 */
	public static function normalize( string $field, $value ) {
		$kind     = self::FIELDS[ $field ] ?? null;
		$warnings = array();
		if ( null === $kind ) {
			return self::error( 'bad_field', sprintf( '"%s" is not an SEO field. Fields: %s.', $field, implode( ', ', array_keys( self::FIELDS ) ) ) );
		}
		if ( 'flag' === $kind ) {
			return array( null !== $value && '' !== $value && filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'on' : null, $warnings );
		}
		if ( 'on_off' === $kind ) {
			// Empty means Rank Math's default, which is on.
			return array( null === $value || '' === $value || filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? null : 'off', $warnings );
		}
		if ( null === $value || '' === $value || array() === $value || false === $value ) {
			return array( null, $warnings );
		}
		switch ( $kind ) {
			case 'text':
				if ( ! is_scalar( $value ) ) {
					return self::error( 'bad_value', sprintf( '%s must be text.', $field ) );
				}
				$text   = trim( wp_filter_nohtml_kses( (string) $value ) );
				$length = mb_strlen( $text );
				if ( 'title' === $field && $length > self::TITLE_LENGTH && false === strpos( $text, '%' ) ) {
					$warnings[] = sprintf( 'The title is %d characters; Google shows about %d.', $length, self::TITLE_LENGTH );
				}
				if ( 'description' === $field && $length > self::DESCRIPTION_LENGTH && false === strpos( $text, '%' ) ) {
					$warnings[] = sprintf( 'The description is %d characters; Google shows about %d.', $length, self::DESCRIPTION_LENGTH );
				}
				return array( '' === $text ? null : $text, $warnings );

			case 'keywords':
				$list = is_array( $value ) ? $value : explode( ',', (string) $value );
				$list = array_values(
					array_unique(
						array_filter(
							array_map(
								static function ( $k ) {
									return trim( sanitize_text_field( (string) $k ) );
								},
								$list
							),
							'strlen'
						)
					)
				);
				if ( count( $list ) > self::MAX_KEYWORDS ) {
					return self::error( 'bad_value', sprintf( 'Rank Math takes at most %d focus keywords; the first is the primary one.', self::MAX_KEYWORDS ) );
				}
				return array( $list ? implode( ',', $list ) : null, $warnings );

			case 'robots':
				$list = array_values( array_unique( array_map( 'strtolower', array_map( 'strval', (array) $value ) ) ) );
				$bad  = array_diff( $list, self::ROBOTS );
				if ( $bad ) {
					return self::error( 'bad_value', sprintf( 'robots takes only %s (follow is implied; leave the list out to inherit the site default); not: %s.', implode( ', ', self::ROBOTS ), implode( ', ', $bad ) ) );
				}
				if ( in_array( 'index', $list, true ) && in_array( 'noindex', $list, true ) ) {
					return self::error( 'bad_value', 'robots cannot hold both index and noindex.' );
				}
				return array( $list ? $list : null, $warnings );

			case 'advanced_robots':
				if ( ! is_array( $value ) ) {
					return self::error( 'bad_value', 'advanced_robots must be an object such as {"max-snippet": -1, "max-image-preview": "large"}.' );
				}
				$clean = array();
				foreach ( $value as $key => $v ) {
					if ( ! in_array( $key, self::ADVANCED_ROBOTS, true ) ) {
						return self::error( 'bad_value', sprintf( 'advanced_robots takes only %s.', implode( ', ', self::ADVANCED_ROBOTS ) ) );
					}
					if ( 'max-image-preview' === $key ) {
						if ( ! in_array( $v, array( 'large', 'standard', 'none' ), true ) ) {
							return self::error( 'bad_value', 'max-image-preview is "large", "standard" or "none".' );
						}
						$clean[ $key ] = $v;
					} else {
						$clean[ $key ] = (int) $v;
					}
				}
				return array( $clean ? $clean : null, $warnings );

			case 'url':
				$url = esc_url_raw( (string) $value );
				if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
					return self::error( 'bad_value', sprintf( '%s must be a valid URL.', $field ) );
				}
				return array( $url, $warnings );

			case 'image':
				// A URL, or an attachment ID from the media library.
				if ( is_int( $value ) || ctype_digit( (string) $value ) ) {
					$url = wp_get_attachment_url( (int) $value );
					if ( ! $url ) {
						return self::error( 'bad_value', sprintf( 'No media item with ID %d.', (int) $value ) );
					}
					return array(
						array(
							'url' => $url,
							'id'  => (int) $value,
						),
						$warnings,
					);
				}
				$url = esc_url_raw( (string) $value );
				if ( '' === $url ) {
					return self::error( 'bad_value', sprintf( '%s must be an image URL or a media library ID.', $field ) );
				}
				return array(
					array(
						'url' => $url,
						'id'  => (int) attachment_url_to_postid( $url ),
					),
					$warnings,
				);

			case 'card':
				if ( ! in_array( $value, array( 'summary_large_image', 'summary' ), true ) ) {
					return self::error( 'bad_value', 'twitter_card_type is "summary_large_image" or "summary".' );
				}
				return array( $value, $warnings );
		}
		return array( $value, $warnings );
	}

	/**
	 * The meta keys a field occupies, for undo records taken before a
	 * write. Images use two rows (URL and attachment ID).
	 *
	 * @param string $field Field name.
	 * @return string[]
	 */
	public static function keys_for( string $field ): array {
		$key = self::meta_key( $field );
		return 'image' === ( self::FIELDS[ $field ] ?? '' ) ? array( $key, $key . '_id' ) : array( $key );
	}

	/**
	 * Writes one normalized value (deleting the row for null). Images set
	 * both the URL and the ID rows, as Rank Math's metabox does.
	 *
	 * @param string $object_type "post" or "term".
	 * @param int    $object_id   Object ID.
	 * @param string $field       Field name.
	 * @param mixed  $stored      From normalize().
	 */
	public static function write( string $object_type, int $object_id, string $field, $stored ): void {
		$key = self::meta_key( $field );
		if ( 'image' === ( self::FIELDS[ $field ] ?? '' ) ) {
			if ( null === $stored ) {
				delete_metadata( $object_type, $object_id, $key );
				delete_metadata( $object_type, $object_id, $key . '_id' );
				return;
			}
			update_metadata( $object_type, $object_id, $key, $stored['url'] );
			if ( ! empty( $stored['id'] ) ) {
				update_metadata( $object_type, $object_id, $key . '_id', (int) $stored['id'] );
			} else {
				delete_metadata( $object_type, $object_id, $key . '_id' );
			}
			return;
		}
		if ( null === $stored ) {
			delete_metadata( $object_type, $object_id, $key );
		} else {
			update_metadata( $object_type, $object_id, $key, $stored );
		}
	}

	// Effective values (what visitors and Google see).


	/**
	 * A Rank Math setting ("titles.pt_post_title"), or $fallback when it
	 * is not set.
	 *
	 * @param string $path     "group.key".
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	public static function setting( string $path, $fallback = null ) {
		if ( self::loaded() ) {
			try {
				return \RankMath\Helper::get_settings( $path, $fallback );
			} catch ( Throwable $e ) {
				return $fallback;
			}
		}
		list( $group, $key ) = array_pad( explode( '.', $path, 2 ), 2, '' );
		$options             = (array) get_option( 'rank-math-options-' . $group, array() );
		return array_key_exists( $key, $options ) ? $options[ $key ] : $fallback;
	}

	/**
	 * Rank Math variables (%title%, %sitename% …) replaced for a post or
	 * term, when Rank Math is loaded; otherwise the common ones.
	 *
	 * @param string               $template Text with %variables%.
	 * @param WP_Post|WP_Term|null $context  Post or term.
	 */
	public static function replace_vars( string $template, $context ): string {
		if ( '' === $template ) {
			return '';
		}
		if ( self::variables_ready() ) {
			try {
				$out = (string) \RankMath\Helper::replace_vars( $template, $context );
			} catch ( Throwable $e ) {
				return self::basic_vars( $template, $context );
			}
			// Anything Rank Math left (a variable it does not know in this
			// request) is expanded or removed the way it would on the front end.
			return preg_match( '/%[a-z_]+(\([^)]*\))?%/', $out ) ? self::basic_vars( $out, $context ) : $out;
		}
		return self::basic_vars( $template, $context );
	}

	/**
	 * Whether Rank Math's variable replacer has been primed this request.
	 *
	 * @var bool
	 */
	private static $primed = false;

	/**
	 * Rank Math registers its %variables% on the front-end `wp` hook (or
	 * admin_enqueue_scripts), which never fires in a REST or MCP request:
	 * its replacer would then replace nothing, and cache that. Run its
	 * (idempotent) setup once and clear the cache it may have filled.
	 */
	public static function variables_ready(): bool {
		if ( ! self::loaded() ) {
			return false;
		}
		if ( ! self::$primed ) {
			self::$primed = true;
			try {
				$manager = rank_math()->variables ?? null;
				if ( $manager && method_exists( $manager, 'setup' ) ) {
					$manager->setup();
				}
				if ( class_exists( '\RankMath\Replace_Variables\Replacer' ) ) {
					\RankMath\Replace_Variables\Replacer::$replacements_cache = array();
				}
			} catch ( Throwable $e ) {
				return false;
			}
		}
		try {
			$manager = rank_math()->variables ?? null;
			return $manager && method_exists( $manager, 'get_replacements' ) && (array) $manager->get_replacements();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Text with every shortcode removed, registered or not. In a REST
	 * request Divi registers none of its shortcodes, so WordPress's
	 * strip_shortcodes() would leave "[et_pb_section ...]" in a description;
	 * on the front end the page shows the text inside them.
	 */
	public static function strip_all_shortcodes( string $text ): string {
		$text = (string) preg_replace( '/\[\/?[a-zA-Z0-9_-]+(?:\s[^\]]*)?\]/s', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	}

	/**
	 * The common Rank Math variables, expanded without Rank Math (its PHP
	 * is not loaded in every request). Unknown variables are removed, as
	 * Rank Math does.
	 *
	 * @param string               $template Text with %variables%.
	 * @param WP_Post|WP_Term|null $context  Post or term.
	 */
	public static function basic_vars( string $template, $context ): string {
		$sep  = html_entity_decode( (string) self::setting( 'titles.title_separator', '-' ), ENT_QUOTES, 'UTF-8' );
		$vars = array(
			'%sitename%'    => get_bloginfo( 'name' ),
			'%sitedesc%'    => get_bloginfo( 'description' ),
			'%sep%'         => $sep,
			'%page%'        => '',
			'%currentyear%' => gmdate( 'Y' ),
			'%currentdate%' => date_i18n( get_option( 'date_format' ) ),
		);
		if ( $context instanceof WP_Post ) {
			$excerpt    = '' !== trim( (string) $context->post_excerpt ) ? $context->post_excerpt : $context->post_content;
			$excerpt    = self::strip_all_shortcodes( (string) $excerpt );
			$vars      += array(
				'%title%'           => $context->post_title,
				'%excerpt%'         => mb_substr( $excerpt, 0, self::DESCRIPTION_LENGTH ),
				'%excerpt_only%'    => trim( wp_strip_all_tags( (string) $context->post_excerpt ) ),
				'%seo_title%'       => $context->post_title,
				'%seo_description%' => mb_substr( $excerpt, 0, self::DESCRIPTION_LENGTH ),
				'%date%'            => get_the_date( '', $context ),
				'%modified%'        => get_the_modified_date( '', $context ),
				'%url%'             => get_permalink( $context ),
			);
			$categories = get_the_terms( $context, 'category' );
			$vars      += array(
				'%category%'   => is_array( $categories ) && $categories ? $categories[0]->name : '',
				'%categories%' => is_array( $categories ) ? implode( ', ', wp_list_pluck( $categories, 'name' ) ) : '',
			);
			$parent     = $context->post_parent ? get_post( $context->post_parent ) : null;
			$vars      += array( '%parent_title%' => $parent ? $parent->post_title : '' );
		} elseif ( $context instanceof WP_Term ) {
			$vars += array(
				'%term%'             => $context->name,
				'%term_description%' => trim( wp_strip_all_tags( (string) $context->description ) ),
				'%title%'            => $context->name,
			);
		}
		$out = strtr( $template, $vars );
		$out = preg_replace( '/%[a-z_]+(\([^)]*\))?%/', '', $out );
		return trim( preg_replace( '/\s{2,}/', ' ', (string) $out ) );
	}

	/**
	 * What Rank Math will output for a post: title, description and
	 * robots, from the stored meta or the site templates.
	 *
	 * @return array{title: string, description: string, robots: string[], source: array<string, string>}
	 */
	public static function effective_post( WP_Post $post, array $stored ): array {
		$type   = $post->post_type;
		$source = array();
		if ( false !== strpos( $post->post_content, '[' ) || false !== strpos( $post->post_excerpt, '[' ) ) {
			$clean               = clone $post;
			$clean->post_content = self::strip_all_shortcodes( $post->post_content );
			$clean->post_excerpt = self::strip_all_shortcodes( $post->post_excerpt );
			$post                = $clean;
		}

		if ( isset( $stored['title'] ) ) {
			$title           = self::replace_vars( $stored['title'], $post );
			$source['title'] = 'custom';
		} else {
			$title           = self::replace_vars( (string) self::setting( "titles.pt_{$type}_title", '%title% %sep% %sitename%' ), $post );
			$source['title'] = 'template';
		}
		if ( isset( $stored['description'] ) ) {
			$description           = self::replace_vars( $stored['description'], $post );
			$source['description'] = 'custom';
		} elseif ( '' !== trim( (string) $post->post_excerpt ) ) {
			$description           = wp_strip_all_tags( $post->post_excerpt );
			$source['description'] = 'excerpt';
		} else {
			$description           = self::replace_vars( (string) self::setting( "titles.pt_{$type}_description", '%excerpt%' ), $post );
			$source['description'] = 'template';
		}
		if ( ! empty( $stored['robots'] ) ) {
			$robots           = $stored['robots'];
			$source['robots'] = 'custom';
		} elseif ( 'on' === self::setting( "titles.pt_{$type}_custom_robots", 'off' ) || true === self::setting( "titles.pt_{$type}_custom_robots", false ) ) {
			$robots           = (array) self::setting( "titles.pt_{$type}_robots", array( 'index' ) );
			$source['robots'] = 'post type setting';
		} else {
			$robots           = (array) self::setting( 'titles.robots_global', array( 'index' ) );
			$source['robots'] = 'site default';
		}
		if ( 'private' === $post->post_status || '' !== (string) $post->post_password ) {
			$robots           = array_values( array_unique( array_merge( array( 'noindex' ), array_diff( $robots, array( 'index' ) ) ) ) );
			$source['robots'] = 'private or password-protected';
		}
		if ( ! in_array( 'noindex', $robots, true ) && ! in_array( 'index', $robots, true ) ) {
			array_unshift( $robots, 'index' );
		}
		return array(
			'title'       => html_entity_decode( trim( $title ), ENT_QUOTES, 'UTF-8' ),
			'description' => html_entity_decode( trim( $description ), ENT_QUOTES, 'UTF-8' ),
			'robots'      => array_values( $robots ),
			'source'      => $source,
		);
	}

	/**
	 * What Rank Math will output for a term.
	 *
	 * @return array{title: string, description: string, robots: string[], source: array<string, string>}
	 */
	public static function effective_term( WP_Term $term, array $stored ): array {
		$tax    = $term->taxonomy;
		$source = array();
		if ( isset( $stored['title'] ) ) {
			$title           = self::replace_vars( $stored['title'], $term );
			$source['title'] = 'custom';
		} else {
			$title           = self::replace_vars( (string) self::setting( "titles.tax_{$tax}_title", '%term% %sep% %sitename%' ), $term );
			$source['title'] = 'template';
		}
		if ( isset( $stored['description'] ) ) {
			$description           = self::replace_vars( $stored['description'], $term );
			$source['description'] = 'custom';
		} else {
			$description           = self::replace_vars( (string) self::setting( "titles.tax_{$tax}_description", '%term_description%' ), $term );
			$source['description'] = 'template';
		}
		if ( ! empty( $stored['robots'] ) ) {
			$robots           = $stored['robots'];
			$source['robots'] = 'custom';
		} elseif ( 'on' === self::setting( "titles.tax_{$tax}_custom_robots", 'off' ) || true === self::setting( "titles.tax_{$tax}_custom_robots", false ) ) {
			$robots           = (array) self::setting( "titles.tax_{$tax}_robots", array( 'index' ) );
			$source['robots'] = 'taxonomy setting';
		} else {
			$robots           = (array) self::setting( 'titles.robots_global', array( 'index' ) );
			$source['robots'] = 'site default';
		}
		if ( ! in_array( 'noindex', $robots, true ) && ! in_array( 'index', $robots, true ) ) {
			array_unshift( $robots, 'index' );
		}
		return array(
			'title'       => html_entity_decode( trim( $title ), ENT_QUOTES, 'UTF-8' ),
			'description' => html_entity_decode( trim( $description ), ENT_QUOTES, 'UTF-8' ),
			'robots'      => array_values( $robots ),
			'source'      => $source,
		);
	}

	/**
	 * Problems worth fixing on one item, from its stored and effective values.
	 *
	 * @return string[]
	 */
	public static function issues( array $stored, array $effective, bool $indexable_type = true ): array {
		$issues  = array();
		$noindex = in_array( 'noindex', $effective['robots'], true );
		if ( $noindex ) {
			$issues[] = 'noindex';
			return $issues;
		}
		if ( ! $indexable_type ) {
			return $issues;
		}
		if ( ! isset( $stored['title'] ) ) {
			$issues[] = 'no custom title';
		} elseif ( mb_strlen( $effective['title'] ) > self::TITLE_LENGTH ) {
			$issues[] = 'title too long';
		}
		if ( '' === $effective['description'] ) {
			$issues[] = 'no description';
		} elseif ( ! isset( $stored['description'] ) ) {
			$issues[] = 'description from template';
		} elseif ( mb_strlen( $effective['description'] ) > self::DESCRIPTION_LENGTH ) {
			$issues[] = 'description too long';
		} elseif ( mb_strlen( $effective['description'] ) < 50 ) {
			$issues[] = 'description short';
		}
		if ( empty( $stored['focus_keywords'] ) ) {
			$issues[] = 'no focus keyword';
		}
		return $issues;
	}
}
