<?php
/**
 * `wp wproadie hq` commands: connect this site to WPRoadie HQ.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

// WP-CLI passes ($args, $assoc_args) to every subcommand, used or not.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter

/**
 * Connects this site to WPRoadie HQ, which shows every site's status and
 * manages the team's connection passwords.
 */
final class HqCliCommand {

	/**
	 * Connects to HQ with the connection string HQ showed when the site was added.
	 *
	 * ## OPTIONS
	 *
	 * <connection-string>
	 * : The string starting with "wproadie-hq:v1:". Quote it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wproadie hq connect 'wproadie-hq:v1:eyJ1cmwiOi...'
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function connect( $args, $assoc_args ) {
		$config = Connection::connect( (string) $args[0] );
		if ( is_wp_error( $config ) ) {
			WP_CLI::error( $config->get_error_message() );
		}
		Heartbeat::ensure_scheduled();

		$sent = Heartbeat::send();
		if ( is_wp_error( $sent ) ) {
			WP_CLI::warning( 'Saved, but the first heartbeat failed: ' . $sent->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Connected to %s. HQ now shows this site.', $config['url'] ) );
	}

	/**
	 * Shows the HQ connection and the last heartbeat.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$config = Connection::get();
		if ( ! $config ) {
			WP_CLI::log( 'Not connected to HQ.' );
			return;
		}
		$last = Heartbeat::last_result();
		$next = wp_next_scheduled( Heartbeat::CRON_HOOK );

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'Field' => 'HQ',
					'Value' => $config['url'],
				),
				array(
					'Field' => 'Site ID',
					'Value' => $config['site_id'],
				),
				array(
					'Field' => 'Configured by',
					'Value' => Connection::from_constants() ? 'wp-config.php constants' : 'wp wproadie hq connect',
				),
				array(
					'Field' => 'Last heartbeat',
					'Value' => $last ? gmdate( 'Y-m-d H:i:s', $last['at'] ) . ' UTC, ' . ( $last['ok'] ? 'accepted' : 'FAILED: ' . $last['error'] ) : 'never',
				),
				array(
					'Field' => 'Next heartbeat',
					'Value' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled',
				),
			),
			array( 'Field', 'Value' )
		);
	}

	/**
	 * Sends a heartbeat to HQ now.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function heartbeat( $args, $assoc_args ) {
		$sent = Heartbeat::send();
		if ( is_wp_error( $sent ) ) {
			WP_CLI::error( $sent->get_error_message() );
		}
		WP_CLI::success( 'Heartbeat accepted by HQ.' );
	}

	/**
	 * Disconnects from HQ. HQ keeps the site until an admin removes it.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function disconnect( $args, $assoc_args ) {
		if ( Connection::from_constants() ) {
			WP_CLI::error( 'HQ is configured with constants in wp-config.php; remove them there.' );
		}
		Connection::disconnect();
		Heartbeat::unschedule();
		WP_CLI::success( 'Disconnected from HQ.' );
	}
}
