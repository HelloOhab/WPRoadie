<?php
/**
 * Skills: step-by-step guides the AI reads before a kind of job, shipped
 * with the plugin as SKILL.md files (the Agent Skills format: YAML front
 * matter with name and description, then the body). The catalogue is a
 * line per skill in the get-skill tool's description and in the site
 * briefing; the body is fetched on demand, so it costs nothing until needed.
 *
 * A skill's `requires` line names what the site must run for the skill to
 * apply (divi, elementor); a skill with no requirement applies everywhere.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Skills;

use WPRoadie\Abilities\Divi\Divi;

defined( 'ABSPATH' ) || exit;

final class Skills {

	const DIR = __DIR__ . '/library';

	/**
	 * Every skill the plugin ships, by slug.
	 *
	 * @return array<string, array{slug: string, name: string, description: string, requires: string, body: string}>
	 */
	public static function all(): array {
		static $skills = null;
		if ( null !== $skills ) {
			return $skills;
		}
		$skills = array();
		$files  = glob( self::DIR . '/*/SKILL.md' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$slug = basename( dirname( $file ) );
			$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin's own file.
			if ( false === $raw ) {
				continue;
			}
			$parsed = self::parse( $raw );
			if ( '' === $parsed['description'] ) {
				continue;
			}
			$skills[ $slug ] = array(
				'slug'        => $slug,
				'name'        => '' !== $parsed['name'] ? $parsed['name'] : $slug,
				'description' => $parsed['description'],
				'requires'    => $parsed['requires'],
				'body'        => $parsed['body'],
			);
		}
		ksort( $skills );
		return $skills;
	}

	/**
	 * The skills that apply to this site.
	 *
	 * @return array<string, array{slug: string, name: string, description: string, requires: string, body: string}>
	 */
	public static function available(): array {
		return array_filter(
			self::all(),
			static function ( array $skill ): bool {
				return self::requirement_met( $skill['requires'] );
			}
		);
	}

	/**
	 * One available skill, or null.
	 *
	 * @return array{slug: string, name: string, description: string, requires: string, body: string}|null
	 */
	public static function get( string $slug ): ?array {
		return self::available()[ $slug ] ?? null;
	}

	/** One line per available skill, for tool descriptions and briefings. */
	public static function catalogue(): array {
		$lines = array();
		foreach ( self::available() as $skill ) {
			$lines[] = $skill['slug'] . ': ' . $skill['description'];
		}
		return $lines;
	}

	private static function requirement_met( string $requires ): bool {
		switch ( $requires ) {
			case '':
				return true;
			case 'divi':
				return Divi::is_active();
			case 'elementor':
				return defined( 'ELEMENTOR_VERSION' );
		}
		return false;
	}

	/**
	 * Splits YAML-ish front matter (name, description, requires) from the body.
	 *
	 * @return array{name: string, description: string, requires: string, body: string}
	 */
	private static function parse( string $raw ): array {
		$out = array(
			'name'        => '',
			'description' => '',
			'requires'    => '',
			'body'        => trim( $raw ),
		);
		if ( ! preg_match( '/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $m ) ) {
			return $out;
		}
		$out['body'] = trim( $m[2] );
		foreach ( preg_split( '/\R/', $m[1] ) as $line ) {
			if ( preg_match( '/^(name|description|requires):\s*(.*)$/', $line, $kv ) ) {
				$out[ $kv[1] ] = trim( $kv[2], " \t\"'" );
			}
		}
		return $out;
	}
}
