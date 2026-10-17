<?php
/**
 * Connecting to HQ with the button (docs/hq-protocol.md, "Connecting with
 * the button") and the setup permissions around it.
 *
 * @package WPRoadie
 */

// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames, WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Hq\Connection;
use WPRoadie\Hq\Heartbeat;
use WPRoadie\Hq\HqIdentity;
use WPRoadie\Hq\Pairing;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\Permission;

const PAIR_TEST_HQ     = 'https://hq.pair.example.test';
const PAIR_TEST_SITE   = '0b6f6a8e-3c1d-4e2f-9a0b-1c2d3e4f5a6b';
const PAIR_TEST_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

/**
 * A connection string as HQ's exchange endpoint returns it.
 *
 * @param string $url HQ address inside the string.
 */
function pair_test_string( string $url = PAIR_TEST_HQ ): string {
	$json = (string) wp_json_encode(
		array(
			'url'     => $url,
			'site_id' => PAIR_TEST_SITE,
			'secret'  => PAIR_TEST_SECRET,
		)
	);
	return Connection::STRING_PREFIX . Connection::base64url_encode( $json );
}

/**
 * Runs $fn with HTTP requests answered by a fake HQ.
 *
 * @param callable $exchange Returns [status, body array] for an exchange request; gets the decoded request body.
 * @param callable $fn       Test body.
 * @return array Requests seen: [url, decoded body].
 */
function pair_test_with_fake_hq( callable $exchange, callable $fn ): array {
	$seen = array();
	$fake = static function ( $pre, $args, $url ) use ( &$seen, $exchange ) {
		$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
		$seen[] = array(
			'url'  => $url,
			'body' => $body,
			'args' => $args,
		);
		if ( PAIR_TEST_HQ . '/api/v1/pair/exchange' === $url ) {
			list( $status, $reply ) = $exchange( $body );
		} elseif ( PAIR_TEST_HQ . '/api/v1/heartbeat' === $url ) {
			$status = 200;
			$reply  = array( 'ok' => true );
		} else {
			$status = 404;
			$reply  = array();
		}
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $reply ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $fake, 10, 3 );
	try {
		$fn();
	} finally {
		remove_filter( 'pre_http_request', $fake, 10 );
	}
	return $seen;
}

/**
 * Starts pairing and returns [state, pending request, query args].
 */
function pair_test_start(): array {
	$started = Pairing::start( PAIR_TEST_HQ . '/' );
	wproadie_assert( is_array( $started ), is_wp_error( $started ) ? $started->get_error_message() : 'start failed' );
	$query = array();
	wp_parse_str( (string) wp_parse_url( $started['redirect'], PHP_URL_QUERY ), $query );
	$pending = Pairing::pending( $query['state'] );
	return array( $query['state'], $pending, $query, $started['redirect'] );
}

/**
 * Creates an administrator without WPRoadie access and makes them current.
 *
 * @return int User ID.
 */
function pair_test_admin(): int {
	$login   = 'pair_' . wp_generate_password( 6, false );
	$user_id = wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
	( new WP_User( $user_id ) )->set_role( 'administrator' );
	return $user_id;
}

/**
 * Runs $fn as another user, then switches back and deletes that user.
 *
 * @param int      $user_id User.
 * @param callable $fn      Test body.
 */
function pair_test_as( int $user_id, callable $fn ): void {
	$previous = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		$fn();
	} finally {
		wp_set_current_user( $previous );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}

function pair_test_reset(): void {
	Connection::disconnect();
	Heartbeat::unschedule();
	delete_option( Heartbeat::LAST_RESULT );
}

/**
 * A REST request as the current user.
 *
 * @param string $method HTTP method.
 * @param string $route  Route under wproadie/v1.
 * @param array  $params JSON body.
 */
function pair_test_rest( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/wproadie/v1' . $route );
	if ( $params ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
	}
	return rest_do_request( $request );
}

wproadie_test(
	'setup: not connected to HQ',
	function () {
		pair_test_reset();
		wproadie_assert( ! Connection::is_connected() );
	}
);

