<?php
/**
 * Connection state stored in a single non-autoloaded option. The secret is
 * never printed, logged or returned by any endpoint.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Support;

defined( 'ABSPATH' ) || exit;

final class Connection {

	const OPTION = 'sitex_post_connection';

	/**
	 * @return array
	 */
	public static function get() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return bool
	 */
	public static function is_connected() {
		$c = self::get();
		return ! empty( $c['key_id'] ) && ! empty( $c['secret'] ) && 'connected' === ( isset( $c['status'] ) ? $c['status'] : '' );
	}

	/**
	 * @param array $data Connection data.
	 */
	public static function save( array $data ) {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $data, '', 'no' );
		} else {
			update_option( self::OPTION, $data, false );
		}
	}

	/**
	 * @param array $changes Fields to merge.
	 */
	public static function update( array $changes ) {
		self::save( array_merge( self::get(), $changes ) );
	}

	/**
	 * Wipes all key material, keeps a short public summary for the admin UI.
	 *
	 * @param string $reason Why.
	 */
	public static function forget( $reason ) {
		$c = self::get();
		self::save(
			array(
				'status'          => 'disconnected',
				'disconnected_at' => time(),
				'disconnect_reason' => sanitize_key( $reason ),
				'last_site_name'  => isset( $c['site_name'] ) ? $c['site_name'] : '',
				'last_workspace'  => isset( $c['workspace_name'] ) ? $c['workspace_name'] : '',
			)
		);
	}

	/**
	 * Secret for a key id: the current key, or the previous key during the
	 * rotation overlap window.
	 *
	 * @param string $key_id Key id from the request.
	 * @return string|null
	 */
	public static function secret_for( $key_id ) {
		$c = self::get();
		if ( ! self::is_connected() || ! is_string( $key_id ) || '' === $key_id ) {
			return null;
		}
		if ( hash_equals( (string) $c['key_id'], $key_id ) ) {
			return (string) $c['secret'];
		}
		if ( ! empty( $c['previous_key']['key_id'] ) && hash_equals( (string) $c['previous_key']['key_id'], $key_id )
			&& (int) $c['previous_key']['grace_until'] > time() ) {
			return (string) $c['previous_key']['secret'];
		}
		return null;
	}

	/**
	 * @return string
	 */
	public static function cloud_url() {
		return untrailingslashit( (string) SITEX_POST_CLOUD_URL );
	}

	/**
	 * Development escape hatch: http Cloud URL and local addresses are only
	 * allowed when explicitly enabled AND the site is a local/development
	 * environment. Never on production sites.
	 *
	 * @return bool
	 */
	public static function allow_insecure() {
		return defined( 'SITEX_POST_ALLOW_INSECURE' ) && SITEX_POST_ALLOW_INSECURE
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * @return int
	 */
	public static function author_id() {
		$c = self::get();
		return isset( $c['author_id'] ) ? (int) $c['author_id'] : 0;
	}
}
