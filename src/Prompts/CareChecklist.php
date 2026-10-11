<?php
/**
 * "Care checklist" prompt: walks the AI through the monthly care report.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Prompts;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Site\HealthCheck;
use WPRoadie\Abilities\Site\ListPlugins;
use WPRoadie\Abilities\Site\SiteOverview;
use WPRoadie\Abilities\Site\Versions;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class CareChecklist extends AbilityBase {

	public function slug(): string {
		return 'care-checklist';
	}

	public function label(): string {
		return __( 'Care checklist', 'wproadie' );
	}

	public function description(): string {
		return __( 'Runs the monthly care checklist for this site and drafts the care report: updates, security, health, content and housekeeping, with the current findings filled in.', 'wproadie' );
	}

	public function mcp_type(): string {
		return 'prompt';
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'month' => array(
					'type'        => 'string',
					'description' => __( 'Optional: the month the report covers, e.g. "October 2026". Defaults to the current month.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	public function output_schema(): array {
		return array();
	}

	protected function execute( array $input ) {
		$overview = ( new SiteOverview() )->run_internal();
		$versions = ( new Versions() )->run_internal();
		$plugins  = ( new ListPlugins() )->run_internal();
		$health   = ( new HealthCheck() )->run_internal();
		$month    = ! empty( $input['month'] ) ? (string) $input['month'] : wp_date( 'F Y' );

		$updates  = array();
		$inactive = array();
		foreach ( $plugins['plugins'] as $plugin ) {
			if ( $plugin['update_available'] ) {
				$updates[] = sprintf( '%s %s → %s', $plugin['name'], $plugin['version'], $plugin['update_available'] );
			}
			if ( ! $plugin['active'] ) {
				$inactive[] = $plugin['name'];
			}
		}

		$admins  = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'user_login' ),
			)
		);
		$pending = wp_count_comments();
		$is_live = Mode::PRODUCTION === Mode::current();
		$facts   = array(
			sprintf( 'WordPress %s%s', $versions['wordpress'], $versions['core_update'] ? ' (update to ' . $versions['core_update'] . ' available)' : ' (up to date as far as WordPress knows)' ),
			sprintf( 'PHP %s', $versions['php'] ),
			$updates ? 'Plugin updates: ' . implode( '; ', $updates ) : 'No known plugin updates',
			$inactive ? 'Inactive plugins: ' . implode( ', ', $inactive ) : 'No inactive plugins',
			sprintf( 'Site Health: %d critical, %d recommended', $health['summary']['critical'], $health['summary']['recommended'] ),
			sprintf( 'Administrators: %s', implode( ', ', wp_list_pluck( $admins, 'user_login' ) ) ),
			sprintf( 'Comments awaiting moderation: %d, spam: %d', (int) $pending->moderated, (int) $pending->spam ),
			sprintf( 'Search engines %s', get_option( 'blog_public' ) ? 'allowed' : 'DISCOURAGED (blog_public is off)' ),
			sprintf( 'Debug mode: %s', $versions['debug']['wp_debug'] ? 'ON' : 'off' ),
			sprintf( 'Home URL uses %s', 0 === strpos( $overview['home_url'], 'https://' ) ? 'HTTPS' : 'HTTP (no HTTPS!)' ),
		);

		$text  = sprintf( "Prepare the %s care report for %s (%s).\n\n", $month, $overview['name'], $overview['home_url'] );
		$text .= "## What WPRoadie already found\n- " . implode( "\n- ", $facts ) . "\n\n";
		$text .= "## Checklist\nWork through each item with the WPRoadie tools. Mark it done, needs attention, or not applicable, with one line of evidence.\n\n";
		$text .= '1. **Updates.** Core, plugins and themes (list-plugins, active-theme, versions). ' . ( $is_live ? "This is a live site: list the updates and recommend a staging test; do not update here.\n" : "On staging you may apply updates (update-plugin, update-theme), one at a time, running health-check after each.\n" );
		$text .= "2. **Health.** Run health-check and summarise every critical issue and the important recommendations.\n";
		$text .= "3. **Security.** Review administrators (list-users if enabled), inactive plugins and themes to remove, debug mode on a live site, HTTPS, and search-engine visibility on a live site.\n";
		$text .= "4. **Content.** Count drafts and scheduled posts (list-posts with status draft and future); flag anything stale. Check comments awaiting moderation.\n";
		$text .= "5. **Housekeeping.** Note anything odd in recent AI activity (activity-log) and anything you changed during this check, with its undo_id.\n\n";
		$text .= "## Report format\nEnd with a short client-friendly report: a one-paragraph summary, then sections Updates, Health, Security, Content, Recommendations. Plain language, no jargon, no internal tool names.";

		return array(
			'description' => sprintf( 'Care checklist for %s, %s', $overview['name'], $month ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
