<?php
/**
 * The Tools > WPRoadie admin screen: status, connect, access, tool
 * switches and activity log.
 *
 * Plain server-rendered PHP for now; the plan moves this to a React app
 * built with @wordpress/scripts once the REST endpoints exist.
 *
 * @package WPRoadie
 */

namespace WPRoadie\Admin;

use WPRoadie\Abilities\Registry;
use WPRoadie\Log\AuditLog;
use WPRoadie\Plugin;
use WPRoadie\Safety\Mode;
use WPRoadie\Safety\Settings;
use WPRoadie\Server\McpServer;
use WPRoadie\Server\Permission;

defined( 'ABSPATH' ) || exit;

final class Menu {

	const SLUG  = 'wproadie';
	const NONCE = 'wproadie_admin';

	/**
	 * A just-created credential, shown once on this request and never stored.
	 *
	 * @var array|null
	 */
	private $new_credential = null;

	/**
	 * An error from this request's form post, shown inline.
	 *
	 * @var string|null
	 */
	private $inline_error = null;

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	public function register(): void {
		$hook = add_management_page(
			__( 'WPRoadie', 'wproadie' ),
			__( 'WPRoadie', 'wproadie' ),
			Permission::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_post' ) );
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Handles form posts before the page renders.
	 */
	public function handle_post(): void {
		if ( ! Permission::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage WPRoadie.', 'wproadie' ), 403 );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || empty( $_POST['wproadie_action'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$action  = sanitize_key( wp_unslash( $_POST['wproadie_action'] ) );
		$user_id = get_current_user_id();

		switch ( $action ) {
			case 'create_password':
				$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
				$created = Credentials::create( $user_id, $label );
				// Render on this request so the password is never stored.
				if ( is_wp_error( $created ) ) {
					$this->inline_error = $created->get_error_message();
				} else {
					$this->new_credential = $created;
				}
				return;

			case 'revoke_password':
				$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
				$this->redirect( is_wp_error( Credentials::revoke( $user_id, $uuid ) ) ? 'password_not_found' : 'password_revoked' );
				break;

			case 'save_settings':
				$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
				Settings::set( 'mode', in_array( $mode, Mode::all(), true ) ? $mode : '' );

				$groups = isset( $_POST['groups'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ) ) : array();
				foreach ( array_keys( Settings::GROUPS ) as $group ) {
					Settings::set_group( $group, in_array( $group, $groups, true ) );
				}
				$this->redirect( 'settings_saved' );
				break;

			case 'kill_switch':
				$off = ! empty( $_POST['disabled'] );
				Settings::set( 'disabled', $off );
				$this->redirect( $off ? 'turned_off' : 'turned_on' );
				break;

			case 'grant':
				$target = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
				$this->redirect( $target && Permission::grant( $target ) ? 'access_granted' : 'not_admin' );
				break;

			case 'revoke':
				$target = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
				if ( $target === $user_id ) {
					$this->redirect( 'cannot_revoke_self' );
				}
				Permission::revoke( $target );
				$this->redirect( 'access_removed' );
				break;
		}
	}

	/**
	 * Notices shown after a redirect. The URL carries only the code, so a
	 * crafted link cannot put arbitrary text on this screen.
	 *
	 * @return array<string, array{0: string, 1: string}> Code => [type, message].
	 */
	private static function notices(): array {
		return array(
			'password_revoked'   => array( 'success', __( 'Password revoked.', 'wproadie' ) ),
			'password_not_found' => array( 'error', __( 'That WPRoadie password was not found.', 'wproadie' ) ),
			'settings_saved'     => array( 'success', __( 'Settings saved.', 'wproadie' ) ),
			'turned_off'         => array( 'warning', __( 'WPRoadie is off. No AI client can use it.', 'wproadie' ) ),
			'turned_on'          => array( 'success', __( 'WPRoadie is on.', 'wproadie' ) ),
			'access_granted'     => array( 'success', __( 'Access granted.', 'wproadie' ) ),
			'not_admin'          => array( 'error', __( 'Only administrators can be given access.', 'wproadie' ) ),
			'access_removed'     => array( 'success', __( 'Access removed.', 'wproadie' ) ),
			'cannot_revoke_self' => array( 'error', __( 'You cannot remove your own access here. Ask another WPRoadie user, or use `wp wproadie revoke`.', 'wproadie' ) ),
		);
	}

	/**
	 * Redirects back to the screen with a notice code, then exits.
	 *
	 * @param string $notice Key from notices().
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect( self::url( array( 'wproadie_notice' => $notice ) ) );
		exit;
	}

	public function render(): void {
		$current = wp_get_current_user();
		?>
		<div class="wrap wproadie">
			<h1><?php esc_html_e( 'WPRoadie', 'wproadie' ); ?></h1>
			<?php
			$this->render_notice();
			$this->render_status();
			$this->render_connect( $current );
			$this->render_access( $current );
			$this->render_settings();
			$this->render_log();
			?>
		</div>
		<style>
			.wproadie .card { max-width: none; }
			.wproadie pre { background: #f6f7f7; padding: 12px; overflow: auto; white-space: pre-wrap; word-break: break-all; }
			.wproadie .wproadie-status td:first-child { width: 180px; font-weight: 600; }
			.wproadie .wproadie-error { color: #b32d2e; }
		</style>
		<?php
	}

	private function render_notice(): void {
		if ( null !== $this->inline_error ) {
			wp_admin_notice( esc_html( $this->inline_error ), array( 'type' => 'error' ) );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; the value is looked up, never echoed.
		$code    = isset( $_GET['wproadie_notice'] ) ? sanitize_key( wp_unslash( $_GET['wproadie_notice'] ) ) : '';
		$notices = self::notices();
		if ( ! isset( $notices[ $code ] ) ) {
			return;
		}

		wp_admin_notice(
			esc_html( $notices[ $code ][1] ),
			array(
				'type'        => $notices[ $code ][0],
				'dismissible' => true,
			)
		);
	}

	private function render_status(): void {
		$registry = new Registry();
		$disabled = Settings::is_disabled();
		$sources  = array(
			'constant'    => __( 'set by WPROADIE_MODE in wp-config.php', 'wproadie' ),
			'setting'     => __( 'set on this screen', 'wproadie' ),
			'environment' => sprintf(
				/* translators: %s: environment type. */
				__( 'detected from environment type "%s"', 'wproadie' ),
				wp_get_environment_type()
			),
		);
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Status', 'wproadie' ); ?></h2>
			<table class="widefat striped wproadie-status">
				<tr>
					<td><?php esc_html_e( 'WPRoadie', 'wproadie' ); ?></td>
					<td>
						<?php if ( $disabled ) : ?>
							<strong class="wproadie-error"><?php esc_html_e( 'Off (kill switch is on)', 'wproadie' ); ?></strong>
						<?php else : ?>
							<?php esc_html_e( 'On', 'wproadie' ); ?>
						<?php endif; ?>
						<form method="post" style="display:inline; margin-left: 12px;">
							<?php wp_nonce_field( self::NONCE ); ?>
							<input type="hidden" name="wproadie_action" value="kill_switch" />
							<?php if ( Settings::disabled_by_constant() ) : ?>
								<em><?php esc_html_e( 'Locked off by WPROADIE_DISABLED in wp-config.php.', 'wproadie' ); ?></em>
							<?php elseif ( $disabled ) : ?>
								<button class="button"><?php esc_html_e( 'Turn back on', 'wproadie' ); ?></button>
							<?php else : ?>
								<input type="hidden" name="disabled" value="1" />
								<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Shut off WPRoadie for every AI client?', 'wproadie' ) ); ?>');"><?php esc_html_e( 'Kill switch: turn off', 'wproadie' ); ?></button>
							<?php endif; ?>
						</form>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'MCP Adapter', 'wproadie' ); ?></td>
					<td>
						<?php if ( Plugin::adapter_available() ) : ?>
							<?php echo esc_html( defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : __( 'Active', 'wproadie' ) ); ?>
						<?php else : ?>
							<strong class="wproadie-error">
								<?php
								/* translators: %s: version number. */
								echo esc_html( sprintf( __( 'Missing. Install and activate MCP Adapter %s or newer.', 'wproadie' ), WPROADIE_MCP_ADAPTER_MIN ) );
								?>
							</strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Mode', 'wproadie' ); ?></td>
					<td><strong><?php echo esc_html( Mode::label( Mode::current() ) ); ?></strong> — <?php echo esc_html( $sources[ Mode::source() ] ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Endpoint', 'wproadie' ); ?></td>
					<td><code><?php echo esc_html( McpServer::endpoint_url() ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Tools the AI sees', 'wproadie' ); ?></td>
					<td><?php echo $disabled ? '—' : esc_html( implode( ', ', $registry->enabled_names() ) ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	private function render_connect( \WP_User $current ): void {
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Connect an AI client', 'wproadie' ); ?></h2>
			<?php if ( ! Credentials::available( $current->ID ) ) : ?>
				<p class="wproadie-error"><?php echo esc_html( Credentials::unavailable_reason() ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( $this->new_credential ) : ?>
				<?php $password = $this->new_credential['password']; ?>
				<div class="notice notice-success inline">
					<p><strong><?php esc_html_e( 'New password created. Copy it now: it will not be shown again.', 'wproadie' ); ?></strong></p>
				</div>
				<p><?php esc_html_e( 'Password', 'wproadie' ); ?>: <code><?php echo esc_html( $password ); ?></code></p>
				<h3><?php esc_html_e( 'Claude Code', 'wproadie' ); ?></h3>
				<pre><?php echo esc_html( Credentials::claude_code_command( $current->user_login, $password ) ); ?></pre>
				<h3><?php esc_html_e( 'Claude Desktop or Cursor', 'wproadie' ); ?></h3>
				<p><?php esc_html_e( 'Add this to claude_desktop_config.json (Cursor: .cursor/mcp.json), then restart the app. Needs Node.js.', 'wproadie' ); ?></p>
				<pre><?php echo esc_html( Credentials::desktop_config( $current->user_login, $password ) ); ?></pre>
				<p><em><?php esc_html_e( 'Keep this password in the team password manager, never in chat or shared files.', 'wproadie' ); ?></em></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Creates a WordPress Application Password for your user and shows the config to paste into your AI client. The AI acts as you, so everything it does is logged under your name.', 'wproadie' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="wproadie_action" value="create_password" />
					<input type="text" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Label, e.g. "Abdul – Claude Desktop"', 'wproadie' ); ?>" />
					<button class="button button-primary"><?php esc_html_e( 'Create connection password', 'wproadie' ); ?></button>
				</form>
			<?php endif; ?>

			<?php $passwords = Credentials::list_for( $current->ID ); ?>
			<?php if ( $passwords ) : ?>
				<h3><?php esc_html_e( 'Your WPRoadie passwords', 'wproadie' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Name', 'wproadie' ); ?></th><th><?php esc_html_e( 'Created', 'wproadie' ); ?></th><th><?php esc_html_e( 'Last used', 'wproadie' ); ?></th><th></th></tr></thead>
					<?php foreach ( $passwords as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $item['created'] ) ); ?></td>
							<td><?php echo $item['last_used'] ? esc_html( wp_date( 'Y-m-d H:i', (int) $item['last_used'] ) ) : '—'; ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="wproadie_action" value="revoke_password" />
									<input type="hidden" name="uuid" value="<?php echo esc_attr( $item['uuid'] ); ?>" />
									<button class="button button-link-delete"><?php esc_html_e( 'Revoke', 'wproadie' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_access( \WP_User $current ): void {
		$granted     = Permission::granted_users();
		$granted_ids = wp_list_pluck( $granted, 'ID' );
		$candidates  = array_filter(
			get_users( array( 'role' => 'administrator' ) ),
			static function ( $user ) use ( $granted_ids ) {
				return ! in_array( $user->ID, $granted_ids, true );
			}
		);
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Who can connect', 'wproadie' ); ?></h2>
			<p><?php esc_html_e( 'Only administrators you add here can connect AI clients. Being an administrator is not enough on its own.', 'wproadie' ); ?></p>
			<table class="widefat striped">
				<?php foreach ( $granted as $user ) : ?>
					<tr>
						<td><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></td>
						<td>
							<?php if ( $user->ID !== $current->ID ) : ?>
								<form method="post">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="wproadie_action" value="revoke" />
									<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
									<button class="button button-link-delete"><?php esc_html_e( 'Remove access', 'wproadie' ); ?></button>
								</form>
							<?php else : ?>
								<em><?php esc_html_e( 'You', 'wproadie' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php if ( $candidates ) : ?>
				<form method="post" style="margin-top: 12px;">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="wproadie_action" value="grant" />
					<select name="user_id">
						<?php foreach ( $candidates as $user ) : ?>
							<option value="<?php echo esc_attr( (string) $user->ID ); ?>"><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button"><?php esc_html_e( 'Give access', 'wproadie' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_settings(): void {
		$stored_mode = Settings::get( 'mode' );
		$registry    = new Registry();
		$built       = array_unique(
			array_map(
				static function ( $ability ) {
					return $ability->group();
				},
				$registry->all()
			)
		);
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Mode and tools', 'wproadie' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="wproadie_action" value="save_settings" />
				<p>
					<label for="wproadie-mode"><strong><?php esc_html_e( 'Mode', 'wproadie' ); ?></strong></label><br />
					<select id="wproadie-mode" name="mode" <?php disabled( 'constant' === Mode::source() ); ?>>
						<option value=""><?php echo esc_html( sprintf( /* translators: %s: detected mode. */ __( 'Automatic (%s)', 'wproadie' ), Mode::label( Mode::detect() ) ) ); ?></option>
						<?php foreach ( Mode::all() as $mode ) : ?>
							<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $stored_mode, $mode ); ?>><?php echo esc_html( Mode::label( $mode ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<br /><span class="description"><?php esc_html_e( 'Staging allows every enabled tool. Production allows reading plus content and settings edits. Read-only allows reading only.', 'wproadie' ); ?></span>
				</p>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Tool group', 'wproadie' ); ?></th><th><?php esc_html_e( 'Risk', 'wproadie' ); ?></th><th><?php esc_html_e( 'Allowed in current mode', 'wproadie' ); ?></th></tr></thead>
					<?php foreach ( Settings::GROUPS as $group => $config ) : ?>
						<tr>
							<td>
								<label>
									<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group ); ?>" <?php checked( Settings::group_enabled( $group ) ); ?> />
									<?php echo esc_html( Settings::group_label( $group ) ); ?>
								</label>
								<?php if ( ! in_array( $group, $built, true ) ) : ?>
									<em class="description"><?php esc_html_e( '(coming soon)', 'wproadie' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $config['risk'] ); ?></td>
							<td><?php echo Mode::allows( $config['risk'] ) ? esc_html__( 'Yes', 'wproadie' ) : esc_html__( 'No', 'wproadie' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p><button class="button button-primary"><?php esc_html_e( 'Save', 'wproadie' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private function render_log(): void {
		$rows = AuditLog::recent( 50 );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Recent AI activity', 'wproadie' ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'No activity yet.', 'wproadie' ); ?></p>
				<?php return; ?>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Time (UTC)', 'wproadie' ); ?></th>
					<th><?php esc_html_e( 'User', 'wproadie' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'wproadie' ); ?></th>
					<th><?php esc_html_e( 'Input', 'wproadie' ); ?></th>
					<th><?php esc_html_e( 'Result', 'wproadie' ); ?></th>
					<th><?php esc_html_e( 'Via', 'wproadie' ); ?></th>
				</tr></thead>
				<?php foreach ( $rows as $row ) : ?>
					<?php $user = get_userdata( (int) $row->user_id ); ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $user ? $user->user_login : '#' . $row->user_id ); ?></td>
						<td><code><?php echo esc_html( $row->ability ); ?></code></td>
						<td><code><?php echo esc_html( wp_html_excerpt( (string) $row->input, 80, '…' ) ); ?></code></td>
						<td class="<?php echo 'error' === $row->status ? 'wproadie-error' : ''; ?>">
							<?php echo esc_html( 'error' === $row->status ? wp_html_excerpt( (string) $row->result, 120, '…' ) : __( 'OK', 'wproadie' ) . ' · ' . $row->duration_ms . ' ms' ); ?>
						</td>
						<td><?php echo esc_html( trim( $row->transport . ' ' . $row->ip ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}
}
