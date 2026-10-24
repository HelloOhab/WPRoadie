<?php
/**
 * Lists Elementor's widgets, and the settings one widget or element
 * understands, so the AI sets real control names instead of guessing.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Elementor;

use Throwable;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetWidgetSchema extends ElementorAbility {

	/** Widget types that are editor plumbing, not things to add to a page. */
	const HIDDEN_WIDGETS = array( 'common', 'common-base', 'common-optimized', 'global', 'inner-section' );

	/** Most controls one answer carries. */
	const MAX_CONTROLS = 400;

	public function slug(): string {
		return 'elementor-get-widget-schema';
	}

	public function label(): string {
		return __( 'Get Elementor widget schema', 'wproadie' );
	}

	public function description(): string {
		return __( 'Without arguments: every widget type Elementor offers on this site (name, title, category, where it comes from) and the layout element types. With widget (e.g. "heading", "button") or element ("container", "section", "column"): the settings that element understands, grouped by panel section, each with its type, label, default, options, units and whether it is responsive, takes a global or dynamic value. These are the exact keys for settings in elementor-add-element and elementor-update-element; Elementor silently ignores any other key, so read this before setting anything you have not seen on the page. Narrow a long answer with section or search. Conventions: responsive values are the same key with _tablet or _mobile; sizes are {"size": 24, "unit": "px"}; links are {"url": "...", "is_external": "", "nofollow": ""}; a global colour or font goes in __globals__ (see elementor-get-kit); group controls are flat keys with a prefix (typography_font_size).', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'widget'        => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9][A-Za-z0-9_.-]*$',
					'maxLength'   => 100,
					'description' => __( 'Widget type to describe, e.g. "heading".', 'wproadie' ),
				),
				'element'       => array(
					'type'        => 'string',
					'enum'        => array( 'container', 'section', 'column' ),
					'description' => __( 'Layout element type to describe instead of a widget.', 'wproadie' ),
				),
				'section'       => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => __( 'Only this panel section (an id from a previous answer, e.g. "section_style").', 'wproadie' ),
				),
				'search'        => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => __( 'Only controls whose name or label contains this text (e.g. "color", "padding").', 'wproadie' ),
				),
				'include_style' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'false leaves out the Style and Advanced tabs, keeping only content settings.', 'wproadie' ),
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
		if ( ! ElementorHelper::is_loaded() ) {
			return $this->error( 'not_loaded', __( 'Elementor is active but not loaded in this request, so its widgets cannot be listed.', 'wproadie' ), 503 );
		}
		$widget  = isset( $input['widget'] ) ? (string) $input['widget'] : '';
		$element = isset( $input['element'] ) ? (string) $input['element'] : '';
		if ( '' === $widget && '' === $element ) {
			return self::catalog();
		}
		$el_type = '' !== $element ? $element : 'widget';
		$type    = ElementorHelper::type_of( $el_type, $widget );
		if ( ! $type ) {
			return $this->error(
				'unknown_type',
				sprintf(
					/* translators: 1: type name, 2: comma-separated known widget types. */
					__( 'Elementor on this site has no widget or element type "%1$s". Widget types include: %2$s.', 'wproadie' ),
					'' !== $element ? $element : $widget,
					implode( ', ', array_slice( ElementorHelper::widget_names(), 0, 60 ) )
				),
				404
			);
		}
		return self::describe( $type, $el_type, $widget, $input );
	}

	/** Every widget type, plus the layout element types. */
	private static function catalog(): array {
		$widgets = array();
		try {
			$types = (array) \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
		} catch ( Throwable $e ) {
			$types = array();
		}
		foreach ( $types as $name => $type ) {
			$name = (string) $name;
			if ( in_array( $name, self::HIDDEN_WIDGETS, true ) || 0 === strpos( $name, 'wp-widget-' ) || ! is_object( $type ) ) {
				continue;
			}
			$title = method_exists( $type, 'get_title' ) ? wp_strip_all_tags( (string) $type->get_title() ) : '';
			if ( '' === $title ) {
				continue;
			}
			$row = array(
				'name'   => $name,
				'title'  => $title,
				'source' => self::source( $type ),
			);
			if ( method_exists( $type, 'get_categories' ) ) {
				$categories = array_values( array_filter( array_map( 'strval', (array) $type->get_categories() ) ) );
				if ( $categories ) {
					$row['category'] = implode( ', ', $categories );
				}
			}
			$widgets[] = $row;
		}
		usort(
			$widgets,
			static function ( $a, $b ) {
				return strcmp( $a['source'] . ' ' . $a['name'], $b['source'] . ' ' . $b['name'] );
			}
		);
		$elements = array();
		foreach ( array( 'container', 'section', 'column' ) as $name ) {
			if ( ElementorHelper::element_type( $name ) ) {
				$elements[] = $name;
			}
		}
		return array(
			'elementor_version' => ElementorHelper::version(),
			'containers'        => ElementorHelper::containers_active(),
			'elements'          => $elements,
			'widgets'           => $widgets,
			'total'             => count( $widgets ),
			'note'              => __( 'Call again with widget (or element) for the settings it takes.', 'wproadie' ),
		);
	}

	/** Where a widget comes from: elementor, elementor-pro, or the add-on's namespace. */
	private static function source( object $type ): string {
		$class = get_class( $type );
		if ( 0 === strpos( $class, 'ElementorPro\\' ) ) {
			return 'elementor-pro';
		}
		if ( 0 === strpos( $class, 'Elementor\\' ) ) {
			return 'elementor';
		}
		$parts = explode( '\\', $class );
		return sanitize_title( count( $parts ) > 1 ? $parts[0] : $class );
	}

	/** One element type's settings, grouped by panel section. */
	private static function describe( object $type, string $el_type, string $widget, array $input ): array {
		$all      = ElementorHelper::all_controls( $el_type, $widget );
		$sections = ElementorHelper::sections( $all );
		$only     = isset( $input['section'] ) ? (string) $input['section'] : '';
		$search   = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$style    = ! isset( $input['include_style'] ) || $input['include_style'];

		$kept  = array();
		$count = 0;
		$cut   = false;
		foreach ( $sections as $section ) {
			if ( '' !== $only && $section['id'] !== $only ) {
				continue;
			}
			if ( ! $style && 'content' !== $section['tab'] ) {
				continue;
			}
			if ( '' !== $search ) {
				$section['controls'] = array_values(
					array_filter(
						$section['controls'],
						static function ( $control ) use ( $search ) {
							return false !== strpos( strtolower( $control['name'] . ' ' . ( $control['label'] ?? '' ) ), $search );
						}
					)
				);
				if ( ! $section['controls'] ) {
					continue;
				}
			}
			$room = self::MAX_CONTROLS - $count;
			if ( $room <= 0 ) {
				$cut = true;
				break;
			}
			if ( count( $section['controls'] ) > $room ) {
				$section['controls'] = array_slice( $section['controls'], 0, $room );
				$cut                 = true;
			}
			$count += count( $section['controls'] );
			$kept[] = $section;
		}

		$out = array(
			'name'          => 'widget' === $el_type ? $widget : $el_type,
			'elType'        => $el_type,
			'title'         => method_exists( $type, 'get_title' ) ? wp_strip_all_tags( (string) $type->get_title() ) : '',
			'sections'      => $kept,
			'control_count' => $count,
		);
		if ( 'widget' === $el_type && method_exists( $type, 'get_categories' ) ) {
			$out['category'] = implode( ', ', array_map( 'strval', (array) $type->get_categories() ) );
		}
		if ( $cut ) {
			$out['truncated'] = true;
			$out['note']      = __( 'Not every control fit; narrow down with section or search.', 'wproadie' );
		}
		if ( ! $all ) {
			$out['note'] = __( 'Elementor does not know this type right now (an add-on may be inactive), so its controls cannot be listed.', 'wproadie' );
		}
		if ( ElementorHelper::controls_are_stripped() ) {
			$out['note'] = __( 'Elementor strips labels and option lists outside its editor and REST requests, so this answer has names and types only.', 'wproadie' );
		}
		return $out;
	}
}
