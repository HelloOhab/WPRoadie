<?php
/**
 * Ability: get-skill (read one of WPRoadie's built-in skills).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Site;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Mode;
use WPRoadie\Skills\Skills;

defined( 'ABSPATH' ) || exit;

final class GetSkill extends AbilityBase {

	public function slug(): string {
		return 'get-skill';
	}

	public function label(): string {
		return __( 'Get skill', 'wproadie' );
	}

	public function description(): string {
		$lines = Skills::catalogue();
		return sprintf(
			/* translators: %s: one line per skill. */
			__( 'Reads one of WPRoadie\'s skills: step-by-step guides for doing a kind of job well on this site, written for the tools here. Read the matching skill before you start such a job. Skills on this site: %s', 'wproadie' ),
			$lines ? "\n- " . implode( "\n- ", $lines ) : __( '(none apply to this site)', 'wproadie' )
		);
	}

	public function group(): string {
		return 'site';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function is_available(): bool {
		return (bool) Skills::available();
	}

	public function unavailable_reason(): string {
		return __( 'No skill applies to this site (they cover page builders the site does not run).', 'wproadie' );
	}

	public function input_schema(): array {
		$names = array_keys( Skills::available() );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name' => array(
					'type'        => 'string',
					'enum'        => $names ? $names : array( 'none' ),
					'description' => __( 'The skill\'s name, from the list in this tool\'s description.', 'wproadie' ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'body'        => array(
					'type'        => 'string',
					'description' => __( 'The skill, as Markdown. Follow it.', 'wproadie' ),
				),
			),
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return true;
	}

	protected function execute( array $input ) {
		$skill = Skills::get( (string) $input['name'] );
		if ( ! $skill ) {
			return $this->error(
				'unknown_skill',
				sprintf(
					/* translators: %s: comma-separated skill names. */
					__( 'No such skill on this site. Available: %s.', 'wproadie' ),
					implode( ', ', array_keys( Skills::available() ) )
				),
				404
			);
		}
		return array(
			'name'        => $skill['slug'],
			'description' => $skill['description'],
			'body'        => $skill['body'],
		);
	}
}
