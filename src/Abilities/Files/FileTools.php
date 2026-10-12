<?php
/**
 * Shared helpers for the file tools: the file-edit switch, display paths,
 * atomic writes and the after-write site check.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Files;

use WP_Error;
use WPRoadie\Safety\Guardrails;

defined( 'ABSPATH' ) || exit;

final class FileTools {

	/**
	 * Largest amount of file content read-file returns in one call.
	 */
	const MAX_READ_BYTES = 204800;

	/**
	 * Whether wp-config.php turns off file editing. WPRoadie honours
	 * DISALLOW_FILE_EDIT (and DISALLOW_FILE_MODS, which implies it) the
	 * same way wp-admin's theme and plugin editors do: reads still work,
	 * writes and deletes do not.
	 *
	 * @param bool|null $edit Value of DISALLOW_FILE_EDIT, for testing.
	 * @param bool|null $mods Value of DISALLOW_FILE_MODS, for testing.
	 */
	public static function edits_disallowed( ?bool $edit = null, ?bool $mods = null ): bool {
		if ( null === $edit ) {
			$edit = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		}
		if ( null === $mods ) {
			$mods = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		}
		return $edit || $mods;
	}

	public static function edits_disallowed_reason(): string {
		return __( 'File editing is turned off on this site (DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS in wp-config.php), so WPRoadie will not write or delete files. Files can still be listed and read.', 'wproadie' );
	}

	public static function edits_disallowed_error(): WP_Error {
		return new WP_Error( 'wproadie_file_edit_disabled', self::edits_disallowed_reason(), array( 'status' => 403 ) );
	}

	/**
	 * Path as shown to the AI: relative to wp-content when inside it,
	 * otherwise absolute.
	 *
	 * @param string $path Absolute path.
	 */
	public static function display_path( string $path ): string {
		$path    = wp_normalize_path( $path );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/';
		$real    = realpath( WP_CONTENT_DIR );
		$real    = false !== $real ? untrailingslashit( wp_normalize_path( $real ) ) . '/' : $content;
		foreach ( array( $content, $real ) as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return substr( $path, strlen( $prefix ) );
			}
		}
		return $path;
	}

	/**
	 * Whether a file can be written by write-file (path rules only).
	 *
	 * @param string $path Path (absolute or relative to wp-content).
	 */
	public static function is_writable_path( string $path ): bool {
		return is_string( Guardrails::resolve_path( $path, true ) );
	}

	/**
	 * Whether new files may be created inside a folder.
	 *
	 * @param string $dir Folder path.
	 */
	public static function is_writable_dir( string $dir ): bool {
		return self::is_writable_path( untrailingslashit( $dir ) . '/wproadie-probe.txt' );
	}

	public static function is_binary( string $bytes ): bool {
		return false !== strpos( $bytes, "\0" );
	}

	public static function modified( string $path ): ?string {
		$time = filemtime( $path );
		return false === $time ? null : gmdate( 'c', $time );
	}

	/**
	 * Whether a path is inside WPRoadie's own folder.
	 *
	 * @param string $real Normalized real path.
	 */
	public static function in_own_dir( string $real ): bool {
		$own = untrailingslashit( wp_normalize_path( Guardrails::own_dir() ) );
		return $real === $own || 0 === strpos( $real, $own . '/' );
	}

	/**
	 * Whether a path is the active child theme's style.css, which the
	 * theme cannot work without.
	 *
	 * @param string $real Normalized real path.
	 */
	public static function is_theme_stylesheet( string $real ): bool {
		$dir = realpath( get_stylesheet_directory() );
		if ( false === $dir ) {
			return false;
		}
		return untrailingslashit( wp_normalize_path( $dir ) ) . '/style.css' === $real;
	}

	/**
	 * Writes a file atomically: a temporary file in the same folder, then
	 * rename over the target, so a reader (or a crash) never sees half a
	 * file. Keeps the existing file's permissions.
	 *
	 * @param string $path    Absolute path, already checked by Guardrails.
	 * @param string $content New content.
	 * @return true|WP_Error
	 */
	public static function write_atomic( string $path, string $content ) {
		$dir  = dirname( $path );
		$temp = $dir . '/.wproadie-' . wp_generate_password( 12, false ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic write needs a real temp file next to the target; WP_Filesystem has no rename.
		if ( strlen( $content ) !== file_put_contents( $temp, $content, LOCK_EX ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
			return new WP_Error( 'wproadie_write_failed', __( 'Could not write the file (is the folder writable by the web server?). Nothing was changed.', 'wproadie' ), array( 'status' => 500 ) );
		}

		$mode = file_exists( $path ) ? ( fileperms( $path ) & 0777 ) : ( defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		chmod( $temp, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Keep the original permissions on the temp file before it replaces the target.

		if ( ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace.
			wp_delete_file( $temp );
			return new WP_Error( 'wproadie_write_failed', __( 'Could not replace the file. Nothing was changed.', 'wproadie' ), array( 'status' => 500 ) );
		}

		self::invalidate( $path );
		return true;
	}

	/**
	 * Drops a file from OPcache so the next request runs the new code.
	 *
	 * @param string $path Absolute path.
	 */
	public static function invalidate( string $path ): void {
		clearstatcache( true, $path );
		if ( function_exists( 'opcache_invalidate' ) && 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			opcache_invalidate( $path, true );
		}
	}

	/**
	 * Puts a file back the way an Undo::file() record says it was.
	 *
	 * @param array $record Undo::file() record.
	 */
	public static function restore( array $record ): bool {
		$path = (string) $record['path'];
		if ( empty( $record['existed'] ) ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			$ok = ! file_exists( $path );
		} else {
			$ok = ! empty( $record['backup'] ) && copy( (string) $record['backup'], $path );
		}
		self::invalidate( $path );
		return $ok;
	}

	/**
	 * Requests the home page once and classifies the answer.
	 *
	 * @return array{state: string, detail: string} state is "ok", "broken"
	 *               or "unreachable".
	 */
	public static function probe_site(): array {
		$url      = add_query_arg( 'wproadie_check', wp_generate_password( 8, false ), home_url( '/' ) );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => (int) apply_filters( 'wproadie_file_check_timeout', 8 ),
				'redirection' => 3,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'state'  => 'unreachable',
				'detail' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 500 ) {
			return array(
				'state'  => 'broken',
				/* translators: %d: HTTP status code. */
				'detail' => sprintf( __( 'the home page returned HTTP %d', 'wproadie' ), $code ),
			);
		}
		if ( false !== stripos( $body, 'There has been a critical error on this website' ) || preg_match( '#<b>(Fatal|Parse) error</b>:#i', $body ) ) {
			return array(
				'state'  => 'broken',
				'detail' => __( 'the home page shows a PHP fatal error', 'wproadie' ),
			);
		}
		return array(
			'state'  => 'ok',
			/* translators: %d: HTTP status code. */
			'detail' => sprintf( __( 'the home page returned HTTP %d', 'wproadie' ), $code ),
		);
	}

	/**
	 * Runs a PHP file change with a before/after check of the home page.
	 * If the site worked before and is broken after, the change is rolled
	 * back from its undo record and an error is returned.
	 *
	 * The check is skipped (and the change kept) when the site cannot be
	 * reached over HTTP, or was already failing before the change.
	 *
	 * @param array    $record Undo::file() record for the path.
	 * @param callable $change Makes the change; returns true or WP_Error.
	 * @return array|WP_Error Verification info on success.
	 */
	public static function change_with_check( array $record, callable $change ) {
		if ( ! apply_filters( 'wproadie_file_check_enabled', true ) ) {
			$done = $change();
			return is_wp_error( $done ) ? $done : array(
				'verified' => false,
				'check'    => __( 'Site check is turned off.', 'wproadie' ),
			);
		}

		$before = self::probe_site();
		$done   = $change();
		if ( is_wp_error( $done ) ) {
			return $done;
		}

		if ( 'unreachable' === $before['state'] ) {
			return array(
				'verified' => false,
				/* translators: %s: error message. */
				'check'    => sprintf( __( 'Could not verify the site still loads: the site could not be reached over HTTP from the server (%s). Check the site in a browser.', 'wproadie' ), $before['detail'] ),
			);
		}
		if ( 'broken' === $before['state'] ) {
			return array(
				'verified' => false,
				/* translators: %s: what the check saw. */
				'check'    => sprintf( __( 'Could not verify: the site was already failing before this change (%s).', 'wproadie' ), $before['detail'] ),
			);
		}

		$after = self::probe_site();
		if ( 'broken' === $after['state'] ) {
			$restored = self::restore( $record );
			return new WP_Error(
				'wproadie_site_broken',
				$restored
					/* translators: %s: what the check saw. */
					? sprintf( __( 'The change broke the site (%s), so it was rolled back automatically. The file is as it was before. Fix the code and try again; check debug.log for the PHP error.', 'wproadie' ), $after['detail'] )
					/* translators: %s: what the check saw. */
					: sprintf( __( 'The change broke the site (%s) and rolling it back FAILED. Restore the file by hand now.', 'wproadie' ), $after['detail'] ),
				array(
					'status'      => 500,
					'rolled_back' => $restored,
				)
			);
		}
		if ( 'unreachable' === $after['state'] ) {
			return array(
				'verified' => false,
				/* translators: %s: error message. */
				'check'    => sprintf( __( 'Could not verify the site still loads: the request after the change failed (%s). Check the site in a browser.', 'wproadie' ), $after['detail'] ),
			);
		}
		return array(
			'verified' => true,
			/* translators: %s: what the check saw. */
			'check'    => sprintf( __( 'Site still loads (%s).', 'wproadie' ), $after['detail'] ),
		);
	}
}
