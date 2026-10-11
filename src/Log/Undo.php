<?php
/**
 * Captures how to reverse a change, and reverses it from the log.
 *
 * Abilities call a capture method before they change something (or just
 * after they create something) and hand the record to add_undo(). The
 * records are stored on the log entry; revert() replays them in reverse.
 *
 * Covered: posts and pages (with a WordPress revision for history), post
 * creation and trashing, post/term/user meta, post terms, term creation,
 * options, theme mods, files, user roles and creation, plugin activation
 * and theme switches. Raw SQL writes and plugin installs/updates cannot be
 * undone this way.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Log;

use WP_Error;
use WPRoadie\Safety\Guardrails;

defined( 'ABSPATH' ) || exit;

final class Undo {

	const POST_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_password', 'comment_status', 'ping_status', 'post_date', 'post_date_gmt', 'post_author' );

	// Capture.


	/**
	 * Before updating a post: saves a WordPress revision (so the change is
	 * visible in the editor's history) and a snapshot of its fields.
	 */
	public static function post( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$revision_id = null;
		if ( wp_revisions_enabled( $post ) ) {
			$saved       = wp_save_post_revision( $post_id );
			$revision_id = $saved ? (int) $saved : null;
		}

		$snapshot = array();
		foreach ( self::POST_FIELDS as $field ) {
			$snapshot[ $field ] = $post->$field;
		}

		return array(
			'kind'        => 'post',
			'post_id'     => $post_id,
			'revision_id' => $revision_id,
			'snapshot'    => $snapshot,
		);
	}

	/**
	 * After creating a post, page, attachment or menu item.
	 */
	public static function post_created( int $post_id ): array {
		return array(
			'kind'    => 'post_created',
			'post_id' => $post_id,
		);
	}

	/**
	 * Before trashing a post.
	 */
	public static function post_trashed( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		return array(
			'kind'    => 'post_trashed',
			'post_id' => $post_id,
			'status'  => $post->post_status,
		);
	}

	/**
	 * Before changing or deleting a meta key on a post, term, user or
	 * comment. Captures every value of the key.
	 */
	public static function meta( string $object_type, int $object_id, string $key ): array {
		return array(
			'kind'        => 'meta',
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'key'         => $key,
			'existed'     => metadata_exists( $object_type, $object_id, $key ),
			'values'      => array_map( 'maybe_serialize', (array) get_metadata( $object_type, $object_id, $key, false ) ),
		);
	}

	/**
	 * Before changing a post's terms in one taxonomy.
	 */
	public static function terms( int $post_id, string $taxonomy ): array {
		$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		return array(
			'kind'     => 'terms',
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy,
			'term_ids' => is_wp_error( $ids ) ? array() : array_map( 'intval', $ids ),
		);
	}

	/**
	 * After creating a term.
	 */
	public static function term_created( int $term_id, string $taxonomy ): array {
		return array(
			'kind'     => 'term_created',
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
		);
	}

	/**
	 * Before changing a term's name, slug, description or parent.
	 */
	public static function term( int $term_id, string $taxonomy ): ?array {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return array(
			'kind'     => 'term',
			'term_id'  => $term_id,
			'taxonomy' => $taxonomy,
			'snapshot' => array(
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent'      => (int) $term->parent,
			),
		);
	}

	/**
	 * Before changing or deleting an option.
	 */
	public static function option( string $name ): array {
		$sentinel = new \stdClass();
		$value    = get_option( $name, $sentinel );
		return array(
			'kind'    => 'option',
			'name'    => $name,
			'existed' => $value !== $sentinel,
			'value'   => $value !== $sentinel ? maybe_serialize( $value ) : null,
		);
	}

	/**
	 * Before changing a theme mod of the active theme, e.g. menu locations.
	 */
	public static function theme_mod( string $name ): array {
		$mods = get_theme_mods();
		return array(
			'kind'       => 'theme_mod',
			'stylesheet' => get_stylesheet(),
			'name'       => $name,
			'existed'    => is_array( $mods ) && array_key_exists( $name, $mods ),
			'value'      => is_array( $mods ) && array_key_exists( $name, $mods ) ? maybe_serialize( $mods[ $name ] ) : null,
		);
	}

	/**
	 * Before writing or deleting a file. Copies the current file to
	 * wp-content/wproadie/backups so it can be put back.
	 *
	 * @param string $path Absolute path, already checked by Guardrails.
	 * @return array|WP_Error
	 */
	public static function file( string $path ) {
		if ( ! file_exists( $path ) ) {
			return array(
				'kind'    => 'file',
				'path'    => $path,
				'existed' => false,
				'backup'  => null,
			);
		}

		$dir = trailingslashit( Guardrails::own_dir() ) . 'backups';
		if ( ! Guardrails::ensure_own_dir() || ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wproadie_backup_failed', __( 'Could not create the backup folder, so the file was not changed.', 'wproadie' ) );
		}
		// Backups end in .bak so they can never be served as PHP.
		$backup = $dir . '/' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false ) . '-' . sanitize_file_name( basename( $path ) ) . '.bak';
		if ( ! copy( $path, $backup ) ) {
			return new WP_Error( 'wproadie_backup_failed', __( 'Could not back up the file, so it was not changed.', 'wproadie' ) );
		}

		return array(
			'kind'    => 'file',
			'path'    => $path,
			'existed' => true,
			'backup'  => $backup,
		);
	}

	/**
	 * Before changing a user's roles.
	 */
	public static function user_roles( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		return array(
			'kind'    => 'user_roles',
			'user_id' => $user_id,
			'roles'   => array_values( $user->roles ),
		);
	}

	/**
	 * After creating a user.
	 */
	public static function user_created( int $user_id ): array {
		return array(
			'kind'    => 'user_created',
			'user_id' => $user_id,
		);
	}

	/**
	 * Before activating or deactivating a plugin.
	 */
	public static function plugin_state( string $plugin_file ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array(
			'kind'   => 'plugin_state',
			'plugin' => $plugin_file,
			'active' => is_plugin_active( $plugin_file ),
		);
	}

	/**
	 * Before switching theme.
	 */
	public static function theme_switch(): array {
		return array(
			'kind'       => 'theme_switch',
			'stylesheet' => get_stylesheet(),
		);
	}

	// Revert.


	/**
	 * Whether a log entry can still be undone.
	 *
	 * @param object $row Log row.
	 */
	public static function is_undoable( $row ): bool {
		return $row && ! empty( $row->undo_data ) && empty( $row->undone_at ) && 'success' === $row->status;
	}

	/**
	 * Reverses the change recorded on a log entry.
	 *
	 * @param int  $log_id Log entry to reverse.
	 * @param bool $record Whether to write a "wproadie/undo" log entry. The
	 *                     undo-change tool passes false: its own call is logged.
	 * @return array{log_id: int, reverted: int, errors: string[]}|WP_Error
	 */
	public static function revert( int $log_id, bool $record = true ) {
		$row = AuditLog::get( $log_id );
		if ( ! $row ) {
			return new WP_Error( 'wproadie_undo_not_found', __( 'No log entry with that ID.', 'wproadie' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $row->undone_at ) ) {
			return new WP_Error( 'wproadie_undo_done', __( 'That change has already been undone.', 'wproadie' ), array( 'status' => 409 ) );
		}
		if ( ! self::is_undoable( $row ) ) {
			return new WP_Error( 'wproadie_undo_unavailable', __( 'That log entry has nothing that can be undone.', 'wproadie' ), array( 'status' => 400 ) );
		}

		$records = json_decode( (string) $row->undo_data, true );
		if ( ! is_array( $records ) ) {
			return new WP_Error( 'wproadie_undo_corrupt', __( 'The undo data for that entry could not be read.', 'wproadie' ), array( 'status' => 500 ) );
		}

		$started  = microtime( true );
		$errors   = array();
		$reverted = 0;
		foreach ( array_reverse( $records ) as $record ) {
			$done = self::apply( (array) $record );
			if ( is_wp_error( $done ) ) {
				$errors[] = $done->get_error_message();
			} else {
				++$reverted;
			}
		}

		$user_id = get_current_user_id();
		AuditLog::mark_undone( $log_id, $user_id );

		$result = array(
			'log_id'   => $log_id,
			'ability'  => $row->ability,
			'reverted' => $reverted,
			'errors'   => $errors,
		);

		if ( $record ) {
			AuditLog::record( 'wproadie/undo', array( 'log_id' => $log_id ), $result, (int) round( ( microtime( true ) - $started ) * 1000 ), array(), $log_id );
		}

		return $result;
	}

	/**
	 * @param array $r One undo record.
	 * @return true|WP_Error
	 */
	private static function apply( array $r ) {
		switch ( $r['kind'] ?? '' ) {
			case 'post':
				if ( ! get_post( (int) $r['post_id'] ) ) {
					return self::fail( __( 'The post no longer exists.', 'wproadie' ) );
				}
				$data       = wp_slash( (array) $r['snapshot'] );
				$data['ID'] = (int) $r['post_id'];
				$updated    = wp_update_post( $data, true );
				return is_wp_error( $updated ) ? $updated : true;

			case 'post_created':
				$post = get_post( (int) $r['post_id'] );
				if ( ! $post ) {
					return true;
				}
				if ( 'attachment' === $post->post_type ) {
					return wp_delete_attachment( $post->ID, true ) ? true : self::fail( __( 'Could not delete the uploaded file.', 'wproadie' ) );
				}
				if ( 'nav_menu_item' === $post->post_type || ! EMPTY_TRASH_DAYS ) {
					return wp_delete_post( $post->ID, true ) ? true : self::fail( __( 'Could not delete the created item.', 'wproadie' ) );
				}
				return wp_trash_post( $post->ID ) ? true : self::fail( __( 'Could not trash the created post.', 'wproadie' ) );

			case 'post_trashed':
				$post = get_post( (int) $r['post_id'] );
				if ( ! $post ) {
					return self::fail( __( 'The post was permanently deleted and cannot be restored.', 'wproadie' ) );
				}
				if ( 'trash' === $post->post_status && ! wp_untrash_post( $post->ID ) ) {
					return self::fail( __( 'Could not restore the post from the trash.', 'wproadie' ) );
				}
				// Untrashing restores to draft by default; put back the old status.
				$updated = wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => (string) $r['status'],
					),
					true
				);
				return is_wp_error( $updated ) ? $updated : true;

			case 'meta':
				$type = (string) $r['object_type'];
				$id   = (int) $r['object_id'];
				$key  = (string) $r['key'];
				delete_metadata( $type, $id, $key );
				if ( ! empty( $r['existed'] ) ) {
					foreach ( (array) $r['values'] as $value ) {
						add_metadata( $type, $id, wp_slash( $key ), wp_slash( maybe_unserialize( $value ) ) );
					}
				}
				return true;

			case 'terms':
				$set = wp_set_object_terms( (int) $r['post_id'], array_map( 'intval', (array) $r['term_ids'] ), (string) $r['taxonomy'] );
				return is_wp_error( $set ) ? $set : true;

			case 'term_created':
				$deleted = wp_delete_term( (int) $r['term_id'], (string) $r['taxonomy'] );
				return is_wp_error( $deleted ) ? $deleted : true;

			case 'term':
				$updated = wp_update_term( (int) $r['term_id'], (string) $r['taxonomy'], wp_slash( (array) $r['snapshot'] ) );
				return is_wp_error( $updated ) ? $updated : true;

			case 'option':
				if ( empty( $r['existed'] ) ) {
					delete_option( (string) $r['name'] );
				} else {
					update_option( (string) $r['name'], maybe_unserialize( $r['value'] ) );
				}
				if ( in_array( $r['name'], array( 'permalink_structure', 'category_base', 'tag_base' ), true ) ) {
					// Re-read the restored structure before rebuilding the rules.
					global $wp_rewrite;
					$wp_rewrite->init();
					flush_rewrite_rules( false );
				}
				if ( 'sidebars_widgets' === $r['name'] ) {
					// WordPress caches this per request; drop the stale copy.
					unset( $GLOBALS['_wp_sidebars_widgets'] );
				}
				return true;

			case 'theme_mod':
				$option = 'theme_mods_' . $r['stylesheet'];
				$mods   = get_option( $option, array() );
				$mods   = is_array( $mods ) ? $mods : array();
				if ( empty( $r['existed'] ) ) {
					unset( $mods[ $r['name'] ] );
				} else {
					$mods[ $r['name'] ] = maybe_unserialize( $r['value'] );
				}
				update_option( $option, $mods );
				return true;

			case 'file':
				$path = (string) $r['path'];
				if ( empty( $r['existed'] ) ) {
					if ( file_exists( $path ) ) {
						wp_delete_file( $path );
					}
					return file_exists( $path ) ? self::fail( __( 'Could not remove the created file.', 'wproadie' ) ) : true;
				}
				if ( empty( $r['backup'] ) || ! is_readable( $r['backup'] ) ) {
					return self::fail( __( 'The file backup is missing.', 'wproadie' ) );
				}
				return copy( $r['backup'], $path ) ? true : self::fail( __( 'Could not restore the file from its backup.', 'wproadie' ) );

			case 'user_roles':
				$user = get_userdata( (int) $r['user_id'] );
				if ( ! $user ) {
					return self::fail( __( 'The user no longer exists.', 'wproadie' ) );
				}
				$user->set_role( '' );
				foreach ( (array) $r['roles'] as $role ) {
					$user->add_role( (string) $role );
				}
				return true;

			case 'user_created':
				if ( ! get_userdata( (int) $r['user_id'] ) ) {
					return true;
				}
				require_once ABSPATH . 'wp-admin/includes/user.php';
				// Their content, if any, goes to whoever runs the undo.
				return wp_delete_user( (int) $r['user_id'], get_current_user_id() ) ? true : self::fail( __( 'Could not delete the created user.', 'wproadie' ) );

			case 'plugin_state':
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$plugin = (string) $r['plugin'];
				if ( ! empty( $r['active'] ) ) {
					$activated = activate_plugin( $plugin );
					return is_wp_error( $activated ) ? $activated : true;
				}
				deactivate_plugins( $plugin );
				return true;

			case 'theme_switch':
				$theme = wp_get_theme( (string) $r['stylesheet'] );
				if ( ! $theme->exists() ) {
					return self::fail( __( 'The previous theme is no longer installed.', 'wproadie' ) );
				}
				switch_theme( $theme->get_stylesheet() );
				return true;
		}

		return self::fail( __( 'Unknown undo record.', 'wproadie' ) );
	}

	private static function fail( string $message ): WP_Error {
		return new WP_Error( 'wproadie_undo_failed', $message );
	}
}
