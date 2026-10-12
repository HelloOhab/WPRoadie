<?php
/**
 * Every attribute and the content of one Divi module.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Divi;

use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class GetModule extends DiviAbility {

	/**
	 * Content longer than this is cut in the result.
	 */
	const MAX_OUTPUT = 100000;

	public function slug(): string {
		return 'divi-get-module';
	}

	public function label(): string {
		return __( 'Get Divi module', 'wproadie' );
	}

	public function description(): string {
		return __( 'Returns everything about one Divi element (module, section, row or column), found by path, admin label or CSS ID from divi-get-page. Divi 4: attributes as name => value with Divi\'s escapes decoded (%22 shown as ", %91/%93 as [ ]), and content (the HTML or text between the tags, e.g. a Text module\'s body; may contain other plugins\' shortcodes). Divi 5: attributes as the block\'s JSON object; text lives at paths like content.innerContent.desktop.value, title.innerContent.desktop.value, button.innerContent.desktop.value.text / .linkUrl, image.innerContent.desktop.value.src / .alt, admin label at module.meta.adminLabel.desktop.value; tablet/phone/hover values sit beside desktop. Also lists children (e.g. tabs, slides) with their paths. Use this before divi-update-module.', 'wproadie' );
	}

	public function risk(): string {
		return Mode::RISK_READ;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array( 'id' => self::id_property() ),
				self::module_properties(),
				array(
					'raw' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also return the element\'s exact stored markup.', 'wproadie' ),
					),
				)
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	protected function execute( array $input ) {
		$loaded = Divi::load( (int) $input['id'] );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}
		$doc   = $loaded['doc'];
		$entry = $doc->find( $input );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$node = $entry['node'];

		$result = array_merge(
			array(
				'id'     => (int) $loaded['post']->ID,
				'format' => $doc->format,
			),
			$doc->describe( $entry )
		);
		unset( $result['summary'] );

		if ( 5 === $doc->format ) {
			$result['attributes'] = is_object( $node->attrs ) ? $node->attrs : new \stdClass();
			if ( $node->bad_attrs ) {
				$result['warning'] = __( 'This block\'s attributes are not valid JSON; it cannot be edited with these tools.', 'wproadie' );
			}
		} else {
			$attrs                = Shortcodes::decoded_attrs( $node );
			$result['attributes'] = $attrs ? $attrs : new \stdClass();
			if ( null !== $node->content ) {
				$content = $node->content;
				if ( strlen( $content ) > self::MAX_OUTPUT ) {
					$result['content_truncated'] = strlen( $content );
					$content                     = mb_strcut( $content, 0, self::MAX_OUTPUT );
				}
				$result['content'] = $content;
			}
		}

		$children = array();
		foreach ( $doc->all() as $other ) {
			if ( $other['parent'] === $node ) {
				$children[] = $doc->describe( $other );
			}
		}
		if ( $children ) {
			$result['children'] = $children;
		}
		if ( '' !== $entry['global'] ) {
			$result['global_note'] = sprintf(
				/* translators: %d: layout post ID. */
				__( 'Global: Divi shows Divi Library item %1$d here. Change that item (same tools, id %2$d) to change it everywhere.', 'wproadie' ),
				(int) $entry['global'],
				(int) $entry['global']
			);
		}
		if ( ! empty( $input['raw'] ) ) {
			$raw = $node->to_string();
			if ( strlen( $raw ) > self::MAX_OUTPUT ) {
				$result['raw_truncated'] = strlen( $raw );
				$raw                     = mb_strcut( $raw, 0, self::MAX_OUTPUT );
			}
			$result['raw'] = $raw;
		}
		return $result;
	}
}
