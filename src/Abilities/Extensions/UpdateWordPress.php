<?php
/**
 * Ability: update-wordpress (WordPress core, the way Dashboard → Updates does it).
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Core_Upgrader;
use WP_Error;
use WPRoadie\Abilities\AbilityBase;
use WPRoadie\Hq\Heartbeat;
use WPRoadie\Safety\Mode;

defined( 'ABSPATH' ) || exit;

final class UpdateWordPress extends AbilityBase {

	public function slug(): string {
		return 'update-wordpress';
	}

	public function label(): string {
		return __( 'Update WordPress', 'wproadie' );
	}

	public function description(): string {
		return __( 'Updates WordPress itself to the newest version on offer (or the version named), exactly as Dashboard → Updates does: WordPress\'s own core upgrader, with its built-in rollback if copying files fails, followed by the database upgrade and a check that the site still answers. Use dry_run: true first: it reports the current and offered versions and whether this server\'s PHP and MySQL meet the new version\'s requirements. Unlike plugin and theme updates, a WordPress update cannot be undone from here: on an important site make sure a host backup exists first. On live sites, a person approves the call in HQ first.', 'wproadie' );
	}

	public function group(): string {
		return 'extensions';
	}

	public function risk(): string {
		return Mode::RISK_MAINTAIN;
	}

	public function is_available(): bool {
		return Installer::filesystem_ready( ABSPATH );
	}

	public function unavailable_reason(): string {
		return Installer::filesystem_reason();
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version' => array(
					'type'        => 'string',
					'pattern'     => '^[0-9]+(\.[0-9]+){1,2}$',
					'description' => __( 'A specific offered version, e.g. "7.1.3". Default: the newest on offer.', 'wproadie' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Only report what would happen: versions and server requirements.', 'wproadie' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	protected function permission( array $input ): bool {
		unset( $input );
		return current_user_can( 'update_core' );
	}

	protected function execute( array $input ) {
		Installer::load();
		if ( ! Installer::filesystem_ready( ABSPATH ) ) {
			return Installer::filesystem_error();
		}

		$installed = get_bloginfo( 'version' );
		$newest    = Heartbeat::core_update();
		$version   = isset( $input['version'] ) ? (string) $input['version'] : $newest;
		if ( null === $version ) {
			return $this->error(
				'no_update',
				sprintf(
					/* translators: %s: installed WordPress version. */
					__( 'WordPress %s is the newest version WordPress knows about; there is nothing to update.', 'wproadie' ),
					$installed
				),
				409
			);
		}
		if ( version_compare( $version, $installed, '<=' ) ) {
			return $this->error(
				'not_newer',
				sprintf(
					/* translators: 1: requested version, 2: installed version. */
					__( 'WordPress %1$s is not newer than the installed %2$s.', 'wproadie' ),
					$version,
					$installed
				),
				409
			);
		}
		$offer = self::offer( $version );
		if ( ! $offer ) {
			return $this->error(
				'unknown_version',
				sprintf(
					/* translators: 1: requested version, 2: newest offered version. */
					__( 'WordPress does not offer version %1$s to this site. The newest on offer is %2$s.', 'wproadie' ),
					$version,
					(string) $newest
				),
				404
			);
		}

		$requirements = self::requirements( $offer );
		$report       = array(
			'previous_version' => $installed,
			'version'          => (string) $offer->current,
			'locale'           => (string) ( $offer->locale ?? '' ),
			'requirements'     => $requirements,
		);
		if ( ! $requirements['ok'] ) {
			return $this->error(
				'requirements_not_met',
				sprintf(
					/* translators: 1: WordPress version, 2: what is missing. */
					__( 'WordPress %1$s needs %2$s; this server does not meet that. Ask the host to upgrade before updating.', 'wproadie' ),
					(string) $offer->current,
					implode( ' and ', $requirements['missing'] )
				),
				409
			);
		}
		if ( ! empty( $input['dry_run'] ) ) {
			return array( 'dry_run' => true ) + $report;
		}

		$before = SafeUpdate::probe();
		$skin   = Installer::skin();
		$result = Installer::quietly(
			static function () use ( $skin, $offer ) {
				$upgrader = new Core_Upgrader( $skin );
				return $upgrader->upgrade(
					$offer,
					array(
						'attempt_rollback'             => true,
						'allow_relaxed_file_ownership' => isset( $offer->new_files ) && ! $offer->new_files,
					)
				);
			}
		);
		$log    = Installer::log( $skin );

		if ( ! is_string( $result ) ) {
			$outcome = Installer::outcome( is_wp_error( $result ) ? $result : new WP_Error( 'wproadie_core_failed', __( 'WordPress reported the update did not go through.', 'wproadie' ) ), $skin );
			$message = is_wp_error( $outcome ) ? $outcome->get_error_message() : __( 'The update did not go through.', 'wproadie' );
			return new WP_Error(
				'wproadie_update_failed',
				sprintf(
					/* translators: 1: WordPress version, 2: error message. */
					__( 'WordPress was not updated to %1$s: %2$s WordPress keeps the old files when copying fails, so the site should be as before; check it.', 'wproadie' ),
					(string) $offer->current,
					$message
				),
				array(
					'status' => 500,
					'log'    => $log,
				)
			);
		}

		// The new files are in place; the database upgrade runs in a fresh
		// request, as wp-admin's redirect to upgrade.php would, so the new
		// version's code does it.
		$db = self::upgrade_database();
		Heartbeat::send_soon();
		$after  = SafeUpdate::probe();
		$broken = true === $before['ok'] && false === $after['ok'];

		return $report + array(
			'version'    => $result,
			'status'     => $broken ? 'broken' : 'updated',
			'message'    => $broken
				? sprintf(
					/* translators: 1: new version, 2: probe detail. */
					__( 'WordPress is now %1$s but the site stopped answering (%2$s). Restore the host backup, or look at the site right away.', 'wproadie' ),
					$result,
					$after['detail']
				)
				: sprintf(
					/* translators: 1: old version, 2: new version. */
					__( 'WordPress updated from %1$s to %2$s.', 'wproadie' ),
					$installed,
					$result
				),
			'database'   => $db,
			'health'     => array(
				'checked' => true === $before['ok'],
				'before'  => $before,
				'after'   => $after,
			),
			'log'        => $log,
			'undoable'   => false,
			'next_steps' => array(
				__( 'Open the site and its most important pages and forms; a major update can change how a theme or plugin behaves.', 'wproadie' ),
				__( 'Plugins and themes may offer new versions for this WordPress: check list-plugins.', 'wproadie' ),
			),
		);
	}

	/**
	 * The offer for a version, in the site's locale first, then en_US.
	 *
	 * @return object|null
	 */
	private static function offer( string $version ): ?object {
		$data = get_site_transient( 'update_core' );
		if ( ! is_object( $data ) || empty( $data->updates ) ) {
			return null;
		}
		$fallback = null;
		foreach ( (array) $data->updates as $offer ) {
			if ( ! isset( $offer->current, $offer->response ) || (string) $offer->current !== $version || 'upgrade' !== $offer->response ) {
				continue;
			}
			if ( ( $offer->locale ?? '' ) === get_locale() ) {
				return $offer;
			}
			if ( null === $fallback || 'en_US' === ( $offer->locale ?? '' ) ) {
				$fallback = $offer;
			}
		}
		return $fallback;
	}

	/**
	 * Whether this server meets the offer's PHP and MySQL requirements,
	 * as Dashboard → Updates checks before showing the button.
	 *
	 * @return array{ok: bool, php: string, php_required: string|null, mysql: string|null, mysql_required: string|null, missing: string[]}
	 */
	private static function requirements( object $offer ): array {
		global $wpdb;
		$php_required   = isset( $offer->php_version ) ? (string) $offer->php_version : null;
		$mysql_required = isset( $offer->mysql_version ) ? (string) $offer->mysql_version : null;
		$mysql          = $wpdb->db_version();
		$missing        = array();
		if ( $php_required && version_compare( PHP_VERSION, $php_required, '<' ) ) {
			$missing[] = sprintf( 'PHP %s or newer (this server runs %s)', $php_required, PHP_VERSION );
		}
		// MariaDB reports as 5.5.5-10.x; WordPress accepts it, so only check real MySQL.
		if ( $mysql_required && $mysql && false === stripos( (string) $wpdb->db_server_info(), 'mariadb' ) && version_compare( $mysql, $mysql_required, '<' ) ) {
			$missing[] = sprintf( 'MySQL %s or newer (this server runs %s)', $mysql_required, $mysql );
		}
		return array(
			'ok'             => ! $missing,
			'php'            => PHP_VERSION,
			'php_required'   => $php_required,
			'mysql'          => $mysql ? (string) $mysql : null,
			'mysql_required' => $mysql_required,
			'missing'        => $missing,
		);
	}

	/**
	 * Runs WordPress's database upgrade in a fresh request (wp-admin/upgrade.php
	 * step 1 needs no login and is what wp-admin redirects to after a core
	 * update). Best effort: WordPress also runs it on the next admin visit.
	 *
	 * @return array{ok: bool|null, detail: string}
	 */
	private static function upgrade_database(): array {
		$response = wp_remote_get(
			admin_url( 'upgrade.php?step=1&backto=' . rawurlencode( home_url( '/' ) ) ),
			array(
				'timeout'     => 60,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to this site.
				'headers'     => array( 'X-WPRoadie-Probe' => '1' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => null,
				'detail' => sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach wp-admin/upgrade.php (%s); WordPress will run the database upgrade on the next wp-admin visit.', 'wproadie' ),
					$response->get_error_message()
				),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'     => $status < 400,
			'detail' => $status < 400 ? __( 'Database upgrade ran.', 'wproadie' ) : sprintf( 'HTTP %d', $status ),
		);
	}
}
