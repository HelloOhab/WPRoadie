<?php
/**
 * Base class for every WPRoadie ability: schema, permission and logging.
 *
 * Subclasses describe the ability and implement execute(). The base class
 * registers it with the Abilities API and wraps the callbacks so every
 * ability gets the same access check, rate limit, undo capture and audit
 * log entry.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities;

use Throwable;
use WP_Error;
use WPRoadie\Log\AuditLog;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\RateLimit;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

abstract class AbilityBase {

	const NAMESPACE_PREFIX = 'wproadie/';

	/**
	 * Undo records collected during the current call. See add_undo().
	 *
	 * @var array[]
	 */
	private $undo = array();

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
	 * permission check. Return a WP_Error to fail.
	 *
	 * @param array $input Validated input.
	 * @return mixed|WP_Error
	 */
	abstract protected function execute( array $input );

	public function name(): string {
		return self::NAMESPACE_PREFIX . $this->slug();
	}

	/**
	 * How the MCP server exposes the ability: "tool" or "prompt".
	 */
	public function mcp_type(): string {
		return 'tool';
	}

	/**
	 * Whether this host can run the ability at all, e.g. the WP-CLI tool
	 * needs proc_open. Unavailable abilities are not registered.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Why is_available() is false, for the admin screen.
	 */
	public function unavailable_reason(): string {
		return '';
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
	 * MCP hints. Tool defaults follow from the risk level; prompts take
	 * none, since tool hints are invalid on a prompt.
	 */
	protected function annotations(): array {
		if ( 'tool' !== $this->mcp_type() ) {
			return array();
		}
		$read_only = Mode::RISK_READ === $this->risk();
		return array(
			'readonly'      => $read_only,
			'destructive'   => ! $read_only,
			'idempotent'    => $read_only,
			'openWorldHint' => false,
		);
	}

	/**
	 * Records how to reverse a change. Call it before changing anything
	 * (or right after creating something) with a record from
	 * WPRoadie\Log\Undo. Records are stored with the log entry and replayed
	 * in reverse order by Undo::revert().
	 *
	 * @param array|null $record Undo record, or null to skip.
	 */
	protected function add_undo( ?array $record ): void {
		if ( $record ) {
			$this->undo[] = $record;
		}
	}

	/**
	 * Runs the ability's work directly, without the permission check, rate
	 * limit or log entry. For composing abilities inside another one that
	 * has already passed those checks (e.g. a prompt gathering site data).
	 *
	 * @param array $input Input, assumed valid.
	 * @return mixed|WP_Error
	 */
	public function run_internal( array $input = array() ) {
		return $this->execute( $input );
	}

	/**
	 * Shorthand for a WP_Error with an HTTP-style status.
	 */
	protected function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'wproadie_' . $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Registers the ability with the Abilities API. Called during
	 * wp_abilities_api_init.
	 */
	public function register( string $category ): void {
		$meta = array(
			// Served by the WPRoadie MCP server only. Not public, so the
			// adapter's default server and other clients never list it.
			'mcp'      => array(
				'public' => false,
				'type'   => $this->mcp_type(),
			),
			'wproadie' => array(
				'group' => $this->group(),
				'risk'  => $this->risk(),
			),
		);

		$annotations = $this->annotations();
		if ( $annotations ) {
			$meta['annotations'] = $annotations;
		}

		$args = array(
			'label'               => $this->label(),
			'description'         => $this->description(),
			'category'            => $category,
			'input_schema'        => $this->input_schema(),
			'execute_callback'    => array( $this, 'handle_execute' ),
			'permission_callback' => array( $this, 'handle_permission' ),
			'meta'                => $meta,
		);

		$output_schema = $this->output_schema();
		if ( $output_schema ) {
			$args['output_schema'] = $output_schema;
		}

		wp_register_ability( $this->name(), $args );
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
	 * Execute callback handed to the Abilities API. Applies the rate limit,
	 * times the call, turns exceptions into errors and writes the audit log
	 * entry with any undo records.
	 *
	 * @param mixed $input Input as validated by the Abilities API.
	 * @return mixed|WP_Error
	 */
	public function handle_execute( $input = null ) {
		$input      = is_array( $input ) ? $input : array();
		$started    = microtime( true );
		$this->undo = array();

		$limited = RateLimit::hit( get_current_user_id() );
		if ( is_wp_error( $limited ) ) {
			$result = $limited;
		} else {
			try {
				$result = $this->execute( $input );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'wproadie_exception', $e->getMessage() );
			}
		}

		// A failed call changed nothing we can vouch for; keep its undo
		// records anyway, since a partial change may need reverting.
		$log_id = AuditLog::record(
			$this->name(),
			$input,
			$result,
			(int) round( ( microtime( true ) - $started ) * 1000 ),
			$this->undo
		);

		if ( $this->undo && $log_id && is_array( $result ) && ! wp_is_numeric_array( $result ) ) {
			// Tell the AI how to reverse what it just did.
			$result['undo_id'] = $log_id;
		}
		$this->undo = array();

		return $result;
	}
}
