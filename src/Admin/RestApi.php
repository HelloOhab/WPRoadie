<?php
/**
 * REST endpoints behind the WPRoadie admin screen.
 *
 * These serve the React app in wp-admin, authenticated by the logged-in
 * cookie plus REST nonce. They are separate from the MCP endpoint, and
 * every route requires a WPRoadie user (administrator with access).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WPRoadie\Abilities\Registry;
use WPRoadie\Hq\Connection as HqConnection;
use WPRoadie\Hq\Heartbeat as HqHeartbeat;
use WPRoadie\Hq\HqIdentity;
use WPRoadie\Hq\Pairing as HqPairing;
use WPRoadie\Log\AuditLog;
use WPRoadie\Log\Undo;
use WPRoadie\Plugin;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\RateLimit;
use WPRoadie\Safety\Settings;
use WPRoadie\Safety\Stealth;
use WPRoadie\Server\McpServer;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	const NAMESPACE_V1 = 'wproadie/v1';

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$routes = array(
			'/status'                           => array(
				array( WP_REST_Server::READABLE, 'get_status' ),
			),
			'/settings'                         => array(
				array( WP_REST_Server::EDITABLE, 'update_settings' ),
			),
			'/credentials'                      => array(
				array( WP_REST_Server::READABLE, 'list_credentials' ),
				array( WP_REST_Server::CREATABLE, 'create_credential' ),
			),
			'/credentials/(?P<uuid>[\w-]+)'     => array(
				array( WP_REST_Server::DELETABLE, 'revoke_credential' ),
			),
			'/access'                           => array(
				array( WP_REST_Server::READABLE, 'list_access' ),
				array( WP_REST_Server::CREATABLE, 'grant_access' ),
			),
			'/access/(?P<user_id>\d+)'          => array(
				array( WP_REST_Server::DELETABLE, 'revoke_access' ),
			),
			'/access/(?P<user_id>\d+)/hq-email' => array(
				array( WP_REST_Server::CREATABLE, 'set_hq_email' ),
			),
			'/log'                              => array(
				array( WP_REST_Server::READABLE, 'list_log' ),
			),
			'/log/(?P<id>\d+)'                  => array(
				array( WP_REST_Server::READABLE, 'get_log_entry' ),
			),
			'/log/(?P<id>\d+)/undo'             => array(
				array( WP_REST_Server::CREATABLE, 'undo_log_entry' ),
			),
		);

		foreach ( $routes as $route => $endpoints ) {
			$config = array();
			foreach ( $endpoints as $endpoint ) {
				$config[] = array(
					'methods'             => $endpoint[0],
					'callback'            => array( $this, $endpoint[1] ),
					'permission_callback' => array( $this, 'can_manage' ),
				);
			}
			register_rest_route( self::NAMESPACE_V1, $route, $config );
		}

		// Setup steps: also open to administrators who do not have AI
		// access yet, while Permission::can_set_up() allows it.
		$setup = array(
			'/setup'           => array( WP_REST_Server::READABLE, 'get_setup', 'can_set_up' ),
			'/access/me'       => array( WP_REST_Server::CREATABLE, 'grant_self', 'can_set_up' ),
			'/adapter/install' => array( WP_REST_Server::CREATABLE, 'install_adapter', 'can_set_up' ),
			'/hq/connect'      => array( WP_REST_Server::CREATABLE, 'hq_connect', 'can_change_hq' ),
			'/hq/pair'         => array( WP_REST_Server::CREATABLE, 'hq_pair', 'can_change_hq' ),
			'/hq/pair/finish'  => array( WP_REST_Server::CREATABLE, 'hq_pair_finish', 'can_change_hq' ),
		);
		foreach ( $setup as $route => $endpoint ) {
			register_rest_route(
				self::NAMESPACE_V1,
				$route,
				array(
					'methods'             => $endpoint[0],
					'callback'            => array( $this, $endpoint[1] ),
					'permission_callback' => array( $this, $endpoint[2] ),
				)
			);
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public function can_set_up() {
		if ( Permission::can_set_up() ) {
			return true;
		}
		return new WP_Error( 'wproadie_forbidden', __( 'You are not allowed to set up WPRoadie.', 'wproadie' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Connecting to HQ: people with AI access, or, while the site is not
	 * connected yet, anyone allowed to set WPRoadie up. Only people with
	 * access can replace an existing connection.
	 *
	 * @return true|WP_Error
	 */
	public function can_change_hq() {
		if ( Permission::can_manage() || ( Permission::can_set_up() && ! HqConnection::is_connected() ) ) {
			return true;
		}
		return new WP_Error( 'wproadie_forbidden', __( 'Only people with AI access on this site can change its HQ connection.', 'wproadie' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * The setup checklist: MCP Adapter, HQ, the current user's access.
	 */
	public static function setup_data(): array {
		$adapter    = AdapterInstaller::status();
		$has_access = Permission::can_connect();
		$connected  = HqConnection::is_connected();
		return array(
			'complete' => $adapter['available'] && $connected && $has_access,
			'disabled' => Settings::is_disabled(),
			'user'     => wp_get_current_user()->user_login,
			'adapter'  => $adapter,
			'hq'       => array(
				'connected'      => $connected,
				'url'            => $connected ? HqConnection::get()['url'] : null,
				'from_constants' => HqConnection::from_constants(),
				'default_url'    => HqPairing::default_url(),
				'can_change'     => true === ( new self() )->can_change_hq(),
			),
			'access'   => array(
				'has_access' => $has_access,
				'can_grant'  => ! $has_access && Permission::can_set_up() && current_user_can( 'manage_options' ),
			),
		);
	}

	public function get_setup(): array {
		return self::setup_data();
	}

	/**
	 * "Give me access": an administrator gives themselves AI access.
	 *
	 * @return array|WP_Error
	 */
	public function grant_self() {
		$user_id = get_current_user_id();
		$already = Permission::can_connect( $user_id );
		if ( ! $already && ! Permission::grant( $user_id ) ) {
			return new WP_Error( 'wproadie_not_admin', __( 'Only administrators can be given access.', 'wproadie' ), array( 'status' => 400 ) );
		}
		if ( ! $already ) {
			AuditLog::record( 'admin/grant-access', array( 'user' => wp_get_current_user()->user_login ), array( 'granted' => true ), 0 );
		}
		return self::setup_data();
	}

	/**
	 * @return array|WP_Error
	 */
	public function install_adapter() {
		return AdapterInstaller::run();
	}

	/**
	 * Connects with a pasted connection string.
	 *
	 * @return array|WP_Error
	 */
	public function hq_connect( WP_REST_Request $request ) {
		$connected = HqPairing::connect( (string) $request->get_param( 'connection_string' ) );
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}
		return array(
			'ok'        => true,
			'connected' => true,
			'heartbeat' => true === $connected['heartbeat'],
		);
	}

	/**
	 * Starts the Connect to HQ button flow.
	 *
	 * @return array|WP_Error
	 */
	public function hq_pair( WP_REST_Request $request ) {
		return HqPairing::start( (string) $request->get_param( 'hq_url' ) );
	}

	/**
	 * Finishes it when HQ sends the browser back.
	 *
	 * @return array|WP_Error
	 */
	public function hq_pair_finish( WP_REST_Request $request ) {
		return HqPairing::finish( (string) $request->get_param( 'code' ), (string) $request->get_param( 'state' ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( Permission::can_manage() ) {
			return true;
		}
		return new WP_Error( 'wproadie_forbidden', __( 'You are not allowed to manage WPRoadie.', 'wproadie' ), array( 'status' => rest_authorization_required_code() ) );
	}

	public function get_status(): array {
		$registry = new Registry();
		$enabled  = $registry->enabled_names();

		$groups = array();
		foreach ( Settings::GROUPS as $key => $config ) {
			$groups[ $key ] = array(
				'key'       => $key,
				'label'     => Settings::group_label( $key ),
				'risk'      => $config['risk'],
				'enabled'   => Settings::group_enabled( $key ),
				'allowed'   => Mode::allows( $config['risk'] ),
				'abilities' => array(),
			);
		}
		foreach ( $registry->all() as $ability ) {
			if ( ! isset( $groups[ $ability->group() ] ) ) {
				continue;
			}
			$groups[ $ability->group() ]['abilities'][] = array(
				'name'      => $ability->name(),
				'label'     => $ability->label(),
				'type'      => $ability->mcp_type(),
				'risk'      => $ability->risk(),
				'available' => $ability->is_available(),
				'reason'    => $ability->unavailable_reason(),
				'exposed'   => in_array( $ability->name(), $enabled, true ),
				'allowed'   => Mode::allows( $ability->risk() ),
			);
		}
		// A group whose tools span risk levels (e.g. updates allowed on a live
		// site, installs not) is partly allowed: say so instead of "blocked".
		foreach ( $groups as &$group ) {
			$allowed          = count( array_filter( array_column( $group['abilities'], 'allowed' ) ) );
			$group['allowed'] = $group['abilities'] ? $allowed > 0 : Mode::allows( $group['risk'] );
			$group['partly']  = $allowed > 0 && $allowed < count( $group['abilities'] );
		}
		unset( $group );

		$user_id = get_current_user_id();

		return array(
			'version'              => WPROADIE_VERSION,
			'adapter'              => array(
				'available' => Plugin::adapter_available(),
				'version'   => defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : null,
				'required'  => WPROADIE_MCP_ADAPTER_MIN,
			),
			'disabled'             => Settings::is_disabled(),
			'disabled_by_constant' => Settings::disabled_by_constant(),
			'mode'                 => array(
				'current'     => Mode::current(),
				'source'      => Mode::source(),
				'detected'    => Mode::detect(),
				'stored'      => (string) Settings::get( 'mode' ),
				'environment' => wp_get_environment_type(),
				'modes'       => array_map(
					static function ( $mode ) {
						return array(
							'value' => $mode,
							'label' => Mode::label( $mode ),
						);
					},
					Mode::all()
				),
			),
			'endpoint'             => McpServer::endpoint_url(),
			'server_name'          => Credentials::server_name(),
			'exposed'              => count( $enabled ),
			'groups'               => array_values( $groups ),
			'stealth'              => Stealth::enabled(),
			'rate_limit'           => RateLimit::per_minute(),
			'passwords'            => array(
				'available' => Credentials::available( $user_id ),
				'reason'    => Credentials::available( $user_id ) ? '' : Credentials::unavailable_reason(),
			),
			'user'                 => wp_get_current_user()->user_login,
			'hq'                   => self::hq_status(),
			'setup'                => self::setup_data(),
		);
	}

	/**
	 * HQ connection state for the admin screen. Never includes the secret.
	 */
	private static function hq_status(): array {
		$config = HqConnection::get();
		$last   = HqHeartbeat::last_result();
		return array(
			'connected'      => null !== $config,
			'url'            => $config ? $config['url'] : null,
			'site_id'        => $config ? $config['site_id'] : null,
			'from_constants' => HqConnection::from_constants(),
			'last'           => $last ? array(
				'at'    => gmdate( 'c', (int) $last['at'] ),
				'ok'    => (bool) $last['ok'],
				'error' => $last['error'],
			) : null,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		if ( array_key_exists( 'mode', $params ) ) {
			$mode = sanitize_key( (string) $params['mode'] );
			Settings::set( 'mode', in_array( $mode, Mode::all(), true ) ? $mode : '' );
		}
		if ( isset( $params['groups'] ) && is_array( $params['groups'] ) ) {
			foreach ( $params['groups'] as $group => $on ) {
				Settings::set_group( sanitize_key( (string) $group ), (bool) $on );
			}
		}
		if ( array_key_exists( 'stealth', $params ) ) {
			Settings::set( 'stealth', (bool) $params['stealth'] );
		}
		if ( array_key_exists( 'rate_limit', $params ) ) {
			Settings::set( 'rate_limit', max( 0, min( 10000, (int) $params['rate_limit'] ) ) );
		}
		if ( array_key_exists( 'disabled', $params ) ) {
			Settings::set( 'disabled', (bool) $params['disabled'] );
		}
		if ( ! empty( $params['hq_connect'] ) && is_string( $params['hq_connect'] ) ) {
			$connected = HqPairing::connect( $params['hq_connect'] );
			if ( is_wp_error( $connected ) ) {
				return $connected;
			}
		}
		if ( ! empty( $params['hq_disconnect'] ) && ! HqConnection::from_constants() ) {
			HqConnection::disconnect();
			HqHeartbeat::unschedule();
		}
		if ( ! empty( $params['hq_heartbeat'] ) ) {
			HqHeartbeat::send();
		}

		return $this->get_status();
	}

	public function list_credentials(): array {
		return array_map(
			static function ( $item ) {
				return array(
					'uuid'      => $item['uuid'],
					'name'      => $item['name'],
					'created'   => $item['created'] ? gmdate( 'c', (int) $item['created'] ) : null,
					'last_used' => $item['last_used'] ? gmdate( 'c', (int) $item['last_used'] ) : null,
					'last_ip'   => $item['last_ip'] ?? null,
				);
			},
			Credentials::list_for( get_current_user_id() )
		);
	}

	/**
	 * Creates a password and returns it once, with ready-made client config.
	 * It is never stored in plain text and cannot be fetched again.
	 *
	 * @return array|WP_Error
	 */
	public function create_credential( WP_REST_Request $request ) {
		$user  = wp_get_current_user();
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );

		if ( ! Credentials::available( $user->ID ) ) {
			return new WP_Error( 'wproadie_passwords_unavailable', Credentials::unavailable_reason(), array( 'status' => 400 ) );
		}

		$created = Credentials::create( $user->ID, $label );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array(
			'uuid'           => $created['uuid'],
			'username'       => $user->user_login,
			'password'       => $created['password'],
			'claude_code'    => Credentials::claude_code_command( $user->user_login, $created['password'] ),
			'desktop_config' => Credentials::desktop_config( $user->user_login, $created['password'] ),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function revoke_credential( WP_REST_Request $request ) {
		$revoked = Credentials::revoke( get_current_user_id(), (string) $request['uuid'] );
		if ( is_wp_error( $revoked ) ) {
			$revoked->add_data( array( 'status' => 404 ) );
			return $revoked;
		}
		return array( 'revoked' => true );
	}

	public function list_access(): array {
		$granted     = Permission::granted_users();
		$granted_ids = wp_list_pluck( $granted, 'ID' );
		$describe    = static function ( $user ) {
			return array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'hq_email'     => HqIdentity::linked_email( (int) $user->ID ),
				'is_admin'     => user_can( $user, 'manage_options' ),
			);
		};

		return array(
			'granted'    => array_map( $describe, $granted ),
			'candidates' => array_values(
				array_map(
					$describe,
					array_filter(
						get_users( array( 'role' => 'administrator' ) ),
						static function ( $user ) use ( $granted_ids ) {
							return ! in_array( $user->ID, $granted_ids, true );
						}
					)
				)
			),
			'me'         => get_current_user_id(),
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function grant_access( WP_REST_Request $request ) {
		if ( ! Permission::grant( (int) $request->get_param( 'user_id' ) ) ) {
			return new WP_Error( 'wproadie_not_admin', __( 'Only administrators can be given access.', 'wproadie' ), array( 'status' => 400 ) );
		}
		return $this->list_access();
	}

	/**
	 * Links the HQ email (the Google account someone signs in to HQ with)
	 * to a WordPress user whose own email is different. Empty unlinks.
	 *
	 * @return array|WP_Error
	 */
	public function set_hq_email( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'wproadie_no_user', __( 'No such user.', 'wproadie' ), array( 'status' => 404 ) );
		}
		$email = (string) $request->get_param( 'email' );
		if ( ! HqIdentity::link( (int) $user->ID, $email ) ) {
			return new WP_Error( 'wproadie_bad_email', __( 'Enter a valid email address.', 'wproadie' ), array( 'status' => 400 ) );
		}
		AuditLog::record( 'admin/link-hq-email', array( 'user' => $user->user_login ), array( 'hq_email' => HqIdentity::linked_email( (int) $user->ID ) ), 0 );
		return $this->list_access();
	}

	/**
	 * @return array|WP_Error
	 */
	public function revoke_access( WP_REST_Request $request ) {
		$user_id = (int) $request['user_id'];
		if ( get_current_user_id() === $user_id ) {
			return new WP_Error( 'wproadie_revoke_self', __( 'You cannot remove your own access here. Ask another WPRoadie user, or use `wp wproadie revoke`.', 'wproadie' ), array( 'status' => 400 ) );
		}
		Permission::revoke( $user_id );
		return $this->list_access();
	}

	public function list_log( WP_REST_Request $request ): array {
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 25 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1 ) );
		$filters  = array(
			'user_id'  => (int) $request->get_param( 'user_id' ),
			'ability'  => sanitize_text_field( (string) $request->get_param( 'ability' ) ),
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'undoable' => rest_sanitize_boolean( $request->get_param( 'undoable' ) ),
		);

		$result = AuditLog::query( $filters, $per_page, ( $page - 1 ) * $per_page );

		return array(
			'entries'     => array_map( array( $this, 'describe_row' ), $result['rows'] ),
			'total'       => $result['total'],
			'total_pages' => (int) ceil( $result['total'] / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function get_log_entry( WP_REST_Request $request ) {
		$row = AuditLog::get( (int) $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'wproadie_not_found', __( 'No log entry with that ID.', 'wproadie' ), array( 'status' => 404 ) );
		}
		$entry           = $this->describe_row( $row );
		$entry['input']  = (string) $row->input;
		$entry['result'] = (string) $row->result;
		return $entry;
	}

	/**
	 * Undo from the admin screen. A person deciding to revert is allowed in
	 * any mode, unlike the AI's undo-change tool.
	 *
	 * @return array|WP_Error
	 */
	public function undo_log_entry( WP_REST_Request $request ) {
		return Undo::revert( (int) $request['id'] );
	}

	/**
	 * @param object $row Log row.
	 */
	private function describe_row( $row ): array {
		return array(
			'id'          => (int) $row->id,
			'time'        => gmdate( 'c', (int) strtotime( $row->created_at . ' UTC' ) ),
			'user'        => AuditLog::actor( $row ),
			'ability'     => $row->ability,
			'status'      => $row->status,
			'summary'     => 'error' === $row->status ? wp_html_excerpt( (string) $row->result, 160, '…' ) : wp_html_excerpt( (string) $row->input, 120, '…' ),
			'error_code'  => $row->error_code,
			'ip'          => $row->ip,
			'transport'   => $row->transport,
			'duration_ms' => (int) $row->duration_ms,
			'undoable'    => Undo::is_undoable( $row ),
			'undone_at'   => $row->undone_at ? gmdate( 'c', (int) strtotime( $row->undone_at . ' UTC' ) ) : null,
			'undo_of'     => $row->undo_of ? (int) $row->undo_of : null,
		);
	}
}
