<?php
/**
 * Shared base for the Elementor abilities: group, availability, the page
 * permission check and saving with undo.
 *
 * Abstract, so the Registry never lists it as an ability.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use WP_Error;
use WP_Post;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;

defined( 'ABSPATH' ) || exit;

abstract class ElementorAbility extends AbilityBase {

	public function group(): string {
		return 'elementor';
	}

	public function is_available(): bool {
		return ElementorHelper::is_active();
	}

	public function unavailable_reason(): string {
		return ElementorHelper::unavailable_reason();
	}

	/**
	 * Schema for the post_id argument most tools take.
	 */
	protected static function post_id_schema(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the page, post or template built with Elementor (from elementor-list-pages or elementor-list-templates).', 'wproadie' ),
		);
	}

	/**
	 * Schema for an element ID argument.
	 */
	protected static function element_id_schema( string $description ): array {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => 40,
			'pattern'     => '^[A-Za-z0-9_-]+$',
			'description' => $description,
		);
	}

	/**
	 * Permission: the user can edit the post with Elementor. A missing post
	 * passes so execute() can report it clearly.
	 */
	protected function permission( array $input ): bool {
		$id = (int) ( $input['post_id'] ?? 0 );
		if ( ! $id || ! get_post( $id ) ) {
			return true;
		}
		return ElementorHelper::can_edit( $id );
	}

	/**
	 * Loads an Elementor page and its tree.
	 *
	 * @return array{0: WP_Post, 1: array}|WP_Error
	 */
	protected function load( int $post_id ) {
		$post = ElementorHelper::get_page( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$tree = ElementorHelper::read_tree( $post->ID );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		return array( $post, $tree );
	}

	/**
	 * Records undo for a page and writes its new tree.
	 *
	 * @return array|WP_Error Extra result fields (warnings), or an error.
	 */
	protected function save_tree( WP_Post $post, array $tree ) {
		// Check the size before recording anything.
		$encoded = ElementorHelper::encode( $tree );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$this->add_undo( Undo::meta( 'post', (int) $post->ID, ElementorHelper::DATA_KEY ) );
		if ( ElementorHelper::syncs_plain_text() ) {
			// The plain-text copy in post_content changes too; this also
			// keeps a WordPress revision of the previous version.
			$this->add_undo( Undo::post( (int) $post->ID ) );
		}

		$saved = ElementorHelper::write_tree( (int) $post->ID, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$extra   = array();
		$warning = ElementorHelper::lock_warning( (int) $post->ID );
		if ( $warning ) {
			$extra['warning'] = $warning;
		}
		return $extra;
	}

	/**
	 * Parses an element the AI sent: size, shape, and that its types exist.
	 *
	 * @param mixed $element Element data.
	 * @param bool  $check_types Refuse widget types Elementor does not know.
	 * @return array|WP_Error
	 */
	protected function parse_element( $element, bool $check_types = true ) {
		$json = wp_json_encode( $element );
		if ( false === $json ) {
			return $this->error( 'invalid_element', __( 'The element could not be read as JSON.', 'wproadie' ) );
		}
		if ( strlen( $json ) > ElementorHelper::MAX_ELEMENT_BYTES ) {
			return $this->error(
				'too_large',
				sprintf(
					/* translators: %s: size limit, e.g. "256 KB". */
					__( 'The element is larger than %s. Add it in smaller parts.', 'wproadie' ),
					size_format( ElementorHelper::MAX_ELEMENT_BYTES )
				),
				413
			);
		}
		$element = Tree::normalize( $element );
		if ( is_wp_error( $element ) ) {
			return $element;
		}
		if ( $check_types ) {
			$valid = ElementorHelper::check_types( $element );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return $element;
	}

	/**
	 * Refuses settings keys Elementor would silently ignore on this
	 * element type, naming the likely right key and the controls the type
	 * does take, so the next try is right. Passes when Elementor is not
	 * loaded or does not know the type (check_types reports that).
	 *
	 * @param array  $element  Normalized element (elType, widgetType, settings).
	 * @param string $where    Where the element is, for the message ("" for the element itself).
	 * @return true|WP_Error
	 */
	protected function check_settings( array $element, string $where = '' ) {
		$el_type  = (string) ( $element['elType'] ?? '' );
		$widget   = (string) ( $element['widgetType'] ?? '' );
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		if ( ! $settings ) {
			return true;
		}
		$controls = ElementorHelper::all_controls( $el_type, $widget );
		$unknown  = ElementorHelper::unknown_settings( $controls, $settings );
		if ( ! $unknown ) {
			return true;
		}
		$label       = 'widget' === $el_type ? sprintf( 'widget "%s"', $widget ) : $el_type;
		$suggestions = ElementorHelper::suggestions( $controls, $unknown );
		$hints       = array();
		foreach ( $suggestions as $key => $meant ) {
			$hints[] = sprintf( '%s → %s', $key, $meant );
		}
		$message = sprintf(
			/* translators: 1: element description, 2: comma-separated keys, 3: where. */
			__( 'Elementor would ignore these settings on %1$s: %2$s%3$s.', 'wproadie' ),
			$label,
			implode( ', ', $unknown ),
			'' !== $where ? ' (' . $where . ')' : ''
		);
		if ( $hints ) {
			/* translators: %s: "wrong → right" pairs. */
			$message .= ' ' . sprintf( __( 'Did you mean: %s.', 'wproadie' ), implode( '; ', $hints ) );
		}
		$message .= ' ' . __( 'The settings this type takes are in the error\'s schema (names by panel section); elementor-get-widget-schema has the details. To store the keys anyway, send allow_unknown_settings: true.', 'wproadie' );

		$schema = array();
		foreach ( ElementorHelper::sections( $controls ) as $section ) {
			$schema[ $section['id'] ] = array_column( $section['controls'], 'name' );
		}
		return new WP_Error(
			'wproadie_unknown_setting',
			$message,
			array(
				'status'      => 400,
				'unknown'     => $unknown,
				'suggestions' => $suggestions,
				'schema'      => $schema,
			)
		);
	}

	/**
	 * Runs check_settings() on an element and everything inside it.
	 *
	 * @return true|WP_Error
	 */
	protected function check_settings_tree( array $element ) {
		$problem = true;
		Tree::walk(
			array( $element ),
			function ( $el, $path ) use ( &$problem ) {
				if ( true !== $problem ) {
					return;
				}
				$where   = count( $path ) > 1 ? sprintf( 'nested element at %s', implode( '.', array_slice( $path, 1 ) ) ) : '';
				$problem = $this->check_settings( $el, $where );
			}
		);
		return $problem;
	}

	/**
	 * Inserts new elements (already given fresh IDs and sanitized) and
	 * saves the page.
	 *
	 * @param WP_Post     $post      Page.
	 * @param array       $tree      Current tree.
	 * @param array[]     $elements  Elements to insert.
	 * @param string|null $parent_id Parent, or null for the top level.
	 * @param int|null    $position  Index, or null to append.
	 * @param array       $taken     IDs in use (for wrappers).
	 * @return array|WP_Error Result fields.
	 */
	protected function insert_and_save( WP_Post $post, array $tree, array $elements, ?string $parent_id, ?int $position, array &$taken ) {
		$wrapped = false;
		if ( null === $parent_id ) {
			foreach ( $elements as $i => $element ) {
				if ( in_array( $element['elType'] ?? '', array( 'widget', 'column' ), true ) ) {
					if ( 'column' === $element['elType'] ) {
						return $this->error( 'invalid_placement', __( 'A column can only go inside a section.', 'wproadie' ) );
					}
					$elements[ $i ] = ElementorHelper::wrap_for_top_level( $element, $taken );
					$wrapped        = true;
				}
			}
		}

		$new_tree = Tree::insert( $tree, $parent_id, $position, $elements );
		if ( is_wp_error( $new_tree ) ) {
			return $new_tree;
		}
		$saved = $this->save_tree( $post, $new_tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$added = array();
		foreach ( $elements as $element ) {
			$found   = Tree::find( $new_tree, (string) $element['id'] );
			$added[] = $found ? Tree::node( $found['element'], $found['path'] ) : array( 'id' => $element['id'] );
		}
		$result = array(
			'post_id' => (int) $post->ID,
			'added'   => $added,
		);
		if ( $wrapped ) {
			$result['note'] = __( 'Widgets cannot sit at the top level of a page, so each was wrapped in a new container (or section and column).', 'wproadie' );
		}
		return array_merge( $result, $saved );
	}

	/**
	 * Optional parent_id input: a string ID, or null for the top level.
	 */
	protected static function parent_from_input( array $input ): ?string {
		$parent = isset( $input['parent_id'] ) ? trim( (string) $input['parent_id'] ) : '';
		return ( '' === $parent || 'root' === $parent ) ? null : $parent;
	}

	/**
	 * Optional position input.
	 */
	protected static function position_from_input( array $input ): ?int {
		return isset( $input['position'] ) ? max( 0, (int) $input['position'] ) : null;
	}

	/**
	 * Error for an element ID not on the page.
	 */
	protected function element_not_found(): WP_Error {
		return $this->error( 'element_not_found', __( 'No element with that ID on this page. Element IDs are the short codes (e.g. "4f5e6d7") in elementor-get-page\'s outline.', 'wproadie' ), 404 );
	}
}
