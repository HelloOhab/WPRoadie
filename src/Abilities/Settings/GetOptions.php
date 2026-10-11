<?php
/**
 * Reads site settings from the option allow-list.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Settings;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Safety\Guardrails;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetOptions extends AbilityBase {

	public function slug(): string {
		return 'get-options';
	}

	public function label(): string {
		return __( 'Get options', 'wproadie' );
	}

	public function description(): string {
		return __( 'Reads site settings (WordPress options) such as blogname, timezone_string, show_on_front or permalink_structure. Pass names to read specific options, or omit them to read every option WPRoadie may read. Only options on WPRoadie\'s allow-list can be read; names that are not allowed or do not exist are listed in "unavailable" instead of failing the call. Also returns "writable": the options update-options may change.', 'wproadie' );
	}

	public function group(): string {
		return 'settings';
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'names' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Option names to read. Omit to read all readable options.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	protected function execute( array $input ) {
		$names = ! empty( $input['names'] ) ? array_values( array_unique( array_map( 'strval', $input['names'] ) ) ) : Guardrails::readable_options();

		$values      = array();
		$unavailable = array();
		$sentinel    = new \stdClass();
		foreach ( $names as $name ) {
			if ( ! Guardrails::can_read_option( $name ) ) {
				$unavailable[] = array(
					'name'   => $name,
					'reason' => 'not_allowed',
				);
				continue;
			}
			$value = get_option( $name, $sentinel );
			if ( $value === $sentinel ) {
				$unavailable[] = array(
					'name'   => $name,
					'reason' => 'not_set',
				);
				continue;
			}
			$values[ $name ] = $value;
		}

		return array(
			'options'     => $values ? $values : new \stdClass(),
			'unavailable' => $unavailable,
			'writable'    => Guardrails::writable_options(),
		);
	}
}
