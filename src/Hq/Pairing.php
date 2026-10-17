<?php
/**
 * Connecting to HQ with a button instead of a pasted connection string
 * (docs/hq-protocol.md, "Connecting with the button").
 *
 * Pairing::start() sends the browser to HQ with a one-time state and a
 * challenge; HQ sends it back with a one-time code; Pairing::finish() trades
 * the code plus the verifier only this server knows for the connection
 * string. The secret
 * never appears in a URL.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WP_Error;
use WPRoadie\Admin\Menu;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Pairing {

	/**
	 * Pending requests live in one option (never a transient: with an object
	 * cache that is not persistent, e.g. a staging site without Redis, a
	 * transient set in one request is gone by the next, and the person coming
	 * back from HQ is told their request expired).
	 */
	const PENDING_OPTION  = 'wproadie_hq_pairs';
	const LAST_URL_OPTION = 'wproadie_hq_last_url';
	const LIFETIME        = 15 * MINUTE_IN_SECONDS;
	const PROTOCOL        = '1';

	/**
	 * The HQ address to suggest: the WPROADIE_HQ_DEFAULT_URL constant, the
	 * wproadie_hq_default_url filter, else the address last used here.
	 */
	public static function default_url(): string {
		$url = defined( 'WPROADIE_HQ_DEFAULT_URL' ) ? (string) WPROADIE_HQ_DEFAULT_URL : '';
		/**
		 * Filters the HQ address prefilled on the Connect to HQ button.
		 *
		 * @param string $url Address from WPROADIE_HQ_DEFAULT_URL, or empty.
		 */
		$url = (string) apply_filters( 'wproadie_hq_default_url', $url );
		if ( '' === $url ) {
			$url = (string) get_option( self::LAST_URL_OPTION, '' );
		}
		return $url;
	}

	/**
	 * Why pairing is not possible right now, or null if it is.
	 */
	public static function blocked(): ?WP_Error {
		if ( Settings::is_disabled() ) {
			return new WP_Error( 'wproadie_disabled', __( 'WPRoadie is switched off on this site. Turn it back on before connecting to HQ.', 'wproadie' ), array( 'status' => 403 ) );
		}
		if ( Connection::from_constants() ) {
			return new WP_Error( 'wproadie_hq_constants', __( 'The HQ connection is set in wp-config.php (WPROADIE_HQ_URL, WPROADIE_HQ_SITE_ID and WPROADIE_HQ_SECRET), and those settings always win. Change or remove them there instead.', 'wproadie' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * Starts pairing for the current user.
	 *
	 * @param string $hq_url HQ address as typed.
	 * @return array{redirect: string}|WP_Error
	 */
	public static function start( string $hq_url ) {
		$blocked = self::blocked();
		if ( $blocked ) {
			return $blocked;
		}
		$url = Connection::normalize_url( $hq_url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return new WP_Error( 'wproadie_forbidden', __( 'Log in first.', 'wproadie' ), array( 'status' => 401 ) );
		}

		$state    = bin2hex( random_bytes( 16 ) );
		$verifier = Connection::base64url_encode( random_bytes( 32 ) );

		self::remember(
			$state,
			array(
				'hq_url'   => $url,
				'verifier' => $verifier,
				'user_id'  => (int) $user->ID,
			)
		);
		update_option( self::LAST_URL_OPTION, $url, false );

		$query = http_build_query(
			array(
				'v'           => self::PROTOCOL,
				'site_url'    => home_url(),
				'site_name'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'return_url'  => self::return_url(),
				'state'       => $state,
				'challenge'   => self::challenge( $verifier ),
				'wp_user'     => $user->user_login,
				'environment' => wp_get_environment_type(),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return array( 'redirect' => $url . '/connect?' . $query );
	}

	/**
	 * Base64url (no padding) SHA-256 of the verifier.
	 */
	public static function challenge( string $verifier ): string {
		return Connection::base64url_encode( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Where HQ sends the browser back: the WPRoadie admin screen.
	 */
	public static function return_url(): string {
		return admin_url( 'tools.php?page=' . Menu::SLUG );
	}

	/**
	 * Finishes pairing: trades HQ's one-time code for the connection.
	 *
	 * @param string $code  One-time code from HQ.
	 * @param string $state State from start().
	 * @return array{ok: bool, connected: bool, granted: bool}|WP_Error
	 */
	public static function finish( string $code, string $state ) {
		$started = microtime( true );
		$result  = self::exchange( $code, $state );

		$log = is_wp_error( $result ) ? $result : array(
			'connected' => true,
			'hq_url'    => $result['hq_url'],
			'site_id'   => $result['site_id'],
			'granted'   => $result['granted'],
			'linked'    => $result['linked'],
			'heartbeat' => $result['heartbeat'],
		);
		// Never the code, verifier or secret.
		AuditLog::record(
			'hq/pair',
			is_wp_error( $result )
				? array(
					'hq_url' => self::pending_url( $state ),
					'reason' => (string) ( $result->get_error_data()['reason'] ?? '' ),
				)
				: array( 'hq_url' => $result['hq_url'] ),
			$log,
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'        => true,
			'connected' => true,
			'granted'   => $result['granted'],
		);
	}

	/**
	 * @return array{hq_url: string, site_id: string, granted: bool, linked: bool, heartbeat: string}|WP_Error
	 */
	private static function exchange( string $code, string $state ) {
		$blocked = self::blocked();
		if ( $blocked ) {
			return $blocked;
		}
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $state ) ) {
			return self::expired( 'bad_state' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $code ) ) {
			return new WP_Error( 'wproadie_pair_bad_code', __( 'HQ sent back a code this site does not understand. Click "Connect to HQ" again.', 'wproadie' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		$pending = self::pending( $state );
		if ( ! $pending ) {
			return self::expired( 'not_found' );
		}
		// Another administrator's request is left alone for them to finish.
		if ( ! $user_id || (int) ( $pending['user_id'] ?? 0 ) !== $user_id ) {
			return self::expired( 'other_user' );
		}
		self::forget( $state );

		$hq_url   = (string) $pending['hq_url'];
		$response = wp_remote_post(
			$hq_url . '/api/v1/pair/exchange',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => (string) wp_json_encode(
					array(
						'code'          => $code,
						'code_verifier' => (string) $pending['verifier'],
						'site_url'      => home_url(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wproadie_pair_unreachable',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach HQ to finish connecting: %s', 'wproadie' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 400 === $status && is_array( $data ) && 'invalid_code' === ( $data['error'] ?? '' ) ) {
			return new WP_Error( 'wproadie_pair_invalid_code', __( 'HQ did not accept this connection request. It may have expired (approve within 10 minutes) or been used already. Click "Connect to HQ" again.', 'wproadie' ), array( 'status' => 400 ) );
		}
		if ( 200 !== $status || ! is_array( $data ) || ! isset( $data['connection_string'] ) || ! is_string( $data['connection_string'] ) ) {
			return new WP_Error(
				'wproadie_pair_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'HQ answered with an unexpected response (HTTP %d). Try again, or use a connection string instead.', 'wproadie' ),
					$status
				),
				array( 'status' => 502 )
			);
		}

		$config = Connection::parse( $data['connection_string'] );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		// The connection must point back at the HQ the person chose.
		if ( '' === Connection::origin( $config['url'] ) || Connection::origin( $config['url'] ) !== Connection::origin( $hq_url ) ) {
			return new WP_Error( 'wproadie_pair_mismatch', __( 'HQ answered with a connection for a different address, so it was not saved.', 'wproadie' ), array( 'status' => 400 ) );
		}

		$connected = self::connect( $data['connection_string'] );
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}

		$granted = false;
		if ( ! Permission::can_connect( $user_id ) ) {
			$granted = Permission::grant( $user_id );
		}
		// HQ names the admin who approved; when their email differs from this
		// user's, link it so HQ can issue this person passwords later.
		$linked = isset( $data['hq_email'] ) && is_string( $data['hq_email'] ) && HqIdentity::link_after_pairing( $user_id, $data['hq_email'] );

		return array(
			'hq_url'    => $connected['config']['url'],
			'site_id'   => $connected['config']['site_id'],
			'granted'   => $granted,
			'linked'    => $linked,
			'heartbeat' => true === $connected['heartbeat'] ? 'accepted' : 'failed',
		);
	}

	/**
	 * Stores a connection string and reports to HQ straight away: the one
	 * code path for the button, the pasted string and the REST API.
	 *
	 * @return array{config: array, heartbeat: true|WP_Error}|WP_Error
	 */
	public static function connect( string $connection_string ) {
		$config = Connection::connect( $connection_string );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		Heartbeat::ensure_scheduled();
		return array(
			'config'    => $config,
			'heartbeat' => Heartbeat::send(),
		);
	}

	/**
	 * Why a request could not be finished, in words a person can act on.
	 * The reason is kept in the error data (and the activity log).
	 *
	 * @param string $reason bad_state | not_found | other_user.
	 */
	private static function expired( string $reason ): WP_Error {
		switch ( $reason ) {
			case 'other_user':
				$message = __( 'This connection request was started by a different WordPress user. Sign in as that user, or click "Connect to HQ" again as yourself.', 'wproadie' );
				break;
			case 'not_found':
				$message = sprintf(
					/* translators: %d: minutes a request stays valid. */
					__( 'This site has no record of that connection request: it was started more than %d minutes ago, was already used, or the site could not save it. Click "Connect to HQ" again; if it happens again, the site is not keeping saved options between page loads.', 'wproadie' ),
					(int) ( self::LIFETIME / MINUTE_IN_SECONDS )
				);
				break;
			default:
				$message = __( 'HQ sent back a connection request this site does not recognise. Click "Connect to HQ" again.', 'wproadie' );
		}
		return new WP_Error(
			'wproadie_pair_expired',
			$message,
			array(
				'status' => 400,
				'reason' => $reason,
			)
		);
	}

	/**
	 * The HQ address of a pending request, for the log.
	 */
	private static function pending_url( string $state ): ?string {
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $state ) ) {
			return null;
		}
		$pending = self::pending( $state );
		return $pending ? (string) $pending['hq_url'] : null;
	}

	/**
	 * All pending requests that have not expired.
	 *
	 * @return array<string, array>
	 */
	private static function all_pending(): array {
		$all = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$now = time();
		return array_filter(
			$all,
			static function ( $row ) use ( $now ) {
				return is_array( $row ) && (int) ( $row['expires'] ?? 0 ) > $now;
			}
		);
	}

	/**
	 * A pending request, or null when unknown or expired.
	 *
	 * @param string $state State from start().
	 */
	public static function pending( string $state ): ?array {
		$all = self::all_pending();
		return isset( $all[ $state ] ) ? $all[ $state ] : null;
	}

	private static function remember( string $state, array $data ): void {
		$all           = self::all_pending();
		$all[ $state ] = $data + array( 'expires' => time() + self::LIFETIME );
		update_option( self::PENDING_OPTION, $all, false );
	}

	/**
	 * Drops a pending request (used, or abandoned).
	 *
	 * @param string $state State from start().
	 */
	public static function forget( string $state ): void {
		$all = self::all_pending();
		unset( $all[ $state ] );
		if ( $all ) {
			update_option( self::PENDING_OPTION, $all, false );
		} else {
			delete_option( self::PENDING_OPTION );
		}
	}
}
