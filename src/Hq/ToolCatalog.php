<?php
/**
 * The MCP tools this site exposes, shaped as the site's own MCP
 * `tools/list` returns them, for HQ's `list_tools` command and the
 * heartbeat's `tools_hash` (docs/hq-protocol.md).
 *
 * The MCP Adapter builds each tool from the registered ability: the name
 * is sanitized (`wproadie/site-overview` becomes `wproadie-site-overview`),
 * the input schema is normalized to an object schema, and the ability's
 * annotations are mapped to MCP hints. This class uses the adapter's own
 * helpers for that when they are loaded, and the same rules otherwise.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Hq;

use stdClass;
use WPRoadie\Abilities\Registry;
use WPRoadie\Safety\Settings;

defined( 'ABSPATH' ) || exit;

final class ToolCatalog {

	/**
	 * The catalog: tools currently exposed, the hash and the kill switch.
	 *
	 * @return array{tools_hash: string, disabled: bool, tools: array[]}
	 */
	public static function get(): array {
		$tools = self::tools();
		return array(
			'tools_hash' => self::hash( $tools ),
			'disabled'   => Settings::is_disabled(),
			'tools'      => $tools,
		);
	}

	/**
	 * Lowercase hex SHA-256 of the tools array as wp_json_encode() writes
	 * it with default flags (slashes escaped, non-ASCII as \u escapes).
	 *
	 * @param array[] $tools Tools from tools().
	 */
	public static function hash( array $tools ): string {
		return hash( 'sha256', (string) wp_json_encode( $tools ) );
	}

	/**
	 * Every tool the WPRoadie MCP server lists right now, in the order it
	 * lists them. Empty with the kill switch on, since nothing is exposed.
	 *
	 * @return array[]
	 */
	public static function tools(): array {
		if ( Settings::is_disabled() || ! function_exists( 'wp_get_ability' ) ) {
			return array();
		}

		$tools = array();
		foreach ( ( new Registry() )->enabled() as $base ) {
			if ( 'tool' !== $base->mcp_type() ) {
				continue;
			}
			// What the adapter sees: the registered ability. One that is not
			// registered (yet) is not listed by the MCP server either.
			$ability = wp_get_ability( $base->name() );
			if ( ! $ability ) {
				continue;
			}
			$name = self::tool_name( $ability );
			if ( null === $name ) {
				continue;
			}

			$tools[] = array(
				'name'        => $name,
				'title'       => (string) $ability->get_label(),
				'description' => (string) $ability->get_description(),
				'inputSchema' => self::input_schema( (array) $ability->get_input_schema() ),
				'annotations' => self::annotations( $ability ),
				'risk'        => $base->risk(),
			);
		}
		return $tools;
	}

	/**
	 * The MCP tool name for an ability, or null if the adapter could not
	 * derive one (it then skips the tool).
	 *
	 * @param \WP_Ability $ability Registered ability.
	 */
	public static function tool_name( $ability ): ?string {
		$raw = (string) $ability->get_name();
		if ( class_exists( '\WP\MCP\Domain\Utils\McpNameSanitizer' ) ) {
			$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $raw );
			if ( is_wp_error( $name ) ) {
				return null;
			}
			/** This filter is documented in the MCP Adapter (RegisterAbilityAsMcpTool). */
			$name = apply_filters( 'mcp_adapter_tool_name', $name, $ability ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The adapter's own hook, applied the way it applies it.
			return is_string( $name ) && '' !== $name ? $name : null;
		}
		return str_replace( '/', '-', trim( $raw ) );
	}

	/**
	 * The input schema as an MCP object schema, with `properties` always
	 * present as a JSON object.
	 *
	 * @param array $schema Ability input schema.
	 * @return array
	 */
	private static function input_schema( array $schema ): array {
		if ( class_exists( '\WP\MCP\Domain\Utils\SchemaTransformer' ) ) {
			$schema = \WP\MCP\Domain\Utils\SchemaTransformer::transform_to_object_schema( $schema )['schema'];
		} else {
			$schema = json_decode( (string) wp_json_encode( $schema ), true );
			$schema = is_array( $schema ) ? $schema : array();
			if ( isset( $schema['properties'] ) && array() === $schema['properties'] ) {
				unset( $schema['properties'] );
			}
			if ( ! isset( $schema['type'] ) ) {
				$schema['type'] = 'object';
			} elseif ( 'object' !== $schema['type'] ) {
				$schema = array(
					'type'       => 'object',
					'properties' => array( 'input' => $schema ),
					'required'   => array( 'input' ),
				);
			}
		}
		return $schema + array( 'properties' => new stdClass() );
	}

	/**
	 * MCP tool hints from the ability's annotations, with the title the
	 * adapter adds. An empty object when the ability has none.
	 *
	 * @param \WP_Ability $ability Registered ability.
	 * @return array|stdClass
	 */
	private static function annotations( $ability ) {
		$meta = (array) $ability->get_meta();
		$raw  = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! $raw ) {
			return new stdClass();
		}

		if ( class_exists( '\WP\MCP\Domain\Utils\McpAnnotationMapper' ) ) {
			$mapped = \WP\MCP\Domain\Utils\McpAnnotationMapper::map( $raw, 'tool' );
		} else {
			$mapped = array();
			$fields = array(
				'readOnlyHint'    => 'readonly',
				'destructiveHint' => 'destructive',
				'idempotentHint'  => 'idempotent',
				'openWorldHint'   => null,
				'title'           => null,
			);
			foreach ( $fields as $field => $property ) {
				if ( null !== $property && isset( $raw[ $property ] ) ) {
					$mapped[ $field ] = $raw[ $property ];
				} elseif ( isset( $raw[ $field ] ) ) {
					$mapped[ $field ] = $raw[ $field ];
				}
			}
		}

		if ( ! $mapped ) {
			return new stdClass();
		}
		if ( ! isset( $mapped['title'] ) ) {
			$mapped['title'] = (string) $ability->get_label();
		}
		return $mapped;
	}
}
