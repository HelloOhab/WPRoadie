<?php
/**
 * Shared base for the Forms (WPForms) abilities.
 *
 * Abstract, so the Registry never lists it as an ability.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Forms;

use WPRoadie\Abilities\AbilityBase;

defined( 'ABSPATH' ) || exit;

abstract class FormsAbility extends AbilityBase {

	public function group(): string {
		return 'forms';
	}

	public function is_available(): bool {
		return WPForms::is_active();
	}

	public function unavailable_reason(): string {
		return WPForms::unavailable_reason();
	}

	/**
	 * WPForms Lite maps every right to manage_options; Pro has its own
	 * capabilities, checked through its helper when present.
	 */
	protected static function can( string $cap, int $id = 0 ): bool {
		if ( function_exists( 'wpforms_current_user_can' ) ) {
			return (bool) wpforms_current_user_can( $cap, $id );
		}
		return current_user_can( 'manage_options' );
	}

	protected function permission( array $input ): bool {
		$id = (int) ( $input['id'] ?? $input['form_id'] ?? 0 );
		if ( $id && ! get_post( $id ) ) {
			return true;
		}
		return self::can( static::CAP, $id );
	}

	/** The WPForms capability this ability needs (a category or meta cap). */
	const CAP = 'view_forms';
}
