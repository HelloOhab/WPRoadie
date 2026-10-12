<?php
/**
 * The endpoint HQ sends signed commands to (docs/hq-protocol.md,
 * "Commands"). No WordPress login: the signature is the authentication.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WPRoadie\Admin\Credentials;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Commands {

	const ROUTE_NAMESPACE = 'wproadie/v1';
	const ROUTE           = '/hq';

	/**
	 * Commands that still run with the kill switch on: they report state
	 * or take access away, never grant it.
	 */
	const ALLOWED_WHEN_DISABLED = array( 'ping', 'heartbeat', 'list_access', 'list_tools', 'revoke_credential', 'revoke_user' );

	/**
	 * Result of the signature check for the current request.
	 *
	 * @var true|string|null
	 */
	private $verified = null;

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify' ),
			)
		);
	}

	/**
	 * Permission callback: a valid HQ signature, nothing else.
	 */
	public function verify( WP_REST_Request $request ): bool {
		$this->verified = Signer::verify(
			'command',
			(string) $request->get_header( 'x_wproadie_site' ),
			(string) $request->get_header( 'x_wproadie_timestamp' ),
			(string) $request->get_header( 'x_wproadie_nonce' ),
			(string) $request->get_header( 'x_wproadie_signature' ),
			(string) $request->get_body()
		);
		// Always let the request through to handle(), which answers in the
		// protocol's format; a WordPress 401 here would not.
		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( true !== $this->verified ) {
			return new WP_REST_Response( array( 'error' => is_string( $this->verified ) ? $this->verified : 'bad_signature' ), 401 );
		}

		$data    = json_decode( (string) $request->get_body(), true );
		$command = is_array( $data ) && isset( $data['command'] ) && is_string( $data['command'] ) ? $data['command'] : '';
		$args    = is_array( $data ) && isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();

		$started = microtime( true );
		$result  = $this->run( $command, $args );

		$ok = ! isset( $result['error'] );
		AuditLog::record(
			'hq/' . ( '' !== $command ? sanitize_key( $command ) : 'unknown' ),
			$args,
			$ok ? self::loggable( $result['result'] ) : new \WP_Error( 'wproadie_hq_' . $result['error'], $result['message'] ),
			(int) round( ( microtime( true ) - $started ) * 1000 )
		);

		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => $result['error'],
					'message' => $result['message'],
				),
				(int) $result['status']
			);
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'result' => $result['result'],
			),
			200
		);
	}

	/**
	 * @param string $command Command name.
	 * @param array  $args    Arguments.
	 * @return array{result?: mixed, error?: string, message?: string, status?: int}
	 */
	private function run( string $command, array $args ): array {
		$known = array( 'ping', 'heartbeat', 'list_access', 'list_tools', 'provision_credential', 'revoke_credential', 'revoke_user' );
		if ( ! in_array( $command, $known, true ) ) {
			return self::fail( 'unknown_command', __( 'Unknown command.', 'wproadie' ), 400 );
		}
		if ( Settings::is_disabled() && ! in_array( $command, self::ALLOWED_WHEN_DISABLED, true ) ) {
			return self::fail( 'disabled', __( 'WPRoadie is switched off on this site.', 'wproadie' ), 403 );
		}

		switch ( $command ) {
			case 'ping':
				return array( 'result' => array( 'version' => WPROADIE_VERSION ) );

			case 'heartbeat':
				return array( 'result' => Heartbeat::payload() );

			case 'list_access':
				return array( 'result' => array( 'users' => self::access_list() ) );

			case 'list_tools':
				return array( 'result' => ToolCatalog::get() );

			case 'provision_credential':
				return self::provision( $args );

			case 'revoke_credential':
				return self::revoke_credential( $args );

			case 'revoke_user':
				return self::revoke_user( $args );
		}

		return self::fail( 'unknown_command', __( 'Unknown command.', 'wproadie' ), 400 );
	}

	private static function access_list(): array {
		$users = array();
		foreach ( Permission::granted_users() as $user ) {
			$passwords = array();
			foreach ( Credentials::list_for( (int) $user->ID ) as $item ) {
				$passwords[] = array(
					'uuid'      => $item['uuid'],
					'name'      => $item['name'],
					'created'   => $item['created'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $item['created'] ) : null,
					'last_used' => $item['last_used'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $item['last_used'] ) : null,
				);
			}
			$users[] = array(
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'is_admin'     => user_can( $user, 'manage_options' ),
				'has_access'   => Permission::can_connect( (int) $user->ID ),
				'passwords'    => $passwords,
			);
		}
		return $users;
	}

	private static function provision( array $args ): array {
		$user = self::user( $args );
		if ( ! $user ) {
			return self::fail( 'user_not_found', __( 'No WordPress user with that login or email.', 'wproadie' ), 404 );
		}
		// HQ can hand out passwords only to people a site admin already let
		// in; it cannot widen access on its own.
		if ( ! Permission::can_connect( (int) $user->ID ) ) {
			return self::fail( 'no_access', __( 'That user does not have WPRoadie access on this site. Grant it first with `wp wproadie grant`.', 'wproadie' ), 403 );
		}
		if ( ! is_ssl() && 'local' !== wp_get_environment_type() && ! (bool) apply_filters( 'wproadie_hq_allow_http', false ) ) {
			return self::fail( 'insecure', __( 'Passwords are only issued over HTTPS.', 'wproadie' ), 403 );
		}
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return self::fail( 'failed', Credentials::unavailable_reason(), 400 );
		}

		$label = isset( $args['label'] ) && is_string( $args['label'] ) ? sanitize_text_field( $args['label'] ) : '';
		$label = '' !== $label ? $label : __( 'HQ', 'wproadie' );

		$created = WP_Application_Passwords::create_new_application_password( (int) $user->ID, array( 'name' => Credentials::NAME_PREFIX . ' HQ – ' . $label ) );
		if ( is_wp_error( $created ) ) {
			return self::fail( 'failed', $created->get_error_message(), 500 );
		}

		list( $password, $item ) = $created;
		return array(
			'result' => array(
				'username' => $user->user_login,
				'uuid'     => $item['uuid'],
				'password' => WP_Application_Passwords::chunk_password( $password ),
			),
		);
	}

	private static function revoke_credential( array $args ): array {
		$user = self::user( $args );
		if ( ! $user ) {
			return self::fail( 'user_not_found', __( 'No WordPress user with that login or email.', 'wproadie' ), 404 );
		}
		$uuid = isset( $args['uuid'] ) && is_string( $args['uuid'] ) ? $args['uuid'] : '';
		if ( '' === $uuid ) {
			return self::fail( 'bad_args', __( 'Give the password uuid.', 'wproadie' ), 400 );
		}
		$revoked = Credentials::revoke( (int) $user->ID, $uuid );
		if ( is_wp_error( $revoked ) ) {
			return self::fail( 'not_found', $revoked->get_error_message(), 404 );
		}
		return array( 'result' => array( 'revoked' => true ) );
	}

	private static function revoke_user( array $args ): array {
		$user = self::user( $args );
		if ( ! $user ) {
			return self::fail( 'user_not_found', __( 'No WordPress user with that login or email.', 'wproadie' ), 404 );
		}

		$count = 0;
		foreach ( Credentials::list_for( (int) $user->ID ) as $item ) {
			if ( true === WP_Application_Passwords::delete_application_password( (int) $user->ID, $item['uuid'] ) ) {
				++$count;
			}
		}
		Permission::revoke( (int) $user->ID );

		return array(
			'result' => array(
				'passwords_revoked' => $count,
				'access_removed'    => true,
			),
		);
	}

	/**
	 * The user named by args.wp_user, by login or email.
	 *
	 * @param array $args Command arguments.
	 */
	private static function user( array $args ): ?WP_User {
		$id = isset( $args['wp_user'] ) && is_string( $args['wp_user'] ) ? trim( $args['wp_user'] ) : '';
		if ( '' === $id ) {
			return null;
		}
		$user = get_user_by( 'login', $id );
		if ( ! $user && is_email( $id ) ) {
			$user = get_user_by( 'email', $id );
		}
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * A result safe to store in the audit log: never a password.
	 *
	 * @param mixed $result Command result.
	 * @return mixed
	 */
	private static function loggable( $result ) {
		if ( is_array( $result ) && isset( $result['password'] ) ) {
			$result['password'] = '[issued, not logged]';
		}
		if ( is_array( $result ) && isset( $result['site'], $result['versions'] ) ) {
			return array( 'heartbeat' => 'sent' );
		}
		if ( is_array( $result ) && isset( $result['tools_hash'], $result['tools'] ) ) {
			// The full catalog is large; the count and hash say what was sent.
			return array(
				'tools'      => count( $result['tools'] ),
				'tools_hash' => $result['tools_hash'],
			);
		}
		return $result;
	}

	private static function fail( string $code, string $message, int $status ): array {
		return array(
			'error'   => $code,
			'message' => $message,
			'status'  => $status,
		);
	}
}
