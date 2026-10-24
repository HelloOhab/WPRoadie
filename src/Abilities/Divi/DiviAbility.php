<?php
/**
 * Base class for the Divi abilities: group, availability, the post and
 * module arguments they share, and saving an edited page with undo.
 *
 * Abstract, so the Registry never loads it as an ability itself.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WP_Error;
use WP_Post;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Content\CreatePost;
use WPRoadie\Log\Undo;

defined( 'ABSPATH' ) || exit;

abstract class DiviAbility extends AbilityBase {

	public function group(): string {
		return 'divi';
	}

	public function is_available(): bool {
		return Divi::is_active();
	}

	public function unavailable_reason(): string {
		return Divi::is_active() ? '' : Divi::unavailable_reason();
	}

	/**
	 * Tools that take a post ID need edit_post on it, reads included:
	 * outlines show drafts and private pages.
	 *
	 * @param array $input Validated input.
	 */
	protected function permission( array $input ): bool {
		if ( empty( $input['id'] ) ) {
			return true;
		}
		$post = get_post( (int) $input['id'] );
		if ( ! $post ) {
			// Reported as not found by execute().
			return true;
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Schema for the page ID.
	 */
	protected static function id_property(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the Divi page, post, library layout or Theme Builder layout (from divi-list-pages, divi-list-layouts or divi-list-theme-builder).', 'wproadie' ),
		);
	}

	/**
	 * Schema for addressing one module: path, admin label or CSS ID.
	 */
	protected static function module_properties(): array {
		return array(
			'path'   => array(
				'type'        => 'string',
				'pattern'     => '^[0-9]+(\.[0-9]+)*$',
				'description' => __( 'Module path from divi-get-page, e.g. "0.1.0.2" = section 0, row 1, column 0, module 2. Sections, rows and columns have paths too.', 'wproadie' ),
			),
			'label'  => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'Instead of path: the module\'s admin label (case-insensitive; must be unique on the page).', 'wproadie' ),
			),
			'css_id' => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => __( 'Instead of path: the module\'s CSS ID.', 'wproadie' ),
			),
		);
	}

	/**
	 * Refuses edits inside a global element: Divi renders those from the
	 * Divi Library item, so a change on the page would not show (and is
	 * overwritten the next time the global item is saved).
	 *
	 * @param Document $doc        Page.
	 * @param array    $entry      Entry from Document::all().
	 * @param bool     $allow_root Whether the global element itself may be
	 *                             handled as a whole (moved, removed, copied).
	 * @return true|\WP_Error
	 */
	/**
	 * Refuses Divi 4 attribute names the module would silently ignore,
	 * naming the likely right one and the module's fields by toggle. Passes
	 * when Divi's definitions are not loadable or the module is unknown to
	 * them (an add-on not loaded here).
	 *
	 * @param string $type  Full module slug.
	 * @param array  $attrs Attributes to check.
	 * @param string $where Where the module is, for the message.
	 * @return true|WP_Error
	 */
	protected function check_attributes_4( string $type, array $attrs, string $where = '' ) {
		if ( ! $attrs ) {
			return true;
		}
		$fields = Schema::fields_4( $type );
		if ( null === $fields || ! $fields ) {
			return true;
		}
		$unknown = Schema::unknown_4( $fields, $attrs );
		if ( ! $unknown ) {
			return true;
		}
		$suggestions = Schema::suggest( $fields, $unknown );
		$hints       = array();
		foreach ( $suggestions as $key => $meant ) {
			$hints[] = sprintf( '%s → %s', $key, $meant );
		}
		$message = sprintf(
			/* translators: 1: module slug, 2: comma-separated attribute names, 3: where. */
			__( 'Divi would ignore these attributes on %1$s: %2$s%3$s.', 'wproadie' ),
			$type,
			implode( ', ', $unknown ),
			'' !== $where ? ' (' . $where . ')' : ''
		);
		if ( $hints ) {
			/* translators: %s: "wrong → right" pairs. */
			$message .= ' ' . sprintf( __( 'Did you mean: %s.', 'wproadie' ), implode( '; ', $hints ) );
		}
		$message .= ' ' . __( 'The attributes this module takes are in the error\'s schema (names by tab/toggle); divi-get-module-schema has the details. To store the names anyway, send allow_unknown_attributes: true.', 'wproadie' );
		return new WP_Error(
			'wproadie_divi_unknown_attribute',
			$message,
			array(
				'status'      => 400,
				'unknown'     => $unknown,
				'suggestions' => $suggestions,
				'schema'      => Schema::outline_4( $fields ),
			)
		);
	}

	protected function check_not_global( Document $doc, array $entry, bool $allow_root = false ) {
		if ( '' === $entry['global'] ) {
			return true;
		}
		if ( $allow_root && $doc->global_id( $entry['node'] ) === $entry['global'] ) {
			return true;
		}
		return Divi::error(
			'global_module',
			sprintf(
				/* translators: %d: layout post ID. */
				__( 'This is (or is inside) a global Divi Library item (layout ID %1$d). Divi shows the library version on every page that uses it, so a change here would not show. Edit the library item instead: divi-get-page and divi-update-module with id %2$d (that changes it everywhere it is used).', 'wproadie' ),
				(int) $entry['global'],
				(int) $entry['global']
			),
			409
		);
	}

	/**
	 * Saves an edited page: records undo (a revision is saved too), writes
	 * through wp_update_post() and clears Divi's caches for it.
	 *
	 * @param WP_Post  $post Post being changed.
	 * @param Document $doc  Edited document.
	 * @return array|\WP_Error Facts for the tool result.
	 */
	protected function save( WP_Post $post, Document $doc ) {
		$content = $doc->to_string();
		if ( $content === $post->post_content ) {
			return array(
				'id'      => (int) $post->ID,
				'changed' => false,
			);
		}
		if ( strlen( $content ) > Divi::MAX_CONTENT ) {
			return Divi::error( 'too_large', __( 'The page would become too large. Nothing was changed.', 'wproadie' ), 413 );
		}
		$checked = Divi::check_save_filters( $content, $doc->format );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$undo = Undo::post( $post->ID );
		$this->add_undo( $undo );

		// A page whose Divi content was written straight into post_content
		// (never opened in the visual builder) lacks the settings the builder
		// records on save: builder on, full width, title hidden, and on Divi 5
		// its two markers. Without them WordPress renders it as an ordinary
		// page, and Divi 5's first builder open would bury the layout. They
		// are written before the content: Divi memoizes "is this a builder
		// page" per request, and save_post listeners ask during the update.
		$marked = array();
		if ( ! in_array( $post->post_type, Divi::LAYOUT_TYPES, true ) && 'on' !== get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			$settings = CreatePost::page_settings( array( 'builder' => 'divi' ), $doc->format );
			if ( ! is_wp_error( $settings ) ) {
				$marked = CreatePost::apply_page_settings(
					(int) $post->ID,
					$settings,
					function ( array $record ) {
						$this->add_undo( $record );
					}
				);
			}
			if ( 'on' !== get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
				return Divi::error( 'builder_meta_blocked', __( 'The page could not be marked as a Divi Builder page (a plugin or filter blocks _et_pb_use_builder), so the content was not written: the builder would treat it as classic content.', 'wproadie' ), 409 );
			}
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$result = array(
			'id'          => (int) $post->ID,
			'changed'     => true,
			'revision_id' => isset( $undo['revision_id'] ) ? (int) $undo['revision_id'] : null,
			'cache'       => Divi::is_active() ? Divi::clear_cache( (int) $post->ID ) : array(),
		);

		if ( $marked ) {
			$result['page_settings'] = $marked;
			$result['note']          = __( 'This page had never been saved in the Divi Builder, so its page settings were recorded too: builder on, full width, title hidden.', 'wproadie' );
		}
		$link = get_permalink( $post->ID );
		if ( $link && ! in_array( $post->post_type, Divi::LAYOUT_TYPES, true ) ) {
			$result['link'] = (string) $link;
		}

		$result['verification'] = $this->verify( (int) $post->ID, $content, $doc->format );

		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$lock = wp_check_post_lock( $post->ID );
		if ( $lock ) {
			$user              = get_userdata( $lock );
			$result['warning'] = sprintf(
				/* translators: %s: user display name. */
				__( '%s has this page open in an editor right now. If they save, their version replaces this change; tell them, or undo this change.', 'wproadie' ),
				$user ? $user->display_name : __( 'Someone', 'wproadie' )
			);
			$result['verification']['warnings'][] = $result['warning'];
		}
		return $result;
	}

	/**
	 * Proof that a write landed, and what the saved page looks like now: a
	 * write response alone is not proof. The AI reads it after every write.
	 *
	 * @param int    $post_id The page.
	 * @param string $written The content that was sent to wp_update_post().
	 * @param int    $format  4 or 5.
	 */
	protected function verify( int $post_id, string $written, int $format ): array {
		clean_post_cache( $post_id );
		$saved = (string) get_post_field( 'post_content', $post_id, 'raw' );
		if ( 5 === $format ) {
			$verified = $saved === $written || Divi::same_blocks( parse_blocks( $written ), parse_blocks( $saved ) );
		} else {
			$verified = $saved === $written;
		}
		$fresh    = new Document( $saved, $format );
		$empty    = $fresh->empty_structural();
		$code     = $fresh->code_modules();
		$warnings = array();
		if ( ! $verified ) {
			$warnings[] = __( 'The saved page differs from what was written (a plugin changed it on save). Read it again with divi-get-page before the next edit; do not retry blindly.', 'wproadie' );
		}
		if ( $empty ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated paths. */
				__( 'Nothing inside: %s. Fill or remove these before finishing.', 'wproadie' ),
				implode( ', ', $empty )
			);
		}
		if ( $code ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated paths. */
				__( 'Code modules (raw HTML) at %s: fine only for a snippet the person asked to embed, never to fake a layout or add CSS.', 'wproadie' ),
				implode( ', ', $code )
			);
		}
		return array(
			'verified'         => $verified,
			'module_count'     => $fresh->module_count(),
			'empty_structural' => $empty,
			'raw_html_modules' => $code,
			'warnings'         => $warnings,
		);
	}
}
