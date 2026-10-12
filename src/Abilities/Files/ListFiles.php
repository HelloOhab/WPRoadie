<?php
/**
 * Lists files in themes, plugins and WPRoadie's own folder.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Files;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListFiles extends AbilityBase {

	const MAX_DEPTH   = 3;
	const MAX_ENTRIES = 500;

	/**
	 * Folders listed but never descended into.
	 */
	const SKIP_DESCEND = array( 'node_modules', 'vendor', '.git', '.svn' );

	/**
	 * @var array[]
	 */
	private $entries = array();

	/**
	 * @var bool
	 */
	private $truncated = false;

	public function slug(): string {
		return 'list-files';
	}

	public function label(): string {
		return __( 'List files', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists files and folders the AI may read: themes, plugins, must-use plugins and wp-content/wproadie (never WordPress core, uploads or wp-config.php). With no path it lists those root folders. Paths are relative to wp-content (e.g. "themes/my-child") or absolute. Each entry has path, type (file/dir), size, modified (UTC) and writable: only the active child theme and wp-content/wproadie are writable, and PHP only in the child theme. recursive descends up to 3 levels (not into node_modules, vendor or .git) and stops at 500 entries (truncated is then true). Files that may hold secrets and links pointing outside the allowed folders are left out. Use read-file to read a file.', 'wproadie' );
	}

	public function group(): string {
		return 'files';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	protected function annotations(): array {
		return array(
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'openWorldHint' => false,
		);
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'path'      => array(
					'type'        => 'string',
					'default'     => '',
					'description' => __( 'Folder to list, relative to wp-content (e.g. "themes/my-child") or absolute. Empty lists the allowed root folders.', 'wproadie' ),
				),
				'recursive' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also list subfolders, up to 3 levels deep and 500 entries.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$this->entries   = array();
		$this->truncated = false;

		$path = trim( (string) ( $input['path'] ?? '' ) );
		if ( '' === $path ) {
			foreach ( Guardrails::readable_roots() as $root ) {
				if ( ! is_dir( $root ) ) {
					continue;
				}
				$this->entries[] = $this->entry( $root, $root );
			}
			return array(
				'path'      => '',
				'entries'   => $this->entries,
				'truncated' => false,
				'note'      => __( 'These are the folders that can be read. Writable: the active child theme and wp-content/wproadie only.', 'wproadie' ),
			);
		}

		$real = Guardrails::resolve_path( $path, false );
		if ( is_wp_error( $real ) ) {
			return $real;
		}
		if ( ! file_exists( $real ) ) {
			return $this->error( 'not_found', __( 'Nothing exists at that path.', 'wproadie' ), 404 );
		}
		if ( ! is_dir( $real ) ) {
			return array(
				'path'      => FileTools::display_path( $real ),
				'entries'   => array( $this->entry( $real, $real ) ),
				'truncated' => false,
			);
		}

		$this->walk( $real, ! empty( $input['recursive'] ) ? self::MAX_DEPTH : 1 );

		return array(
			'path'      => FileTools::display_path( $real ),
			'entries'   => $this->entries,
			'truncated' => $this->truncated,
		);
	}

	/**
	 * @param string $dir   Folder (normalized, already checked).
	 * @param int    $depth Levels left to list.
	 */
	private function walk( string $dir, int $depth ): void {
		$names = scandir( $dir );
		if ( false === $names ) {
			return;
		}
		natcasesort( $names );

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( count( $this->entries ) >= self::MAX_ENTRIES ) {
				$this->truncated = true;
				return;
			}

			$logical = $dir . '/' . $name;
			// Resolves symlinks and applies the root and secret-file rules;
			// anything refused is simply left out of the listing.
			$real = Guardrails::resolve_path( $logical, false );
			if ( is_wp_error( $real ) || ! file_exists( $real ) ) {
				continue;
			}

			$this->entries[] = $this->entry( $logical, $real );

			if ( $depth > 1 && is_dir( $real ) && ! in_array( strtolower( $name ), self::SKIP_DESCEND, true ) && ! is_link( $logical ) ) {
				$this->walk( $logical, $depth - 1 );
			}
		}
	}

	/**
	 * @param string $logical Path as walked (shown to the AI).
	 * @param string $real    Real path (used for facts).
	 */
	private function entry( string $logical, string $real ): array {
		$is_dir = is_dir( $real );
		$size   = $is_dir ? null : filesize( $real );
		return array(
			'path'     => FileTools::display_path( $logical ),
			'type'     => $is_dir ? 'dir' : 'file',
			'size'     => false === $size ? null : $size,
			'modified' => FileTools::modified( $real ),
			'writable' => $is_dir ? FileTools::is_writable_dir( $real ) : FileTools::is_writable_path( $real ),
		);
	}
}
