<?php
/**
 * PSR-4 style autoloader for the SiteXPost\WP namespace (no Composer at runtime).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	const PREFIX = 'SiteXPost\\WP\\';

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		if ( strpos( $class, self::PREFIX ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( self::PREFIX ) );
		if ( ! preg_match( '/^[A-Za-z0-9\\\\]+$/', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
