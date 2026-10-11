<?php
/**
 * The catalog of WPRoadie abilities and which of them this site exposes.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities;

use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * Every ability WPRoadie ships, by class.
	 */
	const ABILITIES = array(
		Site\SiteOverview::class,
		Site\ActiveTheme::class,
		Site\ListPlugins::class,
		Site\Versions::class,
		Site\HealthCheck::class,
	);

	/**
	 * @var AbilityBase[]|null
	 */
	private $all = null;

	/**
	 * @return AbilityBase[]
	 */
	public function all(): array {
		if ( null === $this->all ) {
			$classes   = (array) apply_filters( 'wproadie_ability_classes', self::ABILITIES );
			$this->all = array();
			foreach ( $classes as $class ) {
				if ( is_string( $class ) && is_subclass_of( $class, AbilityBase::class ) ) {
					$this->all[] = new $class();
				}
			}
		}
		return $this->all;
	}

	/**
	 * Abilities this site exposes: their group is switched on and the
	 * current mode allows their risk level. Anything else is never
	 * registered, so the AI never sees it.
	 *
	 * @return AbilityBase[]
	 */
	public function enabled(): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( AbilityBase $ability ) {
					return Settings::group_enabled( $ability->group() ) && Mode::allows( $ability->risk() );
				}
			)
		);
	}

	/**
	 * @return string[] Registered ability names, e.g. "wproadie/site-overview".
	 */
	public function enabled_names(): array {
		return array_map(
			static function ( AbilityBase $ability ) {
				return $ability->name();
			},
			$this->enabled()
		);
	}

	public static function category( string $group ): string {
		return 'wproadie-' . $group;
	}

	/**
	 * Hooked to wp_abilities_api_categories_init.
	 */
	public function register_categories(): void {
		$groups = array_unique(
			array_map(
				static function ( AbilityBase $ability ) {
					return $ability->group();
				},
				$this->enabled()
			)
		);

		foreach ( $groups as $group ) {
			wp_register_ability_category(
				self::category( $group ),
				array(
					/* translators: %s: ability group, e.g. "Site info". */
					'label'       => sprintf( __( 'WPRoadie: %s', 'wproadie' ), Settings::group_label( $group ) ),
					/* translators: %s: ability group, e.g. "Site info". */
					'description' => sprintf( __( 'WPRoadie abilities for %s.', 'wproadie' ), Settings::group_label( $group ) ),
				)
			);
		}
	}

	/**
	 * Hooked to wp_abilities_api_init.
	 */
	public function register_abilities(): void {
		foreach ( $this->enabled() as $ability ) {
			$ability->register( self::category( $ability->group() ) );
		}
	}
}
