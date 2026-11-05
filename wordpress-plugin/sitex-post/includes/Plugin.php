<?php
/**
 * Wires the plugin modules.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP;

use SiteXPost\WP\Admin\AdminPage;
use SiteXPost\WP\Cloud\CloudClient;
use SiteXPost\WP\Rest\RestController;
use SiteXPost\WP\Seo\SeoManager;
use SiteXPost\WP\Support\Installer;
use SiteXPost\WP\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot() {
		load_plugin_textdomain( 'sitex-post', false, dirname( plugin_basename( SITEX_POST_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();

		$rest = new RestController();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $rest, 'serve_signed_response' ), 10, 4 );

		SeoManager::register_frontend_hooks();

		add_action( 'sitex_post_daily', array( __CLASS__, 'daily' ) );

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	/**
	 * Daily cron: heartbeat to Cloud and housekeeping.
	 */
	public static function daily() {
		Installer::prune();
		$client = new CloudClient();
		if ( $client->is_connected() ) {
			$result = $client->heartbeat( 'daily' );
			if ( is_wp_error( $result ) ) {
				Logger::warning( 'heartbeat_failed', $result->get_error_message() );
			}
		}
	}
}
