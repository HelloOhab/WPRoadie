<?php
/**
 * Ability: seo-get (one item's Rank Math SEO: stored and effective).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Seo;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetSeo extends SeoAbility {

	public function slug(): string {
		return 'seo-get';
	}

	public function label(): string {
		return __( 'Get SEO', 'wproadie' );
	}

	public function description(): string {
		return __( 'Reads one post, page or term\'s Rank Math SEO: what is stored (custom title, description, focus keywords, robots, canonical, social cards, pillar flag, schema types, primary term, SEO score) and what Rank Math will actually output (effective title, description and robots, each with where it comes from: custom, the site template, the excerpt). Also lists issues worth fixing (no custom title, description missing or too long, no focus keyword, noindex). Read this before seo-update.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => self::target_properties(),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$target = $this->target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		list( $object_type, $object ) = $target;
		$id                           = 'term' === $object_type ? (int) $object->term_id : (int) $object->ID;
		$stored                       = RankMath::read( $object_type, $id );
		$effective                    = 'term' === $object_type ? RankMath::effective_term( $object, $stored ) : RankMath::effective_post( $object, $stored );
		$indexable                    = 'post' === $object_type ? 'publish' === $object->post_status : true;

		return self::describe( $object_type, $object ) + array(
			'stored'    => $stored ? $stored : new \stdClass(),
			'effective' => $effective,
			'lengths'   => array(
				'title'       => mb_strlen( $effective['title'] ),
				'description' => mb_strlen( $effective['description'] ),
			),
			'issues'    => RankMath::issues( $stored, $effective, $indexable ),
			'rank_math' => array(
				'version'         => RankMath::version(),
				'pro'             => RankMath::pro_version(),
				'variables_known' => RankMath::loaded(),
			),
		);
	}
}
