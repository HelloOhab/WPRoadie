<?php
/**
 * Creates and revokes the Application Passwords AI clients connect with.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WP_Application_Passwords;
use WP_Error;
use WPRoadie\Server\McpServer;

defined( 'ABSPATH' ) || exit;

final class Credentials {

	/**
	 * Application Passwords created by WPRoadie are named with this prefix,
	 * so the admin screen lists and revokes only its own.
	 */
	const NAME_PREFIX = 'WPRoadie';

	public static function available( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && wp_is_application_passwords_available_for_user( $user );
	}

	/**
	 * Why Application Passwords are unavailable, for display.
	 */
	public static function unavailable_reason(): string {
		if ( ! wp_is_application_passwords_supported() ) {
			return __( 'Application Passwords need HTTPS. Serve the site over HTTPS (or set the environment type to "local" for local development).', 'wproadie' );
		}
		return __( 'Application Passwords are turned off on this site, for example by a security plugin.', 'wproadie' );
	}

	/**
	 * @return array{password: string, uuid: string}|WP_Error
	 */
	public static function create( int $user_id, string $label = '' ) {
		$name = self::NAME_PREFIX . ' – ' . ( '' !== $label ? $label : wp_date( 'Y-m-d H:i' ) );

		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $name ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		list( $password, $item ) = $created;
		return array(
			'password' => WP_Application_Passwords::chunk_password( $password ),
			'uuid'     => $item['uuid'],
		);
	}

	/**
	 * WPRoadie's Application Passwords for a user.
	 *
	 * @return array[]
	 */
	public static function list_for( int $user_id ): array {
		$items = WP_Application_Passwords::get_user_application_passwords( $user_id );
		return array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 0 === strpos( (string) $item['name'], self::NAME_PREFIX );
				}
			)
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function revoke( int $user_id, string $uuid ) {
		foreach ( self::list_for( $user_id ) as $item ) {
			if ( $item['uuid'] === $uuid ) {
				return WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			}
		}
		return new WP_Error( 'wproadie_unknown_password', __( 'That WPRoadie password was not found.', 'wproadie' ) );
	}

	/**
	 * A short, config-safe name for this site's MCP server entry.
	 */
	public static function server_name(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = sanitize_key( str_replace( '.', '-', (string) $host ) );
		return 'wproadie-' . ( '' !== $name ? $name : 'site' );
	}

	/**
	 * Claude Desktop (and Cursor) config, using the official remote proxy
	 * that turns STDIO into HTTP calls with an Application Password.
	 */
	public static function desktop_config( string $username, string $password ): string {
		$config = array(
			'mcpServers' => array(
				self::server_name() => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => array(
						'WP_API_URL'      => McpServer::endpoint_url(),
						'WP_API_USERNAME' => $username,
						'WP_API_PASSWORD' => $password,
					),
				),
			),
		);
		return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Claude Code connects over HTTP directly; no proxy needed.
	 */
	public static function claude_code_command( string $username, string $password ): string {
		$token = base64_encode( $username . ':' . str_replace( ' ', '', $password ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
		return sprintf(
			'claude mcp add --transport http %s %s --header "Authorization: Basic %s"',
			self::server_name(),
			McpServer::endpoint_url(),
			$token
		);
	}
}
