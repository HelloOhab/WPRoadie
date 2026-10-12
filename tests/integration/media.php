<?php
/**
 * Media tools.
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Abilities\Media\UploadMediaFromUrl;
use WPRoadie\Log\Undo;

const WPROADIE_TEST_IMAGE_URL = 'https://raw.githubusercontent.com/WordPress/WordPress/6.9/wp-admin/images/wordpress-logo.png';

/**
 * Uploads the test image once per run and returns its attachment ID.
 */
function wproadie_media_image(): int {
	static $id = null;
	if ( null === $id || ! get_post( $id ) ) {
		$result = wproadie_ok(
			wproadie_call(
				'upload-media-from-url',
				array(
					'url'   => WPROADIE_TEST_IMAGE_URL,
					'title' => 'Roadie test logo',
					'alt'   => 'WordPress logo',
				)
			)
		);
		$id     = $result['id'];
	}
	return $id;
}

wproadie_test(
	'upload-media-from-url downloads an image and undo deletes it',
	function () {
		$post_id = (int) wp_insert_post( array( 'post_title' => 'Roadie media parent' ) );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'upload-media-from-url',
					array(
						'url'       => WPROADIE_TEST_IMAGE_URL,
						'title'     => 'Roadie "logo"',
						'alt'       => 'The WordPress logo',
						'caption'   => 'A caption',
						'attach_to' => $post_id,
					)
				)
			);
			$id     = $result['id'];
			wproadie_assert_same( 'image/png', $result['mime'] );
			wproadie_assert_same( 'Roadie "logo"', $result['title'] );
			wproadie_assert_same( 'The WordPress logo', $result['alt'] );
			wproadie_assert_same( $post_id, $result['attached_to'] );
			wproadie_assert( $result['width'] > 0, 'Width missing.' );
			$file = get_attached_file( $id );
			wproadie_assert( file_exists( $file ), 'File missing.' );

			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert( ! get_post( $id ), 'Undo should delete the attachment.' );
			wproadie_assert( ! file_exists( $file ), 'Undo should delete the file.' );
		} finally {
			wp_delete_post( $post_id, true );
		}
	}
);

wproadie_test(
	'upload-media-from-url refuses unsafe URLs and missing files',
	function () {
		wproadie_assert_error( wproadie_call( 'upload-media-from-url', array( 'url' => 'file:///etc/passwd' ) ), 'wproadie_invalid_url' );
		wproadie_assert_error( wproadie_call( 'upload-media-from-url', array( 'url' => 'ftp://example.com/a.png' ) ), 'wproadie_invalid_url' );
		wproadie_assert_error( wproadie_call( 'upload-media-from-url', array( 'url' => 'http://10.0.0.1/a.png' ) ), 'wproadie_unsafe_url' );
		wproadie_assert_error( wproadie_call( 'upload-media-from-url', array( 'url' => 'http://192.168.1.1/a.png' ) ), 'wproadie_unsafe_url' );
		wproadie_assert_error( wproadie_call( 'upload-media-from-url', array( 'url' => 'http://169.254.169.254/latest/meta-data/' ) ), 'wproadie_unsafe_url' );
		wproadie_assert( ! UploadMediaFromUrl::is_public_url( 'http://100.64.0.1/a.png' ), 'CGNAT range allowed.' );
		wproadie_assert( ! UploadMediaFromUrl::is_public_url( 'http://0.0.0.0/a.png' ), '0.0.0.0 allowed.' );
		wproadie_assert( UploadMediaFromUrl::is_public_url( WPROADIE_TEST_IMAGE_URL ), 'Public URL refused.' );
		wproadie_assert_error(
			wproadie_call( 'upload-media-from-url', array( 'url' => 'https://raw.githubusercontent.com/WordPress/WordPress/6.9/does-not-exist.png' ) ),
			'wproadie_download_failed'
		);
		// A PHP file is not an allowed upload type.
		$result = wproadie_call( 'upload-media-from-url', array( 'url' => 'https://raw.githubusercontent.com/WordPress/WordPress/6.9/index.php' ) );
		wproadie_assert_error( $result );
	}
);

wproadie_test(
	'upload-media-from-url needs upload_files',
	function () {
		$filter = static function ( $allcaps ) {
			$allcaps['upload_files'] = false;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $filter );
		try {
			wproadie_assert_error( wproadie_call( 'upload-media-from-url', array( 'url' => WPROADIE_TEST_IMAGE_URL ) ), 'ability_invalid_permissions' );
		} finally {
			remove_filter( 'user_has_cap', $filter );
		}
	}
);

