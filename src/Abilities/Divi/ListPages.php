<?php
/**
 * Lists the posts, pages and library layouts built with Divi.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListPages extends DiviAbility {

	public function slug(): string {
		return 'divi-list-pages';
	}

	public function label(): string {
		return __( 'List Divi pages', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists pages, posts and other items built with the Divi Builder: those with the builder switched on (Divi 4 shortcodes) or with Divi 5 block content, plus Divi Library layouts and Theme Builder layouts if you ask for those types. Returns id, title, type, status, format (4 = Divi 4 shortcodes, 5 = Divi 5 blocks), modules (module count), modified, link and edit_link, newest first, with total for paging. Use search to filter by title. Next step: divi-get-page with an id to see its sections and modules.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'default'     => 'any',
					'description' => __( 'Post type slug, e.g. "page", "post", "et_pb_layout" (Divi Library), "et_header_layout"/"et_body_layout"/"et_footer_layout" (Theme Builder), or "any" for pages, posts and other content types (not library or Theme Builder layouts).', 'wproadie' ),
				),
				'status'    => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private', 'future' ),
					'default'     => 'any',
					'description' => __( 'Only items with this status. "any" means every status except trash.', 'wproadie' ),
				),
				'search'    => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Only items whose title contains this text.', 'wproadie' ),
				),
				'page'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$type = (string) ( $input['post_type'] ?? 'any' );
		if ( 'any' === $type ) {
			$types = array_diff( Divi::post_types(), Divi::LAYOUT_TYPES );
		} elseif ( in_array( $type, Divi::post_types(), true ) ) {
			$types = array( $type );
		} else {
			return Divi::error(
				'wrong_type',
				/* translators: %s: comma-separated post types. */
				sprintf( __( 'Use one of: any, %s.', 'wproadie' ), implode( ', ', Divi::post_types() ) )
			);
		}
		$types = Divi::editable_types( $types );
		if ( ! $types ) {
			return Divi::error( 'forbidden', __( 'You cannot edit any of those items.', 'wproadie' ), 403 );
		}

		$status   = (string) ( $input['status'] ?? 'any' );
		$statuses = 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : array( $status );
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$search   = trim( (string) ( $input['search'] ?? '' ) );

		$found = Divi::query_ids( $types, $statuses, $search, $per_page, ( $page - 1 ) * $per_page );
		$total = $found['total'];
		$ids   = $found['ids'];

		$items  = array();
		$hidden = 0;
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				++$hidden;
				continue;
			}
			$doc     = Divi::format_of( $post ) && strlen( $post->post_content ) <= Divi::MAX_CONTENT
				? new Document( (string) $post->post_content, Divi::format_of( $post ) )
				: null;
			$items[] = Divi::brief( $post, $doc );
		}

		$result = array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
		);
		if ( $hidden ) {
			$result['hidden'] = $hidden;
		}
		return $result;
	}
}
