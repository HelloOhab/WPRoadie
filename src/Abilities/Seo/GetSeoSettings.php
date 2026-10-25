<?php
/**
 * Ability: seo-get-settings (the site-wide Rank Math settings that shape
 * every page's SEO).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Seo;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetSeoSettings extends SeoAbility {

	public function slug(): string {
		return 'seo-get-settings';
	}

	public function label(): string {
		return __( 'Get SEO settings', 'wproadie' );
	}

	public function description(): string {
		return __( 'Reads the site-wide Rank Math settings that shape every page: title separator, homepage title and description, the global robots default, per post type and per taxonomy title/description templates, robots and sitemap inclusion, the knowledge graph (site or person name, logo), social profiles, and which Rank Math modules are on. Read-only; these are changed in Rank Math\'s own settings by a person.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_posts' );
	}

	protected function execute( array $input ) {
		unset( $input );
		$titles  = (array) get_option( 'rank-math-options-titles', array() );
		$sitemap = (array) get_option( 'rank-math-options-sitemap', array() );
		$general = (array) get_option( 'rank-math-options-general', array() );
		$modules = array_values( array_map( 'strval', (array) get_option( 'rank_math_modules', array() ) ) );

		$on = static function ( $value ): bool {
			return 'on' === $value || true === $value;
		};

		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$name = $type->name;
			if ( 'attachment' === $name ) {
				continue;
			}
			$post_types[ $name ] = array(
				'label'       => $type->labels->singular_name,
				'title'       => (string) ( $titles[ "pt_{$name}_title" ] ?? '%title% %sep% %sitename%' ),
				'description' => (string) ( $titles[ "pt_{$name}_description" ] ?? '%excerpt%' ),
				'robots'      => $on( $titles[ "pt_{$name}_custom_robots" ] ?? 'off' ) ? array_values( (array) ( $titles[ "pt_{$name}_robots" ] ?? array( 'index' ) ) ) : 'site default',
				'schema_type' => (string) ( $titles[ "pt_{$name}_default_rich_snippet" ] ?? '' ),
				'in_sitemap'  => $on( $sitemap[ "pt_{$name}_sitemap" ] ?? 'off' ),
			);
		}
		$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$name                = $tax->name;
			$taxonomies[ $name ] = array(
				'label'       => $tax->labels->singular_name,
				'title'       => (string) ( $titles[ "tax_{$name}_title" ] ?? '%term% %sep% %sitename%' ),
				'description' => (string) ( $titles[ "tax_{$name}_description" ] ?? '%term_description%' ),
				'robots'      => $on( $titles[ "tax_{$name}_custom_robots" ] ?? 'off' ) ? array_values( (array) ( $titles[ "tax_{$name}_robots" ] ?? array( 'index' ) ) ) : 'site default',
				'in_sitemap'  => $on( $sitemap[ "tax_{$name}_sitemap" ] ?? 'off' ),
			);
		}

		$social = array();
		foreach ( $titles as $key => $value ) {
			if ( 0 === strpos( (string) $key, 'social_url_' ) && '' !== (string) $value ) {
				$social[ substr( (string) $key, strlen( 'social_url_' ) ) ] = (string) $value;
			}
		}
		if ( ! empty( $titles['twitter_author_names'] ) ) {
			$social['twitter'] = (string) $titles['twitter_author_names'];
		}

		return array(
			'rank_math'        => array(
				'version' => RankMath::version(),
				'pro'     => RankMath::pro_version(),
				'modules' => $modules,
			),
			'separator'        => html_entity_decode( (string) ( $titles['title_separator'] ?? '-' ), ENT_QUOTES, 'UTF-8' ),
			'homepage'         => array(
				'title'       => (string) ( $titles['homepage_title'] ?? '%sitename% %page% %sep% %sitedesc%' ),
				'description' => (string) ( $titles['homepage_description'] ?? '' ),
				'robots'      => $on( $titles['homepage_custom_robots'] ?? 'off' ) ? array_values( (array) ( $titles['homepage_robots'] ?? array( 'index' ) ) ) : 'site default',
				'static_page' => 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : null,
			),
			'robots_global'    => array_values( (array) ( $titles['robots_global'] ?? array( 'index' ) ) ),
			'noindex'          => array(
				'search'           => $on( $titles['noindex_search'] ?? 'on' ),
				'empty_taxonomies' => $on( $titles['noindex_empty_taxonomies'] ?? 'on' ),
				'paginated_pages'  => $on( $titles['noindex_paginated_pages'] ?? 'off' ),
				'author_archives'  => $on( $titles['disable_author_archives'] ?? 'off' ) ? 'disabled' : ( $on( $titles['author_custom_robots'] ?? 'on' ) && in_array( 'noindex', (array) ( $titles['author_robots'] ?? array() ), true ) ),
			),
			'post_types'       => $post_types,
			'taxonomies'       => $taxonomies,
			'knowledge_graph'  => array(
				'type'    => (string) ( $titles['knowledgegraph_type'] ?? '' ),
				'name'    => (string) ( $titles['knowledgegraph_name'] ?? '' ),
				'website' => (string) ( $titles['website_name'] ?? '' ),
				'logo'    => (string) ( $titles['knowledgegraph_logo'] ?? '' ),
			),
			'social'           => $social ? $social : new \stdClass(),
			'open_graph_image' => (string) ( $titles['open_graph_image'] ?? '' ),
			'breadcrumbs'      => $on( $general['breadcrumbs'] ?? 'off' ),
			'sitemap'          => array(
				'enabled'        => in_array( 'sitemap', $modules, true ),
				'url'            => in_array( 'sitemap', $modules, true ) ? home_url( '/sitemap_index.xml' ) : null,
				'items_per_page' => (int) ( $sitemap['items_per_page'] ?? 200 ),
			),
			'variables'        => array( '%title%', '%sitename%', '%sitedesc%', '%sep%', '%excerpt%', '%page%', '%term%', '%term_description%', '%category%', '%date%', '%currentyear%' ),
		);
	}
}
