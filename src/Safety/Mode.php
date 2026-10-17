<?php
/**
 * Environment modes: what kind of change a site allows.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Safety;

defined( 'ABSPATH' ) || exit;

final class Mode {

	const STAGING    = 'staging';
	const PRODUCTION = 'production';
	const READ_ONLY  = 'read_only';

	/**
	 * Risk levels an ability can declare, from least to most dangerous.
	 * `maintain` is keeping what is already installed up to date: plugin
	 * and theme updates to versions WordPress offers. That is routine care,
	 * so live sites allow it (HQ queues it for a person's approval there);
	 * installs, removals and anything that runs arbitrary code stay
	 * `admin` or `dangerous`.
	 */
	const RISK_READ      = 'read';
	const RISK_WRITE     = 'write';
	const RISK_MAINTAIN  = 'maintain';
	const RISK_ADMIN     = 'admin';
	const RISK_DANGEROUS = 'dangerous';

	const RISKS = array( self::RISK_READ, self::RISK_WRITE, self::RISK_MAINTAIN, self::RISK_ADMIN, self::RISK_DANGEROUS );

	/**
	 * Which risk levels each mode allows.
	 */
	const ALLOWED_RISKS = array(
		self::STAGING    => array( self::RISK_READ, self::RISK_WRITE, self::RISK_MAINTAIN, self::RISK_ADMIN, self::RISK_DANGEROUS ),
		self::PRODUCTION => array( self::RISK_READ, self::RISK_WRITE, self::RISK_MAINTAIN ),
		self::READ_ONLY  => array( self::RISK_READ ),
	);

	public static function all(): array {
		return array( self::STAGING, self::PRODUCTION, self::READ_ONLY );
	}

	public static function label( string $mode ): string {
		switch ( $mode ) {
			case self::STAGING:
				return __( 'Staging', 'wproadie' );
			case self::PRODUCTION:
				return __( 'Production', 'wproadie' );
			case self::READ_ONLY:
				return __( 'Read-only', 'wproadie' );
		}
		return $mode;
	}

	/**
	 * The mode in effect. Precedence: WPROADIE_MODE constant, then the
	 * admin setting, then the environment type WordPress reports.
	 */
	public static function current(): string {
		if ( defined( 'WPROADIE_MODE' ) && in_array( WPROADIE_MODE, self::all(), true ) ) {
			return WPROADIE_MODE;
		}

		$override = Settings::get( 'mode' );
		if ( in_array( $override, self::all(), true ) ) {
			return $override;
		}

		return self::detect();
	}

	/**
	 * Maps wp_get_environment_type() to a mode. Anything that is not
	 * explicitly non-production is treated as production, which is also
	 * WordPress's own default when nothing is configured.
	 */
	public static function detect(): string {
		$type = wp_get_environment_type();
		if ( in_array( $type, array( 'staging', 'development', 'local' ), true ) ) {
			return self::STAGING;
		}
		return self::PRODUCTION;
	}

	/**
	 * Where the current mode came from, for display.
	 */
	public static function source(): string {
		if ( defined( 'WPROADIE_MODE' ) && in_array( WPROADIE_MODE, self::all(), true ) ) {
			return 'constant';
		}
		if ( in_array( Settings::get( 'mode' ), self::all(), true ) ) {
			return 'setting';
		}
		return 'environment';
	}

	public static function allows( string $risk, ?string $mode = null ): bool {
		$mode = $mode ?? self::current();
		return in_array( $risk, self::ALLOWED_RISKS[ $mode ] ?? array(), true );
	}
}
