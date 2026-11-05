<?php
/**
 * REST API `sitex-post/v1` called by SiteX Post Cloud.
 *
 * Only /ping is public (and reveals nothing but the plugin version and
 * whether the site is paired). Every other route requires a valid HMAC
 * signature (RequestVerifier) and runs as the publishing user chosen at
 * pairing, with WordPress capability checks on every write.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Rest;

use SiteXPost\WP\Content\IndexService;
use SiteXPost\WP\Content\MediaService;
use SiteXPost\WP\Content\PostService;
use SiteXPost\WP\Security\RequestVerifier;
use SiteXPost\WP\Security\Signer;
use SiteXPost\WP\Support\Connection;
use SiteXPost\WP\Support\Environment;
use SiteXPost\WP\Support\Logger;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class RestController {

	const NAMESPACE_V1 = 'sitex-post/v1';

	/** @var string|null Key kept in memory to sign the answer to /disconnect after wiping it. */
	private static $farewell_secret = null;

	public function register_routes() {
		$signed = array( RequestVerifier::class, 'check' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'health' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/terms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'terms' ),
				'permission_callback' => $signed,
				'args'                => array(
					'taxonomy' => array( 'required' => true, 'type' => 'string', 'enum' => IndexService::TAXONOMIES ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'content' ),
				'permission_callback' => $signed,
				'args'                => array(
					'type'           => array( 'required' => true, 'type' => 'string', 'enum' => IndexService::TYPES ),
					'page'           => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'       => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 100 ),
					'modified_after' => array( 'type' => 'string', 'format' => 'date-time' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/media',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_media' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/credentials/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate' ),
				'permission_callback' => $signed,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/disconnect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => $signed,
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function ping() {
		return new WP_REST_Response(
			array(
				'plugin'  => 'sitex-post',
				'version' => SITEX_POST_VERSION,
				'paired'  => Connection::is_connected(),
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function health() {
		$c = Connection::get();
		return new WP_REST_Response(
			array(
				'ok'       => true,
				'site_id'  => isset( $c['site_id'] ) ? $c['site_id'] : null,
				'versions' => Environment::versions(),
				'features' => Environment::features(),
				'author'   => Environment::author( Connection::author_id() ),
				'limits'   => array( 'max_upload_bytes' => Environment::max_upload_bytes() ),
				'site'     => array(
					'name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'home_url' => home_url( '/' ),
					'locale'   => get_locale(),
					'timezone' => wp_timezone_string(),
				),
				'time'     => time(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function terms( WP_REST_Request $request ) {
		return $this->wrap( ( new IndexService() )->terms( (string) $request['taxonomy'], (int) $request['page'], (int) $request['per_page'] ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function content( WP_REST_Request $request ) {
		return $this->wrap( ( new IndexService() )->content( (string) $request['type'], (int) $request['page'], (int) $request['per_page'], (string) $request['modified_after'] ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_post( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return $this->wrap( ( new PostService() )->upsert( is_array( $params ) ? $params : array(), null ), 'created' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_post( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return $this->wrap( ( new PostService() )->upsert( is_array( $params ) ? $params : array(), (int) $request['id'] ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_media( WP_REST_Request $request ) {
		return $this->wrap( ( new MediaService() )->upload( $request ), 'created' );
	}

	/**
	 * Key rotation: install the new key, keep the current one as "previous"
	 * for the overlap window so requests already in flight still verify.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rotate( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$key_id = isset( $params['key_id'] ) ? (string) $params['key_id'] : '';
		$secret = isset( $params['secret'] ) ? (string) $params['secret'] : '';
		$grace  = isset( $params['grace_seconds'] ) ? max( 0, min( 3600, (int) $params['grace_seconds'] ) ) : 600;

		if ( ! preg_match( '/^[A-Za-z0-9]{10,40}$/', $key_id ) || ! preg_match( '/^[A-Za-z0-9_-]{40,128}$/', $secret ) ) {
			return new WP_Error( 'sitex_invalid_key', __( 'Invalid key material.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		$c = Connection::get();
		Connection::update(
			array(
				'previous_key' => array(
					'key_id'      => $c['key_id'],
					'secret'      => $c['secret'],
					'grace_until' => time() + $grace,
				),
				'key_id'       => $key_id,
				'secret'       => $secret,
				'rotated_at'   => time(),
			)
		);
		Logger::info( 'key_rotated', __( 'Connection key rotated by SiteX Post Cloud.', 'sitex-post' ), array( 'new_key' => substr( $key_id, -6 ) ) );

		return new WP_REST_Response( array( 'rotated' => true, 'key_id' => $key_id ) );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function disconnect() {
		self::$farewell_secret = Connection::secret_for( (string) RequestVerifier::verified_key() );
		Connection::forget( 'cloud' );
		Logger::info( 'disconnected', __( 'Disconnected by SiteX Post Cloud. The local key was deleted.', 'sitex-post' ) );
		return new WP_REST_Response( array( 'disconnected' => true ) );
	}

	/**
	 * Signs every response of a verified request. The body is encoded here
	 * (instead of by the REST server) so the signed bytes are exactly the
	 * bytes sent.
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_HTTP_Response $result  Result.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public function serve_signed_response( $served, $result, $request, $server ) {
		if ( $served || ! ( $request instanceof WP_REST_Request ) || 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE_V1 . '/' ) ) {
			return $served;
		}
		$nonce = RequestVerifier::verified_nonce();
		$key   = RequestVerifier::verified_key();
		if ( null === $nonce || null === $key ) {
			return $served;
		}
		$secret = Connection::secret_for( $key );
		if ( null === $secret ) {
			$secret = null !== self::$farewell_secret ? self::$farewell_secret : '';
		}

		$data      = $server->response_to_data( $result, false );
		$body      = (string) wp_json_encode( $data );
		$timestamp = time();

		if ( '' !== $secret ) {
			$server->send_header( Signer::HEADER_TIMESTAMP, (string) $timestamp );
			$server->send_header( Signer::HEADER_SIGNATURE, Signer::sign( Signer::response_canonical( $nonce, $timestamp, $body ), $secret ) );
		}
		$server->send_header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset' ) );
		$server->send_header( 'Cache-Control', 'no-store' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.
		return true;
	}

	/**
	 * @param array|WP_Error $result  Service result.
	 * @param string         $created Pass 'created' to answer 201 when result['created'] is true.
	 * @return WP_REST_Response|WP_Error
	 */
	private function wrap( $result, $created = '' ) {
		if ( is_wp_error( $result ) ) {
			Logger::warning( 'request_failed', $result->get_error_message(), array( 'code' => $result->get_error_code() ) );
			return $result;
		}
		$status = ( 'created' === $created && ! empty( $result['created'] ) ) ? 201 : 200;
		return new WP_REST_Response( $result, $status );
	}
}
