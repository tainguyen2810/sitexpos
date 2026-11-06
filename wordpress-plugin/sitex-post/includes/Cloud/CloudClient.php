<?php
/**
 * Plugin → Cloud calls: pairing (code based) and signed heartbeat / revoke.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Cloud;

use SiteXPost\WP\Security\Signer;
use SiteXPost\WP\Support\Connection;
use SiteXPost\WP\Support\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CloudClient {

	/**
	 * @return bool
	 */
	public function is_connected() {
		return Connection::is_connected();
	}

	/**
	 * Step 1: ask Cloud who issued this code (nothing is granted yet).
	 *
	 * @param string $code Pairing code.
	 * @return array|WP_Error
	 */
	public function preview( $code ) {
		return $this->post(
			'pair/preview',
			array(
				'pairing_code' => $code,
				'site_url'     => home_url( '/' ),
			),
			false
		);
	}

	/**
	 * Step 2: the admin confirmed. Cloud returns the per-site key once.
	 *
	 * @param string $code      Pairing code.
	 * @param int    $author_id WP user that will own published content.
	 * @return array|WP_Error
	 */
	public function pair( $code, $author_id ) {
		$author = get_userdata( $author_id );
		$admin  = wp_get_current_user();

		$result = $this->post(
			'pair',
			array(
				'pairing_code'   => $code,
				'site_url'       => home_url( '/' ),
				'rest_url'       => get_rest_url(),
				'publish_author' => array(
					'wp_user_id'   => (int) $author_id,
					'display_name' => $author ? $author->display_name : '',
				),
				'confirmed_by'   => array(
					'wp_user_id'   => (int) $admin->ID,
					'display_name' => $admin->display_name,
				),
				'environment'    => array(
					'wp_version'     => get_bloginfo( 'version' ),
					'php_version'    => PHP_VERSION,
					'plugin_version' => SITEX_POST_VERSION,
					'locale'         => get_locale(),
				),
			),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		if ( empty( $data['key_id'] ) || empty( $data['secret'] ) || ! is_string( $data['secret'] ) || strlen( $data['secret'] ) < 40 ) {
			return new WP_Error( 'sitex_invalid_pair_response', __( 'SiteX Post Cloud returned an invalid pairing response.', 'sitex-post' ) );
		}

		Connection::save(
			array(
				'status'         => 'connected',
				'site_id'        => sanitize_text_field( (string) $data['site_id'] ),
				'key_id'         => sanitize_text_field( (string) $data['key_id'] ),
				'secret'         => (string) $data['secret'],
				'site_name'      => sanitize_text_field( (string) ( isset( $data['site_name'] ) ? $data['site_name'] : '' ) ),
				'workspace_name' => sanitize_text_field( (string) ( isset( $data['workspace_name'] ) ? $data['workspace_name'] : '' ) ),
				'author_id'      => (int) $author_id,
				'connected_at'   => time(),
				'connected_by'   => (int) $admin->ID,
				'cloud_url'      => Connection::cloud_url(),
			)
		);
		Logger::info( 'paired', __( 'Connected to SiteX Post Cloud.', 'sitex-post' ), array( 'site_id' => $data['site_id'], 'confirmed_by' => (int) $admin->ID, 'author_id' => (int) $author_id ) );

		$heartbeat = $this->heartbeat( 'paired' );
		if ( is_wp_error( $heartbeat ) ) {
			Logger::warning( 'heartbeat_failed', $heartbeat->get_error_message() );
		}

		return $data;
	}

	/**
	 * @param string $reason Why the heartbeat is sent.
	 * @return array|WP_Error
	 */
	public function heartbeat( $reason ) {
		$result = $this->post(
			'heartbeat',
			array(
				'site_url'       => home_url( '/' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'plugin_version' => SITEX_POST_VERSION,
				'reason'         => $reason,
			),
			true
		);
		if ( ! is_wp_error( $result ) ) {
			Connection::update( array( 'last_heartbeat_at' => time() ) );
		}
		return $result;
	}

	/**
	 * Tell Cloud we disconnect (best effort), then wipe the key locally no
	 * matter what: the WordPress admin always has the last word.
	 *
	 * @return true|WP_Error WP_Error only to report that Cloud was not notified.
	 */
	public function disconnect() {
		$result = $this->is_connected() ? $this->post( 'revoke', array( 'reason' => 'disconnected_from_wordpress' ), true ) : true;
		Connection::forget( 'wordpress' );
		Logger::info( 'disconnected', __( 'Disconnected from SiteX Post Cloud by a WordPress administrator.', 'sitex-post' ), array( 'user' => get_current_user_id() ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @param string $endpoint Endpoint relative to /connector/v1/.
	 * @param array  $payload  JSON body.
	 * @param bool   $signed   Sign with the site key.
	 * @return array|WP_Error
	 */
	private function post( $endpoint, array $payload, $signed ) {
		$base = Connection::cloud_url();
		if ( 0 !== strpos( $base, 'https://' ) && ! Connection::allow_insecure() ) {
			return new WP_Error( 'sitex_insecure_cloud_url', __( 'The SiteX Post Cloud URL must use HTTPS.', 'sitex-post' ) );
		}

		$path    = '/connector/v1/' . $endpoint;
		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		if ( $signed ) {
			$c = Connection::get();
			if ( ! Connection::is_connected() ) {
				return new WP_Error( 'sitex_not_connected', __( 'This site is not connected.', 'sitex-post' ) );
			}
			$timestamp                          = time();
			$nonce                              = Signer::nonce();
			$headers[ Signer::HEADER_KEY ]       = $c['key_id'];
			$headers[ Signer::HEADER_TIMESTAMP ] = (string) $timestamp;
			$headers[ Signer::HEADER_NONCE ]     = $nonce;
			$headers[ Signer::HEADER_SIGNATURE ] = Signer::sign( Signer::canonical( Signer::DIRECTION_TO_CLOUD, 'POST', $path, array(), $timestamp, $nonce, $body ), $c['secret'] );
		}

		$args = array(
			'method'      => 'POST',
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
			'user-agent'  => 'SiteXPostPlugin/' . SITEX_POST_VERSION . '; ' . home_url( '/' ),
		);

		$url      = $base . $path;
		$response = Connection::allow_insecure() ? wp_remote_post( $url, $args ) : wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'cloud_unreachable', $response->get_error_message(), array( 'endpoint' => $endpoint ) );
			return new WP_Error( 'sitex_cloud_unreachable', __( 'Could not reach SiteX Post Cloud. Please try again.', 'sitex-post' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$code    = is_array( $decoded ) && isset( $decoded['error']['code'] ) ? sanitize_key( $decoded['error']['code'] ) : 'http_' . $status;
			$message = is_array( $decoded ) && isset( $decoded['error']['message'] ) ? sanitize_text_field( (string) $decoded['error']['message'] ) : __( 'SiteX Post Cloud rejected the request.', 'sitex-post' );
			if ( 401 === $status && $signed ) {
				$message = __( 'SiteX Post Cloud no longer accepts this site key (it may have been revoked). Disconnect and pair again.', 'sitex-post' );
			}
			Logger::warning( 'cloud_rejected', $message, array( 'endpoint' => $endpoint, 'status' => $status, 'code' => $code ) );
			return new WP_Error( 'sitex_' . $code, $message, array( 'status' => $status ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
