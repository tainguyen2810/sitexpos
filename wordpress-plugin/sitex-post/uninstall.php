<?php
/**
 * Uninstall: removes the connection key, options and plugin tables.
 * Posts and media created by SiteX Post stay on the site (they belong to the
 * site owner); only internal lock options are cleaned.
 *
 * @package SiteXPost\WP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'sitex_post_connection' );
delete_option( 'sitex_post_db_version' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sitex\\_post\\_lock\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sitex\\_post\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sitex\\_post\\_%'" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sitex_post_nonces" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sitex_post_logs" );
// phpcs:enable

wp_clear_scheduled_hook( 'sitex_post_daily' );
