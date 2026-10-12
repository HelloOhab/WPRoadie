<?php
/**
 * Switches the site's theme.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Log\Undo;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class ActivateTheme extends AbilityBase {

	public function slug(): string {
		return 'activate-theme';
	}

	public function label(): string {
		return __( 'Activate theme', 'wproadie' );
	}

	public function description(): string {
		return __( 'Switches the site to an installed theme, by stylesheet (the theme folder name, e.g. "twentytwentyfour"). The theme must be installed and valid; a child theme needs its parent installed. Switching themes changes the whole look of the site and can reset widgets and menu locations, so only do it when asked. Returns the new theme\'s stylesheet, name, version, template (parent) and previous_stylesheet, and an undo_id; pass it to undo-change to switch back.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_ADMIN;
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'stylesheet' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Theme folder name, e.g. "twentytwentyfour".', 'wproadie' ),
				),
			),
			'required'             => array( 'stylesheet' ),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'switch_themes' );
	}

	protected function execute( array $input ) {
		$stylesheet = (string) $input['stylesheet'];
		$theme      = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return $this->error( 'theme_not_found', __( 'No installed theme with that stylesheet (folder name).', 'wproadie' ), 404 );
		}
		if ( $theme->errors() ) {
			return $this->error(
				'theme_broken',
				sprintf(
					/* translators: %s: error message. */
					__( 'That theme cannot be activated: %s', 'wproadie' ),
					Installer::plain( $theme->errors()->get_error_message() )
				),
				400
			);
		}
		if ( ! $theme->is_allowed() ) {
			return $this->error( 'theme_not_allowed', __( 'That theme is not enabled for this site in the network admin.', 'wproadie' ), 403 );
		}

		$previous = get_stylesheet();
		if ( $previous === $theme->get_stylesheet() ) {
			$summary                        = Installer::theme_summary( $previous );
			$summary['previous_stylesheet'] = $previous;
			$summary['changed']             = false;
			return $summary;
		}

		$this->add_undo( Undo::theme_switch() );
		switch_theme( $theme->get_stylesheet() );

		$summary                        = Installer::theme_summary( $theme->get_stylesheet() );
		$summary['previous_stylesheet'] = $previous;
		$summary['changed']             = true;
		return $summary;
	}
}
