<?php
/**
 * The Tools > WPRoadie admin screen: a React app in admin/src, built
 * with the WordPress scripts package, talking to the REST endpoints in
 * RestApi.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Menu {

	const SLUG = 'wproadie';

	/**
	 * @var string
	 */
	private $hook = '';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * WPRoadie users see the full screen. Other administrators see only
	 * the setup checklist, and only while Permission::can_set_up() allows
	 * it (never in stealth mode once someone has access).
	 */
	public function register(): void {
		$capability = ! Permission::can_manage() && Permission::can_set_up() ? 'manage_options' : Permission::CAPABILITY;
		$hook       = add_management_page(
			__( 'WPRoadie', 'wproadie' ),
			__( 'WPRoadie', 'wproadie' ),
			$capability,
			self::SLUG,
			array( $this, 'render' )
		);
		$this->hook = $hook ? $hook : '';
	}

	public static function url(): string {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'tools.php' ) );
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook || ! Permission::can_set_up() ) {
			return;
		}

		$asset_file = WPROADIE_DIR . 'admin/build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'wproadie-admin', WPROADIE_URL . 'admin/build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'wproadie-admin', 'wproadie', WPROADIE_DIR . 'languages' );
		wp_enqueue_style( 'wproadie-admin', WPROADIE_URL . 'admin/build/style-index.css', array( 'wp-components' ), $asset['version'] );
	}

	public function render(): void {
		if ( ! Permission::can_set_up() ) {
			wp_die( esc_html__( 'You are not allowed to manage WPRoadie.', 'wproadie' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPRoadie', 'wproadie' ); ?></h1>
			<?php if ( ! is_readable( WPROADIE_DIR . 'admin/build/index.asset.php' ) ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'The admin screen has not been built. Install a release zip, or run `npm install && npm run build` in the plugin folder. Everything else, including `wp wproadie`, works without it.', 'wproadie' ); ?>
				</p></div>
			<?php endif; ?>
			<div id="wproadie-admin"></div>
		</div>
		<?php
	}
}
