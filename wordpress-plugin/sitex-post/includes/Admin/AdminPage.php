<?php
/**
 * WP admin screen "SiteX Post": connection wizard, status, logs, diagnostics.
 *
 * Every action requires `manage_options` and a WP nonce, and is handled
 * through admin-post.php (POST-redirect-GET).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Admin;

use SiteXPost\WP\Cloud\CloudClient;
use SiteXPost\WP\Support\Connection;
use SiteXPost\WP\Support\Environment;
use SiteXPost\WP\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class AdminPage {

	const SLUG       = 'sitex-post';
	const CAPABILITY = 'manage_options';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_sitex_post_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_sitex_post_pair', array( $this, 'handle_pair' ) );
		add_action( 'admin_post_sitex_post_cancel', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_sitex_post_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_sitex_post_heartbeat', array( $this, 'handle_heartbeat' ) );
		add_action( 'admin_post_sitex_post_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITEX_POST_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'SiteX Post', 'sitex-post' ),
			__( 'SiteX Post', 'sitex-post' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-admin-site-alt3',
			81
		);
	}

	/**
	 * @param string $hook Current screen hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'sitex-post-admin', SITEX_POST_URL . 'assets/admin.css', array(), SITEX_POST_VERSION );
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'sitex-post' ) . '</a>' );
		return $links;
	}

	/**
	 * Reminder on the Plugins screen while the site is not connected.
	 */
	public function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( self::CAPABILITY ) || Connection::is_connected() ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'SiteX Post is installed but not connected yet.', 'sitex-post' ),
			esc_url( self::url() ),
			esc_html__( 'Connect to SiteX Post Cloud', 'sitex-post' )
		);
	}

	/**
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	// ------------------------------------------------------------------ handlers

	public function handle_preview() {
		$this->guard( 'sitex_post_preview' );
		if ( Connection::is_connected() ) {
			$this->redirect( array( 'sxp_error' => 'already_connected' ) );
		}
		$code = isset( $_POST['pairing_code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', sanitize_text_field( wp_unslash( $_POST['pairing_code'] ) ) ) ) : '';
		if ( strlen( $code ) < 8 ) {
			$this->redirect( array( 'sxp_error' => 'code_format' ) );
		}
		$result = ( new CloudClient() )->preview( $code );
		if ( is_wp_error( $result ) ) {
			$this->flash_error( $result->get_error_message() );
			$this->redirect( array( 'sxp_error' => 'cloud' ) );
		}
		set_transient(
			self::preview_key(),
			array(
				'code'    => $code,
				'preview' => isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array(),
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->redirect( array( 'step' => 'confirm' ) );
	}

	public function handle_pair() {
		$this->guard( 'sitex_post_pair' );
		$pending = get_transient( self::preview_key() );
		if ( ! is_array( $pending ) || empty( $pending['code'] ) ) {
			$this->redirect( array( 'sxp_error' => 'expired' ) );
		}
		if ( empty( $_POST['sxp_confirm'] ) ) {
			$this->redirect( array( 'step' => 'confirm', 'sxp_error' => 'not_confirmed' ) );
		}
		$author = isset( $_POST['author_id'] ) ? absint( $_POST['author_id'] ) : 0;
		if ( ! $author || ! user_can( $author, 'publish_posts' ) || ! user_can( $author, 'upload_files' ) ) {
			$this->redirect( array( 'step' => 'confirm', 'sxp_error' => 'author' ) );
		}
		$result = ( new CloudClient() )->pair( (string) $pending['code'], $author );
		delete_transient( self::preview_key() );
		if ( is_wp_error( $result ) ) {
			$this->flash_error( $result->get_error_message() );
			$this->redirect( array( 'sxp_error' => 'cloud' ) );
		}
		$this->redirect( array( 'sxp_notice' => 'connected' ) );
	}

	public function handle_cancel() {
		$this->guard( 'sitex_post_cancel' );
		delete_transient( self::preview_key() );
		$this->redirect();
	}

	public function handle_disconnect() {
		$this->guard( 'sitex_post_disconnect' );
		$result = ( new CloudClient() )->disconnect();
		$this->redirect( array( 'sxp_notice' => is_wp_error( $result ) ? 'disconnected_local' : 'disconnected' ) );
	}

	public function handle_heartbeat() {
		$this->guard( 'sitex_post_heartbeat' );
		$result = ( new CloudClient() )->heartbeat( 'manual' );
		if ( is_wp_error( $result ) ) {
			$this->flash_error( $result->get_error_message() );
			$this->redirect( array( 'sxp_error' => 'cloud' ) );
		}
		$this->redirect( array( 'sxp_notice' => 'heartbeat_ok' ) );
	}

	public function handle_clear_logs() {
		$this->guard( 'sitex_post_clear_logs' );
		Logger::clear();
		$this->redirect( array( 'tab' => 'logs', 'sxp_notice' => 'logs_cleared' ) );
	}

	/**
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage SiteX Post.', 'sitex-post' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param array $args Query args.
	 */
	private function redirect( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * @param string $message Message from Cloud (already sanitized).
	 */
	private function flash_error( $message ) {
		set_transient( 'sitex_post_flash_' . get_current_user_id(), (string) $message, MINUTE_IN_SECONDS );
	}

	/**
	 * @return string
	 */
	private static function preview_key() {
		return 'sitex_post_pairing_' . get_current_user_id();
	}

	// ------------------------------------------------------------------ views

	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';
		$tabs = array(
			'connection'  => __( 'Connection', 'sitex-post' ),
			'logs'        => __( 'Logs', 'sitex-post' ),
			'diagnostics' => __( 'Diagnostics', 'sitex-post' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connection';
		}
		echo '<div class="wrap sitex-post">';
		echo '<h1>' . esc_html__( 'SiteX Post', 'sitex-post' ) . '</h1>';
		$this->render_messages();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( array( 'tab' => $key ) ) ), $tab === $key ? 'nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav><div class="sitex-post__body">';
		if ( 'logs' === $tab ) {
			$this->render_logs();
		} elseif ( 'diagnostics' === $tab ) {
			$this->render_diagnostics();
		} else {
			$this->render_connection();
		}
		echo '</div></div>';
	}

	private function render_messages() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['sxp_notice'] ) ? sanitize_key( wp_unslash( $_GET['sxp_notice'] ) ) : '';
		$error  = isset( $_GET['sxp_error'] ) ? sanitize_key( wp_unslash( $_GET['sxp_error'] ) ) : '';
		// phpcs:enable
		$notices = array(
			'connected'          => __( 'Connected. SiteX Post Cloud can now publish to this site with the account you selected.', 'sitex-post' ),
			'disconnected'       => __( 'Disconnected. The connection key was deleted and SiteX Post Cloud was notified.', 'sitex-post' ),
			'disconnected_local' => __( 'Disconnected locally. The key was deleted here, but SiteX Post Cloud could not be notified; it can no longer write to this site anyway.', 'sitex-post' ),
			'heartbeat_ok'       => __( 'SiteX Post Cloud answered: the connection works.', 'sitex-post' ),
			'logs_cleared'       => __( 'Logs cleared.', 'sitex-post' ),
		);
		$errors  = array(
			'code_format'       => __( 'Enter the pairing code shown in SiteX Post Cloud (format XXXX-XXXX).', 'sitex-post' ),
			'expired'           => __( 'The pairing session expired. Enter the code again.', 'sitex-post' ),
			'not_confirmed'     => __( 'Please tick the confirmation box to authorize the connection.', 'sitex-post' ),
			'author'            => __( 'Choose an account that can publish posts and upload media.', 'sitex-post' ),
			'already_connected' => __( 'This site is already connected. Disconnect first.', 'sitex-post' ),
		);
		if ( isset( $notices[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
		}
		if ( 'cloud' === $error ) {
			$flash = get_transient( 'sitex_post_flash_' . get_current_user_id() );
			delete_transient( 'sitex_post_flash_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( is_string( $flash ) && '' !== $flash ? $flash : __( 'SiteX Post Cloud returned an error.', 'sitex-post' ) ) . '</p></div>';
		} elseif ( isset( $errors[ $error ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $error ] ) . '</p></div>';
		}
	}

	private function render_connection() {
		if ( Connection::is_connected() ) {
			$this->render_connected();
			return;
		}
		$pending = get_transient( self::preview_key() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		if ( 'confirm' === $step && is_array( $pending ) && ! empty( $pending['preview'] ) ) {
			$this->render_confirm( $pending['preview'] );
			return;
		}
		$this->render_start();
	}

	private function render_steps( $current ) {
		$steps = array(
			1 => __( 'Pairing code', 'sitex-post' ),
			2 => __( 'Review & confirm', 'sitex-post' ),
			3 => __( 'Connected', 'sitex-post' ),
		);
		echo '<ol class="sitex-post__steps">';
		foreach ( $steps as $n => $label ) {
			printf( '<li class="%s"><span>%d</span> %s</li>', $n === $current ? 'is-current' : ( $n < $current ? 'is-done' : '' ), (int) $n, esc_html( $label ) );
		}
		echo '</ol>';
	}

	private function render_start() {
		$https = 0 === strpos( home_url( '/' ), 'https://' );
		$this->render_steps( 1 );
		?>
		<div class="sitex-post__card">
			<h2><?php esc_html_e( 'Connect this site to SiteX Post Cloud', 'sitex-post' ); ?></h2>
			<ol class="sitex-post__howto">
				<li><?php esc_html_e( 'In SiteX Post Cloud, open Websites → Add website and enter this site address.', 'sitex-post' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?></code></li>
				<li><?php esc_html_e( 'Copy the one-time pairing code (valid 15 minutes) and paste it below.', 'sitex-post' ); ?></li>
				<li><?php esc_html_e( 'Review who is asking and confirm. Nothing is granted before you confirm.', 'sitex-post' ); ?></li>
			</ol>
			<?php if ( ! $https ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site does not use HTTPS. SiteX Post Cloud only connects to HTTPS sites in production.', 'sitex-post' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sitex_post_preview" />
				<?php wp_nonce_field( 'sitex_post_preview' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sxp-code"><?php esc_html_e( 'Pairing code', 'sitex-post' ); ?></label></th>
						<td><input id="sxp-code" name="pairing_code" type="text" class="regular-text sitex-post__code" placeholder="XXXX-XXXX" autocomplete="off" required maxlength="12" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SiteX Post Cloud', 'sitex-post' ); ?></th>
						<td><code><?php echo esc_html( Connection::cloud_url() ); ?></code>
						<p class="description"><?php esc_html_e( 'Set by the plugin (or SITEX_POST_CLOUD_URL in wp-config.php).', 'sitex-post' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Continue', 'sitex-post' ) ); ?>
			</form>
			<p class="description"><?php esc_html_e( 'SiteX Post never asks for your WordPress password and never stores any AI API key on this site.', 'sitex-post' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param array $preview Data returned by Cloud.
	 */
	private function render_confirm( array $preview ) {
		$this->render_steps( 2 );
		$labels = array(
			'read_site_info'   => __( 'Read basic site information (name, versions, active SEO plugin, WooCommerce)', 'sitex-post' ),
			'read_content'     => __( 'Read the list of categories, tags, posts, pages and products (titles, links, excerpts)', 'sitex-post' ),
			'create_posts'     => __( 'Create posts and pages as the account you choose below', 'sitex-post' ),
			'update_own_posts' => __( 'Update only the posts it created (never your other content)', 'sitex-post' ),
			'upload_media'     => __( 'Upload images to the media library', 'sitex-post' ),
			'write_seo_meta'   => __( 'Write SEO title, description and focus keyword for its posts', 'sitex-post' ),
		);
		$perms  = isset( $preview['permissions'] ) && is_array( $preview['permissions'] ) ? $preview['permissions'] : array();
		?>
		<div class="sitex-post__card">
			<h2><?php esc_html_e( 'Review the connection request', 'sitex-post' ); ?></h2>
			<table class="widefat striped sitex-post__summary">
				<tbody>
					<tr><th><?php esc_html_e( 'Workspace', 'sitex-post' ); ?></th><td><strong><?php echo esc_html( (string) ( isset( $preview['workspace_name'] ) ? $preview['workspace_name'] : '' ) ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'Website name in SiteX Post', 'sitex-post' ); ?></th><td><?php echo esc_html( (string) ( isset( $preview['site_name'] ) ? $preview['site_name'] : '' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Registered address', 'sitex-post' ); ?></th><td><code><?php echo esc_html( (string) ( isset( $preview['site_url'] ) ? $preview['site_url'] : '' ) ); ?></code></td></tr>
				</tbody>
			</table>
			<h3><?php esc_html_e( 'SiteX Post Cloud will be able to:', 'sitex-post' ); ?></h3>
			<ul class="sitex-post__perms">
				<?php foreach ( $perms as $perm ) : ?>
					<?php if ( isset( $labels[ $perm ] ) ) : ?>
						<li><?php echo esc_html( $labels[ $perm ] ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'It cannot change settings, plugins, themes, users or content it did not create. You can disconnect at any time.', 'sitex-post' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sitex_post_pair" />
				<?php wp_nonce_field( 'sitex_post_pair' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sxp-author"><?php esc_html_e( 'Publish as', 'sitex-post' ); ?></label></th>
						<td>
							<select name="author_id" id="sxp-author" required>
								<?php foreach ( self::publishing_users() as $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( (int) $user->ID, get_current_user_id() ); ?>>
										<?php echo esc_html( sprintf( '%1$s (%2$s)', $user->display_name, $user->user_login ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Tip: create a dedicated Author or Editor account for SiteX Post instead of using an administrator.', 'sitex-post' ); ?></p>
						</td>
					</tr>
				</table>
				<p><label><input type="checkbox" name="sxp_confirm" value="1" required /> <?php esc_html_e( 'I am an administrator of this site and I authorize this connection.', 'sitex-post' ); ?></label></p>
				<p class="submit">
					<?php submit_button( __( 'Confirm and connect', 'sitex-post' ), 'primary', 'submit', false ); ?>
				</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sitex_post_cancel" />
				<?php wp_nonce_field( 'sitex_post_cancel' ); ?>
				<?php submit_button( __( 'Cancel', 'sitex-post' ), 'secondary', 'cancel', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Users who can both publish posts and upload media. WP_User_Query's
	 * "capability" argument does not AND an array of capabilities, so the
	 * second capability is checked per user.
	 *
	 * @return \WP_User[]
	 */
	private static function publishing_users() {
		$users = get_users(
			array(
				'capability' => 'publish_posts',
				'orderby'    => 'display_name',
				'number'     => 200,
			)
		);
		return array_values(
			array_filter(
				$users,
				function ( $user ) {
					return user_can( $user, 'upload_files' );
				}
			)
		);
	}

	private function render_connected() {
		$c      = Connection::get();
		$author = Environment::author( Connection::author_id() );
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$this->render_steps( 3 );
		?>
		<div class="sitex-post__card">
			<h2><span class="sitex-post__dot is-ok"></span><?php esc_html_e( 'Connected to SiteX Post Cloud', 'sitex-post' ); ?></h2>
			<table class="widefat striped sitex-post__summary">
				<tbody>
					<tr><th><?php esc_html_e( 'Workspace', 'sitex-post' ); ?></th><td><?php echo esc_html( (string) ( isset( $c['workspace_name'] ) ? $c['workspace_name'] : '' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Website name in SiteX Post', 'sitex-post' ); ?></th><td><?php echo esc_html( (string) ( isset( $c['site_name'] ) ? $c['site_name'] : '' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Publishing account', 'sitex-post' ); ?></th><td><?php echo esc_html( isset( $author['display_name'] ) ? $author['display_name'] : __( 'Missing — disconnect and pair again', 'sitex-post' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Connected since', 'sitex-post' ); ?></th><td><?php echo esc_html( ! empty( $c['connected_at'] ) ? wp_date( $fmt, (int) $c['connected_at'] ) : '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Last request from Cloud', 'sitex-post' ); ?></th><td><?php echo esc_html( ! empty( $c['last_cloud_request_at'] ) ? wp_date( $fmt, (int) $c['last_cloud_request_at'] ) : __( 'None yet', 'sitex-post' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Key ID', 'sitex-post' ); ?></th><td><code>…<?php echo esc_html( substr( (string) $c['key_id'], -6 ) ); ?></code> <?php echo ! empty( $c['rotated_at'] ) ? esc_html( sprintf( /* translators: %s: date */ __( '(rotated %s)', 'sitex-post' ), wp_date( $fmt, (int) $c['rotated_at'] ) ) ) : ''; ?></td></tr>
				</tbody>
			</table>
			<div class="sitex-post__actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sitex_post_heartbeat" />
					<?php wp_nonce_field( 'sitex_post_heartbeat' ); ?>
					<?php submit_button( __( 'Test connection to Cloud', 'sitex-post' ), 'secondary', 'heartbeat', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Disconnect from SiteX Post Cloud? The key will be deleted and Cloud will immediately lose write access.', 'sitex-post' ) ) ); ?>);">
					<input type="hidden" name="action" value="sitex_post_disconnect" />
					<?php wp_nonce_field( 'sitex_post_disconnect' ); ?>
					<?php submit_button( __( 'Disconnect', 'sitex-post' ), 'delete', 'disconnect', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_logs() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$rows  = Logger::recent( 100, $level );
		echo '<div class="sitex-post__card">';
		echo '<p class="sitex-post__filters">';
		foreach ( array( '' => __( 'All', 'sitex-post' ), 'error' => __( 'Errors', 'sitex-post' ), 'warning' => __( 'Warnings', 'sitex-post' ), 'info' => __( 'Info', 'sitex-post' ) ) as $key => $label ) {
			printf( '<a href="%s" class="%s">%s</a> ', esc_url( self::url( array( 'tab' => 'logs', 'level' => $key ) ) ), $level === $key ? 'current' : '', esc_html( $label ) );
		}
		echo '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No log entries yet.', 'sitex-post' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'sitex-post' ) . '</th><th>' . esc_html__( 'Level', 'sitex-post' ) . '</th><th>' . esc_html__( 'Event', 'sitex-post' ) . '</th><th>' . esc_html__( 'Message', 'sitex-post' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td><span class="sitex-post__level is-%s">%s</span></td><td><code>%s</code></td><td>%s</td></tr>',
				esc_html( $row['created_at'] ),
				esc_attr( $row['level'] ),
				esc_html( $row['level'] ),
				esc_html( $row['event'] ),
				esc_html( (string) $row['message'] )
			);
		}
		echo '</tbody></table>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sitex_post_clear_logs" />
			<?php wp_nonce_field( 'sitex_post_clear_logs' ); ?>
			<?php submit_button( __( 'Clear logs', 'sitex-post' ), 'secondary', 'clear', false ); ?>
		</form>
		</div>
		<?php
	}

	private function render_diagnostics() {
		$labels = array(
			'ok'   => __( 'OK', 'sitex-post' ),
			'warn' => __( 'Warning', 'sitex-post' ),
			'fail' => __( 'Problem', 'sitex-post' ),
		);
		echo '<div class="sitex-post__card"><table class="widefat striped"><tbody>';
		foreach ( Environment::diagnostics() as $check ) {
			printf(
				'<tr><td><span class="sitex-post__dot is-%1$s"></span> %2$s</td><td>%3$s</td><td><code>%4$s</code></td></tr>',
				esc_attr( $check[1] ),
				esc_html( $labels[ $check[1] ] ),
				esc_html( $check[2] ),
				esc_html( (string) $check[3] )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: version */ __( 'SiteX Post plugin version %s', 'sitex-post' ), SITEX_POST_VERSION ) ) . '</p></div>';
	}
}
