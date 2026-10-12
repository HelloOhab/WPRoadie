<?php
/**
 * Finds and replaces text inside Divi modules, on one page or across all
 * Divi pages, with a dry run first.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ReplaceText extends DiviAbility {

	/**
	 * Divi 4 attributes that hold visible text.
	 */
	const TEXT_ATTRS_4 = array( 'title', 'heading', 'subhead', 'name', 'author', 'position', 'job_title', 'company_name', 'button_text', 'button_one_text', 'button_two_text', 'alt', 'title_text', 'caption', 'description', 'currency', 'sum', 'per', 'subtitle', 'meta', 'submit_button_text', 'field_title', 'success_message', 'footer_content', 'label', 'placeholder', 'link_text', 'more_text', 'message' );

	/**
	 * Divi 5 innerContent keys that are links or settings, not text.
	 */
	const NON_TEXT_KEYS_5 = array( 'src', 'url', 'linkUrl', 'linkTarget', 'target', 'id', 'class', 'rel', 'icon', 'unicode', 'type', 'weight', 'useIcon', 'srcset', 'sizes' );

	const MAX_SAMPLES = 5;

	public function slug(): string {
		return 'divi-replace-text';
	}

	public function label(): string {
		return __( 'Replace text in Divi pages', 'wproadie' );
	}

	public function description(): string {
		return __( 'Finds and replaces text inside Divi modules: on one page (id) or, with all_pages true, across every Divi page, post, Divi Library layout and Theme Builder layout. dry_run defaults to true: it changes nothing and returns, per page, the number of matches and samples (path, field, before/after). Run it again with dry_run false to apply. scope "text" (default) only touches visible text (module content outside HTML tags, titles, button labels, alt text…); scope "all" also changes attributes such as URLs, image sources and CSS settings (e.g. to move links to a new domain). Plain text match, case-sensitive unless case_sensitive is false. Text inside global Divi Library items is skipped on pages that use them (it is changed in the library item itself when all_pages is on). Large sites: limit/offset page through the Divi posts. Each changed post gets a revision; one undo_id restores all of them.', 'wproadie' );
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
					'minLength'   => 1,
					'maxLength'   => 1000,
					'description' => __( 'Text to find.', 'wproadie' ),
				),
				'replace'        => array(
					'type'        => 'string',
					'maxLength'   => 10000,
					'description' => __( 'Replacement text ("" to delete the matches).', 'wproadie' ),
				),
				'id'             => self::id_property(),
				'all_pages'      => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Search every Divi item instead of one page (do not pass id).', 'wproadie' ),
				),
				'scope'          => array(
					'type'        => 'string',
					'enum'        => array( 'text', 'all' ),
					'default'     => 'text',
					'description' => __( '"text": visible text only. "all": every attribute too, including URLs and settings.', 'wproadie' ),
				),
				'case_sensitive' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'true (default): only report matches. false: make the changes.', 'wproadie' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
					'description' => __( 'With all_pages: how many Divi items to scan in this call.', 'wproadie' ),
				),
				'offset'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( 'With all_pages: skip this many Divi items (for the next batch).', 'wproadie' ),
				),
			),
			'required'             => array( 'search', 'replace' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$search     = (string) $input['search'];
		$replace    = (string) $input['replace'];
		$dry_run    = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$all        = ! empty( $input['all_pages'] );
		$scope      = (string) ( $input['scope'] ?? 'text' );
		$match_case = ! isset( $input['case_sensitive'] ) || (bool) $input['case_sensitive'];

		$has_id = ! empty( $input['id'] );
		if ( ( $all && $has_id ) || ( ! $all && ! $has_id ) ) {
			return Divi::error( 'replace_target', __( 'Pass either id (one page) or all_pages true (every Divi item), not both.', 'wproadie' ) );
		}
		if ( ! Divi::unfiltered() && wp_kses_post( $replace ) !== $replace ) {
			return Divi::error( 'replace_not_allowed', __( 'The replacement contains HTML your account may not save (scripts, iframes or event attributes). Nothing was changed.', 'wproadie' ), 403 );
		}

		$total = 1;
		if ( $all ) {
			$types = Divi::editable_types( Divi::post_types() );
			$found = Divi::query_ids( $types, array( 'publish', 'draft', 'pending', 'private', 'future' ), '', (int) ( $input['limit'] ?? 100 ), (int) ( $input['offset'] ?? 0 ) );
			$ids   = $found['ids'];
			$total = $found['total'];
		} else {
			$ids = array( (int) $input['id'] );
		}

		$pages   = array();
		$failed  = array();
		$matches = 0;
		$skipped = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$loaded = Divi::load( $id );
			if ( is_wp_error( $loaded ) ) {
				if ( ! $all ) {
					return $loaded;
				}
				$failed[] = array(
					'id'    => $id,
					'error' => $loaded->get_error_message(),
				);
				continue;
			}
			$doc     = $loaded['doc'];
			$samples = array();
			$count   = 0;
			foreach ( $doc->all() as $entry ) {
				if ( '' !== $entry['global'] ) {
					$skipped[ (int) $entry['global'] ] = true;
					continue;
				}
				$count += 5 === $doc->format
					? $this->replace_5( $entry, $search, $replace, $scope, $match_case, $samples )
					: $this->replace_4( $entry, $search, $replace, $scope, $match_case, $samples );
			}
			if ( ! $count ) {
				continue;
			}
			$matches += $count;
			$page     = array(
				'id'      => (int) $loaded['post']->ID,
				'title'   => $loaded['post']->post_title,
				'type'    => $loaded['post']->post_type,
				'matches' => $count,
				'samples' => $samples,
			);
			if ( ! $dry_run ) {
				$saved = $this->save( $loaded['post'], $doc );
				if ( is_wp_error( $saved ) ) {
					$failed[] = array(
						'id'    => $page['id'],
						'error' => $saved->get_error_message(),
					);
					continue;
				}
				$page['revision_id'] = $saved['revision_id'] ?? null;
			}
			$pages[] = $page;
		}

		$result = array(
			'dry_run' => $dry_run,
			'matches' => $matches,
			'pages'   => $pages,
		);
		if ( $all ) {
			$offset            = (int) ( $input['offset'] ?? 0 );
			$result['scanned'] = count( $ids );
			$result['total']   = $total;
			if ( $offset + count( $ids ) < $total ) {
				$result['next_offset'] = $offset + count( $ids );
			}
		}
		if ( $failed ) {
			$result['failed'] = $failed;
		}
		if ( $skipped ) {
			$result['skipped_global_layouts'] = array_keys( $skipped );
		}
		if ( $dry_run && $matches ) {
			$result['next'] = __( 'Nothing was changed. Call again with dry_run false to apply.', 'wproadie' );
		}
		return $result;
	}

	/**
	 * Replaces in one Divi 4 element. Returns the number of matches.
	 */
	private function replace_4( array $entry, string $search, string $replace, string $scope, bool $match_case, array &$samples ): int {
		$node  = $entry['node'];
		$count = 0;
		foreach ( Shortcodes::decoded_attrs( $node ) as $key => $value ) {
			$key = (string) $key;
			if ( is_numeric( $key ) || in_array( $key, array( 'global_module', 'global_parent', '_builder_version' ), true ) ) {
				continue;
			}
			if ( 'text' === $scope && ! in_array( $key, self::TEXT_ATTRS_4, true ) ) {
				continue;
			}
			$new = $this->replace_string( $value, $search, $replace, $match_case, 'text' === $scope, $n );
			if ( $n ) {
				$count += $n;
				$this->sample( $samples, $entry['path'], $key, $value, $new, $search, $match_case );
				Shortcodes::set_attr( $node, $key, $new );
			}
		}
		if ( null !== $node->content ) {
			$new = $this->replace_string( $node->content, $search, $replace, $match_case, 'text' === $scope, $n );
			if ( $n ) {
				$count += $n;
				$this->sample( $samples, $entry['path'], 'content', $node->content, $new, $search, $match_case );
				$node->content = $new;
			}
		}
		return $count;
	}

	/**
	 * Replaces in one Divi 5 block. Returns the number of matches.
	 */
	private function replace_5( array $entry, string $search, string $replace, string $scope, bool $match_case, array &$samples ): int {
		$node = $entry['node'];
		if ( ! is_object( $node->attrs ) || 'divi/global-layout' === $node->name ) {
			return 0;
		}
		$count = 0;
		$this->walk_5( $node->attrs, '', $entry['path'], $search, $replace, $scope, $match_case, $samples, $count );
		if ( $count ) {
			Blocks::refresh_open( $node );
		}
		return $count;
	}

	/**
	 * @param object $value Object being walked (changed in place).
	 */
	private function walk_5( $value, string $prefix, string $path, string $search, string $replace, string $scope, bool $match_case, array &$samples, int &$count ): void {
		foreach ( get_object_vars( $value ) as $key => $item ) {
			$key_path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( '' === $prefix && in_array( $key, array( 'globalModule', 'builderVersion', 'localAttrs' ), true ) ) {
				continue;
			}
			if ( is_object( $item ) ) {
				$this->walk_5( $item, $key_path, $path, $search, $replace, $scope, $match_case, $samples, $count );
				continue;
			}
			if ( ! is_string( $item ) ) {
				continue;
			}
			if ( 'text' === $scope && ( false === strpos( $key_path, '.innerContent.' ) || in_array( (string) $key, self::NON_TEXT_KEYS_5, true ) ) ) {
				continue;
			}
			$new = $this->replace_string( $item, $search, $replace, $match_case, 'text' === $scope, $n );
			if ( $n ) {
				$count += $n;
				$this->sample( $samples, $path, $key_path, $item, $new, $search, $match_case );
				$value->$key = $new;
			}
		}
	}

	/**
	 * Replaces in a string; with $text_only, only outside HTML tags.
	 *
	 * @param string   $subject    Text to change.
	 * @param string   $search     Text to find.
	 * @param string   $replace    Replacement.
	 * @param bool     $match_case Case-sensitive.
	 * @param bool     $text_only  Leave HTML tags alone.
	 * @param int|null $count      Set to the number of replacements.
	 */
	private function replace_string( string $subject, string $search, string $replace, bool $match_case, bool $text_only, ?int &$count ): string {
		$count = 0;
		if ( '' === $subject || false === ( $match_case ? strpos( $subject, $search ) : stripos( $subject, $search ) ) ) {
			return $subject;
		}
		if ( ! $text_only || false === strpos( $subject, '<' ) ) {
			return $match_case ? str_replace( $search, $replace, $subject, $count ) : str_ireplace( $search, $replace, $subject, $count );
		}
		$pieces = preg_split( '/(<[^>]*>)/', $subject, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $pieces as $i => $piece ) {
			if ( 1 === $i % 2 ) {
				continue;
			}
			$n            = 0;
			$pieces[ $i ] = $match_case ? str_replace( $search, $replace, $piece, $n ) : str_ireplace( $search, $replace, $piece, $n );
			$count       += $n;
		}
		return implode( '', $pieces );
	}

	private function sample( array &$samples, string $path, string $field, string $before, string $after, string $search, bool $match_case ): void {
		if ( count( $samples ) >= self::MAX_SAMPLES ) {
			return;
		}
		$at        = (int) ( $match_case ? mb_strpos( $before, $search ) : mb_stripos( $before, $search ) );
		$start     = max( 0, $at - 40 );
		$length    = 80 + mb_strlen( $search );
		$samples[] = array(
			'path'   => $path,
			'field'  => $field,
			'before' => Document::short( mb_substr( $before, $start, $length ), 200 ),
			'after'  => Document::short( mb_substr( $after, $start, $length ), 200 ),
		);
	}
}
