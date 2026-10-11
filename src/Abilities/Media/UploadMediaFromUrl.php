<?php
/**
 * Downloads a file from a URL into the media library.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Media;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UploadMediaFromUrl extends AbilityBase {

	/**
	 * Download timeout in seconds.
	 */
	const TIMEOUT = 60;

	public function slug(): string {
		return 'upload-media-from-url';
	}

	public function label(): string {
		return __( 'Upload media from URL', 'wproadie' );
	}

	public function description(): string {
		return __( 'Downloads a public http(s) URL into the media library, the same as uploading it in wp-admin: only file types the site allows, up to the site\'s upload size limit, and image sizes are generated. Private, local and loopback addresses are refused. Optionally sets title, alt text, caption and description, and attaches it to a post. Returns id, url, mime, title, alt, width and height, plus an undo_id; pass it to undo-change to delete the uploaded file.', 'wproadie' );
	}

	public function group(): string {
		return 'media';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	protected function annotations(): array {
		$annotations                  = parent::annotations();
		$annotations['destructive']   = false;
		$annotations['openWorldHint'] = true;
		return $annotations;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'         => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Public http or https URL of the file.', 'wproadie' ),
				),
				'filename'    => array(
					'type'        => 'string',
					'description' => __( 'File name to save as, e.g. "team-photo.jpg". Taken from the URL if omitted.', 'wproadie' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Media title. Defaults to the file name.', 'wproadie' ),
				),
				'alt'         => array(
					'type'        => 'string',
					'description' => __( 'Alt text describing the image for screen readers.', 'wproadie' ),
				),
				'caption'     => array(
					'type'        => 'string',
					'description' => __( 'Caption.', 'wproadie' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Description.', 'wproadie' ),
				),
				'attach_to'   => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Post ID to attach the file to (0 for none). Does not set it as the featured image; use set-featured-image for that.', 'wproadie' ),
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		$parent = (int) ( $input['attach_to'] ?? 0 );
		return ! $parent || ! get_post( $parent ) || current_user_can( 'edit_post', $parent );
	}

	protected function execute( array $input ) {
		$url    = trim( (string) $input['url'] );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $this->error( 'invalid_url', __( 'Only http and https URLs can be downloaded.', 'wproadie' ) );
		}
		if ( ! self::is_public_url( $url ) ) {
			return $this->error( 'unsafe_url', __( 'That URL is not allowed: it is malformed, or points to a private, local or loopback address.', 'wproadie' ) );
		}

		$parent = (int) ( $input['attach_to'] ?? 0 );
		if ( $parent && ! get_post( $parent ) ) {
			return $this->error( 'not_found', __( 'No post with the attach_to ID.', 'wproadie' ), 404 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$max = (int) wp_max_upload_size();

		// Stop reading a response once it is over the upload limit.
		$limit = static function ( $args, $request_url ) use ( $url, $max ) {
			if ( $request_url === $url && $max > 0 ) {
				$args['limit_response_size'] = $max + 1;
			}
			return $args;
		};
		// download_url() uses wp_safe_remote_get(), which re-checks the URL
		// and every redirect with wp_http_validate_url(). That misses
		// link-local addresses such as cloud metadata endpoints, so check
		// each redirect again with the stricter test.
		$redirects = static function ( $location ) {
			if ( ! self::is_public_url( (string) $location ) ) {
				throw new \WpOrg\Requests\Exception( esc_html__( 'The URL redirected to an address that is not allowed.', 'wproadie' ), 'wp_http.redirect_failed_validation' );
			}
		};
		add_filter( 'http_request_args', $limit, 10, 2 );
		add_action( 'requests-requests.before_redirect', $redirects ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core hook name.
		$tmp = download_url( $url, self::TIMEOUT );
		remove_filter( 'http_request_args', $limit, 10 );
		remove_action( 'requests-requests.before_redirect', $redirects );

		if ( is_wp_error( $tmp ) ) {
			return $this->error(
				'download_failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not download the file: %s', 'wproadie' ), $tmp->get_error_message() ),
				502
			);
		}

		if ( $max > 0 && filesize( $tmp ) > $max ) {
			wp_delete_file( $tmp );
			return $this->error(
				'too_large',
				/* translators: %s: size such as "8 MB". */
				sprintf( __( 'The file is larger than the site\'s upload limit of %s.', 'wproadie' ), size_format( $max ) ),
				413
			);
		}

		$name = $this->file_name( $input, $url, $tmp );

		$post_data = array();
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = (string) $input['caption'];
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = (string) $input['description'];
		}
		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$post_data['post_title'] = (string) $input['title'];
		}

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		// media_handle_sideload() checks the file type against the site's
		// allowed MIME types and moves the file into uploads.
		$id = media_handle_sideload( $file, $parent, null, wp_slash( $post_data ) );
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $id;
		}
		$this->add_undo( Undo::post_created( (int) $id ) );

		if ( isset( $input['alt'] ) && '' !== trim( (string) $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( wp_strip_all_tags( (string) $input['alt'] ) ) );
		}

		return ListMedia::item( get_post( $id ) );
	}

	/**
	 * Whether a URL may be fetched: wp_http_validate_url() (http(s), no
	 * credentials, allowed ports, no private or loopback IPs), plus every
	 * address the host resolves to must be public, which also rules out
	 * link-local (169.254.0.0/16, e.g. cloud metadata), reserved and
	 * carrier-grade NAT ranges. The site's own host stays allowed, as in
	 * core.
	 */
	public static function is_public_url( string $url ): bool {
		return Guardrails::is_public_url( $url );
	}

	/**
	 * Picks the file name: the one given, else the URL's, adding an
	 * extension for images when the URL has none.
	 */
	private function file_name( array $input, string $url, string $tmp ): string {
		$name = isset( $input['filename'] ) && '' !== trim( (string) $input['filename'] )
			? (string) $input['filename']
			: rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$name = sanitize_file_name( $name );
		if ( '' === $name || '.' === $name[0] ) {
			$name = 'download' . $name;
		}

		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
			$mime = wp_get_image_mime( $tmp );
			if ( $mime ) {
				$extensions = wp_get_default_extension_for_mime_type( $mime );
				if ( $extensions ) {
					$name .= '.' . $extensions;
				}
			}
		}
		return $name;
	}
}
