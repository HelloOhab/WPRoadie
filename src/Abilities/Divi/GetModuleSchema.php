<?php
/**
 * Lists Divi's modules, and the attributes one module takes, so the AI
 * sets real attribute names instead of guessing (Divi ignores names it
 * does not know, without any error).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetModuleSchema extends DiviAbility {

	/** Most fields one answer carries. */
	const MAX_FIELDS = 400;

	public function slug(): string {
		return 'divi-get-module-schema';
	}

	public function label(): string {
		return __( 'Get Divi module schema', 'wproadie' );
	}

	public function description(): string {
		return __( 'Without module: every module this site\'s Divi offers (slug, name, type, child module, whether it comes from an add-on such as Divi Supreme). With module (short "button" or full "et_pb_button"; Divi 5 "divi/button"): the attributes it takes, grouped by settings tab and toggle, each with type, label, default, options, units, and whether it has responsive (_tablet, _phone, _last_edited "on|tablet"), hover (__hover, __hover_enabled "on|hover") or sticky variants and takes dynamic content. These are the exact names for attributes in divi-add-module and set in divi-update-module: an unknown name is refused on write, with the likely right one. Narrow a long answer with toggle or search, or include_advanced: false for the content fields only. Divi 5 answers list attribute paths (add ".desktop.value").', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'module'           => array(
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_/-]+$',
					'maxLength'   => 100,
					'description' => __( 'Module to describe, e.g. "text", "et_pb_button", "dsm_button", "divi/heading".', 'wproadie' ),
				),
				'format'           => array(
					'type'        => 'integer',
					'enum'        => array( 4, 5 ),
					'description' => __( 'Divi 4 (shortcodes) or Divi 5 (blocks). Default: what this site\'s Divi writes.', 'wproadie' ),
				),
				'toggle'           => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => __( 'Divi 4: only this toggle (a toggle id from a previous answer, e.g. "main_content", "button", "background").', 'wproadie' ),
				),
				'search'           => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => __( 'Only fields whose name or label contains this text (e.g. "color", "padding").', 'wproadie' ),
				),
				'include_advanced' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Divi 4: false keeps only the Content tab, leaving out Design and Advanced.', 'wproadie' ),
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
		$format = isset( $input['format'] ) ? (int) $input['format'] : self::site_format();
		$module = isset( $input['module'] ) ? (string) $input['module'] : '';
		if ( 5 === $format ) {
			return '' === $module ? self::catalog_5() : self::describe_5( $module );
		}
		return '' === $module ? self::catalog_4() : self::describe_4( $module, $input );
	}

	/** The format this site's Divi writes new pages in. */
	private static function site_format(): int {
		return function_exists( 'et_builder_d5_enabled' ) && et_builder_d5_enabled() ? 5 : 4;
	}

	private function catalog_4() {
		$rows = Schema::catalog_4();
		if ( ! $rows ) {
			return $this->error( 'not_loaded', __( 'Divi\'s module definitions could not be loaded in this request. Divi may not be fully active; divi-get-module on an existing module still shows its attributes.', 'wproadie' ), 503 );
		}
		return array(
			'format'  => 4,
			'modules' => $rows,
			'total'   => count( $rows ),
			'note'    => __( 'Short names drop the et_pb_ prefix (et_pb_text → text). Call again with module for the attributes one takes.', 'wproadie' ),
		);
	}

	private function describe_4( string $module, array $input ) {
		$slug = str_replace( '-', '_', $module );
		if ( AddModule::needs_divi_prefix( $slug ) ) {
			$slug = 'et_pb_' . $slug;
		}
		$fields = Schema::fields_4( $slug );
		if ( null === $fields ) {
			$known = array_column( Schema::catalog_4(), 'slug' );
			return $this->error(
				'unknown_type',
				$known
					? sprintf(
						/* translators: 1: module name, 2: comma-separated module slugs. */
						__( 'Divi on this site has no module "%1$s". Modules include: %2$s.', 'wproadie' ),
						$slug,
						implode( ', ', array_slice( $known, 0, 60 ) )
					)
					: __( 'Divi\'s module definitions could not be loaded in this request.', 'wproadie' ),
				404
			);
		}
		$toggles  = array();
		$title    = Document::type_name( $slug );
		$instance = Schema::load_4() ? ( \ET_Builder_Element::get_modules()[ $slug ] ?? null ) : null;
		if ( is_object( $instance ) ) {
			$toggles = is_array( $instance->settings_modal_toggles ?? null ) ? $instance->settings_modal_toggles : array();
			$title   = wp_strip_all_tags( (string) ( $instance->name ?? $title ) );
		}

		$only   = isset( $input['toggle'] ) ? (string) $input['toggle'] : '';
		$search = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$all    = ! isset( $input['include_advanced'] ) || $input['include_advanced'];
		$groups = array();
		$count  = 0;
		$cut    = false;
		foreach ( Schema::grouped_4( $fields, $toggles ) as $group ) {
			if ( '' !== $only && $group['toggle'] !== $only ) {
				continue;
			}
			if ( ! $all && 'general' !== $group['tab'] ) {
				continue;
			}
			if ( '' !== $search ) {
				$group['fields'] = array_values(
					array_filter(
						$group['fields'],
						static function ( $field ) use ( $search ) {
							return false !== strpos( strtolower( $field['name'] . ' ' . ( $field['label'] ?? '' ) ), $search );
						}
					)
				);
				if ( ! $group['fields'] ) {
					continue;
				}
			}
			$room = self::MAX_FIELDS - $count;
			if ( $room <= 0 ) {
				$cut = true;
				break;
			}
			if ( count( $group['fields'] ) > $room ) {
				$group['fields'] = array_slice( $group['fields'], 0, $room );
				$cut             = true;
			}
			$count   += count( $group['fields'] );
			$groups[] = $group;
		}
		$out = array(
			'format'      => 4,
			'slug'        => $slug,
			'name'        => $title,
			'groups'      => $groups,
			'field_count' => $count,
			'conventions' => array(
				'responsive' => __( 'A responsive field takes <name>_tablet and <name>_phone, with <name>_last_edited "on|tablet" (or "on|phone") so Divi reads them.', 'wproadie' ),
				'hover'      => __( 'A hover field takes <name>__hover with <name>__hover_enabled "on|hover".', 'wproadie' ),
				'on_off'     => __( 'yes_no_button fields take "on" or "off".', 'wproadie' ),
				'spacing'    => __( 'custom_margin and custom_padding are "top|right|bottom|left|linked|linked", e.g. "20px||20px||true|false".', 'wproadie' ),
				'font'       => __( 'A font field is "Family|weight|italic|uppercase|underline|capitalize|strikethrough|line-through|small-caps", e.g. "Barlow|700||on|||||".', 'wproadie' ),
			),
		);
		if ( $cut ) {
			$out['truncated'] = true;
			$out['note']      = __( 'Not every field fit; narrow down with toggle, search or include_advanced: false.', 'wproadie' );
		}
		return $out;
	}

	private function catalog_5() {
		if ( ! Schema::has_5() ) {
			return $this->error( 'not_divi_5', __( 'This site\'s Divi has no Divi 5 module registry.', 'wproadie' ), 404 );
		}
		$rows = Schema::catalog_5();
		return array(
			'format'  => 5,
			'modules' => $rows,
			'total'   => count( $rows ),
			'note'    => __( 'Call again with module (e.g. "divi/text") for the attribute paths one takes.', 'wproadie' ),
		);
	}

	private function describe_5( string $module ) {
		if ( ! Schema::has_5() ) {
			return $this->error( 'not_divi_5', __( 'This site\'s Divi has no Divi 5 module registry.', 'wproadie' ), 404 );
		}
		$name = false === strpos( $module, '/' ) ? 'divi/' . str_replace( '_', '-', $module ) : $module;
		$info = Schema::module_5( $name );
		if ( ! $info ) {
			return $this->error(
				'unknown_type',
				sprintf(
					/* translators: 1: module name, 2: comma-separated module names. */
					__( 'Divi 5 on this site has no module "%1$s". Modules include: %2$s.', 'wproadie' ),
					$name,
					implode( ', ', array_slice( array_column( Schema::catalog_5(), 'name' ), 0, 60 ) )
				),
				404
			);
		}
		$info['format']      = 5;
		$info['attributes']  = array_values( $info['attributes'] );
		$info['conventions'] = array(
			'shape'   => __( 'Values are responsive objects: write <path>.desktop.value (or .tablet / .phone). divi-add-module and divi-update-module take the dotted path as the key.', 'wproadie' ),
			'content' => __( 'A module\'s text or HTML is <group>.innerContent.desktop.value; content is shorthand for the content group.', 'wproadie' ),
			'styling' => __( 'Visual settings live under the style groups listed (e.g. module.decoration.background.desktop.value.color); copy their shape from an existing module with divi-get-module.', 'wproadie' ),
		);
		return $info;
	}
}
