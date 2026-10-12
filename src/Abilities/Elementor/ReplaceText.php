<?php
/**
 * Finds and replaces text in Elementor widget settings, on one page or
 * across every Elementor page.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WP_Query;
use WPRoadie\Abilities\Content\ContentHelper;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ReplaceText extends ElementorAbility {

	/**
	 * Most Elementor posts scanned in one call.
	 */
	const MAX_POSTS = 1000;

	/**
	 * Most matches listed in the result (all are counted and replaced).
	 */
	const MAX_LISTED = 200;

	public function slug(): string {
		return 'elementor-replace-text';
	}

	public function label(): string {
		return __( 'Replace text in Elementor pages', 'wproadie' );
	}

	public function description(): string {
		return __( 'Finds and replaces text inside Elementor widgets: headings, text blocks, buttons, links (e.g. tel: and mailto: URLs), list items, form labels and every other text setting. Use it for site-wide changes such as a new phone number, address, email or company name. Works on one page (post_id) or on every Elementor page, post and template the user can edit (all_pages: true). dry_run defaults to true and only reports the matches (element id, setting key, before/after text); run again with dry_run: false to save. The search is plain text (not a pattern), case-sensitive unless case_sensitive is false, and matches the stored value, so HTML entities such as &amp; count as written. IDs, global style references, dynamic tags and CSS classes are never changed. Returns changed posts with their matches, plus one undo_id that reverts every page changed by this call.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'         => array(
					'type'        => 'string',
					'minLength'   => 2,
					'maxLength'   => 1000,
					'description' => __( 'Exact text to find.', 'wproadie' ),
				),
				'replace'        => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => __( 'Replacement text (may be empty to delete the text).', 'wproadie' ),
				),
				'post_id'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Only this Elementor page, post or template.', 'wproadie' ),
				),
				'all_pages'      => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Search every Elementor page, post and template you can edit. Required when post_id is not given.', 'wproadie' ),
				),
				'case_sensitive' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Only list what would change (default). Set false to save the changes.', 'wproadie' ),
				),
			),
			'required'             => array( 'search', 'replace' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$search  = (string) $input['search'];
		$replace = (string) $input['replace'];
		$case    = ! isset( $input['case_sensitive'] ) || ! empty( $input['case_sensitive'] );
		$dry_run = ! isset( $input['dry_run'] ) || ! empty( $input['dry_run'] );
		if ( $case && $search === $replace ) {
			return $this->error( 'nothing_to_replace', __( 'search and replace are the same.', 'wproadie' ) );
		}

		if ( ! empty( $input['post_id'] ) ) {
			$post = ElementorHelper::get_page( (int) $input['post_id'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$ids = array( (int) $post->ID );
		} elseif ( ! empty( $input['all_pages'] ) ) {
			$ids = $this->all_page_ids();
		} else {
			return $this->error( 'missing_scope', __( 'Send post_id for one page, or all_pages: true to search every Elementor page.', 'wproadie' ) );
		}

		$filter  = ElementorHelper::text_filter();
		$posts   = array();
		$errors  = array();
		$total   = 0;
		$listed  = 0;
		$scanned = 0;
		$skipped = 0;
		$encoded = trim( (string) wp_json_encode( $search ), '"' );
		foreach ( $ids as $id ) {
			if ( ! ElementorHelper::can_edit( $id ) ) {
				++$skipped;
				continue;
			}
			++$scanned;
			$raw = ElementorHelper::raw_data( $id );
			if ( $case && is_string( $raw ) && false === strpos( $raw, $search ) && false === strpos( $raw, $encoded ) ) {
				continue;
			}
			$tree = ElementorHelper::read_tree( $id );
			if ( is_wp_error( $tree ) ) {
				$errors[] = array(
					'post_id' => $id,
					'error'   => $tree->get_error_message(),
				);
				continue;
			}
			list( $new_tree, $matches ) = Tree::replace_text( $tree, $search, $replace, $case, $filter );
			if ( ! $matches ) {
				continue;
			}

			$post  = get_post( $id );
			$count = array_sum( array_column( $matches, 'count' ) );
			$entry = array(
				'post_id' => $id,
				'title'   => $post->post_title,
				'type'    => $post->post_type,
				'count'   => $count,
			);
			$room  = max( 0, self::MAX_LISTED - $listed );
			if ( $room ) {
				$entry['matches'] = array_slice( $matches, 0, $room );
				$listed          += count( $entry['matches'] );
			}

			if ( ! $dry_run && $new_tree !== $tree ) {
				$saved = $this->save_tree( $post, $new_tree );
				if ( is_wp_error( $saved ) ) {
					$errors[] = array(
						'post_id' => $id,
						'error'   => $saved->get_error_message(),
					);
					continue;
				}
				$entry = array_merge( $entry, $saved );
			}
			$total  += $count;
			$posts[] = $entry;
		}

		$result = array(
			'dry_run'       => $dry_run,
			'search'        => $search,
			'replace'       => $replace,
			'posts_scanned' => $scanned,
			'posts_matched' => count( $posts ),
			'replacements'  => $total,
			'posts'         => $posts,
		);
		if ( $skipped ) {
			$result['posts_skipped'] = $skipped;
		}
		if ( $errors ) {
			$result['errors'] = $errors;
		}
		if ( $listed >= self::MAX_LISTED ) {
			$result['note'] = __( 'Only the first matches are listed; all of them are counted (and replaced unless dry_run).', 'wproadie' );
		}
		if ( $dry_run && $posts ) {
			$result['next_step'] = __( 'Nothing was changed. Call again with dry_run: false to apply these replacements.', 'wproadie' );
		}
		if ( ! $dry_run && $posts && null !== $filter ) {
			$result['kses'] = __( 'Changed values were filtered for HTML your account may not post (scripts, iframes and similar).', 'wproadie' );
		}
		return $result;
	}

	/**
	 * IDs of every Elementor-built post of the types the user can edit,
	 * templates included, kits excluded.
	 *
	 * @return int[]
	 */
	private function all_page_ids(): array {
		$query = new WP_Query(
			array(
				'post_type'           => ContentHelper::editable_types(),
				'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'      => self::MAX_POSTS,
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The only marker of an Elementor page.
				'meta_query'          => array(
					array(
						'key'   => ElementorHelper::EDIT_MODE_KEY,
						'value' => 'builder',
					),
				),
			)
		);
		return array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				static function ( $id ) {
					return ! ElementorHelper::is_kit( $id );
				}
			)
		);
	}
}
