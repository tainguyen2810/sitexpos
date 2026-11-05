<?php
/**
 * Connector log (shown in WP admin → SiteX Post → Logs). Never logs secrets
 * or request bodies. Keeps the newest 500 rows.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Support;

defined( 'ABSPATH' ) || exit;

final class Logger {

	const MAX_ROWS = 500;

	/**
	 * @param string $event   Machine event name.
	 * @param string $message Human message.
	 * @param array  $context Extra safe context.
	 */
	public static function info( $event, $message = '', array $context = array() ) {
		self::write( 'info', $event, $message, $context );
	}

	/**
	 * @param string $event   Machine event name.
	 * @param string $message Human message.
	 * @param array  $context Extra safe context.
	 */
	public static function warning( $event, $message = '', array $context = array() ) {
		self::write( 'warning', $event, $message, $context );
	}

	/**
	 * @param string $event   Machine event name.
	 * @param string $message Human message.
	 * @param array  $context Extra safe context.
	 */
	public static function error( $event, $message = '', array $context = array() ) {
		self::write( 'error', $event, $message, $context );
	}

	/**
	 * @param string $level   Level.
	 * @param string $event   Event.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	private static function write( $level, $event, $message, array $context ) {
		global $wpdb;
		foreach ( array_keys( $context ) as $key ) {
			if ( preg_match( '/secret|signature|token|password|key$/i', (string) $key ) ) {
				$context[ $key ] = '[redacted]';
			}
		}
		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Installer::table( 'logs' ),
			array(
				'level'      => $level,
				'event'      => substr( sanitize_key( $event ), 0, 64 ),
				'message'    => substr( wp_strip_all_tags( (string) $message ), 0, 1000 ),
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous );
	}

	/**
	 * @param int    $limit Max rows.
	 * @param string $level Optional level filter.
	 * @return array
	 */
	public static function recent( $limit = 100, $level = '' ) {
		global $wpdb;
		$table = Installer::table( 'logs' );
		if ( in_array( $level, array( 'info', 'warning', 'error' ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d", $level, (int) $limit ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
	}

	public static function clear() {
		global $wpdb;
		$table = Installer::table( 'logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	public static function prune() {
		global $wpdb;
		$table = Installer::table( 'logs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $cutoff > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
		}
	}
}