wproadie_test(
	'pair start sends the browser to HQ with every parameter and a matching challenge',
	function () {
		list( $state, $pending, $query, $redirect ) = pair_test_start();

		wproadie_assert_same( 0, strpos( $redirect, PAIR_TEST_HQ . '/connect?v=1&' ), $redirect );
		wproadie_assert( (bool) preg_match( '/^[0-9a-f]{32}$/', $state ), 'state is 32 lowercase hex' );
		wproadie_assert_same( '1', $query['v'] );
		wproadie_assert_same( home_url(), $query['site_url'] );
		wproadie_assert_same( get_bloginfo( 'name' ), $query['site_name'] );
		wproadie_assert_same( admin_url( 'tools.php?page=wproadie' ), $query['return_url'] );
		wproadie_assert_same( wp_get_current_user()->user_login, $query['wp_user'] );
		wproadie_assert_same( wp_get_environment_type(), $query['environment'] );

		wproadie_assert( is_array( $pending ), 'pending request stored' );
		wproadie_assert_same( PAIR_TEST_HQ, $pending['hq_url'] );
		wproadie_assert_same( get_current_user_id(), $pending['user_id'] );
		wproadie_assert( (bool) preg_match( '/^[A-Za-z0-9_-]{43}$/', $pending['verifier'] ), 'verifier is 43 base64url characters' );
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $pending['verifier'], true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		wproadie_assert_same( $expected, $query['challenge'] );
		wproadie_assert( false === strpos( $redirect, $pending['verifier'] ), 'verifier never leaves the site' );

		// Spaces and the like are encoded, never left raw in the URL.
		wproadie_assert( false === strpos( $redirect, ' ' ) );
		wproadie_assert( false !== strpos( $redirect, 'return_url=' . rawurlencode( admin_url( 'tools.php?page=wproadie' ) ) ) );

		// The address is remembered (not autoloaded) and suggested next time.
		wproadie_assert_same( PAIR_TEST_HQ, get_option( Pairing::LAST_URL_OPTION ) );
		wproadie_assert_same( PAIR_TEST_HQ, Pairing::default_url() );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Pairing::LAST_URL_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wproadie_assert( in_array( $autoload, array( 'no', 'off' ), true ), 'not autoloaded: ' . $autoload );
		$filter = static function () {
			return 'https://hq.from-filter.test';
		};
		add_filter( 'wproadie_hq_default_url', $filter );
		wproadie_assert_same( 'https://hq.from-filter.test', Pairing::default_url() );
		remove_filter( 'wproadie_hq_default_url', $filter );

		Pairing::forget( $state );
	}
);

wproadie_test(
	'pair start checks the HQ address',
	function () {
		wproadie_assert_error( Pairing::start( 'not a url at all' ), 'wproadie_hq_invalid' );
		if ( ! Connection::allow_http() ) {
			wproadie_assert_error( Pairing::start( 'http://hq.pair.example.test' ), 'wproadie_hq_invalid' );
		}
		add_filter( 'wproadie_hq_allow_http', '__return_true' );
		$http = Pairing::start( 'http://hq.pair.example.test' );
		remove_filter( 'wproadie_hq_allow_http', '__return_true' );
		wproadie_assert( is_array( $http ) && 0 === strpos( $http['redirect'], 'http://hq.pair.example.test/connect?' ) );

		$bare = Pairing::start( 'hq.pair.example.test/?x=1#y' );
		wproadie_assert( is_array( $bare ) && 0 === strpos( $bare['redirect'], PAIR_TEST_HQ . '/connect?' ), 'https:// is added, query dropped' );
		update_option( Pairing::LAST_URL_OPTION, PAIR_TEST_HQ, false );
	}
);

