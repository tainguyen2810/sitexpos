<?php
/**
 * Verifies Cloud → WordPress requests (permission_callback of every
 * non-public route): connection active, key id known, timestamp within
 * ±300 s, single-use nonce, HMAC over method + route + query + body.
 * On success the request runs as the WordPress user chosen at pairing.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Security;

use SiteXPost\WP\Support\Connection;
use SiteXPost\WP\Support\Logger;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class RequestVerifier {

	const CLOCK_SKEW = 300;

	/** @var string|null Nonce of the verified request (used to sign the response). */
	private static $verified_nonce = null;

	/** @var string|null */
	private static $verified_key = null;

	/**
	 * The request object that passed verification. WordPress calls the
	 * permission callback again for the same request when it builds the
	 * Allow header (rest_send_allow_header); that second call must not try
	 * to claim the nonce again, or it would wipe the verified state and the
	 * response would go out unsigned.
	 *
	 * @var WP_REST_Request|null
	 */
	private static $verified_request = null;

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		if ( null !== self::$verified_request && self::$verified_request === $request && null !== self::$verified_nonce ) {
			return true;
		}
		self::$verified_nonce   = null;
		self::$verified_key     = null;
		self::$verified_request = null;

		if ( ! Connection::is_connected() ) {
			return new WP_Error( 'sitex_not_connected', __( 'This site is not connected to SiteX Post Cloud.', 'sitex-post' ), array( 'status' => 401 ) );
		}

		$key_id    = (string) $request->get_header( 'x_sitex_key_id' );
		$timestamp = (string) $request->get_header( 'x_sitex_timestamp' );
		$nonce     = (string) $request->get_header( 'x_sitex_nonce' );
		$signature = (string) $request->get_header( 'x_sitex_signature' );

		if ( '' === $key_id || '' === $signature || ! ctype_digit( $timestamp ) || ! Signer::is_valid_nonce( $nonce ) ) {
			return self::reject( 'missing_headers' );
		}
		if ( abs( time() - (int) $timestamp ) > self::CLOCK_SKEW ) {
			return self::reject( 'timestamp_out_of_window' );
		}

		$secret = Connection::secret_for( $key_id );
		if ( null === $secret ) {
			return self::reject( 'unknown_key' );
		}

		$query = $request->get_query_params();
		unset( $query['rest_route'] );

		$canonical = Signer::canonical( Signer::DIRECTION_TO_WP, $request->get_method(), $request->get_route(), $query, (int) $timestamp, $nonce, (string) $request->get_body() );
		if ( ! Signer::verify( $canonical, $secret, $signature ) ) {
			return self::reject( 'bad_signature' );
		}

		if ( ! NonceStore::claim( $key_id, $nonce ) ) {
			return self::reject( 'nonce_reused' );
		}

		$author = Connection::author_id();
		if ( $author <= 0 || ! get_userdata( $author ) ) {
			return new WP_Error( 'sitex_author_missing', __( 'The publishing account selected during pairing no longer exists.', 'sitex-post' ), array( 'status' => 403 ) );
		}
		wp_set_current_user( $author );

		self::$verified_nonce   = $nonce;
		self::$verified_key     = $key_id;
		self::$verified_request = $request;
		Connection::update( array( 'last_cloud_request_at' => time() ) );

		return true;
	}

	/**
	 * @return string|null
	 */
	public static function verified_nonce() {
		return self::$verified_nonce;
	}

	/**
	 * @return string|null
	 */
	public static function verified_key() {
		return self::$verified_key;
	}

	/**
	 * Same generic error for every failure (no oracle); the reason is logged.
	 *
	 * @param string $reason Internal reason.
	 * @return WP_Error
	 */
	private static function reject( $reason ) {
		Logger::warning( 'rejected_request', __( 'Rejected a request with an invalid signature.', 'sitex-post' ), array( 'reason' => $reason, 'ip' => self::client_ip() ) );
		return new WP_Error( 'sitex_invalid_signature', __( 'Invalid or expired signature.', 'sitex-post' ), array( 'status' => 401 ) );
	}

	/**
	 * @return string
	 */
	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
