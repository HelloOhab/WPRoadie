<?php
/**
 * Ability: seo-audit (every published item's SEO at a glance, with the
 * ones that need work first).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Seo;

use WP_Query;
use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class AuditSeo extends SeoAbility {

	const MAX_ITEMS = 500;

	public function slug(): string {
		return 'seo-audit';
	}

	public function label(): string {
		return __( 'SEO audit', 'wproadie' );
	}

	public function description(): string {
		return __( 'Goes through the published posts and pages (or one post_type) and reports each one\'s Rank Math SEO state: effective title and description with their lengths, whether they are custom or from the template, focus keywords, robots, SEO score, and the issues found (no custom title, title too long, no description or too long or short, description from template, no focus keyword, noindex). Items with issues come first. Use it to find what to fix, then seo-get and seo-update per item. Up to 500 items per call; use page for more.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type'   => array(
					'type'        => 'string',
					'maxLength'   => 20,
					'description' => __( 'Only this post type (default: every public type except attachments).', 'wproadie' ),
				),
				'only_issues' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'false lists every item, including the ones without issues.', 'wproadie' ),
				),
				'per_page'    => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_ITEMS,
					'default' => 100,
				),
				'page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'edit_posts' );
	}

	protected function execute( array $input ) {
		$types = array_values( array_diff( ContentHelper::content_types(), array( 'attachment' ) ) );
		if ( ! empty( $input['post_type'] ) ) {
			$type = (string) $input['post_type'];
			if ( ! in_array( $type, $types, true ) ) {
				return RankMath::error( 'bad_post_type', sprintf( 'Unknown or private post type "%s". Public types: %s.', $type, implode( ', ', $types ) ) );
			}
			$types = array( $type );
		}
		$per_page = (int) ( $input['per_page'] ?? 100 );
		$page     = (int) ( $input['page'] ?? 1 );
		$query    = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		$items  = array();
		$counts = array();
		foreach ( $query->posts as $post ) {
			$stored    = RankMath::read( 'post', (int) $post->ID );
			$effective = RankMath::effective_post( $post, $stored );
			$issues    = RankMath::issues( $stored, $effective, true );
			foreach ( $issues as $issue ) {
				$counts[ $issue ] = ( $counts[ $issue ] ?? 0 ) + 1;
			}
			if ( ! $issues && ( ! isset( $input['only_issues'] ) || $input['only_issues'] ) ) {
				continue;
			}
			$row = array(
				'id'          => (int) $post->ID,
				'type'        => $post->post_type,
				'title'       => $post->post_title,
				'link'        => (string) get_permalink( $post ),
				'seo_title'   => $effective['title'],
				'description' => $effective['description'],
				'lengths'     => array(
					'title'       => mb_strlen( $effective['title'] ),
					'description' => mb_strlen( $effective['description'] ),
				),
				'source'      => $effective['source'],
				'robots'      => $effective['robots'],
				'issues'      => $issues,
			);
			if ( ! empty( $stored['focus_keywords'] ) ) {
				$row['focus_keywords'] = $stored['focus_keywords'];
			}
			if ( isset( $stored['seo_score'] ) ) {
				$row['seo_score'] = $stored['seo_score'];
			}
			$items[] = $row;
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return count( $b['issues'] ) <=> count( $a['issues'] );
			}
		);
		arsort( $counts );

		return array(
			'post_types'   => $types,
			'checked'      => count( $query->posts ),
			'total'        => (int) $query->found_posts,
			'page'         => $page,
			'total_pages'  => (int) $query->max_num_pages,
			'with_issues'  => count(
				array_filter(
					$items,
					static function ( $row ) {
						return ! empty( $row['issues'] );
					}
				)
			),
			'issue_counts' => $counts ? $counts : new \stdClass(),
			'items'        => $items,
			'note'         => RankMath::loaded()
				? __( 'Effective values use Rank Math\'s own templates and variables.', 'wproadie' )
				: __( 'Rank Math\'s PHP was not loaded in this request, so template variables (%title% …) are shown unexpanded.', 'wproadie' ),
		);
	}
}
