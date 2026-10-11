<?php
/**
 * Shared plumbing for the plugin and theme tools: loading the wp-admin
 * upgrader code, checking filesystem access, resolving a package and
 * running an upgrader silently.
 *
 * Not an ability itself; the Registry only loads AbilityBase subclasses.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Abilities\Extensions;

use Throwable;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use WPRoadie\Safety\Guardrails;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/**
	 * Loads the wp-admin code the upgraders and plugin functions need.
	 */
	public static function load(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
	}

	/**
	 * Whether WordPress can write to the plugin or theme folder without
	 * asking for FTP/SSH credentials. The AI has no way to supply them.
	 *
	 * @param string $context Folder WordPress will write to.
	 */
	public static function filesystem_ready( string $context ): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return 'direct' === get_filesystem_method( array(), $context );
	}

	public static function filesystem_reason(): string {
		return __( 'WordPress cannot write to wp-content directly on this server (it would ask for FTP or SSH credentials), so plugins and themes cannot be installed or updated from here.', 'wproadie' );
	}

	public static function filesystem_error(): WP_Error {
		return new WP_Error( 'wproadie_filesystem_credentials', self::filesystem_reason(), array( 'status' => 500 ) );
	}

	/**
	 * Input schema properties shared by install-plugin and install-theme.
	 *
	 * @param string $kind "plugin" or "theme".
	 */
	public static function source_properties( string $kind ): array {
		return array(
			'slug'     => array(
				'type'        => 'string',
				'pattern'     => '^[a-z0-9][a-z0-9-]*$',
				'maxLength'   => 200,
				'description' => 'plugin' === $kind
					? __( 'WordPress.org plugin slug, e.g. "classic-editor". Give this or zip_url, not both.', 'wproadie' )
					: __( 'WordPress.org theme slug, e.g. "twentytwentyfour". Give this or zip_url, not both.', 'wproadie' ),
			),
			'zip_url'  => array(
				'type'        => 'string',
				'format'      => 'uri',
				'maxLength'   => 2000,
				'description' => __( 'HTTPS URL of a .zip package, e.g. a GitHub release asset. Give this or slug, not both.', 'wproadie' ),
			),
			'activate' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'plugin' === $kind
					? __( 'Activate the plugin after installing it.', 'wproadie' )
					: __( 'Switch the site to the theme after installing it.', 'wproadie' ),
			),
		);
	}

	/**
	 * Works out the package URL from a slug or a zip URL.
	 *
	 * @param array  $input Ability input.
	 * @param string $kind  "plugin" or "theme".
	 * @return string|WP_Error
	 */
	public static function package( array $input, string $kind ) {
		$slug = isset( $input['slug'] ) ? trim( (string) $input['slug'] ) : '';
		$url  = isset( $input['zip_url'] ) ? trim( (string) $input['zip_url'] ) : '';

		if ( ( '' === $slug ) === ( '' === $url ) ) {
			return new WP_Error( 'wproadie_bad_source', __( 'Give either slug or zip_url (exactly one).', 'wproadie' ), array( 'status' => 400 ) );
		}

		if ( '' !== $url ) {
			if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! \WPRoadie\Safety\Guardrails::is_public_url( $url ) ) {
				return new WP_Error( 'wproadie_bad_source', __( 'zip_url must be a public https:// URL.', 'wproadie' ), array( 'status' => 400 ) );
			}
			return $url;
		}

		$args = array(
			'slug'   => $slug,
			'fields' => array(
				'sections' => false,
				'tags'     => false,
			),
		);
		$info = 'plugin' === $kind ? plugins_api( 'plugin_information', $args ) : themes_api( 'theme_information', $args );
		if ( is_wp_error( $info ) ) {
			return new WP_Error(
				'wproadie_wporg_failed',
				sprintf(
					/* translators: 1: slug, 2: error message. */
					__( 'Could not look up "%1$s" on WordPress.org: %2$s', 'wproadie' ),
					$slug,
					self::plain( $info->get_error_message() )
				),
				array( 'status' => 502 )
			);
		}
		$link = is_object( $info ) && ! empty( $info->download_link ) ? (string) $info->download_link : '';
		if ( '' === $link ) {
			return new WP_Error( 'wproadie_wporg_failed', __( 'WordPress.org did not return a download link for that slug.', 'wproadie' ), array( 'status' => 502 ) );
		}
		return $link;
	}

	/**
	 * A skin that prints nothing and collects messages and errors.
	 */
	public static function skin(): WP_Ajax_Upgrader_Skin {
		return new WP_Ajax_Upgrader_Skin();
	}

	/**
	 * Runs an upgrader step with all output captured: anything printed
	 * would corrupt the MCP STDIO stream.
	 *
	 * @param callable $step Calls the upgrader.
	 * @return mixed The step's return value, or a WP_Error if it threw.
	 */
	public static function quietly( callable $step ) {
		$level = ob_get_level();
		ob_start();
		try {
			$result = $step();
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'wproadie_upgrader_exception', $e->getMessage(), array( 'status' => 500 ) );
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		return $result;
	}

	/**
	 * Turns an upgrader result into true or a WP_Error that carries the
	 * skin's error messages.
	 *
	 * @param mixed                 $result Upgrader return value.
	 * @param WP_Ajax_Upgrader_Skin $skin   Skin used for the run.
	 * @return true|WP_Error
	 */
	public static function outcome( $result, WP_Ajax_Upgrader_Skin $skin ) {
		$messages = array_map( array( self::class, 'plain' ), (array) $skin->get_error_messages() );
		if ( is_wp_error( $result ) ) {
			$messages = array_merge( array( self::plain( $result->get_error_message() ) ), $messages );
		} elseif ( true === $result && ! $skin->get_errors()->has_errors() ) {
			return true;
		} elseif ( true === $result ) {
			// Upgraders return true for some soft failures and report them
			// through the skin only.
			$result = false;
		}

		$messages = array_values( array_unique( array_filter( $messages ) ) );
		if ( ! $messages ) {
			$messages = array( __( 'The upgrader failed without saying why.', 'wproadie' ) );
		}
		return new WP_Error(
			is_wp_error( $result ) ? 'wproadie_' . $result->get_error_code() : 'wproadie_upgrader_failed',
			implode( ' ', $messages ),
			array(
				'status' => 500,
				'log'    => self::log( $skin ),
			)
		);
	}

	/**
	 * The upgrader's progress messages, as plain text.
	 *
	 * @return string[]
	 */
	public static function log( WP_Ajax_Upgrader_Skin $skin ): array {
		return array_values( array_filter( array_map( array( self::class, 'plain' ), (array) $skin->get_upgrade_messages() ) ) );
	}

	/**
	 * Calls wp_update_plugins() or wp_update_themes() and returns any
	 * warning it raised, e.g. when WordPress.org cannot be reached.
	 *
	 * @param string $kind "plugin" or "theme".
	 */
	public static function refresh_updates( string $kind ): string {
		$warning = '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Captures the update check's warning so it can be returned to the AI instead of printed.
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$warning ) {
				$warning = self::plain( (string) $errstr );
				return true;
			},
			E_USER_WARNING | E_USER_NOTICE | E_WARNING
		);
		try {
			self::quietly( 'plugin' === $kind ? 'wp_update_plugins' : 'wp_update_themes' );
		} finally {
			restore_error_handler();
		}
		return $warning;
	}

	/**
	 * Checks a plugin's main file for PHP syntax errors before loading it.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins folder.
	 * @return true|WP_Error
	 */
	public static function check_plugin_syntax( string $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_readable( $path ) ) {
			return true;
		}
		$checked = Guardrails::check_php_syntax( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( is_wp_error( $checked ) ) {
			return new WP_Error(
				'wproadie_plugin_syntax',
				sprintf(
					/* translators: %s: syntax error message. */
					__( 'The plugin\'s main file has a syntax error, so it was not activated. %s', 'wproadie' ),
					$checked->get_error_message()
				),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Activates a plugin, turning a thrown error into a WP_Error and
	 * closing any output buffer activate_plugin() left open.
	 *
	 * Returns an array with "warning" when the plugin activated but printed
	 * output (WordPress reports that as an error, yet the plugin is active).
	 *
	 * @param string $plugin_file Plugin file.
	 * @param bool   $silent      Skip activation hooks (used when restoring after an update).
	 * @return array|WP_Error
	 */
	public static function activate( string $plugin_file, bool $silent = false ) {
		$syntax = self::check_plugin_syntax( $plugin_file );
		if ( is_wp_error( $syntax ) ) {
			return $syntax;
		}

		$result = self::quietly(
			static function () use ( $plugin_file, $silent ) {
				return activate_plugin( $plugin_file, '', false, $silent );
			}
		);

		if ( is_wp_error( $result ) ) {
			if ( 'unexpected_output' === $result->get_error_code() && is_plugin_active( $plugin_file ) ) {
				$output = trim( wp_strip_all_tags( (string) $result->get_error_data() ) );
				return array(
					'warning' => sprintf(
						/* translators: %s: start of the output. */
						__( 'The plugin is active but printed output while activating, which can break headers or feeds: %s', 'wproadie' ),
						substr( $output, 0, 500 )
					),
				);
			}
			return new WP_Error(
				'wproadie_activation_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Activation failed: %s', 'wproadie' ),
					self::plain( $result->get_error_message() )
				),
				array( 'status' => 500 )
			);
		}
		return array();
	}

	/**
	 * Basic facts about an installed plugin.
	 *
	 * @param string $plugin_file Plugin file.
	 */
	public static function plugin_summary( string $plugin_file ): array {
		wp_clean_plugins_cache( false );
		$plugins = get_plugins();
		$data    = $plugins[ $plugin_file ] ?? array();
		return array(
			'plugin'  => $plugin_file,
			'name'    => (string) ( $data['Name'] ?? '' ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'active'  => is_plugin_active( $plugin_file ),
		);
	}

	/**
	 * Basic facts about an installed theme.
	 *
	 * @param string $stylesheet Theme folder name.
	 */
	public static function theme_summary( string $stylesheet ): array {
		wp_clean_themes_cache( false );
		$theme = wp_get_theme( $stylesheet );
		return array(
			'stylesheet' => $stylesheet,
			'name'       => (string) $theme->get( 'Name' ),
			'version'    => (string) $theme->get( 'Version' ),
			'template'   => $theme->get_template(),
			'active'     => get_stylesheet() === $stylesheet,
		);
	}

	/**
	 * Validates a plugin file against the installed plugins.
	 *
	 * @return true|WP_Error
	 */
	public static function check_plugin_exists( string $plugin_file ) {
		if ( ! array_key_exists( $plugin_file, get_plugins() ) ) {
			return new WP_Error(
				'wproadie_plugin_not_found',
				__( 'No installed plugin with that file. Use list-plugins to see plugin files, e.g. "akismet/akismet.php".', 'wproadie' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	public static function protected_error(): WP_Error {
		return new WP_Error(
			'wproadie_protected_plugin',
			__( 'That plugin runs the AI connection (WPRoadie or the MCP Adapter), so it cannot be deactivated or updated from here. Do it in wp-admin.', 'wproadie' ),
			array( 'status' => 403 )
		);
	}

	public static function plain( $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES ) ) );
	}
}
