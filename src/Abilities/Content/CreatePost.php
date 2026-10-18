<?php
/**
 * Creates a post, page or other content item.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Content;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Abilities\Divi\Divi;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class CreatePost extends AbilityBase {

	public function slug(): string {
		return 'create-post';
	}

	public function label(): string {
		return __( 'Create post', 'wproadie' );
	}

	public function description(): string {
		return __( 'Creates a post, page or other content item, as a draft unless you set status. Content may be HTML or block markup (<!-- wp:paragraph --> etc.). To schedule, set status "future" and a date in the future. Set categories, tags or other terms afterwards with set-post-terms and the featured image with set-featured-image. Returns id, link, edit_link and the final status, plus an undo_id; pass it to undo-change to move the new item to the trash.', 'wproadie' );
	}

	public function group(): string {
		return 'content';
	}

	public function risk(): string {
		return Mode::RISK_WRITE;
	}

	protected function annotations(): array {
		$annotations                = parent::annotations();
		$annotations['destructive'] = false;
		return $annotations;
	}

	/**
	 * Properties shared with update-post.
	 */
	public static function post_properties(): array {
		return array(
			'title'      => array(
				'type'        => 'string',
				'description' => __( 'Title, plain text.', 'wproadie' ),
			),
			'content'    => array(
				'type'        => 'string',
				'description' => __( 'Body as HTML or block markup.', 'wproadie' ),
			),
			'excerpt'    => array(
				'type'        => 'string',
				'description' => __( 'Manual excerpt, plain text.', 'wproadie' ),
			),
			'status'     => array(
				'type'        => 'string',
				'enum'        => ContentHelper::WRITABLE_STATUSES,
				'description' => __( 'draft, pending (awaiting review), publish, private, or future (scheduled; needs a future date).', 'wproadie' ),
			),
			'slug'       => array(
				'type'        => 'string',
				'description' => __( 'URL slug. WordPress makes it unique if it is taken.', 'wproadie' ),
			),
			'parent'     => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Parent post ID for hierarchical types such as pages; 0 for none.', 'wproadie' ),
			),
			'menu_order' => array(
				'type'        => 'integer',
				'description' => __( 'Sort order among siblings (pages).', 'wproadie' ),
			),
			'date'       => array(
				'type'        => 'string',
				'description' => __( 'Publish date in the site\'s local time, e.g. 2026-11-01T09:00:00 (add an offset such as +02:00 or Z to give another zone). A future date with status publish or future schedules the item.', 'wproadie' ),
			),
			'builder'    => array(
				'type'        => 'string',
				'enum'        => array( 'divi', 'elementor', 'none' ),
				'description' => __( 'Set the page up for a page builder, as its editor would: "divi" turns the Divi Builder on, makes the page full width and hides the title; "elementor" marks the page as built with Elementor on its header-and-footer template; "none" turns the builders off. Leave out to change nothing.', 'wproadie' ),
			),
			'template'   => array(
				'type'        => 'string',
				'description' => __( 'Page template slug: "default", one of the theme\'s templates (e.g. "page-template-blank.php" for Divi\'s blank page), or an Elementor template (elementor_header_footer, elementor_canvas, elementor_theme). Unknown templates are refused with the list.', 'wproadie' ),
			),
		);
	}

	/**
	 * Builder and template settings a request carries, as meta to store:
	 * what the builders' own editors write when a person ticks their boxes.
	 *
	 * @param array    $input       Validated input.
	 * @param int|null $divi_format 4 or 5 when known (the Divi tools know their
	 *                              document); otherwise read from the content or
	 *                              the site's Divi version.
	 * @return array{set: array<string, string>, delete: string[]}|\WP_Error
	 */
	public static function page_settings( array $input, ?int $divi_format = null ) {
		$set    = array();
		$delete = array();
		switch ( $input['builder'] ?? null ) {
			case 'divi':
				$set['_et_pb_use_builder'] = 'on';
				$set['_et_pb_page_layout'] = 'et_full_width_page';
				$set['_et_pb_show_title']  = 'off';
				// Divi 5 records two more markers on save. Without them the first
				// Visual Builder open treats the page as classic content, wraps
				// it in a placeholder and parks the real layout in
				// _et_pb_old_content: the build looks lost.
				if ( 5 === ( $divi_format ?? self::divi_format( (string) ( $input['content'] ?? '' ) ) ) ) {
					$set['_et_pb_use_divi_5']         = 'on';
					$set['_et_pb_show_page_creation'] = 'off';
				}
				break;
			case 'elementor':
				$set['_elementor_edit_mode']     = 'builder';
				$set['_elementor_template_type'] = 'wp-page';
				if ( ! isset( $input['template'] ) && defined( 'ELEMENTOR_VERSION' ) ) {
					$set['_wp_page_template'] = 'elementor_header_footer';
				}
				break;
			case 'none':
				$set['_et_pb_use_builder'] = 'off';
				$delete[]                  = '_et_pb_use_divi_5';
				$delete[]                  = '_et_pb_show_page_creation';
				$delete[]                  = '_elementor_edit_mode';
				break;
		}
		if ( isset( $input['template'] ) ) {
			$set['_wp_page_template'] = (string) $input['template'];
		}
		foreach ( $set as $key => $value ) {
			$check = ContentHelper::meta_value_check( $key, $value );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		return array(
			'set'    => $set,
			'delete' => $delete,
		);
	}

	/**
	 * Which Divi format a page will be built in: shortcodes say 4, divi/
	 * blocks say 5, and empty content follows the site's Divi version.
	 */
	public static function divi_format( string $content ): int {
		if ( Divi::starts_with_shortcodes( $content ) ) {
			return 4;
		}
		if ( false !== strpos( $content, '<!-- wp:divi/' ) ) {
			return 5;
		}
		return version_compare( Divi::site_builder_version(), '5', '>=' ) ? 5 : 4;
	}

	/**
	 * Stores the settings from page_settings(), with an undo record each.
	 *
	 * @param int      $post_id  The post.
	 * @param array    $settings From page_settings().
	 * @param callable $add_undo Receives undo records.
	 * @return array<string, string|null> What was stored (null = removed).
	 */
	public static function apply_page_settings( int $post_id, array $settings, callable $add_undo ): array {
		$applied = array();
		foreach ( $settings['set'] as $key => $value ) {
			if ( (string) get_post_meta( $post_id, $key, true ) === $value ) {
				continue;
			}
			$add_undo( Undo::meta( 'post', $post_id, $key ) );
			update_post_meta( $post_id, $key, $value );
			$applied[ $key ] = $value;
		}
		foreach ( $settings['delete'] as $key ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) {
				continue;
			}
			$add_undo( Undo::meta( 'post', $post_id, $key ) );
			delete_post_meta( $post_id, $key );
			$applied[ $key ] = null;
		}
		return $applied;
	}

	public function input_schema(): array {
		$properties                      = self::post_properties();
		$properties['post_type']         = array(
			'type'        => 'string',
			'default'     => 'post',
			'description' => __( 'Post type slug, e.g. "post" or "page".', 'wproadie' ),
		);
		$properties['status']['default'] = 'draft';

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function permission( array $input ): bool {
		$type = get_post_type_object( (string) ( $input['post_type'] ?? 'post' ) );
		if ( ! $type ) {
			// Reported as an invalid post type by execute().
			return true;
		}
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			return false;
		}
		return ! ContentHelper::needs_publish_cap( (string) ( $input['status'] ?? 'draft' ) ) || current_user_can( $type->cap->publish_posts );
	}

	protected function execute( array $input ) {
		$post_type = (string) ( $input['post_type'] ?? 'post' );
		$valid     = ContentHelper::check_type( $post_type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$status = (string) ( $input['status'] ?? 'draft' );
		$data   = array(
			'post_type'   => $post_type,
			'post_status' => $status,
			'post_author' => get_current_user_id(),
		);

		$fields = self::fields_to_post_data( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$data = array_merge( $data, $fields );

		if ( 'future' === $status && empty( $data['post_date'] ) ) {
			return $this->error( 'missing_date', __( 'Scheduling needs a date in the future.', 'wproadie' ) );
		}

		$settings = self::page_settings( $input );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->add_undo( Undo::post_created( (int) $id ) );
		$applied = self::apply_page_settings(
			(int) $id,
			$settings,
			function ( array $record ) {
				$this->add_undo( $record );
			}
		);

		$post   = get_post( $id );
		$result = array(
			'id'        => (int) $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'link'      => (string) get_permalink( $post ),
			'edit_link' => (string) get_edit_post_link( $post, 'raw' ),
		);
		if ( $post->post_status !== $status ) {
			$result['note'] = self::status_note( $status, $post->post_status );
		}
		if ( $applied ) {
			$result['page_settings'] = $applied;
		}
		return $result;
	}

	/**
	 * Maps the shared input fields to wp_insert_post() arguments and checks
	 * parent and date.
	 *
	 * @param array $input Validated input.
	 * @return array|\WP_Error
	 */
	public static function fields_to_post_data( array $input ) {
		$map  = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'slug'       => 'post_name',
			'parent'     => 'post_parent',
			'menu_order' => 'menu_order',
		);
		$data = array();
		foreach ( $map as $field => $arg ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $arg ] = in_array( $field, array( 'parent', 'menu_order' ), true ) ? (int) $input[ $field ] : (string) $input[ $field ];
			}
		}

		if ( ! empty( $data['post_parent'] ) && ! get_post( $data['post_parent'] ) ) {
			return new \WP_Error( 'wproadie_invalid_parent', __( 'The parent post does not exist.', 'wproadie' ), array( 'status' => 400 ) );
		}

		if ( isset( $input['date'] ) && '' !== $input['date'] ) {
			$dates = ContentHelper::parse_date( (string) $input['date'] );
			if ( is_wp_error( $dates ) ) {
				return $dates;
			}
			$data['post_date']     = $dates[0];
			$data['post_date_gmt'] = $dates[1];
			// Without this, drafts ignore the date on update.
			$data['edit_date'] = true;
		}

		return $data;
	}

	/**
	 * Explains why WordPress stored a different status than requested.
	 */
	public static function status_note( string $requested, string $actual ): string {
		if ( 'future' === $requested && 'publish' === $actual ) {
			return __( 'The date is not in the future, so WordPress published the item instead of scheduling it.', 'wproadie' );
		}
		if ( 'publish' === $requested && 'future' === $actual ) {
			return __( 'The date is in the future, so WordPress scheduled the item instead of publishing it now.', 'wproadie' );
		}
		/* translators: 1: requested status, 2: stored status. */
		return sprintf( __( 'Requested status "%1$s", but WordPress stored "%2$s".', 'wproadie' ), $requested, $actual );
	}
}
