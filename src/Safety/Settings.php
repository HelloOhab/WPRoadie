<?php
/**
 * Stored settings: mode override, ability group switches, kill switch.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Safety;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'wproadie_settings';

	/**
	 * Ability groups, their risk and whether they are on by default.
	 * Mirrors the abilities catalog in the platform plan. A group whose
	 * abilities are not built yet is listed so the switches are stable.
	 */
	const GROUPS = array(
		'site'           => array(
			'risk'    => Mode::RISK_READ,
			'default' => true,
		),
		'content'        => array(
			'risk'    => Mode::RISK_WRITE,
			'default' => true,
		),
		'media'          => array(
			'risk'    => Mode::RISK_WRITE,
			'default' => true,
		),
		'navigation'     => array(
			'risk'    => Mode::RISK_WRITE,
			'default' => true,
		),
		// Offered only while Elementor is active; kit changes are admin risk.
		'elementor'      => array(
			'risk'    => Mode::RISK_WRITE,
			'default' => true,
		),
		'settings'       => array(
			'risk'    => Mode::RISK_WRITE,
			'default' => true,
		),
		'extensions'     => array(
			'risk'    => Mode::RISK_ADMIN,
			'default' => true,
		),
		'users'          => array(
			'risk'    => Mode::RISK_ADMIN,
			'default' => false,
		),
		'files'          => array(
			'risk'    => Mode::RISK_DANGEROUS,
			'default' => true,
		),
		'database'       => array(
			'risk'    => Mode::RISK_DANGEROUS,
			'default' => true,
		),
		// Separate switch for write queries, per the plan: reads on, writes off.
		'database_write' => array(
			'risk'    => Mode::RISK_DANGEROUS,
			'default' => false,
		),
		'cli'            => array(
			'risk'    => Mode::RISK_DANGEROUS,
			'default' => true,
		),
		'php'            => array(
			'risk'    => Mode::RISK_DANGEROUS,
			'default' => false,
		),
	);

	public static function group_label( string $group ): string {
		$labels = array(
			'site'           => __( 'Site info', 'wproadie' ),
			'content'        => __( 'Content', 'wproadie' ),
			'media'          => __( 'Media', 'wproadie' ),
			'navigation'     => __( 'Navigation', 'wproadie' ),
			'elementor'      => __( 'Elementor', 'wproadie' ),
			'settings'       => __( 'Settings', 'wproadie' ),
			'extensions'     => __( 'Plugins & themes', 'wproadie' ),
			'users'          => __( 'Users', 'wproadie' ),
			'files'          => __( 'Files', 'wproadie' ),
			'database'       => __( 'Database (read)', 'wproadie' ),
			'database_write' => __( 'Database (write)', 'wproadie' ),
			'cli'            => __( 'WP-CLI', 'wproadie' ),
			'php'            => __( 'PHP', 'wproadie' ),
		);
		return $labels[ $group ] ?? $group;
	}

	public static function defaults(): array {
		$groups = array();
		foreach ( self::GROUPS as $group => $config ) {
			$groups[ $group ] = $config['default'];
		}
		return array(
			'mode'       => '', // Empty means detect from the environment type.
			'groups'     => $groups,
			'disabled'   => false,
			'stealth'    => false,
			'rate_limit' => RateLimit::DEFAULT_PER_MINUTE,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( self::defaults(), $stored );

		$merged['groups'] = array_merge(
			self::defaults()['groups'],
			is_array( $merged['groups'] ) ? $merged['groups'] : array()
		);

		return $merged;
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Stores one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public static function set( string $key, $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all, false );
	}

	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public static function group_enabled( string $group ): bool {
		$groups = self::get( 'groups' );
		return ! empty( $groups[ $group ] );
	}

	public static function set_group( string $group, bool $enabled ): void {
		if ( ! isset( self::GROUPS[ $group ] ) ) {
			return;
		}
		$groups           = self::get( 'groups' );
		$groups[ $group ] = $enabled;
		self::set( 'groups', $groups );
	}

	/**
	 * The kill switch. The WPROADIE_DISABLED constant always wins, so a
	 * site can be shut off from wp-config.php even if the admin is broken.
	 */
	public static function is_disabled(): bool {
		if ( defined( 'WPROADIE_DISABLED' ) && WPROADIE_DISABLED ) {
			return true;
		}
		return (bool) self::get( 'disabled' );
	}

	public static function disabled_by_constant(): bool {
		return defined( 'WPROADIE_DISABLED' ) && WPROADIE_DISABLED;
	}
}
