<?php
/**
 * Site overview: the first thing an AI should read before working on a site.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class SiteOverview extends AbilityBase {

	public function slug(): string {
		return 'site-overview';
	}

	public function label(): string {
		return __( 'Site overview', 'wproadie' );
	}

	public function description(): string {
		return __( 'Summarises the site before you start work: name, URLs, language, timezone, WPRoadie mode, active theme, content counts and post types. Call this first on any new site.', 'wproadie' );
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	protected function execute( array $input ) {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$counts       = wp_count_posts( $type->name );
			$post_types[] = array(
				'name'      => $type->name,
				'label'     => $type->label,
				'published' => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'drafts'    => isset( $counts->draft ) ? (int) $counts->draft : 0,
			);
		}

		$theme = wp_get_theme();

		return array(
			'name'             => get_bloginfo( 'name' ),
			'tagline'          => get_bloginfo( 'description' ),
			'home_url'         => home_url(),
			'site_url'         => site_url(),
			'admin_email'      => get_option( 'admin_email' ),
			'language'         => get_locale(),
			'timezone'         => wp_timezone_string(),
			'environment_type' => wp_get_environment_type(),
			'wproadie_mode'    => Mode::current(),
			'is_multisite'     => is_multisite(),
			'permalinks'       => (string) get_option( 'permalink_structure' ),
			'front_page'       => array(
				'shows'          => get_option( 'show_on_front' ),
				'page_on_front'  => (int) get_option( 'page_on_front' ),
				'page_for_posts' => (int) get_option( 'page_for_posts' ),
			),
			'theme'            => array(
				'name'       => $theme->get( 'Name' ),
				'stylesheet' => $theme->get_stylesheet(),
				'is_block'   => wp_is_block_theme(),
			),
			'post_types'       => $post_types,
			'users'            => (int) count_users()['total_users'],
			'active_plugins'   => count( (array) get_option( 'active_plugins', array() ) ),
		);
	}
}
