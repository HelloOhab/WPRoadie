<?php
/**
 * Deletes a file from the active child theme or WPRoadie's own folder.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Files;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class DeleteFile extends AbilityBase {

	public function slug(): string {
		return 'delete-file';
	}

	public function label(): string {
		return __( 'Delete file', 'wproadie' );
	}

	public function description(): string {
		return __( 'Deletes one file from the active child theme or wp-content/wproadie (the only writable places). Folders cannot be deleted, and neither can the child theme\'s style.css, which the theme needs to exist. Pass expected_sha1 from read-file to refuse if the file changed. Deleting a PHP file is followed by a check that the home page still loads; if it breaks, the file is put back and an error is returned. The file is backed up first: returns path, deleted, verified and check, and an undo_id; pass it to undo-change to restore the file. Refused when file editing is turned off (DISALLOW_FILE_EDIT).', 'wproadie' );
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
					'description' => __( 'File path, relative to wp-content (e.g. "themes/my-child/old.css") or absolute.', 'wproadie' ),
				),
				'expected_sha1' => array(
					'type'        => 'string',
					'pattern'     => '^[0-9a-fA-F]{40}$',
					'description' => __( 'sha1 from read-file. If given, the delete is refused when the file no longer has this hash.', 'wproadie' ),
				),
			),
			'required'             => array( 'path' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
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
		if ( ! file_exists( $real ) ) {
			return $this->error( 'not_found', __( 'No file at that path.', 'wproadie' ), 404 );
		}
		if ( is_dir( $real ) ) {
			return $this->error( 'is_dir', __( 'Folders cannot be deleted; delete the files in it one by one.', 'wproadie' ), 400 );
		}
		if ( FileTools::is_theme_stylesheet( $real ) ) {
			return $this->error( 'theme_stylesheet', __( 'The child theme\'s style.css cannot be deleted: WordPress would no longer recognise the theme. Edit it with write-file instead.', 'wproadie' ), 403 );
		}
		if ( ! empty( $input['expected_sha1'] ) && sha1_file( $real ) !== strtolower( (string) $input['expected_sha1'] ) ) {
			return $this->error( 'file_changed', __( 'The file changed since it was read (sha1 differs). Nothing was deleted.', 'wproadie' ), 409 );
		}

		$undo = Undo::file( $real );
		if ( is_wp_error( $undo ) ) {
			return $undo;
		}
		$this->add_undo( $undo );

		$delete = function () use ( $real ) {
			wp_delete_file( $real );
			FileTools::invalidate( $real );
			return file_exists( $real )
				? $this->error( 'delete_failed', __( 'Could not delete the file (is it writable by the web server?).', 'wproadie' ), 500 )
				: true;
		};

		if ( 'php' === strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			$check = FileTools::change_with_check( $undo, $delete );
		} else {
			$check = $delete();
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
			'deleted'  => true,
			'verified' => $check['verified'],
			'check'    => $check['check'],
		);
	}
}
