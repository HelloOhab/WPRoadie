<?php
/**
 * Registers the WPRoadie MCP server with the MCP Adapter.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Server;

use WPRoadie\Abilities\Registry;
use WPRoadie\Plugin;

defined( 'ABSPATH' ) || exit;

final class McpServer {

	const SERVER_ID       = 'wproadie';
	const ROUTE_NAMESPACE = 'wproadie';
	const ROUTE           = 'mcp';

	/**
	 * @var Registry
	 */
	private $registry;

	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * The HTTP endpoint AI clients connect to.
	 */
	public static function endpoint_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Hooked to mcp_adapter_init.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter instance.
	 */
	public function register( $adapter ): void {
		if ( ! Plugin::adapter_available() ) {
			return;
		}

		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'WPRoadie', 'wproadie' ),
			sprintf(
				/* translators: %s: site name. */
				__( 'Read and manage the WordPress site "%s" through WPRoadie.', 'wproadie' ),
				get_bloginfo( 'name' )
			),
			WPROADIE_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			null,
			$this->registry->enabled_names(),
			array(),
			array(),
			array( Permission::class, 'check_transport' )
		);

		if ( is_wp_error( $result ) && function_exists( 'error_log' ) ) {
			error_log( 'WPRoadie: could not create MCP server: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
