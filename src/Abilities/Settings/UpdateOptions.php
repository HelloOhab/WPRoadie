<?php
/**
 * Changes site settings on the option allow-list.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Settings;

use WP_Error;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateOptions extends AbilityBase {

	/**
	 * Options that only take one of a few values.
	 */
	const ENUMS = array(
		'show_on_front'          => array( 'posts', 'page' ),
		'default_comment_status' => array( 'open', 'closed' ),
		'default_ping_status'    => array( 'open', 'closed' ),
		'comment_order'          => array( 'asc', 'desc' ),
		'default_comments_page'  => array( 'newest', 'oldest' ),
	);

	/**
	 * Options whose change needs the rewrite rules rebuilt.
	 */
	const REWRITE_OPTIONS = array( 'permalink_structure', 'category_base', 'tag_base' );

	public function slug(): string {
		return 'update-options';
	}

	public function label(): string {
		return __( 'Update options', 'wproadie' );
	}

	public function description(): string {
		return __( 'Changes one or more site settings at once, e.g. {"options": {"blogname": "Acme", "timezone_string": "Europe/London"}}. Only options on WPRoadie\'s writable allow-list can be changed (get-options returns the list); if any name is not allowed, or any value is invalid, nothing is changed. Values are sanitized the way the Settings screens do. Checks: show_on_front "page" needs a published page in page_on_front; page_on_front and page_for_posts must be pages and must differ; timezone_string must be a valid timezone identifier or "" (use gmt_offset with timezone_string "" for a fixed UTC offset); WPLANG must be an installed language or "" for English. Changing permalink_structure, category_base or tag_base rebuilds the rewrite rules. Returns the old and new value of each option. Returns an undo_id; pass it to undo-change to restore the old values.', 'wproadie' );
	}

	public function group(): string {
		return 'settings';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'options' => array(
					'type'          => 'object',
					'minProperties' => 1,
					'description'   => __( 'Option name to new value.', 'wproadie' ),
				),
			),
			'required'             => array( 'options' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$options = (array) $input['options'];
		if ( ! $options ) {
			return $this->error( 'no_options', __( 'Pass at least one option to change.', 'wproadie' ) );
		}

		$blocked = array();
		foreach ( array_keys( $options ) as $name ) {
			if ( ! Guardrails::can_write_option( (string) $name ) ) {
				$blocked[] = (string) $name;
			}
		}
		if ( $blocked ) {
			return $this->error(
				'option_not_writable',
				sprintf(
					/* translators: %s: comma-separated option names. */
					__( 'These options cannot be changed by WPRoadie: %s. Nothing was changed. Use get-options to see the writable list.', 'wproadie' ),
					implode( ', ', $blocked )
				),
				403
			);
		}

		// Validate and sanitize everything before changing anything.
		$new = array();
		foreach ( $options as $name => $value ) {
			$name  = (string) $name;
			$value = $this->normalize( $name, $value );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$new[ $name ] = sanitize_option( $name, $value );
		}

		$valid = $this->validate( $new );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$changes = array();
		$rewrite = false;
		foreach ( $new as $name => $value ) {
			$old = get_option( $name );
			$this->add_undo( Undo::option( $name ) );
			update_option( $name, $value );
			$changes[ $name ] = array(
				'old' => $old,
				'new' => get_option( $name ),
			);
			if ( in_array( $name, self::REWRITE_OPTIONS, true ) && $old !== $value ) {
				$rewrite = true;
			}
		}

		if ( $rewrite ) {
			global $wp_rewrite;
			$wp_rewrite->init();
			flush_rewrite_rules();
		}

		return array(
			'changed'         => $changes,
			'rewrite_flushed' => $rewrite,
		);
	}

	/**
	 * Checks a value's type and format before sanitize_option() (which
	 * silently keeps the old value for some invalid input).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value from the AI.
	 * @return mixed|WP_Error
	 */
	private function normalize( string $name, $value ) {
		if ( null === $value ) {
			/* translators: %s: option name. */
			return $this->error( 'invalid_value', sprintf( __( '%s cannot be null. Use "" or 0.', 'wproadie' ), $name ) );
		}
		if ( is_bool( $value ) ) {
			$value = $value ? 1 : 0;
		}
		if ( ! is_scalar( $value ) && ! is_array( get_option( $name ) ) ) {
			/* translators: %s: option name. */
			return $this->error( 'invalid_value', sprintf( __( '%s takes a string or number.', 'wproadie' ), $name ) );
		}

		if ( isset( self::ENUMS[ $name ] ) && ! in_array( $value, self::ENUMS[ $name ], true ) ) {
			return $this->error(
				'invalid_value',
				/* translators: 1: option name, 2: allowed values. */
				sprintf( __( '%1$s must be one of: %2$s.', 'wproadie' ), $name, implode( ', ', self::ENUMS[ $name ] ) )
			);
		}

		switch ( $name ) {
			case 'timezone_string':
				$value = (string) $value;
				if ( '' !== $value && ! in_array( $value, timezone_identifiers_list(), true ) ) {
					return $this->error( 'invalid_timezone', __( 'timezone_string must be a timezone identifier such as "Europe/London", or "" to use gmt_offset.', 'wproadie' ) );
				}
				break;

			case 'gmt_offset':
				if ( ! is_numeric( $value ) || (float) $value < -12 || (float) $value > 14 ) {
					return $this->error( 'invalid_value', __( 'gmt_offset must be a number of hours between -12 and 14.', 'wproadie' ) );
				}
				break;

			case 'WPLANG':
				$value = (string) $value;
				if ( 'en_US' === $value ) {
					$value = '';
				}
				if ( '' !== $value && ! in_array( $value, get_available_languages(), true ) ) {
					return $this->error(
						'language_not_installed',
						sprintf(
							/* translators: %s: comma-separated locales. */
							__( 'That language is not installed. Installed: %s. Use "" for English (United States).', 'wproadie' ),
							implode( ', ', array_merge( array( 'en_US' ), get_available_languages() ) )
						)
					);
				}
				break;

			case 'permalink_structure':
				$value = trim( (string) $value );
				if ( '' !== $value && ! preg_match( '/%[^\/%]+%/', $value ) ) {
					return $this->error( 'invalid_permalink', __( 'permalink_structure needs at least one structure tag such as %postname%, or "" for plain links.', 'wproadie' ) );
				}
				break;

			case 'default_post_format':
				if ( '' !== (string) $value && '0' !== (string) $value && ! in_array( (string) $value, get_post_format_slugs(), true ) ) {
					return $this->error( 'invalid_value', __( 'default_post_format must be a post format slug such as "aside", or "0" for standard.', 'wproadie' ) );
				}
				break;
		}

		return $value;
	}

	/**
	 * Checks the settings that depend on each other or on content, using
	 * the values the site will have after the change.
	 *
	 * @param array $values Sanitized new values.
	 * @return true|WP_Error
	 */
	private function validate( array $values ) {
		$after = function ( $name ) use ( $values ) {
			return array_key_exists( $name, $values ) ? $values[ $name ] : get_option( $name );
		};

		foreach ( array( 'page_on_front', 'page_for_posts' ) as $name ) {
			if ( ! array_key_exists( $name, $values ) || ! (int) $values[ $name ] ) {
				continue;
			}
			$page = get_post( (int) $values[ $name ] );
			if ( ! $page || 'page' !== $page->post_type || in_array( $page->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				/* translators: %s: option name. */
				return $this->error( 'invalid_page', sprintf( __( '%s must be the id of a page.', 'wproadie' ), $name ) );
			}
		}

		if ( 'page' === $after( 'show_on_front' ) && ( array_key_exists( 'show_on_front', $values ) || array_key_exists( 'page_on_front', $values ) ) ) {
			$front = get_post( (int) $after( 'page_on_front' ) );
			if ( ! $front || 'page' !== $front->post_type || 'publish' !== $front->post_status ) {
				return $this->error( 'invalid_front_page', __( 'show_on_front "page" needs page_on_front set to a published page.', 'wproadie' ) );
			}
		}

		$front_id = (int) $after( 'page_on_front' );
		if ( $front_id && $front_id === (int) $after( 'page_for_posts' ) && ( array_key_exists( 'page_on_front', $values ) || array_key_exists( 'page_for_posts', $values ) ) ) {
			return $this->error( 'invalid_page', __( 'page_on_front and page_for_posts cannot be the same page.', 'wproadie' ) );
		}

		if ( array_key_exists( 'gmt_offset', $values ) && ! array_key_exists( 'timezone_string', $values ) && '' !== (string) get_option( 'timezone_string' ) ) {
			return $this->error( 'timezone_conflict', __( 'The site uses timezone_string, which overrides gmt_offset. Also pass timezone_string "" to switch to a fixed offset, or change timezone_string instead.', 'wproadie' ) );
		}

		if ( array_key_exists( 'default_category', $values ) ) {
			$term = get_term( (int) $values['default_category'], 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				return $this->error( 'invalid_category', __( 'default_category must be the id of a category.', 'wproadie' ) );
			}
		}

		if ( array_key_exists( 'site_icon', $values ) && (int) $values['site_icon'] && ! wp_attachment_is_image( (int) $values['site_icon'] ) ) {
			return $this->error( 'invalid_site_icon', __( 'site_icon must be the id of an image in the media library, or 0.', 'wproadie' ) );
		}

		return true;
	}
}
