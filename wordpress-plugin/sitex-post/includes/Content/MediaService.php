<?php
/**
 * Image upload pushed by Cloud as a raw request body (covered by the
 * signature). The plugin never downloads remote URLs (no SSRF from here).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Content;

use SiteXPost\WP\Support\Environment;
use SiteXPost\WP\Support\Logger;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class MediaService {

	const MIME = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'sitex_forbidden', __( 'The publishing account cannot upload files.', 'sitex-post' ), array( 'status' => 403 ) );
		}

		$key = (string) $request->get_param( 'idempotency_key' );
		if ( ! PostService::valid_key( $key ) ) {
			return new WP_Error( 'sitex_invalid_idempotency_key', __( 'A valid idempotency_key is required.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		$existing = $this->find( $key );
		if ( $existing ) {
			return $this->describe( $existing, false );
		}

		$bytes = (string) $request->get_body();
		$size  = strlen( $bytes );
		if ( 0 === $size ) {
			return new WP_Error( 'sitex_empty_file', __( 'Empty file.', 'sitex-post' ), array( 'status' => 422 ) );
		}
		if ( $size > Environment::max_upload_bytes() ) {
			return new WP_Error( 'sitex_file_too_large', __( 'The file exceeds the maximum upload size.', 'sitex-post' ), array( 'status' => 413 ) );
		}

		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		if ( '' === $filename ) {
			$filename = 'sitex-image';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'sitex_tmp_failed', __( 'Could not store the upload.', 'sitex-post' ), array( 'status' => 500 ) );
		}

		// Real content check: the extension is derived from the bytes, not trusted from the client.
		$check = wp_check_filetype_and_ext( $tmp, $filename, self::MIME );
		$info  = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIME, true ) || false === $info || ! in_array( $info['mime'], self::MIME, true ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'sitex_invalid_file_type', __( 'Only JPEG, PNG, GIF and WebP images are accepted.', 'sitex-post' ), array( 'status' => 415 ) );
		}
		$ext = array_search( $info['mime'], self::MIME, true );
		$ext = explode( '|', (string) $ext )[0];
		$name = preg_replace( '/\.[^.]+$/', '', $filename ) . '.' . $ext;

		$title     = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$id        = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			'' !== $title ? $title : null,
			array( 'post_excerpt' => sanitize_textarea_field( (string) $request->get_param( 'caption' ) ) )
		);
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'sitex_upload_failed', $id->get_error_message(), array( 'status' => 500 ) );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $request->get_param( 'alt' ) ) );
		update_post_meta( $id, PostService::META_MANAGED, '1' );
		update_post_meta( $id, PostService::META_KEY, $key );

		Logger::info( 'media_uploaded', sprintf( /* translators: %d: attachment ID */ __( 'Image #%d uploaded.', 'sitex-post' ), $id ), array( 'bytes' => $size ) );

		return $this->describe( get_post( $id ), true );
	}

	/**
	 * @param string $key Key.
	 * @return \WP_Post|null
	 */
	private function find( $key ) {
		$posts = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => PostService::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * @param \WP_Post $attachment Attachment.
	 * @param bool     $created    Newly created.
	 * @return array
	 */
	private function describe( $attachment, $created ) {
		$meta = wp_get_attachment_metadata( $attachment->ID );
		return array(
			'id'      => (int) $attachment->ID,
			'created' => (bool) $created,
			'url'     => wp_get_attachment_url( $attachment->ID ),
			'mime'    => get_post_mime_type( $attachment->ID ),
			'width'   => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'  => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		);
	}

	/**
	 * Image attachments of the Media Library, newest first (metadata only).
	 *
	 * @param int $page     Page.
	 * @param int $per_page Page size (max 100).
	 * @return array
	 */
	public function index( $page, $per_page ) {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array_values( self::MIME ),
				'posts_per_page'         => max( 1, min( 100, (int) $per_page ) ),
				'paged'                  => max( 1, (int) $page ),
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);
		$items = array();
		foreach ( $query->posts as $attachment ) {
			$meta    = wp_get_attachment_metadata( $attachment->ID );
			$file    = get_attached_file( $attachment->ID );
			$items[] = array(
				'id'           => (int) $attachment->ID,
				'url'          => wp_get_attachment_url( $attachment->ID ),
				'mime'         => get_post_mime_type( $attachment->ID ),
				'width'        => isset( $meta['width'] ) ? (int) $meta['width'] : null,
				'height'       => isset( $meta['height'] ) ? (int) $meta['height'] : null,
				'filesize'     => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : ( $file && file_exists( $file ) ? (int) filesize( $file ) : null ),
				'title'        => wp_strip_all_tags( get_the_title( $attachment ) ),
				'alt'          => wp_strip_all_tags( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) ),
				'caption'      => wp_strip_all_tags( (string) $attachment->post_excerpt ),
				'parent'       => (int) $attachment->post_parent,
				'managed'      => '1' === (string) get_post_meta( $attachment->ID, PostService::META_MANAGED, true ),
				'modified_gmt' => mysql2date( 'c', $attachment->post_modified_gmt, false ),
			);
		}
		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => max( 1, (int) $page ),
		);
	}
}
