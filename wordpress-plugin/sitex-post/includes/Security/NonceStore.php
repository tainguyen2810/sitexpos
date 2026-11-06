<?php
/**
 * Anti-replay nonce store backed by a table with a PRIMARY KEY on the nonce
 * hash: the INSERT is atomic, so a nonce can be used only once even under
 * concurrent requests (transients are not atomic).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Security;

use SiteXPost\WP\Support\Installer;

defined( 'ABSPATH' ) || exit;

final class NonceStore {

	const TTL = 600;

	/**
	 * @param string $key_id Key id.
	 * @param string $nonce  Nonce.
	 * @return bool true when the nonce was unused (and is now consumed)
	 */
	public static function claim( $key_id, $nonce ) {
		global $wpdb;
		$table = Installer::table( 'nonces' );
		$hash  = hash( 'sha256', $key_id . '|' . $nonce );

		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $table, array( 'nonce_hash' => $hash, 'expires_at' => time() + self::TTL ), array( '%s', '%d' ) );
		$wpdb->suppress_errors( $previous );

		return 1 === $inserted;
	}

	public static function prune() {
		global $wpdb;
		$table = Installer::table( 'nonces' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %d", time() ) );
	}
}
