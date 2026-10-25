<?php
/**
 * Ability: seo-update (change one item's Rank Math SEO fields).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Seo;

use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateSeo extends SeoAbility {

	public function slug(): string {
		return 'seo-update';
	}

	public function label(): string {
		return __( 'Update SEO', 'wproadie' );
	}

	public function description(): string {
		/* translators: %title%, %sitename%, %sep% and %excerpt% are Rank Math variable names, not placeholders. */
		return __( 'Sets Rank Math SEO fields on one post, page or term, stored exactly as Rank Math\'s own editor stores them: title, description (plain text; Rank Math variables such as %title%, %1$sitename%, %2$sep%, %3$excerpt% are allowed), focus_keywords (a list, first is primary, at most 5), robots (a list from index, noindex, nofollow, noarchive, noimageindex, nosnippet; follow is implied), advanced_robots, canonical_url, breadcrumb_title, pillar_content (true/false), facebook_title/description/image, twitter_title/description/image, twitter_use_facebook, twitter_card_type. Send only the fields to change; null (or an empty value) removes a field so Rank Math falls back to the site template. Warns when a title passes ~60 or a description ~160 characters. Returns the stored and effective values, plus an undo_id for undo-change. Schema (rich snippets) is read-only here.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		$fields = array();
		foreach ( RankMath::FIELDS as $field => $kind ) {
			switch ( $kind ) {
				case 'keywords':
				case 'robots':
					$fields[ $field ] = array(
						'type'  => array( 'array', 'string', 'null' ),
						'items' => array( 'type' => 'string' ),
					);
					break;
				case 'advanced_robots':
					$fields[ $field ] = array( 'type' => array( 'object', 'null' ) );
					break;
				case 'flag':
				case 'on_off':
					$fields[ $field ] = array( 'type' => array( 'boolean', 'null' ) );
					break;
				case 'image':
					$fields[ $field ] = array( 'type' => array( 'string', 'integer', 'null' ) );
					break;
				default:
					$fields[ $field ] = array( 'type' => array( 'string', 'null' ) );
			}
		}
		return array(
			'type'                 => 'object',
			'properties'           => self::target_properties() + array(
				'set' => array(
					'type'                 => 'object',
					'minProperties'        => 1,
					'properties'           => $fields,
					'additionalProperties' => false,
					'description'          => __( 'Fields to change, e.g. {"title": "Youth sports mentoring in Knoxville | NALB", "description": "…", "focus_keywords": ["youth sports mentoring"]}. null removes a field.', 'wproadie' ),
				),
			),
			'required'             => array( 'set' ),
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

		$changes  = array();
		$warnings = array();
		foreach ( (array) $input['set'] as $field => $value ) {
			$normalized = RankMath::normalize( (string) $field, $value );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$changes[ (string) $field ] = $normalized[0];
			$warnings                   = array_merge( $warnings, $normalized[1] );
		}

		$before = RankMath::read( $object_type, $id );
		foreach ( $changes as $field => $stored ) {
			foreach ( RankMath::keys_for( $field ) as $key ) {
				$this->add_undo( Undo::meta( $object_type, $id, $key ) );
			}
		}
		foreach ( $changes as $field => $stored ) {
			RankMath::write( $object_type, $id, $field, $stored );
		}
		if ( 'post' === $object_type ) {
			clean_post_cache( $id );
		} else {
			clean_term_cache( $id, $object->taxonomy );
		}

		$stored    = RankMath::read( $object_type, $id );
		$effective = 'term' === $object_type ? RankMath::effective_term( $object, $stored ) : RankMath::effective_post( $object, $stored );
		$result    = self::describe( $object_type, $object ) + array(
			'changed'   => array_keys( $changes ),
			'removed'   => array_keys( array_filter( $changes, 'is_null' ) ),
			'stored'    => $stored ? $stored : new \stdClass(),
			'effective' => $effective,
			'lengths'   => array(
				'title'       => mb_strlen( $effective['title'] ),
				'description' => mb_strlen( $effective['description'] ),
			),
			'unchanged' => $before === $stored,
		);
		if ( $warnings ) {
			$result['warnings'] = array_values( array_unique( $warnings ) );
		}
		return $result;
	}
}
