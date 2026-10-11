<?php
/**
 * Creates or overwrites a file in the active child theme or WPRoadie's
 * own folder.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Files;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class WriteFile extends AbilityBase {

	public function slug(): string {
		return 'write-file';
	}

	public function label(): string {
		return __( 'Write file', 'wproadie' );
	}

	public function description(): string {
		return __( 'Creates or replaces a whole text file. Only the active child theme and wp-content/wproadie are writable (never a parent or third-party theme, plugins or core), PHP only in the child theme, and only these types: php, css, scss, js, json, html, htm, txt, md, xml, svg, twig. Path is relative to wp-content, e.g. "themes/my-child/functions.php". Read the file first and pass its sha1 as expected_sha1: the write is refused if the file changed since. PHP is syntax-checked before writing. After writing a PHP file the home page is loaded once: if it now shows a fatal error the change is rolled back automatically and an error is returned; if the site cannot be reached the change is kept and check says it could not be verified (other templates are not checked). Returns path, bytes, sha1, created, verified and check, and an undo_id; pass it to undo-change to restore the previous file (or remove a new one). Refused when file editing is turned off (DISALLOW_FILE_EDIT).', 'wproadie' );
	}

	public function group(): string {
		return 'files';
	}

	public function risk(): string {
		return Mode::RISK_DANGEROUS;
	}

	public function is_available(): bool {
		return ! FileTools::edits_disallowed();
	}

	public function unavailable_reason(): string {
		return FileTools::edits_disallowed_reason();
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'path'          => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'File path, relative to wp-content (e.g. "themes/my-child/style.css") or absolute.', 'wproadie' ),
				),
				'content'       => array(
					'type'        => 'string',
					'description' => __( 'The complete new file content (UTF-8 text).', 'wproadie' ),
				),
				'expected_sha1' => array(
					'type'        => 'string',
					'pattern'     => '^[0-9a-fA-F]{40}$',
					'description' => __( 'sha1 from read-file. If given, the write is refused when the file no longer has this hash.', 'wproadie' ),
				),
				'create_dirs'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Create missing parent folders.', 'wproadie' ),
				),
			),
			'required'             => array( 'path', 'content' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		// edit_themes is what wp-admin's theme file editor requires.
		return current_user_can( 'edit_themes' );
	}

	protected function execute( array $input ) {
		if ( FileTools::edits_disallowed() ) {
			return FileTools::edits_disallowed_error();
		}

		$real = Guardrails::resolve_path( (string) $input['path'], true );
		if ( is_wp_error( $real ) ) {
			return $real;
		}
		if ( is_dir( $real ) ) {
			return $this->error( 'is_dir', __( 'That path is a folder. Give a file path.', 'wproadie' ), 400 );
		}

		$content   = (string) $input['content'];
		$extension = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		$exists    = file_exists( $real );

		if ( ! empty( $input['expected_sha1'] ) ) {
			$expected = strtolower( (string) $input['expected_sha1'] );
			if ( ! $exists ) {
				return $this->error( 'file_changed', __( 'The file no longer exists, so it does not match expected_sha1. Nothing was written. Check with list-files.', 'wproadie' ), 409 );
			}
			if ( sha1_file( $real ) !== $expected ) {
				return $this->error( 'file_changed', __( 'The file changed since it was read (sha1 differs). Nothing was written. Read it again and redo the edit.', 'wproadie' ), 409 );
			}
		}

		if ( FileTools::is_binary( $content ) ) {
			return $this->error( 'binary_content', __( 'The content contains NUL bytes. Only text files can be written.', 'wproadie' ), 400 );
		}
		if ( 'php' === $extension ) {
			$syntax = Guardrails::check_php_syntax( $content );
			if ( is_wp_error( $syntax ) ) {
				return $syntax;
			}
		}

		if ( $exists && sha1_file( $real ) === sha1( $content ) ) {
			return array(
				'path'    => FileTools::display_path( $real ),
				'bytes'   => strlen( $content ),
				'sha1'    => sha1( $content ),
				'created' => false,
				'changed' => false,
			);
		}

		if ( FileTools::in_own_dir( $real ) && ! Guardrails::ensure_own_dir() ) {
			return $this->error( 'write_failed', __( 'Could not create wp-content/wproadie.', 'wproadie' ), 500 );
		}
		$dir = dirname( $real );
		if ( ! is_dir( $dir ) ) {
			if ( empty( $input['create_dirs'] ) ) {
				return $this->error( 'no_folder', __( 'The folder does not exist. Pass create_dirs: true to create it.', 'wproadie' ), 400 );
			}
			if ( ! wp_mkdir_p( $dir ) ) {
				return $this->error( 'write_failed', __( 'Could not create the folder.', 'wproadie' ), 500 );
			}
		}

		$undo = Undo::file( $real );
		if ( is_wp_error( $undo ) ) {
			return $undo;
		}
		$this->add_undo( $undo );

		$write = static function () use ( $real, $content ) {
			return FileTools::write_atomic( $real, $content );
		};

		if ( 'php' === $extension ) {
			$check = FileTools::change_with_check( $undo, $write );
		} else {
			$check = $write();
			$check = is_wp_error( $check ) ? $check : array(
				'verified' => null,
				'check'    => __( 'Not a PHP file; no site check needed.', 'wproadie' ),
			);
		}
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return array(
			'path'     => FileTools::display_path( $real ),
			'bytes'    => strlen( $content ),
			'sha1'     => sha1( $content ),
			'created'  => ! $exists,
			'changed'  => true,
			'verified' => $check['verified'],
			'check'    => $check['check'],
		);
	}
}
