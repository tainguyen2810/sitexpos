<?php
/**
 * Activation, DB tables and housekeeping.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Support;

use SiteXPost\WP\Security\NonceStore;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/**
	 * @param string $name Short table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'sitex_post_' . $name;
	}

	public static function activate() {
		self::create_tables();
		if ( ! wp_next_scheduled( 'sitex_post_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sitex_post_daily' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'sitex_post_daily' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'sitex_post_db_version' ) !== SITEX_POST_DB_VERSION ) {
			self::create_tables();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$nonces  = self::table( 'nonces' );
		$logs    = self::table( 'logs' );

		dbDelta(
			"CREATE TABLE {$nonces} (
  nonce_hash char(64) NOT NULL,
  expires_at bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (nonce_hash),
  KEY expires_at (expires_at)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL,
  event varchar(64) NOT NULL,
  message text NULL,
  context text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY level (level)
) {$charset};"
		);
		update_option( 'sitex_post_db_version', SITEX_POST_DB_VERSION, false );
	}

	public static function prune() {
		NonceStore::prune();
		Logger::prune();
	}
}
