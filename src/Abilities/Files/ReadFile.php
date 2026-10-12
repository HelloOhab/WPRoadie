<?php
/**
 * Reads a text file from a theme, plugin or WPRoadie's own folder.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Files;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ReadFile extends AbilityBase {

	public function slug(): string {
		return 'read-file';
	}

	public function label(): string {
		return __( 'Read file', 'wproadie' );
	}

	public function description(): string {
		return __( 'Reads a text file from a theme, plugin, must-use plugin or wp-content/wproadie. Path is relative to wp-content (e.g. "themes/my-child/functions.php") or absolute. Returns content, size, modified (UTC), sha1 of the whole file, total_lines and writable. Returns at most 200 KB (max_bytes) per call; truncated is then true, and start_line/line_count read a slice of a longer file. Binary files and files that may hold secrets (wp-config.php, .env, keys) are refused. Pass sha1 to write-file as expected_sha1 so it refuses to overwrite a file that changed since you read it. If write-file is not available, file editing is turned off on this site (DISALLOW_FILE_EDIT).', 'wproadie' );
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
				'path'       => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'File path, relative to wp-content or absolute.', 'wproadie' ),
				),
				'start_line' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'First line to return (1-based). Omit to start at the top.', 'wproadie' ),
				),
				'line_count' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'How many lines to return from start_line. Omit for as many as fit in max_bytes.', 'wproadie' ),
				),
				'max_bytes'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => FileTools::MAX_READ_BYTES,
					'default'     => FileTools::MAX_READ_BYTES,
					'description' => __( 'Most bytes of content to return (up to 204800).', 'wproadie' ),
				),
			),
			'required'             => array( 'path' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$real = Guardrails::resolve_path( (string) $input['path'], false );
		if ( is_wp_error( $real ) ) {
			return $real;
		}
		if ( ! file_exists( $real ) ) {
			return $this->error( 'not_found', __( 'No file at that path. Use list-files to find it.', 'wproadie' ), 404 );
		}
		if ( is_dir( $real ) ) {
			return $this->error( 'is_dir', __( 'That is a folder. Use list-files to see what is in it.', 'wproadie' ), 400 );
		}
		if ( ! is_readable( $real ) ) {
			return $this->error( 'unreadable', __( 'The web server cannot read that file.', 'wproadie' ), 403 );
		}

		$max_bytes = min( FileTools::MAX_READ_BYTES, max( 1, (int) ( $input['max_bytes'] ?? FileTools::MAX_READ_BYTES ) ) );
		$start     = max( 1, (int) ( $input['start_line'] ?? 1 ) );
		$count     = isset( $input['line_count'] ) ? max( 1, (int) $input['line_count'] ) : null;

		$handle = fopen( $real, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams a slice of a possibly large local file.
		if ( false === $handle ) {
			return $this->error( 'unreadable', __( 'Could not open the file.', 'wproadie' ), 500 );
		}

		$head = (string) fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( FileTools::is_binary( $head ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $this->error( 'binary_file', __( 'That looks like a binary file (image, font, archive, compiled file), which cannot be returned as text. Use list-files for its size and date.', 'wproadie' ), 415 );
		}
		rewind( $handle );

		$content   = '';
		$line_no   = 0;
		$end_line  = 0;
		$truncated = false;
		$binary    = false;
		while ( ! feof( $handle ) ) {
			$line = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			if ( false === $line ) {
				break;
			}
			++$line_no;
			if ( FileTools::is_binary( $line ) ) {
				$binary = true;
				break;
			}
			if ( $line_no < $start || $truncated ) {
				continue;
			}
			if ( null !== $count && $line_no >= $start + $count ) {
				continue;
			}
			if ( strlen( $content ) + strlen( $line ) > $max_bytes ) {
				$content  .= substr( $line, 0, $max_bytes - strlen( $content ) );
				$truncated = true;
				continue;
			}
			$content .= $line;
			$end_line = $line_no;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $binary ) {
			return $this->error( 'binary_file', __( 'That file contains binary data and cannot be returned as text.', 'wproadie' ), 415 );
		}

		if ( $truncated ) {
			$content = self::trim_utf8( $content );
		}
		if ( ! wp_is_valid_utf8( $content ) ) {
			return $this->error( 'not_utf8', __( 'That file is not UTF-8 text, so it cannot be returned safely.', 'wproadie' ), 415 );
		}

		$size   = filesize( $real );
		$result = array(
			'path'        => FileTools::display_path( $real ),
			'size'        => false === $size ? null : $size,
			'modified'    => FileTools::modified( $real ),
			'sha1'        => (string) sha1_file( $real ),
			'total_lines' => $line_no,
			'start_line'  => $start,
			'end_line'    => $end_line,
			'truncated'   => $truncated,
			'writable'    => FileTools::is_writable_path( $real ),
			'content'     => $content,
		);
		if ( $truncated ) {
			$result['note'] = sprintf(
				/* translators: 1: byte limit, 2: next line number. */
				__( 'Content was cut at %1$d bytes. Read on with start_line=%2$d.', 'wproadie' ),
				$max_bytes,
				max( $start, $end_line + 1 )
			);
		}
		return $result;
	}

	/**
	 * Drops a partial multi-byte character left at the end by a byte cut.
	 */
	private static function trim_utf8( string $text ): string {
		$tries = 0;
		while ( $tries < 4 && '' !== $text && ! wp_is_valid_utf8( $text ) ) {
			$text = substr( $text, 0, -1 );
			++$tries;
		}
		return $text;
	}
}
