<?php
/**
 * "Site briefing" prompt: summarises the site before work starts.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Prompts;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Registry;
use WPRoadie\Abilities\Site\HealthCheck;
use WPRoadie\Abilities\Site\ListPlugins;
use WPRoadie\Abilities\Site\SiteOverview;
use WPRoadie\Abilities\Site\Versions;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class SiteBriefing extends AbilityBase {

	public function slug(): string {
		return 'site-briefing';
	}

	public function label(): string {
		return __( 'Site briefing', 'wproadie' );
	}

	public function description(): string {
		return __( 'Briefs you on this WordPress site before you start work: what it is, its software, pending updates, health issues, the WPRoadie mode and the tools you may use, and recent AI activity.', 'wproadie' );
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
				'task' => array(
					'type'        => 'string',
					'description' => __( 'Optional: what you are about to do on the site, so the briefing ends with that task.', 'wproadie' ),
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
		$updates  = ( new ListPlugins() )->run_internal( array( 'status' => 'update_available' ) );
		$health   = ( new HealthCheck() )->run_internal();
		$tools    = ( new Registry() )->enabled_names_of_type( 'tool' );
		$recent   = AuditLog::query( array(), 10 )['rows'];

		$lines   = array();
		$lines[] = sprintf( '# Briefing: %s (%s)', $overview['name'], $overview['home_url'] );
		if ( '' !== $overview['tagline'] ) {
			$lines[] = $overview['tagline'];
		}

		$lines[] = '';
		$lines[] = '## Safety';
		$lines[] = sprintf( '- WPRoadie mode: **%s**. %s', Mode::label( Mode::current() ), self::mode_note() );
		$lines[] = '- Every call is logged under your user. Changes return an undo_id; use undo-change to reverse one.';
		$lines[] = '- Read before you write: check the current state of anything you change, and confirm destructive steps with the person first.';

		$lines[] = '';
		$lines[] = '## Site';
		$lines[] = sprintf( '- WordPress %s, PHP %s, database %s', $versions['wordpress'], $versions['php'], $versions['database']['version'] );
		$lines[] = sprintf( '- Theme: %s (%s)', $overview['theme']['name'], $overview['theme']['is_block'] ? 'block theme' : 'classic theme' );
		$lines[] = sprintf( '- Language %s, timezone %s, environment "%s"', $overview['language'], $overview['timezone'], $overview['environment_type'] );
		$lines[] = sprintf( '- Front page: %s', 'page' === $overview['front_page']['shows'] ? 'static page #' . $overview['front_page']['page_on_front'] : 'latest posts' );
		foreach ( $overview['post_types'] as $type ) {
			$lines[] = sprintf( '- %s: %d published, %d drafts', $type['label'], $type['published'], $type['drafts'] );
		}
		$lines[] = sprintf( '- %d active plugins, %d users', $overview['active_plugins'], $overview['users'] );

		$lines[] = '';
		$lines[] = '## Needs attention';
		if ( $versions['core_update'] ) {
			$lines[] = sprintf( '- WordPress %s is available.', $versions['core_update'] );
		}
		foreach ( $updates['plugins'] as $plugin ) {
			$lines[] = sprintf( '- Plugin update: %s %s → %s', $plugin['name'], $plugin['version'], $plugin['update_available'] );
		}
		foreach ( $health['results'] as $issue ) {
			if ( 'critical' === $issue['status'] ) {
				$lines[] = sprintf( '- Critical: %s', $issue['label'] );
			}
		}
		$lines[] = sprintf( '- Site Health: %d critical, %d recommended.', $health['summary']['critical'], $health['summary']['recommended'] );

		$lines[] = '';
		$lines[] = '## Your tools';
		$lines[] = implode( ', ', $tools );

		if ( $recent ) {
			$lines[] = '';
			$lines[] = '## Recent AI activity';
			foreach ( $recent as $row ) {
				$user    = get_userdata( (int) $row->user_id );
				$lines[] = sprintf( '- %s UTC · %s · %s · %s', $row->created_at, $user ? $user->user_login : '#' . $row->user_id, $row->ability, $row->status );
			}
		}

		if ( ! empty( $input['task'] ) ) {
			$lines[] = '';
			$lines[] = '## Task';
			$lines[] = (string) $input['task'];
		}

		return array(
			'description' => sprintf( 'Briefing for %s', $overview['name'] ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => implode( "\n", $lines ),
					),
				),
			),
		);
	}

	private static function mode_note(): string {
		switch ( Mode::current() ) {
			case Mode::STAGING:
				return 'All enabled tools are available, including files, database, WP-CLI and plugin installs.';
			case Mode::PRODUCTION:
				return 'This is a live site: only reading, content and settings edits are allowed. Prefer drafts and check with the person before publishing.';
		}
		return 'Read-only: you can look but not change anything.';
	}
}