wproadie_test(
	'list-media filters by MIME type and search',
	function () {
		$id     = wproadie_media_image();
		$result = wproadie_ok(
			wproadie_call(
				'list-media',
				array(
					'mime_type' => 'image',
					'search'    => 'Roadie test logo',
				)
			)
		);
		wproadie_assert( in_array( $id, wp_list_pluck( $result['items'], 'id' ), true ), 'Uploaded image not listed.' );
		$pdf = wproadie_ok(
			wproadie_call(
				'list-media',
				array(
					'mime_type' => 'application/pdf',
					'search'    => 'Roadie test logo',
				)
			)
		);
		wproadie_assert_same( 0, $pdf['total'] );
	}
);

wproadie_test(
	'set-featured-image sets, removes and undoes',
	function () {
		$image   = wproadie_media_image();
		$post_id = (int) wp_insert_post( array( 'post_title' => 'Roadie featured' ) );
		try {
			$result = wproadie_ok(
				wproadie_call(
					'set-featured-image',
					array(
						'post_id'       => $post_id,
						'attachment_id' => $image,
					)
				)
			);
			wproadie_assert_same( $image, (int) get_post_thumbnail_id( $post_id ) );
			wproadie_ok( Undo::revert( $result['undo_id'] ) );
			wproadie_assert_same( 0, (int) get_post_thumbnail_id( $post_id ) );

			set_post_thumbnail( $post_id, $image );
			$removed = wproadie_ok(
				wproadie_call(
					'set-featured-image',
					array(
						'post_id'       => $post_id,
						'attachment_id' => null,
					)
				)
			);
			wproadie_assert_same( 0, (int) get_post_thumbnail_id( $post_id ) );
			wproadie_ok( Undo::revert( $removed['undo_id'] ) );
			wproadie_assert_same( $image, (int) get_post_thumbnail_id( $post_id ) );

			wproadie_assert_error(
				wproadie_call(
					'set-featured-image',
					array(
						'post_id'       => $post_id,
						'attachment_id' => $post_id,
					)
				),
				'wproadie_not_found'
			);
		} finally {
			wp_delete_post( $post_id, true );
		}
	}
);

wproadie_test(
	'set-featured-image refuses non-images',
	function () {
		$post_id = (int) wp_insert_post( array( 'post_title' => 'Roadie featured 2' ) );
		$doc     = (int) wp_insert_attachment(
			array(
				'post_title'     => 'Roadie doc',
				'post_mime_type' => 'application/pdf',
			),
			'roadie-doc.pdf'
		);
		try {
			wproadie_assert_error(
				wproadie_call(
					'set-featured-image',
					array(
						'post_id'       => $post_id,
						'attachment_id' => $doc,
					)
				),
				'wproadie_not_an_image'
			);
		} finally {
			wp_delete_post( $post_id, true );
			wp_delete_attachment( $doc, true );
		}
	}
);

wproadie_test(
	'update-media-details changes fields and undo restores them',
	function () {
		$image  = wproadie_media_image();
		$before = get_post( $image );
		$alt    = get_post_meta( $image, '_wp_attachment_image_alt', true );
		$result = wproadie_ok(
			wproadie_call(
				'update-media-details',
				array(
					'id'      => $image,
					'alt'     => 'New alt',
					'title'   => 'New title',
					'caption' => 'New caption',
				)
			)
		);
		wproadie_assert_same( 'New alt', $result['alt'] );
		wproadie_assert_same( 'New title', get_post( $image )->post_title );
		wproadie_assert_same( 'New caption', get_post( $image )->post_excerpt );
		wproadie_assert_same( 'inherit', get_post( $image )->post_status );

		wproadie_ok( Undo::revert( $result['undo_id'] ) );
		$after = get_post( $image );
		wproadie_assert_same( $before->post_title, $after->post_title );
		wproadie_assert_same( $before->post_excerpt, $after->post_excerpt );
		wproadie_assert_same( 'inherit', $after->post_status );
		wproadie_assert_same( $alt, get_post_meta( $image, '_wp_attachment_image_alt', true ) );

		wproadie_assert_error( wproadie_call( 'update-media-details', array( 'id' => $image ) ), 'wproadie_nothing_to_update' );
		$post_id = (int) wp_insert_post( array( 'post_title' => 'Roadie not media' ) );
		wproadie_assert_error(
			wproadie_call(
				'update-media-details',
				array(
					'id'  => $post_id,
					'alt' => 'x',
				)
			),
			'wproadie_not_found'
		);
		wp_delete_post( $post_id, true );
	}
);

wproadie_test(
	'clean up the test image',
	function () {
		wp_delete_attachment( wproadie_media_image(), true );
	}
);
