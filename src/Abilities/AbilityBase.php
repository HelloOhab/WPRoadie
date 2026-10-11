<?php
/**
 * Base class for every WPRoadie ability: schema, permission and logging.
 *
 * Subclasses describe the ability and implement execute(). The base class
 * registers it with the Abilities API and wraps the callbacks so every
 * ability gets the same access check and audit log entry.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities;

use Throwable;
use WP_Error;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Mode;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

abstract class AbilityBase {

	const NAMESPACE_PREFIX = 'wproadie/';

	/**
	 * Short name, without the namespace, e.g. "site-overview".
	 */
	abstract public function slug(): string;

	abstract public function label(): string;

	/**
	 * What the ability does, written for the AI: when to use it and what it
	 * returns.
	 */
	abstract public function description(): string;

	/**
	 * Group key from Settings::GROUPS.
	 */
	abstract public function group(): string;

	/**
	 * Risk level from Mode::RISK_*. Decides which modes may register it.
	 */
	abstract public function risk(): string;

	/**
	 * Does the work. Input has already passed schema validation and the
	 * permission check.
	 *
	 * @param array $input Validated input.
	 * @return mixed|WP_Error
	 */
	abstract protected function execute( array $input );

	public function name(): string {
		return self::NAMESPACE_PREFIX . $this->slug();
	}

	/**
	 * JSON Schema for the input. Always an object so MCP clients can send
	 * named arguments; abilities with no arguments keep the empty default.
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Extra, ability-specific permission check on top of the WPRoadie
	 * connect check, e.g. edit_post for a specific post.
	 *
	 * @param array $input Validated input.
	 */
	protected function permission( array $input ): bool {
		unset( $input );
		return true;
	}

	/**
	 * MCP tool hints. Defaults follow from the risk level.
	 */
	protected function annotations(): array {
		$read_only = Mode::RISK_READ === $this->risk();
		return array(
			'readonly'      => $read_only,
			'destructive'   => ! $read_only,
			'idempotent'    => $read_only,
			'openWorldHint' => false,
		);
	}

	/**
	 * Registers the ability with the Abilities API. Called during
	 * wp_abilities_api_init.
	 */
	public function register( string $category ): void {
		wp_register_ability(
			$this->name(),
			array(
				'label'               => $this->label(),
				'description'         => $this->description(),
				'category'            => $category,
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'handle_execute' ),
				'permission_callback' => array( $this, 'handle_permission' ),
				'meta'                => array(
					'annotations' => $this->annotations(),
					// Served by the WPRoadie MCP server only. Not public, so the
					// adapter's default server and other clients never list it.
					'mcp'         => array(
						'public' => false,
						'type'   => 'tool',
					),
					'wproadie'    => array(
						'group' => $this->group(),
						'risk'  => $this->risk(),
					),
				),
			)
		);
	}

	/**
	 * Permission callback handed to the Abilities API. Runs for every
	 * transport, including STDIO, which has no transport-level check.
	 *
	 * @param mixed $input Input as validated by the Abilities API.
	 */
	public function handle_permission( $input = null ): bool {
		if ( ! Permission::can_connect() ) {
			return false;
		}
		// The mode can change after registration (e.g. in a long-running
		// STDIO session), so check it again on every call.
		if ( ! Mode::allows( $this->risk() ) ) {
			return false;
		}
		return $this->permission( is_array( $input ) ? $input : array() );
	}

	/**
	 * Execute callback handed to the Abilities API. Times the call, turns
	 * exceptions into errors and writes the audit log entry.
	 *
	 * @param mixed $input Input as validated by the Abilities API.
	 * @return mixed|WP_Error
	 */
	public function handle_execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$started = microtime( true );

		try {
			$result = $this->execute( $input );
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'wproadie_exception', $e->getMessage() );
		}

		AuditLog::record( $this->name(), $input, $result, (int) round( ( microtime( true ) - $started ) * 1000 ) );

		return $result;
	}
}
