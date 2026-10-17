<?php
/**
 * Pending plugin and theme updates, including the ones premium plugins
 * only announce inside wp-admin.
 *
 * WordPress keeps pending updates in the `update_plugins` and
 * `update_themes` site transients. Updates from WordPress.org land there
 * in any context, but many premium plugins (WPForms, Divi add-ons,
 * Smash Balloon, The Events Calendar add-ons, …) load their updater only
 * in wp-admin, so their updates appear while someone is logged in and
 * vanish again when cron rebuilds the transient. WPRoadie's heartbeat and
 * tools run from cron and REST requests, where those updates are absent,
 * so HQ and the AI would under-count them.
 *
 * This class remembers every update WordPress has shown and reports the
 * union: what the transient says now, plus remembered updates for
 * plugins and themes still on the version they were seen on.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Safety;

defined( 'ABSPATH' ) || exit;

final class PendingUpdates {

	const OPTION = 'wproadie_updates_seen';

	/** Forget an update not re-announced within this long. */
	const MAX_AGE = 60 * DAY_IN_SECONDS;

	/** Fields of an update offer worth remembering. */
	const FIELDS = array( 'new_version', 'package', 'slug', 'url', 'tested', 'requires', 'requires_php' );

	public function hooks(): void {
		add_action( 'set_site_transient_update_plugins', array( self::class, 'remember' ) );
		add_action( 'set_site_transient_update_themes', array( self::class, 'remember' ) );
		// Some updaters inject their offers when the transient is read, not
		// when it is written: look at what wp-admin sees on each visit.
		add_action( 'admin_init', array( self::class, 'remember' ), 999 );
	}

	/**
	 * Pending plugin updates: `file => offer`, merged from the transient and
	 * what wp-admin has shown.
	 *
	 * @return array<string, object>
	 */
	public static function plugins(): array {
		$data = get_site_transient( 'update_plugins' );
		return self::add_remembered_plugins( ( is_object( $data ) && ! empty( $data->response ) ) ? (array) $data->response : array() );
	}

	/**
	 * Pending theme updates: `stylesheet => offer` (as arrays, like WordPress).
	 *
	 * @return array<string, array>
	 */
	public static function themes(): array {
		$data = get_site_transient( 'update_themes' );
		return self::add_remembered_themes( ( is_object( $data ) && ! empty( $data->response ) ) ? (array) $data->response : array() );
	}

	/**
	 * Runs $step with remembered updates merged into the transients, so
	 * WordPress's upgrader can find a premium plugin's package outside
	 * wp-admin. Scoped to the call: WordPress's own auto-updates never see
	 * the remembered offers.
	 *
	 * @param callable $step
	 * @return mixed What $step returns.
	 */
	public static function with_remembered( callable $step ) {
		$plugins = static function ( $value ) {
			return self::merge_into( $value, array( self::class, 'add_remembered_plugins' ) );
		};
		$themes  = static function ( $value ) {
			return self::merge_into( $value, array( self::class, 'add_remembered_themes' ) );
		};
		add_filter( 'site_transient_update_plugins', $plugins, 1000 );
		add_filter( 'site_transient_update_themes', $themes, 1000 );
		try {
			return $step();
		} finally {
			remove_filter( 'site_transient_update_plugins', $plugins, 1000 );
			remove_filter( 'site_transient_update_themes', $themes, 1000 );
		}
	}

	/**
	 * Adds remembered plugin updates to a transient's `response`.
	 *
	 * @param array<string, object|array> $offers `file => offer`.
	 * @return array<string, object>
	 */
	public static function add_remembered_plugins( array $offers ): array {
		$offers  = array_map( array( self::class, 'as_object' ), $offers );
		$current = self::plugin_versions();
		foreach ( self::seen()['plugins'] as $file => $seen ) {
			if ( ! isset( $offers[ $file ] ) && self::still_pending( $current[ $file ] ?? null, $seen ) ) {
				$offers[ $file ] = self::offer( $seen, array( 'plugin' => $file ) );
			}
		}
		return $offers;
	}

	/**
	 * Adds remembered theme updates to a transient's `response`.
	 *
	 * @param array<string, array|object> $offers `stylesheet => offer`.
	 * @return array<string, array>
	 */
	public static function add_remembered_themes( array $offers ): array {
		$offers = array_map(
			static function ( $offer ) {
				return (array) $offer;
			},
			$offers
		);
		foreach ( self::seen()['themes'] as $stylesheet => $seen ) {
			if ( isset( $offers[ $stylesheet ] ) ) {
				continue;
			}
			$theme = wp_get_theme( $stylesheet );
			if ( $theme->exists() && self::still_pending( (string) $theme->get( 'Version' ), $seen ) ) {
				$offers[ $stylesheet ] = (array) self::offer( $seen, array( 'theme' => $stylesheet ) );
			}
		}
		return $offers;
	}

	/**
	 * Records what WordPress currently shows as pending (hooked to the
	 * transients being set, and to wp-admin visits). Drops remembered
	 * updates that were installed, whose plugin or theme is gone, or that
	 * have not been re-announced for a long time. Asks HQ's heartbeat to
	 * go out soon when the picture changed.
	 */
	public static function remember(): void {
		$before = self::seen();
		$after  = self::snapshot( $before );
		if ( self::keys( $after ) === self::keys( $before ) ) {
			return;
		}
		update_option( self::OPTION, $after, false );
		if ( class_exists( \WPRoadie\Hq\Heartbeat::class ) ) {
			\WPRoadie\Hq\Heartbeat::send_soon();
		}
	}

	/** Forgets everything remembered (tests, uninstall). */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array{plugins: array<string, array>, themes: array<string, array>}
	 */
	private static function seen(): array {
		$seen = get_option( self::OPTION );
		return array(
			'plugins' => is_array( $seen['plugins'] ?? null ) ? $seen['plugins'] : array(),
			'themes'  => is_array( $seen['themes'] ?? null ) ? $seen['themes'] : array(),
		);
	}

	private static function snapshot( array $seen ): array {
		$now     = time();
		$current = self::plugin_versions();

		$plugin_data = get_site_transient( 'update_plugins' );
		if ( is_object( $plugin_data ) && ! empty( $plugin_data->response ) ) {
			foreach ( (array) $plugin_data->response as $file => $offer ) {
				if ( isset( $current[ $file ] ) ) {
					$seen['plugins'][ $file ] = self::fields( $offer, $now );
				}
			}
		}
		foreach ( $seen['plugins'] as $file => $entry ) {
			if ( ! isset( $current[ $file ] ) || ! self::still_pending( $current[ $file ], $entry ) || self::stale( $entry, $now ) ) {
				unset( $seen['plugins'][ $file ] );
			}
		}

		$theme_data = get_site_transient( 'update_themes' );
		if ( is_object( $theme_data ) && ! empty( $theme_data->response ) ) {
			foreach ( (array) $theme_data->response as $stylesheet => $offer ) {
				if ( wp_get_theme( $stylesheet )->exists() ) {
					$seen['themes'][ $stylesheet ] = self::fields( $offer, $now );
				}
			}
		}
		foreach ( $seen['themes'] as $stylesheet => $entry ) {
			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() || ! self::still_pending( (string) $theme->get( 'Version' ), $entry ) || self::stale( $entry, $now ) ) {
				unset( $seen['themes'][ $stylesheet ] );
			}
		}
		return $seen;
	}

	/**
	 * Installed plugins' versions, `file => version`.
	 *
	 * @return array<string, string>
	 */
	private static function plugin_versions(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$versions = array();
		foreach ( get_plugins() as $file => $data ) {
			$versions[ $file ] = (string) $data['Version'];
		}
		return $versions;
	}

	private static function fields( $offer, int $now ): array {
		$offer = (array) $offer;
		$entry = array( 'seen_at' => $now );
		foreach ( self::FIELDS as $field ) {
			if ( isset( $offer[ $field ] ) && is_scalar( $offer[ $field ] ) ) {
				$entry[ $field ] = (string) $offer[ $field ];
			}
		}
		return $entry;
	}

	private static function still_pending( ?string $installed, array $entry ): bool {
		if ( null === $installed || empty( $entry['new_version'] ) ) {
			return false;
		}
		return version_compare( $installed, (string) $entry['new_version'], '<' );
	}

	private static function stale( array $entry, int $now ): bool {
		return $now - (int) ( $entry['seen_at'] ?? 0 ) > self::MAX_AGE;
	}

	private static function offer( array $entry, array $extra ): object {
		unset( $entry['seen_at'] );
		return (object) ( $extra + $entry + array( 'remembered' => true ) );
	}

	private static function as_object( $offer ): object {
		return is_object( $offer ) ? $offer : (object) $offer;
	}

	/**
	 * Applies $add to a transient value's `response`.
	 *
	 * @param mixed    $value The transient (an object, or false).
	 * @param callable $add   Returns the response with remembered offers added.
	 * @return mixed
	 */
	private static function merge_into( $value, callable $add ) {
		$response = ( is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ) ? $value->response : array();
		$merged   = $add( $response );
		if ( count( $merged ) === count( $response ) ) {
			return $value;
		}
		$value           = is_object( $value ) ? clone $value : (object) array();
		$value->response = $merged;
		return $value;
	}

	/** A comparable summary: what is pending at which version. */
	private static function keys( array $seen ): array {
		$keys = array();
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			foreach ( $seen[ $kind ] as $id => $entry ) {
				$keys[ $kind . ':' . $id ] = (string) ( $entry['new_version'] ?? '' );
			}
		}
		ksort( $keys );
		return $keys;
	}
}
