<?php
/**
 * The catalog of WPRoadie abilities and which of them this site exposes.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities;

use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings as SafetySettings;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * Every ability WPRoadie ships, by class. Nothing reaches an AI client
	 * without being listed here (or added with the wproadie_ability_classes
	 * filter), which keeps the attack surface reviewable in one place.
	 */
	const ABILITIES = array(
		// Site info (read).
		Site\SiteOverview::class,
		Site\ActiveTheme::class,
		Site\ListPlugins::class,
		Site\Versions::class,
		Site\HealthCheck::class,
		Site\ActivityLog::class,

		// Prompts (read).
		\WPRoadie\Prompts\SiteBriefing::class,
		\WPRoadie\Prompts\CareChecklist::class,

		// Content (write).
		Content\ListPosts::class,
		Content\GetPost::class,
		Content\CreatePost::class,
		Content\UpdatePost::class,
		Content\TrashPost::class,
		Content\GetPostMeta::class,
		Content\UpdatePostMeta::class,
		Content\ListTerms::class,
		Content\CreateTerm::class,
		Content\SetPostTerms::class,
		Content\UndoChange::class,

		// Media (write).
		Media\ListMedia::class,
		Media\UploadMediaFromUrl::class,
		Media\SetFeaturedImage::class,
		Media\UpdateMediaDetails::class,

		// Navigation (write).
		Navigation\ListMenus::class,
		Navigation\GetMenu::class,
		Navigation\CreateMenu::class,
		Navigation\AddMenuItem::class,
		Navigation\UpdateMenuItem::class,
		Navigation\DeleteMenuItem::class,
		Navigation\AssignMenuLocation::class,
		Navigation\ListWidgets::class,
		Navigation\AddBlockWidget::class,
		Navigation\RemoveWidget::class,

		// Elementor (write).
		Elementor\ListPages::class,
		Elementor\GetPage::class,
		Elementor\GetElement::class,
		Elementor\UpdateElement::class,
		Elementor\AddElement::class,
		Elementor\RemoveElement::class,
		Elementor\MoveElement::class,
		Elementor\DuplicateElement::class,
		Elementor\ReplaceText::class,
		Elementor\ListTemplates::class,
		Elementor\InsertTemplate::class,
		Elementor\GetKit::class,
		Elementor\UpdateKit::class,
		Elementor\RegenerateCss::class,

		// Divi (write).
		Divi\ListPages::class,
		Divi\GetPage::class,
		Divi\GetModule::class,
		Divi\UpdateModule::class,
		Divi\AddModule::class,
		Divi\RemoveModule::class,
		Divi\MoveModule::class,
		Divi\DuplicateModule::class,
		Divi\ReplaceText::class,
		Divi\ListLayouts::class,
		Divi\InsertLayout::class,
		Divi\ListThemeBuilder::class,
		Divi\ClearCache::class,

		// Settings (write).
		Settings\GetOptions::class,
		Settings\UpdateOptions::class,

		// Plugins and themes (admin).
		Extensions\InstallPlugin::class,
		Extensions\ActivatePlugin::class,
		Extensions\DeactivatePlugin::class,
		Extensions\UpdatePlugin::class,
		Extensions\InstallTheme::class,
		Extensions\ActivateTheme::class,
		Extensions\UpdateTheme::class,
		Extensions\ApplyUpdates::class,

		// Users (admin).
		Users\ListUsers::class,
		Users\CreateUser::class,
		Users\ChangeUserRole::class,

		// Files (dangerous).
		Files\ListFiles::class,
		Files\ReadFile::class,
		Files\WriteFile::class,
		Files\DeleteFile::class,

		// Database (dangerous; writes behind their own switch).
		Database\ListTables::class,
		Database\RunQuery::class,
		Database\RunWriteQuery::class,

		// WP-CLI and PHP (dangerous).
		Cli\RunWpCli::class,
		Php\RunPhp::class,
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
					return SafetySettings::group_enabled( $ability->group() ) && Mode::allows( $ability->risk() ) && $ability->is_available();
				}
			)
		);
	}

	/**
	 * Looks up a shipped ability by its full name, enabled or not.
	 */
	public function find( string $name ): ?AbilityBase {
		foreach ( $this->all() as $ability ) {
			if ( $ability->name() === $name ) {
				return $ability;
			}
		}
		return null;
	}

	/**
	 * Enabled ability names exposed as one MCP type: "tool" or "prompt".
	 *
	 * @return string[]
	 */
	public function enabled_names_of_type( string $type ): array {
		$names = array();
		foreach ( $this->enabled() as $ability ) {
			if ( $ability->mcp_type() === $type ) {
				$names[] = $ability->name();
			}
		}
		return $names;
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

	/**
	 * Ability category slug for a group. Category slugs allow only
	 * lowercase letters, digits and dashes.
	 */
	public static function category( string $group ): string {
		return 'wproadie-' . str_replace( '_', '-', $group );
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
					'label'       => sprintf( __( 'WPRoadie: %s', 'wproadie' ), SafetySettings::group_label( $group ) ),
					/* translators: %s: ability group, e.g. "Site info". */
					'description' => sprintf( __( 'WPRoadie abilities for %s.', 'wproadie' ), SafetySettings::group_label( $group ) ),
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
