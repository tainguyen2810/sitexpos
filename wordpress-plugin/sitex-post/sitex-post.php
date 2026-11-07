<?php
/**
 * Plugin Name:       SiteX Post
 * Plugin URI:        https://sitexpost.com/
 * Description:       Connects this WordPress site to SiteX Post Cloud (AI SEO content). Signed, per-site connection: no WordPress password and no AI API key are ever stored here.
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            SiteX Post
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitex-post
 * Domain Path:       /languages
 *
 * @package SiteXPost\WP
 */

defined( 'ABSPATH' ) || exit;

define( 'SITEX_POST_VERSION', '1.2.0' );
define( 'SITEX_POST_DB_VERSION', '1' );
define( 'SITEX_POST_FILE', __FILE__ );
define( 'SITEX_POST_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITEX_POST_URL', plugin_dir_url( __FILE__ ) );

/*
 * SiteX Post Cloud API root. Override in wp-config.php only (never from the
 * admin UI, so a compromised admin form cannot point the plugin elsewhere):
 *     define( 'SITEX_POST_CLOUD_URL', 'https://api.example.com' );
 */
if ( ! defined( 'SITEX_POST_CLOUD_URL' ) ) {
	define( 'SITEX_POST_CLOUD_URL', 'https://api.sitexpost.com' );
}

require_once SITEX_POST_DIR . 'includes/Autoloader.php';
\SiteXPost\WP\Autoloader::register();

register_activation_hook( __FILE__, array( '\SiteXPost\WP\Support\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SiteXPost\WP\Support\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\SiteXPost\WP\Plugin', 'boot' ) );
