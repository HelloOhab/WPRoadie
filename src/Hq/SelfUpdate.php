<?php
/**
 * The `self_update` command: HQ asks the site to update WPRoadie itself
 * (docs/hq-protocol.md, "Commands"). A person clicks the button in HQ;
 * the AI has no way to send this, and update-plugin keeps refusing
 * WPRoadie.
 *
 * HQ only names the version it expects. Where the update comes from is
 * the site's own setting (the GitHub repository the Updater reads), so
 * HQ cannot make a site install anything but a published WPRoadie
 * release. The download is checked against the checksum GitHub
 * publishes for the release asset.
 *
 * The update runs through SafeUpdate: back up, update, load the site,
 * and put the old version back if the site or this endpoint stops
 * answering. No undo record is kept: undo-change must not be a way for
 * the AI to downgrade WPRoadie.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WPRoadie\Abilities\Extensions\Installer;
use WPRoadie\Abilities\Extensions\SafeUpdate;
use WPRoadie\Admin\Updater;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\PendingUpdates;

defined( 'ABSPATH' ) || exit;

final class SelfUpdate {

	const LOCK = 'wproadie_self_update_lock';

	/**
	 * Runs the command.
	 *
	 * @param array       $args        {version?: string} The version HQ saw as latest.
	 * @param string|null $plugin_file The plugin to update; WPRoadie itself unless a test says otherwise.
	 * @return array{result?: array, error?: string, message?: string, status?: int}
	 */
	public static function run( array $args, ?string $plugin_file = null ): array {
		$plugin_file = $plugin_file ?? plugin_basename( WPROADIE_FILE );
		$expected    = isset( $args['version'] ) && is_string( $args['version'] ) ? trim( $args['version'] ) : '';
		if ( '' !== $expected && ! preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $expected ) ) {
			return self::fail( 'bad_args', __( 'version must look like 1.2.3.', 'wproadie' ), 400 );
		}

		Installer::load();
		if ( ! Installer::filesystem_ready( WP_PLUGIN_DIR ) ) {
			return self::fail( 'failed', Installer::filesystem_reason(), 409 );
		}
		$installed = (string) ( get_plugins()[ $plugin_file ]['Version'] ?? '' );
		if ( '' === $installed ) {
			return self::fail( 'failed', __( 'WPRoadie\'s own files could not be found.', 'wproadie' ), 500 );
		}

		$release = Updater::latest_release( true );
		if ( ! $release ) {
			return self::fail( 'no_release', __( 'The site could not read WPRoadie\'s latest release from GitHub. Check that it can reach api.github.com (and WPROADIE_GITHUB_TOKEN for a private repository).', 'wproadie' ), 502 );
		}
		if ( '' !== $expected && $release['version'] !== $expected ) {
			return self::fail(
				'version_mismatch',
				sprintf(
					/* translators: 1: version HQ asked for, 2: latest release the site sees. */
					__( 'HQ asked for %1$s but the latest release the site sees is %2$s. Refresh HQ and try again.', 'wproadie' ),
					$expected,
					$release['version']
				),
				409
			);
		}
		if ( ! version_compare( $release['version'], $installed, '>' ) ) {
			return array(
				'result' => array(
					'status'  => 'current',
					'from'    => $installed,
					'to'      => $installed,
					'message' => sprintf(
						/* translators: %s: installed version. */
						__( 'WPRoadie %s is already the latest release.', 'wproadie' ),
						$installed
					),
				),
			);
		}

		if ( get_transient( self::LOCK ) ) {
			return self::fail( 'busy', __( 'An update of WPRoadie is already running on this site.', 'wproadie' ), 409 );
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

		try {
			return array( 'result' => self::update( $plugin_file, $installed, $release ) );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * @param string $plugin_file The plugin to update.
	 * @param string $installed   Its version now.
	 * @param array  $release     From Updater::latest_release().
	 */
	private static function update( string $plugin_file, string $installed, array $release ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on some hosts; best effort.
		}
		// Once the new files are in place, any class this request has not
		// loaded yet would come from the new version. Load what is still
		// needed to finish and answer HQ while the old files are here.
		foreach ( array( AuditLog::class, Heartbeat::class, Connection::class, Updater::class, PendingUpdates::class, \WP_REST_Response::class ) as $class ) {
			class_exists( $class );
		}

		self::offer( $plugin_file, $release );

		$record = null;
		$run    = SafeUpdate::run(
			array(
				array(
					'kind' => SafeUpdate::KIND_PLUGIN,
					'id'   => $plugin_file,
					'name' => 'WPRoadie',
					'from' => $installed,
					'to'   => $release['version'],
				),
			),
			true,
			static function ( array $backup ) use ( &$record ) {
				$record = $backup;
			}
		);
		$item   = $run['results'][0] ?? array(
			'status'  => 'failed',
			'message' => __( 'The update did not run.', 'wproadie' ),
		);

		$endpoint = null;
		if ( 'updated' === $item['status'] && $record ) {
			// The site loads; WPRoadie must also still answer HQ, or HQ loses
			// the site. Put the old version back if the new one does not.
			$endpoint = self::endpoint_probe();
			if ( false === $endpoint['ok'] ) {
				$restored        = SafeUpdate::restore( $record );
				$item['status']  = is_wp_error( $restored ) ? 'broken' : 'rolled_back';
				$item['message'] = sprintf(
					/* translators: 1: probe detail, 2: version put back. */
					__( 'The new version did not answer HQ (%1$s). The previous version (%2$s) was put back.', 'wproadie' ),
					$endpoint['detail'],
					$installed
				);
			}
		}

		return array(
			'status'   => $item['status'],
			'from'     => $installed,
			'to'       => 'updated' === $item['status'] ? (string) $item['to'] : $installed,
			'message'  => (string) $item['message'],
			'health'   => $run['health'],
			'endpoint' => $endpoint,
			'log'      => $item['log'] ?? array(),
		);
	}

	/**
	 * Puts the release into WordPress's update list, as the Updater does on
	 * every update check, so the upgrader finds the package.
	 */
	private static function offer( string $plugin_file, array $release ): void {
		$transient = get_site_transient( 'update_plugins' );
		$transient = is_object( $transient ) ? $transient : (object) array(
			'response'  => array(),
			'no_update' => array(),
		);

		$transient->response                 = isset( $transient->response ) ? (array) $transient->response : array();
		$transient->response[ $plugin_file ] = (object) array(
			'id'          => Updater::repo(),
			'slug'        => dirname( $plugin_file ),
			'plugin'      => $plugin_file,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);
		set_site_transient( 'update_plugins', $transient );
	}

	/**
	 * Calls the HQ endpoint without a signature, as a stranger would: a
	 * working WPRoadie answers 401 with its JSON error. `ok` is null when
	 * the host does not let the site call itself.
	 *
	 * @return array{ok: bool|null, status: int|null, detail: string}
	 */
	public static function endpoint_probe(): array {
		$response = wp_remote_post(
			rest_url( Commands::ROUTE_NAMESPACE . Commands::ROUTE ),
			array(
				'timeout'   => SafeUpdate::PROBE_TIMEOUT,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to this site.
				'headers'   => array(
					'Content-Type'     => 'application/json',
					'X-WPRoadie-Probe' => '1',
				),
				'body'      => '{}',
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
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 401 === $status && is_array( $body ) && isset( $body['error'] ) ) {
			return array(
				'ok'     => true,
				'status' => $status,
				'detail' => 'HTTP 401',
			);
		}
		if ( in_array( $status, array( 403, 429, 503 ), true ) && ! isset( $body['code'] ) ) {
			// A firewall or maintenance page in front of the REST API: no verdict.
			return array(
				'ok'     => null,
				'status' => $status,
				'detail' => sprintf( 'HTTP %d', $status ),
			);
		}
		return array(
			'ok'     => false,
			'status' => $status,
			'detail' => is_array( $body ) && isset( $body['code'] ) ? sprintf( 'HTTP %d, %s', $status, sanitize_key( (string) $body['code'] ) ) : sprintf( 'HTTP %d', $status ),
		);
	}

	private static function fail( string $code, string $message, int $status ): array {
		return array(
			'error'   => $code,
			'message' => $message,
			'status'  => $status,
		);
	}
}
