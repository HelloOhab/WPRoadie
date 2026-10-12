<?php
/**
 * Admin notices: the "MCP Adapter needed" notice with its one-click
 * button, and the results of that button.
 *
 * Results come back as a fixed code in the URL (Notices::QUERY_ARG) that
 * maps to a message here. Text from the URL is never printed.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WPRoadie\Plugin;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Notices {

	const QUERY_ARG = 'wproadie_notice';

	public function hooks(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . AdapterInstaller::ACTION, array( AdapterInstaller::class, 'handle_post' ) );
	}

	/**
	 * Fixed messages for result codes: [type, message].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function messages(): array {
		return array(
			'adapter_installed'  => array( 'success', __( 'The MCP Adapter is installed and active. AI clients can now connect to WPRoadie.', 'wproadie' ) ),
			'adapter_activated'  => array( 'success', __( 'The MCP Adapter is active. AI clients can now connect to WPRoadie.', 'wproadie' ) ),
			'adapter_updated'    => array( 'success', __( 'The MCP Adapter is updated and active. AI clients can now connect to WPRoadie.', 'wproadie' ) ),
			'adapter_ready'      => array( 'success', __( 'The MCP Adapter was already set up.', 'wproadie' ) ),
			'adapter_file_mods'  => array( 'error', __( 'This site does not allow installing plugins from wp-admin. Ask your host to install the MCP Adapter plugin, or install it manually.', 'wproadie' ) ),
			'adapter_multisite'  => array( 'error', __( 'On a multisite network, a network administrator has to install and activate the MCP Adapter under Network Admin → Plugins.', 'wproadie' ) ),
			'adapter_forbidden'  => array( 'error', __( 'You are not allowed to install or activate plugins on this site.', 'wproadie' ) ),
			'adapter_filesystem' => array( 'error', __( 'WordPress cannot write to the plugins folder on this server. Ask your host to install the MCP Adapter plugin, or install it manually.', 'wproadie' ) ),
			'adapter_failed'     => array( 'error', __( 'The MCP Adapter could not be installed. Check the WPRoadie activity log for the reason, or install it manually.', 'wproadie' ) ),
		);
	}

	public function render(): void {
		if ( ! Permission::can_set_up() ) {
			return;
		}
		$this->render_result();
		$this->render_adapter();
	}

	private function render_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects one of the fixed messages below.
		$code     = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		$messages = self::messages();
		if ( '' === $code || ! isset( $messages[ $code ] ) ) {
			return;
		}
		wp_admin_notice(
			esc_html( $messages[ $code ][1] ),
			array(
				'type'        => $messages[ $code ][0],
				'dismissible' => true,
			)
		);
	}

	/**
	 * Button text for the step the adapter needs.
	 *
	 * @param string $action From AdapterInstaller::needed_action().
	 */
	public static function button_label( string $action ): string {
		if ( 'activate' === $action ) {
			return __( 'Activate MCP Adapter', 'wproadie' );
		}
		if ( 'update' === $action ) {
			return __( 'Update MCP Adapter', 'wproadie' );
		}
		return __( 'Install and activate MCP Adapter', 'wproadie' );
	}

	private function render_adapter(): void {
		if ( Plugin::adapter_available() ) {
			return;
		}
		// The WPRoadie screen has its own setup checklist.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'tools_page_' . Menu::SLUG === $screen->id ) {
			return;
		}
		$action = AdapterInstaller::needed_action();
		if ( 'none' === $action ) {
			// Activated in this request; the next page load picks it up.
			return;
		}

		?>
		<div class="notice notice-error wproadie-adapter-notice">
			<p>
				<?php
				printf(
					/* translators: %s: minimum MCP Adapter version. */
					esc_html__( 'WPRoadie needs the free MCP Adapter plugin (version %s or newer) to be installed and active. AI clients cannot connect until it is.', 'wproadie' ),
					esc_html( WPROADIE_MCP_ADAPTER_MIN )
				);
				?>
			</p>
			<?php if ( true === AdapterInstaller::can_run( $action ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( AdapterInstaller::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( AdapterInstaller::ACTION ); ?>" />
					<p><button type="submit" class="button button-primary"><?php echo esc_html( self::button_label( $action ) ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
