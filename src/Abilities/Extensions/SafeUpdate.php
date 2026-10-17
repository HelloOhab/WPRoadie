<?php
/**
 * Safe plugin and theme updates: back up, update, check the site still
 * answers, roll back if it does not, and leave an undo record.
 *
 * WordPress's upgrader swaps the files and hopes. This wraps it the way a
 * careful person does it by hand: keep a copy of the old version, run the
 * update, load the site once, and if it now errors put the old version
 * back before anyone notices. Every successful update is recorded so
 * undo-change can revert it later, which plain WordPress cannot do.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Plugin_Upgrader;
use Theme_Upgrader;
use WP_Error;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\PendingUpdates;

defined( 'ABSPATH' ) || exit;

final class SafeUpdate {

	const KIND_PLUGIN = 'plugin';
	const KIND_THEME  = 'theme';

	/** Seconds to wait for the site to answer the health probe. */
	const PROBE_TIMEOUT = 20;

	/**
	 * What would update: every pending update, or the named ones.
	 *
	 * @param string[] $plugins Plugin files, e.g. "akismet/akismet.php".
	 * @param string[] $themes  Theme folder names.
	 * @param bool     $all     Everything with an update waiting.
	 * @return array{items: array[], skipped: array[]} Items to update (kind,
	 *         id, name, from, to) and requested items that cannot update,
	 *         each with a reason.
	 */
	public static function plan( array $plugins, array $themes, bool $all ): array {
		Installer::load();
		$pending_plugins = PendingUpdates::plugins();
		$pending_themes  = PendingUpdates::themes();
		$installed       = get_plugins();

		if ( $all ) {
			$plugins = array_keys( $pending_plugins );
			$themes  = array_keys( $pending_themes );
		}

		$items   = array();
		$skipped = array();
		foreach ( array_unique( $plugins ) as $file ) {
			$file = (string) $file;
			if ( ! isset( $installed[ $file ] ) ) {
				$skipped[] = self::skip( self::KIND_PLUGIN, $file, 'not_installed', __( 'No installed plugin with that file.', 'wproadie' ) );
			} elseif ( Guardrails::is_protected_plugin( $file ) ) {
				if ( ! $all ) {
					$skipped[] = self::skip( self::KIND_PLUGIN, $file, 'protected', __( 'WPRoadie and the MCP Adapter are updated from their own screens, never by the AI.', 'wproadie' ) );
				}
			} elseif ( empty( $pending_plugins[ $file ]->new_version ) ) {
				$skipped[] = self::skip( self::KIND_PLUGIN, $file, 'no_update', __( 'WordPress knows of no update for it.', 'wproadie' ) );
			} else {
				$items[] = array(
					'kind' => self::KIND_PLUGIN,
					'id'   => $file,
					'name' => (string) $installed[ $file ]['Name'],
					'from' => (string) $installed[ $file ]['Version'],
					'to'   => (string) $pending_plugins[ $file ]->new_version,
				);
			}
		}
		foreach ( array_unique( $themes ) as $stylesheet ) {
			$stylesheet = (string) $stylesheet;
			$theme      = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				$skipped[] = self::skip( self::KIND_THEME, $stylesheet, 'not_installed', __( 'No installed theme with that folder name.', 'wproadie' ) );
			} elseif ( empty( $pending_themes[ $stylesheet ]['new_version'] ) ) {
				$skipped[] = self::skip( self::KIND_THEME, $stylesheet, 'no_update', __( 'WordPress knows of no update for it.', 'wproadie' ) );
			} else {
				$items[] = array(
					'kind' => self::KIND_THEME,
					'id'   => $stylesheet,
					'name' => (string) $theme->get( 'Name' ),
					'from' => (string) $theme->get( 'Version' ),
					'to'   => (string) $pending_themes[ $stylesheet ]['new_version'],
				);
			}
		}
		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * Updates the planned items one at a time.
	 *
	 * @param array[]  $items           From plan().
	 * @param bool     $stop_on_failure Stop at the first failed or rolled-back item.
	 * @param callable $add_undo        Receives one undo record per successful update.
	 * @return array{results: array[], health: array, stopped: bool}
	 */
	public static function run( array $items, bool $stop_on_failure, callable $add_undo ): array {
		Installer::load();
		// If the site already fails the probe before we touch anything (or
		// the host blocks requests to itself), the probe cannot judge our
		// updates: say so instead of rolling back for someone else's fault.
		$baseline = self::probe();
		$usable   = true === $baseline['ok'];

		$results = array();
		$stopped = false;
		// WordPress's upgrader reads the update transient itself. Outside
		// wp-admin that lacks the offers premium updaters only inject there,
		// so merge in what wp-admin last showed (see PendingUpdates).
		PendingUpdates::with_remembered(
			static function () use ( $items, $usable, $add_undo, $stop_on_failure, &$results, &$stopped ) {
				foreach ( $items as $item ) {
					$result    = self::update_one( $item, $usable, $add_undo );
					$results[] = $result;
					if ( $stop_on_failure && in_array( $result['status'], array( 'failed', 'rolled_back' ), true ) ) {
						$stopped = true;
						break;
					}
				}
			}
		);
		return array(
			'results' => $results,
			'health'  => array(
				'checked' => $usable,
				'before'  => $baseline,
				'after'   => $results ? self::probe() : $baseline,
			),
			'stopped' => $stopped,
		);
	}

	/**
	 * Restores a backed-up plugin or theme (an `extension` undo record).
	 *
	 * @param array $record Undo record from backup().
	 * @return true|WP_Error
	 */
	public static function restore( array $record ) {
		Installer::load();
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		$backup = (string) ( $record['backup'] ?? '' );
		$target = (string) ( $record['path'] ?? '' );
		if ( '' === $backup || '' === $target || ! file_exists( $backup ) ) {
			return new WP_Error( 'wproadie_backup_missing', __( 'The backup of the previous version is missing, so it cannot be restored.', 'wproadie' ) );
		}
		$real_backup = (string) realpath( $backup );
		$real_root   = (string) realpath( self::backup_root() );
		if ( '' === $real_root || 0 !== strpos( $real_backup, $real_root ) ) {
			return new WP_Error( 'wproadie_backup_missing', __( 'The backup path is not inside WPRoadie\'s backup folder.', 'wproadie' ) );
		}

		if ( file_exists( $target ) ) {
			$fs->delete( $target, true );
		}
		if ( is_dir( $backup ) ) {
			$fs->mkdir( $target );
			$copied = copy_dir( $backup, $target );
		} else {
			$copied = $fs->copy( $backup, $target, true ) ? true : new WP_Error( 'wproadie_restore_failed', __( 'Could not copy the backup back.', 'wproadie' ) );
		}
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}

		if ( self::KIND_PLUGIN === ( $record['type'] ?? '' ) ) {
			wp_clean_plugins_cache( true );
			if ( ! empty( $record['active'] ) && ! is_plugin_active( (string) $record['id'] ) ) {
				$activated = Installer::activate( (string) $record['id'], true );
				if ( is_wp_error( $activated ) ) {
					return $activated;
				}
			}
		} else {
			wp_clean_themes_cache( true );
		}
		if ( function_exists( 'opcache_reset' ) && ini_get( 'opcache.enable' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort; restricted on some hosts.
		}
		return true;
	}

	/**
	 * Deletes backups older than $days (called from the log's pruning).
	 */
	public static function prune( int $days ): void {
		$entries = glob( self::backup_root() . '/*' );
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			if ( is_dir( $entry ) && filemtime( $entry ) < time() - $days * DAY_IN_SECONDS ) {
				self::remove_dir( $entry );
			}
		}
	}

	/**
	 * Loads the site once and reports whether it answered without a fatal
	 * error. `ok` is null when the probe itself could not run (the host
	 * blocks loopback requests, or the site is behind a login wall).
	 *
	 * @return array{ok: bool|null, status: int|null, detail: string}
	 */
	public static function probe(): array {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => self::PROBE_TIMEOUT,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to this site.
				'headers'     => array( 'X-WPRoadie-Probe' => '1' ),
				'cookies'     => array(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => null,
				'status' => null,
				'detail' => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$fatal  = $status >= 500 || false !== stripos( $body, 'There has been a critical error on this website' );
		if ( $status >= 400 && $status < 500 && ! $fatal ) {
			// 401/403 (a login wall, a maintenance plugin) tells us nothing.
			return array(
				'ok'     => null,
				'status' => $status,
				'detail' => sprintf( 'HTTP %d', $status ),
			);
		}
		return array(
			'ok'     => ! $fatal,
			'status' => $status,
			'detail' => $fatal ? __( 'The site answered with a fatal error.', 'wproadie' ) : sprintf( 'HTTP %d', $status ),
		);
	}

	/**
	 * Backs up, updates and verifies one item.
	 *
	 * @param array    $item     From plan().
	 * @param bool     $probe    Whether the health probe can judge the result.
	 * @param callable $add_undo Receives the undo record on success.
	 */
	private static function update_one( array $item, bool $probe, callable $add_undo ): array {
		$out = $item + array(
			'status'  => 'failed',
			'message' => '',
			'log'     => array(),
		);

		$record = self::backup( $item['kind'], $item['id'] );
		if ( is_wp_error( $record ) ) {
			$out['message'] = $record->get_error_message();
			return $out;
		}

		$skin       = Installer::skin();
		$upgrader   = self::KIND_PLUGIN === $item['kind'] ? new Plugin_Upgrader( $skin ) : new Theme_Upgrader( $skin );
		$id         = $item['id'];
		$result     = Installer::quietly(
			static function () use ( $upgrader, $id ) {
				return $upgrader->upgrade( $id );
			}
		);
		$outcome    = Installer::outcome( $result, $skin );
		$out['log'] = Installer::log( $skin );

		// The upgrader deactivates a plugin while swapping files; put it back.
		// If the new version refuses to activate, that is the update's fault:
		// verify() below turns it into a rollback.
		$reactivation = null;
		if ( ! is_wp_error( $outcome ) && self::KIND_PLUGIN === $item['kind'] && ! empty( $record['active'] ) && ! is_plugin_active( $id ) && array_key_exists( $id, get_plugins() ) ) {
			$reactivated = Installer::activate( $id, true );
			if ( is_wp_error( $reactivated ) ) {
				$reactivation = $reactivated->get_error_message();
			}
		}

		if ( is_wp_error( $outcome ) ) {
			// The upgrader itself failed: WordPress usually leaves the old
			// files in place, but make sure.
			$restored       = self::ensure_restored( $record, $item['from'] );
			$out['message'] = $outcome->get_error_message();
			$out['status']  = $restored ? 'failed' : 'broken';
			return $out;
		}

		$problem = self::verify( $item, $probe );
		if ( null === $problem && null !== $reactivation ) {
			$problem = $reactivation;
		}
		if ( null !== $problem ) {
			$restored       = self::restore( $record );
			$out['status']  = is_wp_error( $restored ) ? 'broken' : 'rolled_back';
			$out['message'] = is_wp_error( $restored )
				? sprintf(
					/* translators: 1: what went wrong, 2: restore error. */
					__( '%1$s Restoring the previous version also failed: %2$s', 'wproadie' ),
					$problem,
					$restored->get_error_message()
				)
				: sprintf(
					/* translators: 1: what went wrong, 2: version put back. */
					__( '%1$s The previous version (%2$s) was put back.', 'wproadie' ),
					$problem,
					$item['from']
				);
			$out['restored'] = ! is_wp_error( $restored );
			return $out;
		}

		$add_undo( $record );
		$out['status']  = 'updated';
		$now            = self::installed_version( $item['kind'], $id );
		$out['to']      = '' !== $now ? $now : $item['to'];
		$out['message'] = sprintf(
			/* translators: 1: old version, 2: new version. */
			__( 'Updated from %1$s to %2$s.', 'wproadie' ),
			$item['from'],
			$out['to']
		);
		return $out;
	}

	/**
	 * After an update: the new main file must parse, and the site must
	 * still answer. Returns what is wrong, or null when all is well.
	 */
	private static function verify( array $item, bool $probe ): ?string {
		if ( self::KIND_PLUGIN === $item['kind'] ) {
			$syntax = Installer::check_plugin_syntax( $item['id'] );
			if ( is_wp_error( $syntax ) ) {
				return $syntax->get_error_message();
			}
			if ( ! array_key_exists( $item['id'], get_plugins() ) ) {
				return __( 'The plugin is missing after the update.', 'wproadie' );
			}
		} elseif ( ! wp_get_theme( $item['id'] )->exists() ) {
			return __( 'The theme is missing after the update.', 'wproadie' );
		}
		if ( $probe ) {
			$after = self::probe();
			if ( false === $after['ok'] ) {
				return sprintf(
					/* translators: %s: probe detail. */
					__( 'The site stopped answering after the update (%s).', 'wproadie' ),
					$after['detail']
				);
			}
		}
		return null;
	}

	/**
	 * Copies the current plugin or theme to WPRoadie's backup folder.
	 *
	 * @return array|WP_Error Undo record.
	 */
	private static function backup( string $kind, string $id ) {
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		$path = self::path( $kind, $id );
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'wproadie_backup_failed', __( 'The current files could not be found, so nothing was changed.', 'wproadie' ) );
		}
		$root = self::backup_root();
		if ( ! Guardrails::ensure_own_dir() || ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'wproadie_backup_failed', __( 'Could not create the backup folder, so nothing was changed.', 'wproadie' ) );
		}
		$version = self::installed_version( $kind, $id );
		$slug    = sanitize_file_name( self::KIND_PLUGIN === $kind ? dirname( $id ) === '.' ? basename( $id, '.php' ) : dirname( $id ) : $id );
		$backup  = $root . '/' . gmdate( 'Ymd-His' ) . '-' . $kind . '-' . $slug . '-' . sanitize_file_name( '' !== $version ? $version : '0' ) . '-' . wp_generate_password( 6, false );

		if ( is_dir( $path ) ) {
			if ( ! $fs->mkdir( $backup ) ) {
				return new WP_Error( 'wproadie_backup_failed', __( 'Could not create the backup folder, so nothing was changed.', 'wproadie' ) );
			}
			$copied = copy_dir( $path, $backup );
			if ( is_wp_error( $copied ) ) {
				self::remove_dir( $backup );
				return new WP_Error( 'wproadie_backup_failed', __( 'Could not back up the current version, so nothing was changed.', 'wproadie' ) );
			}
		} else {
			// Single-file plugin: keep the .bak suffix so it is never served as PHP.
			$backup .= '.bak';
			if ( ! $fs->copy( $path, $backup ) ) {
				return new WP_Error( 'wproadie_backup_failed', __( 'Could not back up the current version, so nothing was changed.', 'wproadie' ) );
			}
		}

		return array(
			'kind'    => 'extension',
			'type'    => $kind,
			'id'      => $id,
			'path'    => $path,
			'backup'  => $backup,
			'version' => $version,
			'active'  => self::KIND_PLUGIN === $kind ? is_plugin_active( $id ) : get_stylesheet() === $id,
		);
	}

	/** After a failed upgrader run: the old version must still be there. */
	private static function ensure_restored( array $record, string $from ): bool {
		if ( self::installed_version( $record['type'], $record['id'] ) === $from ) {
			return true;
		}
		return ! is_wp_error( self::restore( $record ) );
	}

	private static function installed_version( string $kind, string $id ): string {
		if ( self::KIND_PLUGIN === $kind ) {
			wp_clean_plugins_cache( false );
			return (string) ( get_plugins()[ $id ]['Version'] ?? '' );
		}
		wp_clean_themes_cache( false );
		$theme = wp_get_theme( $id );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}

	private static function path( string $kind, string $id ): string {
		if ( self::KIND_THEME === $kind ) {
			return trailingslashit( get_theme_root( $id ) ) . $id;
		}
		return '.' === dirname( $id ) ? trailingslashit( WP_PLUGIN_DIR ) . $id : trailingslashit( WP_PLUGIN_DIR ) . dirname( $id );
	}

	private static function backup_root(): string {
		return trailingslashit( Guardrails::own_dir() ) . 'backups';
	}

	private static function skip( string $kind, string $id, string $reason, string $message ): array {
		return array(
			'kind'    => $kind,
			'id'      => $id,
			'reason'  => $reason,
			'message' => $message,
		);
	}

	/**
	 * @return \WP_Filesystem_Base|WP_Error
	 */
	private static function filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			return Installer::filesystem_error();
		}
		return $wp_filesystem;
	}

	private static function remove_dir( string $dir ): void {
		$fs = self::filesystem();
		if ( ! is_wp_error( $fs ) ) {
			$fs->delete( $dir, true );
		}
	}
}
