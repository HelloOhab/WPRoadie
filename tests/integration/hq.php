<?php
/**
 * HQ connection, signing, commands and heartbeats (docs/hq-protocol.md).
 *
 * @package WPRoadie
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test file.

use WPRoadie\Admin\Credentials;
use WPRoadie\Hq\Connection;
use WPRoadie\Hq\Heartbeat;
use WPRoadie\Hq\HqIdentity;
use WPRoadie\Hq\Signer;
use WPRoadie\Log\AuditLog;
use WPRoadie\Plugin;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\PendingUpdates;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\Permission;

const HQ_TEST_SITE   = '6f1c2a3e-1b2c-4d5e-8f90-123456789abc';
const HQ_TEST_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

/**
 * A connection string for the test HQ.
 *
 * @param array $overrides Fields to replace.
 */
function hq_test_string( array $overrides = array() ): string {
	$data = array_merge(
		array(
			'url'     => 'https://hq.example.test',
			'site_id' => HQ_TEST_SITE,
			'secret'  => HQ_TEST_SECRET,
		),
		$overrides
	);
	return Connection::STRING_PREFIX . rtrim( strtr( base64_encode( (string) wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Sends a command to the site's HQ endpoint as HQ would.
 *
 * @param array $body      Request body.
 * @param array $overrides Header overrides (timestamp, nonce, signature, site).
 * @return WP_REST_Response
 */
function hq_test_command( array $body, array $overrides = array() ) {
	$raw       = (string) wp_json_encode( $body );
	$key       = Connection::key();
	$timestamp = $overrides['timestamp'] ?? (string) time();
	$nonce     = $overrides['nonce'] ?? bin2hex( random_bytes( 16 ) );
	$site      = $overrides['site'] ?? HQ_TEST_SITE;
	$signature = $overrides['signature'] ?? Signer::sign( $key, 'command', HQ_TEST_SITE, $timestamp, $nonce, $raw );

	$request = new WP_REST_Request( 'POST', '/wproadie/v1/hq' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( Signer::HEADER_SITE, $site );
	$request->set_header( Signer::HEADER_TIMESTAMP, $timestamp );
	$request->set_header( Signer::HEADER_NONCE, $nonce );
	$request->set_header( Signer::HEADER_SIGNATURE, $signature );
	$request->set_body( $raw );

	// Commands arrive with no WordPress login.
	$previous = get_current_user_id();
	wp_set_current_user( 0 );
	try {
		return rest_do_request( $request );
	} finally {
		wp_set_current_user( $previous );
	}
}

/**
 * The site's real MCP tools/list, from `wp mcp-adapter serve` over STDIO in
 * a fresh process, as tests/smoke.sh does. A fresh process matters: an MCP
 * server created earlier in this one keeps the tool set it started with.
 *
 * @return array[] Tools as decoded JSON objects (stdClass kept).
 * @throws RuntimeException When the server gives no tools/list answer.
 */
function hq_test_mcp_tools_list(): array {
	// The WP-CLI running these tests: a phar (CI) or a launcher script.
	$launcher = (string) realpath( $GLOBALS['argv'][0] );
	$head     = (string) file_get_contents( $launcher, false, null, 0, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$script   = 0 === strpos( $head, '#!' ) && false === strpos( $head, 'php' );
	$command  = $script ? array( $launcher ) : array( PHP_BINARY, $launcher );
	$command  = array_merge(
		$command,
		array( '--path=' . untrailingslashit( ABSPATH ), '--allow-root', 'mcp-adapter', 'serve', '--server=wproadie', '--user=' . wp_get_current_user()->user_login )
	);

	$input = implode(
		"\n",
		array(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 0,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => new stdClass(),
						'clientInfo'      => array(
							'name'    => 'wproadie-tests',
							'version' => '1',
						),
					),
				)
			),
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => new stdClass(),
				)
			),
		)
	) . "\n";

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.WP.AlternativeFunctions -- Test helper; array command, no shell.
	$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	wproadie_assert( is_resource( $process ), 'could not start wp mcp-adapter serve: ' . wp_json_encode( $command ) );
	fwrite( $pipes[0], $input );
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $process );
	// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.WP.AlternativeFunctions

	foreach ( explode( "\n", $stdout ) as $line ) {
		$message = json_decode( trim( $line ) );
		if ( isset( $message->id, $message->result->tools ) && 1 === $message->id ) {
			return $message->result->tools;
		}
	}
	throw new RuntimeException( 'No tools/list response. stdout: ' . substr( $stdout, 0, 300 ) . ' stderr: ' . substr( $stderr, 0, 300 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text test output.
}

/**
 * JSON for comparing values, with JSON objects kept as objects.
 *
 * @param mixed $value Value.
 */
function hq_test_json( $value ): string {
	return (string) wp_json_encode( json_decode( (string) wp_json_encode( $value ) ) );
}

wproadie_test(
	'signing reproduces the protocol test vector',
	function () {
		$key = base64_decode( strtr( HQ_TEST_SECRET, '-_', '+/' ) . '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		wproadie_assert_same(
			'v1=1d0d93429950e4120cf5d9edca5275746aa674cb4cd8ea520aae73cbfd901bdf',
			Signer::sign( $key, 'command', HQ_TEST_SITE, '1760263200', '0123456789abcdef0123456789abcdef', '{"command":"ping","args":{}}' )
		);
	}
);

wproadie_test(
	'connection strings are validated',
	function () {
		wproadie_assert( is_array( Connection::parse( hq_test_string() ) ) );
		wproadie_assert_error( Connection::parse( 'nonsense' ), 'wproadie_hq_invalid' );
		wproadie_assert_error( Connection::parse( Connection::STRING_PREFIX . '!!!' ), 'wproadie_hq_invalid' );
		wproadie_assert_error( Connection::parse( hq_test_string( array( 'url' => 'http://hq.example.test' ) ) ), 'wproadie_hq_invalid' );
		wproadie_assert_error( Connection::parse( hq_test_string( array( 'site_id' => 'not-a-uuid' ) ) ), 'wproadie_hq_invalid' );
		wproadie_assert_error( Connection::parse( hq_test_string( array( 'secret' => 'c2hvcnQ' ) ) ), 'wproadie_hq_invalid' );
	}
);

wproadie_test(
	'setup: connect to a test HQ',
	function () {
		$connected = Connection::connect( hq_test_string() );
		wproadie_assert( is_array( $connected ), is_wp_error( $connected ) ? $connected->get_error_message() : '' );
		wproadie_assert( Connection::is_connected() );
	}
);

wproadie_test(
	'a copy of the site (new address, same database) stays silent until a person decides',
	function () {
		$sent   = 0;
		$count  = function ( $pre ) use ( &$sent ) {
			$sent++;
			return new WP_Error( 'offline', 'no network in this test' );
		};
		$copied = static function ( $url ) {
			return preg_replace( '#^https?://[^/]+#', 'https://stg-copy.example.test', $url );
		};
		wproadie_assert_same( null, Connection::moved(), 'the connection was made here' );
		add_filter( 'pre_http_request', $count, 10, 3 );
		add_filter( 'home_url', $copied );
		try {
			$moved = Connection::moved();
			wproadie_assert( is_array( $moved ) && 0 === strpos( $moved['to'], 'stg-copy.example.test' ), wp_json_encode( $moved ) );
			wproadie_assert( Connection::is_connected(), 'still connected, just on hold' );

			wproadie_assert_error( Heartbeat::send(), 'wproadie_hq_site_moved' );
			Heartbeat::goodbye( 'deactivated' );
			wproadie_assert_same( 0, $sent, 'a copy must not report or say goodbye as the original site' );
			wproadie_assert_same( false, Heartbeat::last_result()['ok'] );

			// "The site moved here": the connection continues from the new address.
			$request = new WP_REST_Request( 'POST', '/wproadie/v1/hq/moved' );
			$request->set_param( 'keep', true );
			$response = rest_do_request( $request );
			wproadie_assert_same( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			wproadie_assert_same( null, Connection::moved() );
			wproadie_assert( Connection::is_connected() );

			// Back on the original address that now looks like the copy: "this is a copy" drops it quietly.
			remove_filter( 'home_url', $copied );
			wproadie_assert( is_array( Connection::moved() ) );
			$request = new WP_REST_Request( 'POST', '/wproadie/v1/hq/moved' );
			$request->set_param( 'keep', false );
			wproadie_assert_same( 200, rest_do_request( $request )->get_status() );
			wproadie_assert( ! Connection::is_connected(), 'the copy forgets the connection' );
			wproadie_assert_same( 0, $sent, 'and tells HQ nothing' );
		} finally {
			remove_filter( 'home_url', $copied );
			remove_filter( 'pre_http_request', $count, 10 );
			// The rest of this group needs the connection.
			Connection::connect( hq_test_string() );
		}
		wproadie_assert_same( null, Connection::moved() );
	}
);

wproadie_test(
	'ping answers a correctly signed command',
	function () {
		$response = hq_test_command( array( 'command' => 'ping' ) );
		wproadie_assert_same( 200, $response->get_status() );
		wproadie_assert_same( WPROADIE_VERSION, $response->get_data()['result']['version'] );
	}
);

wproadie_test(
	'bad, stale, replayed and foreign requests are refused',
	function () {
		$bad = hq_test_command( array( 'command' => 'ping' ), array( 'signature' => 'v1=' . str_repeat( '0', 64 ) ) );
		wproadie_assert_same( 401, $bad->get_status() );
		wproadie_assert_same( 'bad_signature', $bad->get_data()['error'] );

		$stale = hq_test_command( array( 'command' => 'ping' ), array( 'timestamp' => (string) ( time() - 400 ) ) );
		wproadie_assert_same( 'stale', $stale->get_data()['error'] );

		$nonce = bin2hex( random_bytes( 16 ) );
		wproadie_assert_same( 200, hq_test_command( array( 'command' => 'ping' ), array( 'nonce' => $nonce ) )->get_status() );
		$again = hq_test_command( array( 'command' => 'ping' ), array( 'nonce' => $nonce ) );
		wproadie_assert_same( 'replayed', $again->get_data()['error'] );

		$foreign = hq_test_command( array( 'command' => 'ping' ), array( 'site' => '00000000-0000-4000-8000-000000000000' ) );
		wproadie_assert_same( 'unknown_site', $foreign->get_data()['error'] );

		// A signature for one purpose is useless for the other.
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$raw       = '{"command":"ping"}';
		$wrong     = Signer::sign( Connection::key(), 'heartbeat', HQ_TEST_SITE, $timestamp, $nonce, $raw );
		$crossed   = hq_test_command(
			array( 'command' => 'ping' ),
			array(
				'timestamp' => $timestamp,
				'nonce'     => $nonce,
				'signature' => $wrong,
			)
		);
		wproadie_assert_same( 'bad_signature', $crossed->get_data()['error'] );

		$unknown = hq_test_command( array( 'command' => 'drop_tables' ) );
		wproadie_assert_same( 'unknown_command', $unknown->get_data()['error'] );
	}
);

wproadie_test(
	'heartbeat command returns the full payload',
	function () {
		$result = hq_test_command( array( 'command' => 'heartbeat' ) )->get_data()['result'];
		wproadie_assert_same( 1, $result['schema'] );
		wproadie_assert_same( HQ_TEST_SITE, $result['site_id'] );
		foreach ( array( 'site', 'versions', 'wproadie', 'stack', 'updates', 'health', 'activity' ) as $section ) {
			wproadie_assert( array_key_exists( $section, $result ), $section . ' missing' );
		}
		wproadie_assert_same( WPROADIE_VERSION, $result['versions']['wproadie'] );
		wproadie_assert( false !== strpos( $result['site']['hq_endpoint'], 'wproadie/v1/hq' ) );
		wproadie_assert( in_array( wp_get_current_user()->user_login, $result['wproadie']['users_with_access'], true ) );

		// The stack: theme, builders and active plugins (WPRoadie itself among them).
		wproadie_assert_same( wp_get_theme()->get_stylesheet(), $result['stack']['theme']['stylesheet'] );
		wproadie_assert( array_key_exists( 'divi', $result['stack']['builders'] ) && array_key_exists( 'elementor', $result['stack']['builders'] ) );
		wproadie_assert_same( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null, $result['stack']['builders']['elementor'] );
		$files = array_column( $result['stack']['plugins'], 'file' );
		wproadie_assert( in_array( plugin_basename( WPROADIE_FILE ), $files, true ), wp_json_encode( $files ) );
		foreach ( $result['stack']['plugins'] as $plugin ) {
			wproadie_assert( is_plugin_active( $plugin['file'] ), $plugin['file'] . ' is listed but not active' );
		}
	}
);

wproadie_test(
	'updates premium plugins only announce in wp-admin stay reported until they are installed',
	function () {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file  = 'hello.php';
		$theme = wp_get_theme()->get_stylesheet();
		wproadie_assert( isset( get_plugins()[ $file ] ), 'Hello Dolly is not installed on the test site' );
		$plugin_offer = (object) array(
			'plugin'      => $file,
			'slug'        => 'hello-dolly',
			'new_version' => '99.0.0',
			'package'     => 'https://updates.example.test/hello-dolly-99.zip',
		);
		$theme_offer  = array(
			'theme'       => $theme,
			'new_version' => '99.0.0',
			'package'     => 'https://updates.example.test/theme-99.zip',
		);
		$empty        = static function () {
			return (object) array(
				'last_checked' => time(),
				'response'     => array(),
				'no_update'    => array(),
			);
		};
		$pending      = static function ( string $kind, string $id ) {
			foreach ( Heartbeat::payload()['updates'][ $kind ] as $entry ) {
				if ( in_array( $id, $entry, true ) ) {
					return $entry['new_version'];
				}
			}
			return null;
		};

		PendingUpdates::forget();
		wp_clear_scheduled_hook( Heartbeat::SOON_HOOK );
		try {
			// A wp-admin visit, with the premium updaters loaded, announces updates.
			$announced           = $empty();
			$announced->response = array( $file => $plugin_offer );
			set_site_transient( 'update_plugins', $announced );
			$announced           = $empty();
			$announced->response = array( $theme => $theme_offer );
			set_site_transient( 'update_themes', $announced );
			wproadie_assert( wp_next_scheduled( Heartbeat::SOON_HOOK ), 'a changed picture should send a heartbeat soon' );

			// Cron rebuilds the transients without them, as it does on real sites.
			set_site_transient( 'update_plugins', $empty() );
			set_site_transient( 'update_themes', $empty() );
			wproadie_assert( empty( get_site_transient( 'update_plugins' )->response ) );

			wproadie_assert_same( '99.0.0', $pending( 'plugins', $file ) );
			wproadie_assert_same( '99.0.0', $pending( 'themes', $theme ) );
			$listed = wproadie_call( 'list-plugins', array( 'status' => 'update_available' ) );
			wproadie_assert_same( array( $file ), array_column( $listed['plugins'], 'file' ) );
			wproadie_assert_same( '99.0.0', $listed['plugins'][0]['update_available'] );

			// WordPress's upgrader sees the remembered package only while WPRoadie updates.
			wproadie_assert( empty( get_site_transient( 'update_plugins' )->response[ $file ] ) );
			$seen = PendingUpdates::with_remembered(
				static function () use ( $file, $theme ) {
					return array(
						get_site_transient( 'update_plugins' )->response[ $file ]->package ?? null,
						get_site_transient( 'update_themes' )->response[ $theme ]['package'] ?? null,
					);
				}
			);
			wproadie_assert_same( array( $plugin_offer->package, $theme_offer['package'] ), $seen );
			wproadie_assert( empty( get_site_transient( 'update_plugins' )->response[ $file ] ) );

			// Once the installed version has caught up, the update is forgotten.
			$caught_up           = $empty();
			$caught_up->response = array( $file => (object) array( 'new_version' => get_plugins()[ $file ]['Version'] ) );
			set_site_transient( 'update_plugins', $caught_up );
			set_site_transient( 'update_plugins', $empty() );
			wproadie_assert_same( null, $pending( 'plugins', $file ) );
			wproadie_assert_same( '99.0.0', $pending( 'themes', $theme ) );
		} finally {
			PendingUpdates::forget();
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			wp_clear_scheduled_hook( Heartbeat::SOON_HOOK );
		}
	}
);

wproadie_test(
	'activity summary counts tool calls, not HQ commands',
	function () {
		wproadie_call( 'versions' );
		$activity = AuditLog::activity_summary();
		wproadie_assert_same( 'wproadie/versions', $activity['last_call_tool'] );
		wproadie_assert_same( wp_get_current_user()->user_login, $activity['last_call_user'] );
		wproadie_assert( $activity['calls_24h'] >= 1 );
	}
);

wproadie_test(
	'provision_credential: only for users with access, over HTTPS, never logged',
	function () {
		$login   = 'hq_test_' . wp_generate_password( 6, false );
		$user_id = wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
		( new WP_User( $user_id ) )->set_role( 'administrator' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		try {
			$no_access = hq_test_command(
				array(
					'command' => 'provision_credential',
					'args'    => array( 'wp_user' => $login ),
				)
			);
			wproadie_assert_same( 'no_access', $no_access->get_data()['error'] );

			Permission::grant( $user_id );
			$missing = hq_test_command(
				array(
					'command' => 'provision_credential',
					'args'    => array( 'wp_user' => 'nobody-here' ),
				)
			);
			wproadie_assert_same( 'user_not_found', $missing->get_data()['error'] );

			if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
				$insecure = hq_test_command(
					array(
						'command' => 'provision_credential',
						'args'    => array( 'wp_user' => $login ),
					)
				);
				wproadie_assert_same( 'insecure', $insecure->get_data()['error'] );
			}

			add_filter( 'wproadie_hq_allow_http', '__return_true' );
			$issued = hq_test_command(
				array(
					'command' => 'provision_credential',
					'args'    => array(
						'wp_user'  => $login . '@example.com',
						'label'    => 'Tester',
						'hq_email' => $login . '@example.com',
					),
				)
			);
			remove_filter( 'wproadie_hq_allow_http', '__return_true' );
			wproadie_assert_same( 200, $issued->get_status(), wp_json_encode( $issued->get_data() ) );
			$result = $issued->get_data()['result'];
			wproadie_assert_same( $login, $result['username'] );
			wproadie_assert( 24 <= strlen( str_replace( ' ', '', $result['password'] ) ) );

			$listed = Credentials::list_for( $user_id );
			wproadie_assert_same( 1, count( $listed ) );
			wproadie_assert_same( 'WPRoadie HQ – Tester', $listed[0]['name'] );

			// The password works for Basic auth and appears in no log entry.
			// WordPress only accepts Application Passwords on API requests.
			add_filter( 'application_password_is_api_request', '__return_true' );
			$authed = wp_authenticate_application_password( null, $login, $result['password'] );
			remove_filter( 'application_password_is_api_request', '__return_true' );
			wproadie_assert( $authed instanceof WP_User, 'issued password authenticates' );
			$log = AuditLog::query( array( 'ability' => 'hq/provision_credential' ), 1 )['rows'][0];
			wproadie_assert( false === strpos( (string) $log->result, str_replace( ' ', '', $result['password'] ) ) && false === strpos( (string) $log->result, $result['password'] ), 'password must not be logged' );

			$access = hq_test_command( array( 'command' => 'list_access' ) )->get_data()['result']['users'];
			$mine   = wp_list_filter( $access, array( 'login' => $login ) );
			wproadie_assert_same( 1, count( reset( $mine )['passwords'] ) );

			$revoked = hq_test_command(
				array(
					'command' => 'revoke_credential',
					'args'    => array(
						'wp_user' => $login,
						'uuid'    => $result['uuid'],
					),
				)
			);
			wproadie_assert_same( true, $revoked->get_data()['result']['revoked'] );
			wproadie_assert_same( 0, count( Credentials::list_for( $user_id ) ) );
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
	}
);

wproadie_test(
	'provision_credential: only for the HQ person whose email matches, or is linked',
	function () {
		$login   = 'hq_email_' . wp_generate_password( 6, false );
		$user_id = wp_create_user( $login, wp_generate_password(), $login . '@client.example' );
		( new WP_User( $user_id ) )->set_role( 'administrator' );
		Permission::grant( $user_id );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wproadie_hq_allow_http', '__return_true' );
		$provision = static function ( array $args ) {
			return hq_test_command(
				array(
					'command' => 'provision_credential',
					'args'    => $args + array( 'label' => 'T' ),
				)
			)->get_data();
		};
		try {
			// HQ must say who is asking.
			wproadie_assert_same( 'bad_args', $provision( array( 'wp_user' => $login ) )['error'] );
			// Someone else's HQ account typing this login gets nothing.
			$other = $provision(
				array(
					'wp_user'  => $login,
					'hq_email' => 'someone.else@rhythmco.com',
				)
			);
			wproadie_assert_same( 'email_mismatch', $other['error'] );
			wproadie_assert_same( 0, count( Credentials::list_for( $user_id ) ) );

			// The WordPress email matches, in any case.
			$own = $provision(
				array(
					'wp_user'  => $login,
					'hq_email' => strtoupper( $login ) . '@CLIENT.EXAMPLE',
				)
			);
			wproadie_assert( isset( $own['result']['password'] ), wp_json_encode( $own ) );

			// A linked HQ email matches too, and finds the user by itself.
			wproadie_assert( HqIdentity::link( $user_id, 'Abdul.Test@RhythmCo.com' ) );
			wproadie_assert_same( 'abdul.test@rhythmco.com', HqIdentity::linked_email( $user_id ) );
			$linked = $provision(
				array(
					'wp_user'  => 'abdul.test@rhythmco.com',
					'hq_email' => 'abdul.test@rhythmco.com',
				)
			);
			wproadie_assert_same( $login, $linked['result']['username'] ?? null, wp_json_encode( $linked ) );
			$access = wp_list_filter( hq_test_command( array( 'command' => 'list_access' ) )->get_data()['result']['users'], array( 'login' => $login ) );
			wproadie_assert_same( 'abdul.test@rhythmco.com', reset( $access )['hq_email'] );
			wproadie_assert( ! HqIdentity::link( $user_id, 'not an email' ) );
			wproadie_assert( HqIdentity::link( $user_id, '' ) );
			wproadie_assert_same( '', HqIdentity::linked_email( $user_id ) );

			// Sites can turn the check off.
			add_filter( 'wproadie_hq_require_email_match', '__return_false' );
			$off = $provision( array( 'wp_user' => $login ) );
			remove_filter( 'wproadie_hq_require_email_match', '__return_false' );
			wproadie_assert( isset( $off['result']['password'] ), wp_json_encode( $off ) );
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			remove_filter( 'wproadie_hq_allow_http', '__return_true' );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
	}
);

wproadie_test(
	'revoke_user removes every WPRoadie password and access; works with the kill switch on',
	function () {
		$login   = 'hq_leaver_' . wp_generate_password( 6, false );
		$user_id = wp_create_user( $login, wp_generate_password(), $login . '@example.com' );
		( new WP_User( $user_id ) )->set_role( 'administrator' );
		Permission::grant( $user_id );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		Credentials::create( $user_id, 'one' );
		Credentials::create( $user_id, 'two' );
		// Not a WPRoadie password: left alone.
		WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Other app' ) );

		Settings::set( 'disabled', true );
		try {
			$blocked = hq_test_command(
				array(
					'command' => 'provision_credential',
					'args'    => array( 'wp_user' => $login ),
				)
			);
			wproadie_assert_same( 'disabled', $blocked->get_data()['error'] );

			$result = hq_test_command(
				array(
					'command' => 'revoke_user',
					'args'    => array( 'wp_user' => $login ),
				)
			)->get_data()['result'];
			wproadie_assert_same( 2, $result['passwords_revoked'] );
			wproadie_assert( ! Permission::can_connect( $user_id ) );
			wproadie_assert_same( 1, count( WP_Application_Passwords::get_user_application_passwords( $user_id ) ) );
		} finally {
			Settings::set( 'disabled', false );
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
	}
);

wproadie_test(
	'heartbeat is signed and posted to HQ; a rejection is recorded',
	function () {
		$captured = array();
		$reply    = array(
			'code' => 200,
			'body' => '{"ok":true}',
		);
		$fake     = function ( $pre, $args, $url ) use ( &$captured, &$reply ) {
			$captured = array(
				'url'  => $url,
				'args' => $args,
			);
			return array(
				'headers'  => array(),
				'body'     => $reply['body'],
				'response' => array(
					'code'    => $reply['code'],
					'message' => '',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $fake, 10, 3 );
		try {
			wproadie_assert_same( true, Heartbeat::send() );
			wproadie_assert_same( 'https://hq.example.test/api/v1/heartbeat', $captured['url'] );

			$headers = $captured['args']['headers'];
			$body    = $captured['args']['body'];
			wproadie_assert_same( HQ_TEST_SITE, $headers[ Signer::HEADER_SITE ] );
			$expected = Signer::sign( Connection::key(), 'heartbeat', HQ_TEST_SITE, $headers[ Signer::HEADER_TIMESTAMP ], $headers[ Signer::HEADER_NONCE ], $body );
			wproadie_assert_same( $expected, $headers[ Signer::HEADER_SIGNATURE ] );
			wproadie_assert_same( HQ_TEST_SITE, json_decode( $body, true )['site_id'] );
			wproadie_assert_same( true, Heartbeat::last_result()['ok'] );

			$reply = array(
				'code' => 401,
				'body' => '{"error":"bad_signature"}',
			);
			$sent  = Heartbeat::send();
			wproadie_assert_error( $sent, 'wproadie_hq_rejected' );
			wproadie_assert( false !== strpos( $sent->get_error_message(), 'bad_signature' ) );
			wproadie_assert_same( false, Heartbeat::last_result()['ok'] );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}
	}
);

wproadie_test(
	'list_tools matches the real MCP tools/list, and the heartbeat carries its hash',
	function () {
		$response = hq_test_command( array( 'command' => 'list_tools' ) );
		wproadie_assert_same( 200, $response->get_status() );
		$result = $response->get_data()['result'];
		wproadie_assert_same( false, $result['disabled'] );
		wproadie_assert( count( $result['tools'] ) > 10, 'expected the full tool set' );
		wproadie_assert_same( hash( 'sha256', (string) wp_json_encode( $result['tools'] ) ), $result['tools_hash'] );
		wproadie_assert( (bool) preg_match( '/^[0-9a-f]{64}$/', $result['tools_hash'] ) );
		wproadie_assert_same( $result['tools_hash'], Heartbeat::payload()['wproadie']['tools_hash'] );

		$real = array();
		foreach ( hq_test_mcp_tools_list() as $tool ) {
			$real[ $tool->name ] = $tool;
		}
		$ours = array_column( $result['tools'], null, 'name' );
		wproadie_assert_same( array_keys( $real ), array_keys( $ours ), 'same tools, same order' );

		foreach ( $ours as $name => $tool ) {
			$mcp = $real[ $name ];
			wproadie_assert_same( $mcp->title ?? null, $tool['title'], "{$name} title" );
			wproadie_assert_same( $mcp->description, $tool['description'], "{$name} description" );
			$schema = $mcp->inputSchema; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP field name.
			wproadie_assert_same( hq_test_json( $schema ), hq_test_json( $tool['inputSchema'] ), "{$name} inputSchema" );
			wproadie_assert_same( hq_test_json( $mcp->annotations ?? new stdClass() ), hq_test_json( $tool['annotations'] ), "{$name} annotations" );
			wproadie_assert( in_array( $tool['risk'], Mode::RISKS, true ), "{$name} risk" );
		}

		$log = AuditLog::query( array( 'ability' => 'hq/list_tools' ), 1 )['rows'][0];
		wproadie_assert( false !== strpos( (string) $log->result, $result['tools_hash'] ) );
	}
);

wproadie_test(
	'list_tools follows the tool switches and works with the kill switch on',
	function () {
		$before = hq_test_command( array( 'command' => 'list_tools' ) )->get_data()['result'];
		Settings::set_group( 'users', false );
		try {
			$after = hq_test_command( array( 'command' => 'list_tools' ) )->get_data()['result'];
			wproadie_assert( ! in_array( 'wproadie-list-users', array_column( $after['tools'], 'name' ), true ) );
			wproadie_assert( $before['tools_hash'] !== $after['tools_hash'], 'hash changes with the tool set' );
		} finally {
			Settings::set_group( 'users', true );
		}

		Settings::set( 'disabled', true );
		try {
			$response = hq_test_command( array( 'command' => 'list_tools' ) );
			wproadie_assert_same( 200, $response->get_status() );
			$off = $response->get_data()['result'];
			wproadie_assert_same( true, $off['disabled'] );
			wproadie_assert_same( array(), $off['tools'] );
			wproadie_assert_same( hash( 'sha256', '[]' ), $off['tools_hash'] );
			wproadie_assert_same( $off['tools_hash'], Heartbeat::payload()['wproadie']['tools_hash'] );
		} finally {
			Settings::set( 'disabled', false );
		}
	}
);

wproadie_test(
	'deactivating says goodbye to HQ at once',
	function () {
		$sent = array();
		$fake = function ( $pre, $args ) use ( &$sent ) {
			$sent[] = array(
				'body'    => json_decode( $args['body'], true ),
				'timeout' => $args['timeout'],
			);
			return array(
				'headers'  => array(),
				'body'     => '{"ok":true}',
				'response' => array(
					'code'    => 200,
					'message' => '',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			Plugin::deactivate();
			wproadie_assert_same( 1, count( $sent ) );
			wproadie_assert_same( 'deactivated', $sent[0]['body']['event'] );
			wproadie_assert_same( HQ_TEST_SITE, $sent[0]['body']['site_id'] );
			wproadie_assert_same( 5, $sent[0]['timeout'] );
			wproadie_assert( ! wp_next_scheduled( Heartbeat::CRON_HOOK ) );
			wproadie_assert( Connection::is_connected() );
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
			Plugin::activate();
		}
	}
);

wproadie_test(
	'teardown: disconnect from the test HQ (saying goodbye first)',
	function () {
		$event = null;
		$fake  = function ( $pre, $args ) use ( &$event ) {
			$event = json_decode( $args['body'], true )['event'] ?? null;
			return new WP_Error( 'offline', 'HQ is unreachable' );
		};
		add_filter( 'pre_http_request', $fake, 10, 2 );
		try {
			Connection::disconnect();
		} finally {
			remove_filter( 'pre_http_request', $fake, 10 );
		}
		// An unreachable HQ does not stop the disconnect.
		wproadie_assert_same( 'disconnected', $event );
		Heartbeat::unschedule();
		delete_option( Heartbeat::LAST_RESULT );
		wproadie_assert( ! Connection::is_connected() );
		$response = hq_test_command( array( 'command' => 'ping' ), array( 'signature' => 'v1=x' ) );
		wproadie_assert_same( 'unknown_site', $response->get_data()['error'] );
	}
);
