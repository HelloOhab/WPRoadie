<?php
/**
 * `wp wproadie` commands, for installing and managing WPRoadie over SSH.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WP_CLI;
use WPRoadie\Abilities\Registry;
use WPRoadie\Log\AuditLog;
use WPRoadie\Plugin;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\McpServer;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

// WP-CLI passes ($args, $assoc_args) to every subcommand, used or not.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter

/**
 * Manages WPRoadie: access, mode, kill switch and the activity log.
 */
final class WpCliCommand {

	/**
	 * Shows WPRoadie's status on this site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wproadie status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$registry = new Registry();
		$users    = array_map(
			static function ( $user ) {
				return $user->user_login;
			},
			Permission::granted_users()
		);

		$rows = array(
			array(
				'Field' => 'Version',
				'Value' => WPROADIE_VERSION,
			),
			array(
				'Field' => 'MCP Adapter',
				'Value' => Plugin::adapter_available() ? ( defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : 'active' ) : 'MISSING',
			),
			array(
				'Field' => 'Kill switch',
				'Value' => Settings::is_disabled() ? 'ON (WPRoadie is off)' : 'off',
			),
			array(
				'Field' => 'Mode',
				'Value' => Mode::current() . ' (from ' . Mode::source() . ')',
			),
			array(
				'Field' => 'Endpoint',
				'Value' => McpServer::endpoint_url(),
			),
			array(
				'Field' => 'Abilities',
				'Value' => implode( ', ', $registry->enabled_names() ),
			),
			array(
				'Field' => 'Users with access',
				'Value' => $users ? implode( ', ', $users ) : '(none)',
			),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );
	}

	/**
	 * Lets an administrator connect AI clients to this site.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email. Must be an administrator.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wproadie grant abdul
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function grant( $args, $assoc_args ) {
		$user = $this->user( $args[0] );
		if ( ! Permission::grant( $user->ID ) ) {
			WP_CLI::error( sprintf( '%s is not an administrator (needs manage_options).', $user->user_login ) );
		}
		WP_CLI::success( sprintf( '%s can now connect to WPRoadie.', $user->user_login ) );
	}

	/**
	 * Removes a user's WPRoadie access. Their Application Passwords keep
	 * working for the rest of WordPress; revoke those separately.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function revoke( $args, $assoc_args ) {
		$user = $this->user( $args[0] );
		Permission::revoke( $user->ID );
		WP_CLI::success( sprintf( '%s can no longer connect to WPRoadie.', $user->user_login ) );
	}

	/**
	 * Shows or sets the mode.
	 *
	 * ## OPTIONS
	 *
	 * [<mode>]
	 * : staging, production, read_only, or auto to follow the environment type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wproadie mode
	 *     wp wproadie mode read_only
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function mode( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::log( Mode::current() . ' (from ' . Mode::source() . ')' );
			return;
		}

		$mode = $args[0];
		if ( 'auto' === $mode ) {
			$mode = '';
		} elseif ( ! in_array( $mode, Mode::all(), true ) ) {
			WP_CLI::error( 'Mode must be one of: staging, production, read_only, auto.' );
		}

		Settings::set( 'mode', $mode );
		if ( 'constant' === Mode::source() ) {
			WP_CLI::warning( 'WPROADIE_MODE is defined in wp-config.php and overrides this setting.' );
		}
		WP_CLI::success( 'Mode is now ' . Mode::current() . '.' );
	}

	/**
	 * Turns the kill switch on: no AI client can use WPRoadie.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function disable( $args, $assoc_args ) {
		Settings::set( 'disabled', true );
		WP_CLI::success( 'WPRoadie is off. Run `wp wproadie enable` to turn it back on.' );
	}

	/**
	 * Turns the kill switch off.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function enable( $args, $assoc_args ) {
		Settings::set( 'disabled', false );
		if ( Settings::disabled_by_constant() ) {
			WP_CLI::warning( 'WPROADIE_DISABLED is set in wp-config.php, so WPRoadie stays off.' );
			return;
		}
		WP_CLI::success( 'WPRoadie is on.' );
	}

	/**
	 * Shows recent AI activity.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : How many entries to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function log( $args, $assoc_args ) {
		$rows = array_map(
			static function ( $row ) {
				return array(
					'id'      => $row->id,
					'time'    => $row->created_at,
					'user'    => AuditLog::actor( $row ),
					'ability' => $row->ability,
					'status'  => $row->status,
					'ms'      => $row->duration_ms,
					'via'     => $row->transport,
					'ip'      => $row->ip,
				);
			},
			AuditLog::recent( max( 1, (int) $assoc_args['limit'] ) )
		);

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array( 'id', 'time', 'user', 'ability', 'status', 'ms', 'via', 'ip' ) );
	}

	/**
	 * @param string $id User ID, login or email.
	 * @return \WP_User
	 */
	private function user( string $id ) {
		if ( is_numeric( $id ) ) {
			$user = get_userdata( (int) $id );
		} else {
			$user = get_user_by( 'login', $id );
			if ( ! $user ) {
				$user = get_user_by( 'email', $id );
			}
		}
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User "%s" not found.', $id ) );
		}
		return $user;
	}
}