wproadie_test(
	'pair finish stores the connection, sends a heartbeat, grants access and logs no secrets',
	function () {
		pair_test_as(
			pair_test_admin(),
			function () {
				wproadie_assert( ! Permission::can_connect() );
				list( $state, $pending ) = pair_test_start();
				$code                    = Connection::base64url_encode( random_bytes( 32 ) );

				$result = null;
				$seen   = pair_test_with_fake_hq(
					static function ( $body ) use ( $code, $pending ) {
						$ok = $code === $body['code'] && $pending['verifier'] === $body['code_verifier'];
						return $ok ? array(
							200,
							array(
								'connection_string' => pair_test_string(),
								'hq_email'          => 'Approver@RhythmCo.example',
							),
						) : array( 400, array( 'error' => 'invalid_code' ) );
					},
					static function () use ( &$result, $code, $state ) {
						$result = Pairing::finish( $code, $state );
					}
				);

				// The approving HQ admin's email is linked to this user (their
				// WordPress email differs), so HQ can issue them passwords.
				wproadie_assert_same( 'approver@rhythmco.example', HqIdentity::linked_email( get_current_user_id() ) );
				HqIdentity::link( get_current_user_id(), '' );

				wproadie_assert( is_array( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
				wproadie_assert_same(
					array(
						'ok'        => true,
						'connected' => true,
						'granted'   => true,
					),
					$result
				);

				$exchange = $seen[0];
				wproadie_assert_same( PAIR_TEST_HQ . '/api/v1/pair/exchange', $exchange['url'] );
				wproadie_assert_same( home_url(), $exchange['body']['site_url'] );
				wproadie_assert_same( 0, $exchange['args']['redirection'] );
				wproadie_assert( ! isset( $exchange['args']['sslverify'] ) || true === $exchange['args']['sslverify'] );
				wproadie_assert( in_array( PAIR_TEST_HQ . '/api/v1/heartbeat', array_column( $seen, 'url' ), true ), 'heartbeat sent' );

				$config = Connection::get();
				wproadie_assert_same( PAIR_TEST_SITE, $config['site_id'] );
				wproadie_assert_same( PAIR_TEST_HQ, $config['url'] );
				wproadie_assert( Permission::can_connect(), 'access granted' );
				wproadie_assert_same( true, Heartbeat::last_result()['ok'] );

				// The state is spent.
				wproadie_assert_same( null, Pairing::pending( $state ) );
				wproadie_assert_error( Pairing::finish( $code, $state ), 'wproadie_pair_expired' );

				$rows = AuditLog::query( array( 'ability' => 'hq/pair' ), 5 )['rows'];
				wproadie_assert( count( $rows ) >= 2 );
				$ok_row = null;
				foreach ( $rows as $row ) {
					foreach ( array( $code, $pending['verifier'], PAIR_TEST_SECRET ) as $secret ) {
						wproadie_assert( false === strpos( $row->input . $row->result, $secret ), 'secrets are not logged' );
					}
					if ( 'success' === $row->status ) {
						$ok_row = $row;
					}
				}
				wproadie_assert( $ok_row && get_current_user_id() === (int) $ok_row->user_id );
				wproadie_assert( false !== strpos( (string) $ok_row->result, '"granted":true' ) );
			}
		);
		pair_test_reset();
	}
);

wproadie_test(
	'pair finish refuses a wrong, foreign or reused state',
	function () {
		$code = Connection::base64url_encode( random_bytes( 32 ) );
		wproadie_assert_error( Pairing::finish( $code, str_repeat( 'a', 32 ) ), 'wproadie_pair_expired' );
		wproadie_assert_error( Pairing::finish( $code, 'not-a-state' ), 'wproadie_pair_expired' );

		// Started by this admin, finished by another one: refused, and the
		// request stays for the admin who started it.
		list( $state ) = pair_test_start();
		pair_test_as(
			pair_test_admin(),
			function () use ( $code, $state ) {
				wproadie_assert_error( Pairing::finish( $code, $state ), 'wproadie_pair_expired' );
			}
		);
		wproadie_assert( is_array( Pairing::pending( $state ) ), 'still pending for its owner' );

		// HQ says the code is no good: friendly error, state spent anyway.
		$result = null;
		pair_test_with_fake_hq(
			static function () {
				return array( 400, array( 'error' => 'invalid_code' ) );
			},
			static function () use ( &$result, $code, $state ) {
				$result = Pairing::finish( $code, $state );
			}
		);
		wproadie_assert_error( $result, 'wproadie_pair_invalid_code' );
		wproadie_assert_error( Pairing::finish( $code, $state ), 'wproadie_pair_expired' );
		wproadie_assert( ! Connection::is_connected() );
	}
);

wproadie_test(
	'pair finish refuses a connection for a different HQ address',
	function () {
		list( $state ) = pair_test_start();
		$result        = null;
		pair_test_with_fake_hq(
			static function () {
				return array( 200, array( 'connection_string' => pair_test_string( 'https://evil.example.test' ) ) );
			},
			static function () use ( &$result, $state ) {
				$result = Pairing::finish( Connection::base64url_encode( random_bytes( 32 ) ), $state );
			}
		);
		wproadie_assert_error( $result, 'wproadie_pair_mismatch' );
		wproadie_assert( ! Connection::is_connected() );

		// Same host on another port is another origin too.
		list( $state ) = pair_test_start();
		pair_test_with_fake_hq(
			static function () {
				return array( 200, array( 'connection_string' => pair_test_string( PAIR_TEST_HQ . ':8443' ) ) );
			},
			static function () use ( &$result, $state ) {
				$result = Pairing::finish( Connection::base64url_encode( random_bytes( 32 ) ), $state );
			}
		);
		wproadie_assert_error( $result, 'wproadie_pair_mismatch' );
		wproadie_assert( ! Connection::is_connected() );
	}
);

wproadie_test(
	'pairing is refused with the kill switch on',
	function () {
		list( $state ) = pair_test_start();
		Settings::set( 'disabled', true );
		try {
			wproadie_assert_error( Pairing::start( PAIR_TEST_HQ ), 'wproadie_disabled' );
			wproadie_assert_error( Pairing::finish( Connection::base64url_encode( random_bytes( 32 ) ), $state ), 'wproadie_disabled' );
		} finally {
			Settings::set( 'disabled', false );
			Pairing::forget( $state );
		}
	}
);

wproadie_test(
	'REST: pair routes, and who may use the setup steps',
	function () {
		$started = pair_test_rest( 'POST', '/hq/pair', array( 'hq_url' => PAIR_TEST_HQ ) );
		wproadie_assert_same( 200, $started->get_status(), (string) wp_json_encode( $started->get_data() ) );
		wproadie_assert( 0 === strpos( $started->get_data()['redirect'], PAIR_TEST_HQ . '/connect?' ) );

		$finished = pair_test_rest(
			'POST',
			'/hq/pair/finish',
			array(
				'code'  => Connection::base64url_encode( random_bytes( 32 ) ),
				'state' => str_repeat( 'b', 32 ),
			)
		);
		wproadie_assert_same( 400, $finished->get_status() );
		wproadie_assert_same( 'wproadie_pair_expired', $finished->get_data()['code'] );

		$setup = pair_test_rest( 'GET', '/setup' )->get_data();
		wproadie_assert_same( true, $setup['access']['has_access'] );
		wproadie_assert_same( PAIR_TEST_HQ, $setup['hq']['default_url'] );
		wproadie_assert( isset( $setup['adapter']['action'] ) );

		// An administrator without access, stealth off: may set up, and
		// connect while the site is not connected yet.
		pair_test_as(
			pair_test_admin(),
			function () {
				wproadie_assert_same( 200, pair_test_rest( 'GET', '/setup' )->get_status() );
				wproadie_assert_same( 403, pair_test_rest( 'GET', '/status' )->get_status(), 'full screen needs access' );
				wproadie_assert_same( 200, pair_test_rest( 'POST', '/hq/pair', array( 'hq_url' => PAIR_TEST_HQ ) )->get_status() );

				Connection::connect( pair_test_string() );
				try {
					wproadie_assert_same( 403, pair_test_rest( 'POST', '/hq/pair', array( 'hq_url' => PAIR_TEST_HQ ) )->get_status(), 'cannot replace a connection without access' );
					wproadie_assert_same( 403, pair_test_rest( 'POST', '/hq/connect', array( 'connection_string' => pair_test_string() ) )->get_status() );
				} finally {
					Connection::disconnect();
				}

				// Stealth on and someone has access: no setup for this admin.
				Settings::set( 'stealth', true );
				try {
					wproadie_assert( ! Permission::can_set_up() );
					wproadie_assert_same( 403, pair_test_rest( 'GET', '/setup' )->get_status() );
					wproadie_assert_same( 403, pair_test_rest( 'POST', '/access/me' )->get_status() );
				} finally {
					Settings::set( 'stealth', false );
				}

				$granted = pair_test_rest( 'POST', '/access/me' );
				wproadie_assert_same( 200, $granted->get_status() );
				wproadie_assert_same( true, $granted->get_data()['access']['has_access'] );
				wproadie_assert( Permission::can_connect() );
			}
		);

		// Editors never may.
		$login  = 'pair_editor_' . wp_generate_password( 6, false );
		$editor = wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
		( new WP_User( $editor ) )->set_role( 'editor' );
		pair_test_as(
			$editor,
			function () {
				wproadie_assert_same( 403, pair_test_rest( 'GET', '/setup' )->get_status() );
				wproadie_assert_same( 403, pair_test_rest( 'POST', '/access/me' )->get_status() );
				wproadie_assert_same( 403, pair_test_rest( 'POST', '/hq/pair', array( 'hq_url' => PAIR_TEST_HQ ) )->get_status() );
				wproadie_assert_same( 403, pair_test_rest( 'POST', '/adapter/install' )->get_status() );
			}
		);
	}
);

wproadie_test(
	'REST: connect with a connection string',
	function () {
		$result = null;
		pair_test_with_fake_hq(
			static function () {
				return array( 404, array() );
			},
			static function () use ( &$result ) {
				$result = pair_test_rest( 'POST', '/hq/connect', array( 'connection_string' => pair_test_string() ) );
			}
		);
		wproadie_assert_same( 200, $result->get_status() );
		wproadie_assert_same( true, $result->get_data()['heartbeat'] );
		wproadie_assert_same( PAIR_TEST_SITE, Connection::get()['site_id'] );
		wproadie_assert_same( 400, pair_test_rest( 'POST', '/hq/connect', array( 'connection_string' => 'nope' ) )->get_status() );
	}
);

wproadie_test(
	'teardown: disconnect and forget the HQ address',
	function () {
		pair_test_reset();
		delete_option( Pairing::LAST_URL_OPTION );
		wproadie_assert( ! Connection::is_connected() );
	}
);
