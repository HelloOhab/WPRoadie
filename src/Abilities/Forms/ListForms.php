<?php
/**
 * Ability: forms-list.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Forms;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ListForms extends FormsAbility {

	const CAP = 'view_forms';

	public function slug(): string {
		return 'forms-list';
	}

	public function label(): string {
		return __( 'List forms', 'wproadie' );
	}

	public function description(): string {
		return __( 'Lists the site\'s WPForms forms: id, title, status, field count, the shortcode to embed it, when it was last changed, and on WPForms Pro the number of entries. Use the id with forms-get, forms-update and forms-list-entries.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'          => array(
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => __( 'Only forms whose title contains this.', 'wproadie' ),
				),
				'include_trashed' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$posts = get_posts(
			array(
				'post_type'      => WPForms::POST_TYPE,
				'post_status'    => ! empty( $input['include_trashed'] ) ? array( 'publish', 'trash' ) : 'publish',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A site has few forms.
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => isset( $input['search'] ) ? (string) $input['search'] : '',
			)
		);
		$forms = array();
		foreach ( $posts as $post ) {
			$data  = WPForms::data( $post );
			$row   = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'status'    => $post->post_status,
				'fields'    => is_wp_error( $data ) ? 0 : count( (array) ( $data['fields'] ?? array() ) ),
				'shortcode' => sprintf( '[wpforms id="%d"]', $post->ID ),
				'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
			);
			$count = WPForms::entry_count( (int) $post->ID );
			if ( null !== $count ) {
				$row['entries'] = $count;
			}
			if ( ! is_wp_error( $data ) && ! empty( $data['settings']['notifications'] ) ) {
				$to = array();
				foreach ( (array) $data['settings']['notifications'] as $notification ) {
					if ( is_array( $notification ) && ! empty( $notification['email'] ) ) {
						$to[] = (string) $notification['email'];
					}
				}
				if ( $to ) {
					$row['notifies'] = array_values( array_unique( $to ) );
				}
			}
			$forms[] = $row;
		}
		return array(
			'wpforms' => array(
				'version' => WPForms::version(),
				'pro'     => WPForms::is_pro(),
				'entries' => null !== WPForms::entries() ? 'stored' : 'not stored (Lite sends them by email only)',
			),
			'forms'   => $forms,
			'total'   => count( $forms ),
		);
	}
}
