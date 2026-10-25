<?php
/**
 * Shared helpers for the Forms abilities: WPForms' storage, read and
 * written the way WPForms itself does it (verified against 2.0.3).
 *
 * A form is a `wpforms` post whose post_content is JSON: id, field_id
 * (next id), fields (by id), settings (title, submit text, notifications,
 * confirmations, toggles) and meta. Toggles are the string "1" when on
 * and absent when off. Saving goes through WPForms' own handler when it
 * is loaded, so its hooks and sanitizing run.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Forms;

use Throwable;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class WPForms {

	const POST_TYPE = 'wpforms';

	/** Field settings the AI may change, per field. */
	const FIELD_KEYS = array( 'label', 'description', 'placeholder', 'required', 'default_value', 'size', 'css', 'label_hide', 'sublabel_hide', 'choices', 'show_values', 'limit_enabled', 'limit_count', 'limit_mode', 'format', 'confirmation', 'multiple' );

	/** Form settings the AI may change. */
	const SETTING_KEYS = array( 'form_title', 'form_desc', 'submit_text', 'submit_text_processing', 'notification_enable', 'ajax_submit', 'antispam_v3', 'store_spam_entries', 'form_class', 'honeypot' );

	const NOTIFICATION_KEYS = array( 'notification_name', 'email', 'subject', 'sender_name', 'sender_address', 'replyto', 'carboncopy', 'message' );

	const CONFIRMATION_KEYS = array( 'name', 'type', 'message', 'page', 'redirect', 'message_scroll' );

	/** Settings stored as "1" or absent. */
	const TOGGLES = array( 'required', 'label_hide', 'sublabel_hide', 'show_values', 'limit_enabled', 'multiple', 'notification_enable', 'ajax_submit', 'antispam_v3', 'honeypot', 'message_scroll' );

	/** Smart tags notifications and confirmations may use. */
	const SMART_TAGS = array( '{all_fields}', '{field_id="N"}', '{entry_id}', '{entry_date}', '{form_name}', '{admin_email}', '{site_name}', '{page_title}', '{page_url}', '{user_ip}', '{user_email}', '{user_display}', '{date format="F j, Y"}' );

	// Availability.


	/** Whether WPForms (Lite or Pro) is active on this site. */
	public static function is_active(): bool {
		$active = defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' );
		/**
		 * Filters whether WPRoadie treats WPForms as active (tests, unusual
		 * setups).
		 *
		 * @param bool $active Detected state.
		 */
		return (bool) apply_filters( 'wproadie_forms_available', $active );
	}

	public static function unavailable_reason(): string {
		return __( 'WPForms is not active on this site.', 'wproadie' );
	}

	public static function version(): string {
		return defined( 'WPFORMS_VERSION' ) ? (string) WPFORMS_VERSION : '';
	}

	public static function is_pro(): bool {
		try {
			return function_exists( 'wpforms' ) && method_exists( wpforms(), 'is_pro' ) && (bool) wpforms()->is_pro();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/** WPForms' form handler, when loaded. */
	public static function handler(): ?object {
		try {
			if ( ! function_exists( 'wpforms' ) ) {
				return null;
			}
			$handler = method_exists( wpforms(), 'obj' ) ? wpforms()->obj( 'form' ) : ( wpforms()->form ?? null );
			return is_object( $handler ) ? $handler : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/** WPForms' entry handler (Pro), when loaded. */
	public static function entries(): ?object {
		try {
			if ( ! function_exists( 'wpforms' ) ) {
				return null;
			}
			$handler = method_exists( wpforms(), 'obj' ) ? wpforms()->obj( 'entry' ) : ( wpforms()->entry ?? null );
			return is_object( $handler ) ? $handler : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	public static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wproadie_forms_' . $code, $message, array( 'status' => $status ) );
	}

	// Reading.


	/**
	 * @return WP_Post|WP_Error
	 */
	public static function post( int $id ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return self::error( 'not_found', __( 'No WPForms form with that ID. forms-list shows them.', 'wproadie' ), 404 );
		}
		return $post;
	}

	/**
	 * A form's definition (decoded JSON), or an error.
	 *
	 * @return array|WP_Error
	 */
	public static function data( WP_Post $post ) {
		$raw = (string) $post->post_content;
		if ( '' === trim( $raw ) ) {
			return self::error( 'empty_form', __( 'This form has no definition yet.', 'wproadie' ) );
		}
		$data = function_exists( 'wpforms_decode' ) ? wpforms_decode( $raw ) : json_decode( wp_unslash( $raw ), true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( $raw, true );
		}
		if ( ! is_array( $data ) ) {
			return self::error( 'bad_form', __( 'This form\'s definition is not valid JSON; open it in the form builder.', 'wproadie' ) );
		}
		return $data;
	}

	/** A field as the tools describe it. */
	public static function describe_field( array $field ): array {
		$out = array(
			'id'    => (string) ( $field['id'] ?? '' ),
			'type'  => (string) ( $field['type'] ?? '' ),
			'label' => (string) ( $field['label'] ?? '' ),
		);
		if ( ! empty( $field['required'] ) ) {
			$out['required'] = true;
		}
		foreach ( array( 'placeholder', 'description', 'default_value', 'size', 'format', 'css' ) as $key ) {
			if ( isset( $field[ $key ] ) && '' !== $field[ $key ] ) {
				$out[ $key ] = $field[ $key ];
			}
		}
		if ( ! empty( $field['label_hide'] ) ) {
			$out['label_hidden'] = true;
		}
		if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
			$choices = array();
			foreach ( $field['choices'] as $key => $choice ) {
				if ( is_array( $choice ) ) {
					$row = array(
						'key'   => (string) $key,
						'label' => (string) ( $choice['label'] ?? '' ),
					);
					if ( isset( $choice['value'] ) && '' !== $choice['value'] ) {
						$row['value'] = (string) $choice['value'];
					}
					if ( ! empty( $choice['default'] ) ) {
						$row['default'] = true;
					}
					$choices[] = $row;
				}
			}
			$out['choices'] = $choices;
		}
		if ( ! empty( $field['conditional_logic'] ) ) {
			$out['conditional_logic'] = true;
		}
		return $out;
	}

	/** The entry count of a form (Pro), or null when entries are not stored. */
	public static function entry_count( int $form_id ): ?int {
		$entries = self::entries();
		if ( ! $entries || ! method_exists( $entries, 'get_entries' ) ) {
			return null;
		}
		try {
			return (int) $entries->get_entries( array( 'form_id' => $form_id ), true );
		} catch ( Throwable $e ) {
			return null;
		}
	}

	// Writing.


	/**
	 * Saves a form's definition: through WPForms' handler when it is
	 * loaded (its hooks, meta preservation and tag stripping run), else
	 * as WPForms stores it.
	 *
	 * @return true|WP_Error
	 */
	public static function save( WP_Post $post, array $data ) {
		$data['id'] = (int) $post->ID;
		$handler    = self::handler();
		if ( $handler && method_exists( $handler, 'update' ) ) {
			try {
				// The handler unslashes what it is given, as it does for the builder's POST.
				$saved = $handler->update( (int) $post->ID, wp_slash( $data ), array( 'cap' => false ) );
			} catch ( Throwable $e ) {
				return self::error( 'save_failed', $e->getMessage(), 500 );
			}
			if ( ! $saved ) {
				return self::error( 'save_failed', __( 'WPForms did not save the form.', 'wproadie' ), 500 );
			}
			return true;
		}
		$encoded = function_exists( 'wpforms_encode' ) ? wpforms_encode( $data ) : wp_slash( wp_json_encode( $data ) );
		$result  = wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_title'   => isset( $data['settings']['form_title'] ) ? esc_html( (string) $data['settings']['form_title'] ) : $post->post_title,
				'post_excerpt' => isset( $data['settings']['form_desc'] ) ? (string) $data['settings']['form_desc'] : $post->post_excerpt,
				'post_content' => $encoded,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Applies one dotted-path change to a form definition, within what the
	 * tools allow. Toggles become "1" or are removed.
	 *
	 * @param array  $data  Form definition.
	 * @param string $path  Dotted path.
	 * @param mixed  $value Value; null removes.
	 * @return array|WP_Error Updated data.
	 */
	public static function apply( array $data, string $path, $value ) {
		$parts = explode( '.', $path );
		$root  = $parts[0] ?? '';
		$leaf  = end( $parts );

		if ( 'fields' === $root ) {
			$id = $parts[1] ?? '';
			if ( '' === $id || ! isset( $data['fields'][ $id ] ) ) {
				return self::error( 'no_field', sprintf( 'No field "%s" on this form. forms-get lists the fields with their ids.', $id ) );
			}
			$key = $parts[2] ?? '';
			if ( ! in_array( $key, self::FIELD_KEYS, true ) ) {
				return self::error( 'bad_path', sprintf( 'fields.%s.%s cannot be changed here. Field settings that can: %s. Adding, removing or retyping fields is done in the form builder.', $id, $key, implode( ', ', self::FIELD_KEYS ) ) );
			}
			if ( 'choices' === $key ) {
				if ( 3 === count( $parts ) ) {
					if ( ! is_array( $value ) ) {
						return self::error( 'bad_value', 'choices takes a list of {"label": "…", "value": "…"}.' );
					}
					$choices = array();
					$n       = 1;
					foreach ( $value as $choice ) {
						if ( ! is_array( $choice ) || ! isset( $choice['label'] ) ) {
							return self::error( 'bad_value', 'Each choice needs a label.' );
						}
						$row = array(
							'label' => sanitize_text_field( (string) $choice['label'] ),
							'value' => isset( $choice['value'] ) ? sanitize_text_field( (string) $choice['value'] ) : '',
						);
						if ( ! empty( $choice['default'] ) ) {
							$row['default'] = '1';
						}
						$choices[ $n++ ] = $row;
					}
					$data['fields'][ $id ]['choices'] = $choices;
					return $data;
				}
				$choice = $parts[3] ?? '';
				$sub    = $parts[4] ?? '';
				if ( ! isset( $data['fields'][ $id ]['choices'][ $choice ] ) || ! in_array( $sub, array( 'label', 'value', 'default' ), true ) ) {
					return self::error( 'bad_path', sprintf( 'Choice paths are fields.%s.choices.<key>.label|value|default, or fields.%s.choices for the whole list.', $id, $id ) );
				}
				if ( 'default' === $sub ) {
					if ( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ) {
						$data['fields'][ $id ]['choices'][ $choice ]['default'] = '1';
					} else {
						unset( $data['fields'][ $id ]['choices'][ $choice ]['default'] );
					}
					return $data;
				}
				$data['fields'][ $id ]['choices'][ $choice ][ $sub ] = sanitize_text_field( (string) $value );
				return $data;
			}
			return self::set_leaf( $data, array( 'fields', $id, $key ), $key, $value );
		}

		if ( 'settings' === $root ) {
			$key = $parts[1] ?? '';
			if ( 'notifications' === $key || 'confirmations' === $key ) {
				$id      = $parts[2] ?? '';
				$sub     = $parts[3] ?? '';
				$allowed = 'notifications' === $key ? self::NOTIFICATION_KEYS : self::CONFIRMATION_KEYS;
				if ( '' === $id || ! isset( $data['settings'][ $key ][ $id ] ) ) {
					return self::error( 'no_item', sprintf( 'No %s "%s" on this form. forms-get lists them with their ids.', rtrim( $key, 's' ), $id ) );
				}
				if ( ! in_array( $sub, $allowed, true ) ) {
					return self::error( 'bad_path', sprintf( 'settings.%s.%s.%s cannot be changed here. Keys that can: %s.', $key, $id, $sub, implode( ', ', $allowed ) ) );
				}
				if ( 'type' === $sub && ! in_array( $value, array( 'message', 'page', 'redirect' ), true ) ) {
					return self::error( 'bad_value', 'A confirmation type is "message", "page" or "redirect".' );
				}
				if ( 'page' === $sub && ! get_post( (int) $value ) ) {
					return self::error( 'bad_value', 'A confirmation page is the ID of an existing page.' );
				}
				if ( 'redirect' === $sub && '' === esc_url_raw( (string) $value ) ) {
					return self::error( 'bad_value', 'A confirmation redirect is a full URL.' );
				}
				return self::set_leaf( $data, array( 'settings', $key, $id, $sub ), $sub, $value );
			}
			if ( ! in_array( $key, self::SETTING_KEYS, true ) || count( $parts ) !== 2 ) {
				return self::error( 'bad_path', sprintf( 'settings.%s cannot be changed here. Settings that can: %s, plus settings.notifications.<id>.<key> and settings.confirmations.<id>.<key>.', $key, implode( ', ', self::SETTING_KEYS ) ) );
			}
			return self::set_leaf( $data, array( 'settings', $key ), $key, $value );
		}

		return self::error( 'bad_path', sprintf( '"%s" is not a path the tool changes. Paths start with fields.<id>. or settings.', $path ) );
	}

	/**
	 * Sets (or removes) one leaf of the definition.
	 *
	 * @param array    $data  Form definition.
	 * @param string[] $path  Keys down to the leaf.
	 * @param string   $key   The setting name, for its type.
	 * @param mixed    $value Value.
	 * @return array
	 */
	private static function set_leaf( array $data, array $path, string $key, $value ): array {
		$ref = &$data;
		foreach ( array_slice( $path, 0, -1 ) as $part ) {
			if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) {
				$ref[ $part ] = array();
			}
			$ref = &$ref[ $part ];
		}
		$last = end( $path );
		if ( in_array( $key, self::TOGGLES, true ) ) {
			if ( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ) {
				$ref[ $last ] = '1';
			} else {
				unset( $ref[ $last ] );
			}
		} elseif ( null === $value || '' === $value ) {
			unset( $ref[ $last ] );
		} elseif ( in_array( $key, array( 'message', 'description' ), true ) ) {
			$ref[ $last ] = wp_kses_post( (string) $value );
		} else {
			$ref[ $last ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
		}
		return $data;
	}
}
