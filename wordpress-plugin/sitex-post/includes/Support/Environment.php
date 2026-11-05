<?php
/**
 * Environment / capability detection (health endpoint and diagnostics tab).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Support;

use SiteXPost\WP\Seo\SeoManager;

defined( 'ABSPATH' ) || exit;

final class Environment {

	/**
	 * @return bool
	 */
	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
	}

	/**
	 * @return array
	 */
	public static function features() {
		return array(
			'woocommerce' => self::has_woocommerce() && post_type_exists( 'product' ),
			'yoast'       => defined( 'WPSEO_VERSION' ),
			'rank_math'   => defined( 'RANK_MATH_VERSION' ),
			'seo_adapter' => SeoManager::adapter()->name(),
			'pretty_permalinks' => '' !== (string) get_option( 'permalink_structure' ),
			'https'       => 0 === strpos( home_url( '/' ), 'https://' ),
			'pages'       => true,
			'featured_images' => current_theme_supports( 'post-thumbnails' ),
		);
	}

	/**
	 * @param int $user_id Publishing user.
	 * @return array
	 */
	public static function author( $user_id ) {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return array( 'exists' => false );
		}
		return array(
			'exists'            => true,
			'wp_user_id'        => (int) $user->ID,
			'display_name'      => $user->display_name,
			'can_publish'       => user_can( $user, 'publish_posts' ),
			'can_publish_pages' => user_can( $user, 'publish_pages' ),
			'can_upload'        => user_can( $user, 'upload_files' ),
			'can_edit_others'   => user_can( $user, 'edit_others_posts' ),
		);
	}

	/**
	 * @return array
	 */
	public static function versions() {
		$versions = array(
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'plugin'    => SITEX_POST_VERSION,
		);
		if ( defined( 'WC_VERSION' ) ) {
			$versions['woocommerce'] = WC_VERSION;
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$versions['yoast'] = WPSEO_VERSION;
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$versions['rank_math'] = RANK_MATH_VERSION;
		}
		return $versions;
	}

	/**
	 * @return int
	 */
	public static function max_upload_bytes() {
		return (int) min( wp_max_upload_size(), 10 * MB_IN_BYTES );
	}

	/**
	 * Checks shown on the Diagnostics tab: [key, status ok|warn|fail, label, detail].
	 *
	 * @return array
	 */
	public static function diagnostics() {
		global $wp_version;
		$checks   = array();
		$checks[] = array( 'php', version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'fail', __( 'PHP version', 'sitex-post' ), PHP_VERSION );
		$checks[] = array( 'wordpress', version_compare( $wp_version, '6.4', '>=' ) ? 'ok' : 'fail', __( 'WordPress version', 'sitex-post' ), $wp_version );
		$checks[] = array( 'https', 0 === strpos( home_url( '/' ), 'https://' ) ? 'ok' : 'fail', __( 'Site uses HTTPS', 'sitex-post' ), home_url( '/' ) );
		$cloud    = Connection::cloud_url();
		$checks[] = array( 'cloud_https', 0 === strpos( $cloud, 'https://' ) ? 'ok' : ( Connection::allow_insecure() ? 'warn' : 'fail' ), __( 'SiteX Post Cloud URL uses HTTPS', 'sitex-post' ), $cloud );
		$checks[] = array( 'crypto', function_exists( 'hash_hmac' ) && in_array( 'sha256', hash_algos(), true ) && function_exists( 'random_bytes' ) ? 'ok' : 'fail', __( 'HMAC-SHA256 and secure random available', 'sitex-post' ), '' );
		$checks[] = array( 'rest', self::rest_enabled() ? 'ok' : 'fail', __( 'REST API enabled', 'sitex-post' ), get_rest_url() );
		$checks[] = array( 'permalinks', '' !== (string) get_option( 'permalink_structure' ) ? 'ok' : 'warn', __( 'Pretty permalinks', 'sitex-post' ), '' !== (string) get_option( 'permalink_structure' ) ? (string) get_option( 'permalink_structure' ) : __( 'Plain (the connector falls back to ?rest_route=)', 'sitex-post' ) );
		$checks[] = array( 'uploads', wp_is_writable( wp_upload_dir()['basedir'] ) ? 'ok' : 'fail', __( 'Uploads folder writable', 'sitex-post' ), size_format( self::max_upload_bytes() ) );
		$checks[] = array( 'seo', 'ok', __( 'SEO metadata adapter', 'sitex-post' ), SeoManager::adapter()->label() );
		$checks[] = array( 'woocommerce', 'ok', __( 'WooCommerce', 'sitex-post' ), self::has_woocommerce() ? __( 'Detected: products will be synced', 'sitex-post' ) : __( 'Not installed', 'sitex-post' ) );
		$author   = self::author( Connection::author_id() );
		if ( Connection::is_connected() ) {
			$checks[] = array( 'author', ! empty( $author['can_publish'] ) && ! empty( $author['can_upload'] ) ? 'ok' : 'fail', __( 'Publishing account permissions', 'sitex-post' ), isset( $author['display_name'] ) ? $author['display_name'] : __( 'Missing', 'sitex-post' ) );
		}
		return $checks;
	}

	/**
	 * @return bool
	 */
	private static function rest_enabled() {
		/** This filter is documented in wp-includes/rest-api/class-wp-rest-server.php */
		$errors = apply_filters( 'rest_authentication_errors', null );
		return ! is_wp_error( $errors );
	}
}
