<?php
/**
 * Updates WPRoadie from GitHub releases instead of WordPress.org.
 *
 * Each release must have a "wproadie.zip" asset (built by bin/build-zip.sh
 * and the release workflow). For a private repository, define a read-only
 * token in wp-config.php:
 *
 *   define( 'WPROADIE_GITHUB_TOKEN', 'github_pat_…' );
 *
 * The token is only ever sent to api.github.com. Release downloads redirect
 * to a signed storage URL; WordPress would forward the Authorization header
 * there, so WPRoadie follows that redirect itself, without the token.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const DEFAULT_REPO = 'HelloOhab/WPRoadie';
	const ASSET_NAME   = 'wproadie.zip';
	const CACHE_KEY    = 'wproadie_update_release';

	public function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );
	}

	public static function repo(): string {
		$repo = defined( 'WPROADIE_UPDATE_REPO' ) ? (string) WPROADIE_UPDATE_REPO : self::DEFAULT_REPO;
		return (string) apply_filters( 'wproadie_update_repo', $repo );
	}

	private static function token(): string {
		return defined( 'WPROADIE_GITHUB_TOKEN' ) ? (string) WPROADIE_GITHUB_TOKEN : '';
	}

	private static function asset_prefix(): string {
		return 'https://api.github.com/repos/' . self::repo() . '/releases/assets/';
	}

	/**
	 * Latest release, cached for six hours (one hour after a failure).
	 *
	 * @return array{version: string, package: string, url: string, notes: string, published: string}|null
	 */
	public static function latest_release( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached['release'];
			}
		}

		$release  = null;
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => self::api_headers( 'application/vnd.github+json' ),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
				foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
					if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) && ! empty( $asset['url'] ) ) {
						$release = array(
							'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
							'package'   => (string) $asset['url'],
							'url'       => (string) ( $data['html_url'] ?? '' ),
							'notes'     => (string) ( $data['body'] ?? '' ),
							'published' => (string) ( $data['published_at'] ?? '' ),
						);
						break;
					}
				}
			}
		}

		set_site_transient( self::CACHE_KEY, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::latest_release();
		$file    = plugin_basename( WPROADIE_FILE );
		$item    = (object) array(
			'id'           => self::repo(),
			'slug'         => 'wproadie',
			'plugin'       => $file,
			'new_version'  => $release ? $release['version'] : WPROADIE_VERSION,
			'url'          => $release ? $release['url'] : '',
			'package'      => $release ? $release['package'] : '',
			'requires'     => '6.9',
			'requires_php' => '7.4',
		);

		if ( $release && version_compare( $release['version'], WPROADIE_VERSION, '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			// Listing it as up to date also enables the auto-update toggle.
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	/**
	 * "View details" popup on the Plugins screen.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action plugins_api action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'wproadie' !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		return (object) array(
			'name'          => 'WPRoadie',
			'slug'          => 'wproadie',
			'version'       => $release ? $release['version'] : WPROADIE_VERSION,
			'author'        => 'Rhythm Collective',
			'requires'      => '6.9',
			'requires_php'  => '7.4',
			'last_updated'  => $release ? $release['published'] : '',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'changelog' => $release ? wpautop( esc_html( $release['notes'] ) ) : '',
			),
		);
	}

	/**
	 * Downloads our release asset: authenticated request to the GitHub API,
	 * then the signed redirect target fetched without credentials.
	 *
	 * @param mixed  $reply   False to let WordPress download.
	 * @param string $package Package URL.
	 * @return mixed Temp file path, WP_Error, or $reply.
	 */
	public function download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::asset_prefix() ) ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::api_headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $code >= 300 && $code < 400 && '' !== $location ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			return download_url( $location, 300 );
		}

		if ( 200 === $code ) {
			// Served directly: save the body.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = wp_tempnam( self::ASSET_NAME );
			if ( ! $tmp || false === file_put_contents( $tmp, wp_remote_retrieve_body( $response ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'wproadie_download_failed', __( 'Could not save the WPRoadie update.', 'wproadie' ) );
			}
			return $tmp;
		}

		return new WP_Error(
			'wproadie_download_failed',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'GitHub refused the WPRoadie download (HTTP %d). For a private repository, check WPROADIE_GITHUB_TOKEN.', 'wproadie' ),
				$code
			)
		);
	}

	public function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	private static function api_headers( string $accept ): array {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'WPRoadie/' . WPROADIE_VERSION,
		);
		$token   = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}
}
