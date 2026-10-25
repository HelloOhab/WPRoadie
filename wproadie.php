<?php
/**
 * Plugin Name:       WPRoadie
 * Plugin URI:        https://github.com/HelloOhab/WPRoadie
 * Description:       Lets Claude, Cursor or any MCP client safely read and change this site through WordPress abilities, with modes, guardrails and an audit log.
 * Version:           0.16.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Rhythm Collective
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wproadie
 * Domain Path:       /languages
 *
 * @package WPRoadie
 */

defined( 'ABSPATH' ) || exit;

define( 'WPROADIE_VERSION', '0.16.1' );
define( 'WPROADIE_FILE', __FILE__ );
define( 'WPROADIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPROADIE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum MCP Adapter version WPRoadie is tested against. The adapter is
 * pre-1.0 and ships breaking changes, so we pin and test each upgrade.
 */
define( 'WPROADIE_MCP_ADAPTER_MIN', '0.7.1' );

// PSR-4 style autoloader for the WPRoadie\ namespace, so the plugin runs
// without a vendor/ directory. Composer is only used for dev tooling.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'WPRoadie\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = WPROADIE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'WPRoadie\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPRoadie\\Plugin', 'deactivate' ) );

WPRoadie\Plugin::instance()->boot();
