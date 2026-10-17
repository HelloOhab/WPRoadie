<?php
/**
 * Removes everything WPRoadie stored: the log table, settings, the
 * capability and the Application Passwords it created.
 *
 * @package WPRoadie
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Log/AuditLog.php';
require_once __DIR__ . '/src/Server/Permission.php';
require_once __DIR__ . '/src/Admin/Credentials.php';
require_once __DIR__ . '/src/Safety/Mode.php';
require_once __DIR__ . '/src/Safety/Settings.php';

use WPRoadie\Admin\Credentials;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\Permission;

foreach ( Permission::granted_users() as $wproadie_user ) {
	foreach ( Credentials::list_for( $wproadie_user->ID ) as $wproadie_password ) {
		WP_Application_Passwords::delete_application_password( $wproadie_user->ID, $wproadie_password['uuid'] );
	}
	Permission::revoke( $wproadie_user->ID );
}

AuditLog::drop();
delete_option( Settings::OPTION );
wp_clear_scheduled_hook( 'wproadie_prune_log' );
wp_clear_scheduled_hook( 'wproadie_hq_heartbeat' );
wp_clear_scheduled_hook( 'wproadie_hq_heartbeat_soon' );
delete_option( 'wproadie_hq' );
delete_option( 'wproadie_hq_last_heartbeat' );
delete_option( 'wproadie_hq_last_url' );
delete_option( 'wproadie_hq_pairs' );
delete_option( 'wproadie_updates_seen' );
delete_metadata( 'user', 0, 'wproadie_hq_email', '', true );
delete_transient( 'wproadie_hq_health' );
delete_site_transient( 'wproadie_update_release' );

// wp-content/wproadie is left in place: it may hold files the team wrote
// there on purpose, and file backups someone may still need.
