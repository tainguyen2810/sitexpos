<?php
/**
 * SITEX-HMAC-V1 signing. Byte-for-byte compatible with the Cloud
 * implementation (backend/app/Modules/Sites/Connector/Signer.php).
 *
 * Canonical string, lines joined by "\n":
 *   SITEX-HMAC-V1, direction (c2w | w2c | resp), METHOD, path,
 *   canonical query (sorted, RFC 3986), timestamp, nonce, hex sha256(body)
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Security;

defined( 'ABSPATH' ) || exit;

final class Signer {

	const VERSION            = 'SITEX-HMAC-V1';
	const DIRECTION_TO_WP    = 'c2w';
	const DIRECTION_TO_CLOUD = 'w2c';
	const DIRECTION_RESPONSE = 'resp';

	const HEADER_KEY       = 'X-SiteX-Key-Id';
	const HEADER_TIMESTAMP = 'X-SiteX-Timestamp';
	const HEADER_NONCE     = 'X-SiteX-Nonce';
	const HEADER_SIGNATURE = 'X-SiteX-Signature';

	/**
	 * @param string $direction Direction label.
	 * @param string $method    HTTP method.
	 * @param string $path      REST route or Cloud path.
	 * @param array  $query     Query parameters (scalars only).
	 * @param int    $timestamp Unix seconds.
	 * @param string $nonce     Nonce.
	 * @param string $body      Raw body.
	 * @return string
	 */
	public static function canonical( $direction, $method, $path, array $query, $timestamp, $nonce, $body ) {
		return implode(
			"\n",
			array(
				self::VERSION,
				$direction,
				strtoupper( $method ),
				'/' . ltrim( $path, '/' ),
				self::canonical_query( $query ),
				(string) (int) $timestamp,
				$nonce,
				hash( 'sha256', (string) $body ),
			)
		);
	}

	/**
	 * @param array $query Query parameters.
	 * @return string
	 */
	public static function canonical_query( array $query ) {
		$pairs = array();
		foreach ( $query as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			if ( null === $value ) {
				$value = '';
			} elseif ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			}
			$pairs[ (string) $key ] = (string) $value;
		}
		ksort( $pairs, SORT_STRING );
		$out = array();
		foreach ( $pairs as $key => $value ) {
			$out[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
		return implode( '&', $out );
	}

	/**
	 * @param string $canonical Canonical string.
	 * @param string $secret    base64url secret.
	 * @return string base64 signature
	 */
	public static function sign( $canonical, $secret ) {
		return base64_encode( hash_hmac( 'sha256', $canonical, self::decode_secret( $secret ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $canonical Canonical string.
	 * @param string $secret    base64url secret.
	 * @param string $signature Signature to check.
	 * @return bool
	 */
	public static function verify( $canonical, $secret, $signature ) {
		return hash_equals( self::sign( $canonical, $secret ), (string) $signature );
	}

	/**
	 * @param string $request_nonce Nonce of the request being answered.
	 * @param int    $timestamp     Unix seconds.
	 * @param string $body          Response body.
	 * @return string
	 */
	public static function response_canonical( $request_nonce, $timestamp, $body ) {
		return implode( "\n", array( self::VERSION, self::DIRECTION_RESPONSE, $request_nonce, (string) (int) $timestamp, hash( 'sha256', (string) $body ) ) );
	}

	/**
	 * @return string
	 */
	public static function nonce() {
		return rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $nonce Nonce.
	 * @return bool
	 */
	public static function is_valid_nonce( $nonce ) {
		return is_string( $nonce ) && 1 === preg_match( '/^[A-Za-z0-9_-]{22,64}$/', $nonce );
	}

	/**
	 * @param string $secret base64url secret.
	 * @return string raw key
	 */
	private static function decode_secret( $secret ) {
		$raw = base64_decode( strtr( (string) $secret, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $raw ? (string) $secret : $raw;
	}
}
