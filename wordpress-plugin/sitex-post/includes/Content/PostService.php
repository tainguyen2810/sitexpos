<?php
/**
 * Create / update posts and pages pushed by Cloud, idempotently.
 *
 * - The idempotency key identifies one logical document. A POST with a key
 *   that already exists updates the existing post (never a duplicate), even
 *   if Cloud lost the first response.
 * - Concurrent requests with the same key are serialised with an atomic
 *   option-based lock.
 * - PUT only touches posts created by SiteX Post (meta _sitex_post_managed).
 * - Capabilities of the publishing user are checked for every write.
 * - Conflict detection (Phase 6): after every write the hash of the saved
 *   post_content is stored (_sitex_post_written_hash). An update is refused
 *   with 409 `sitex_conflict` when the post was edited in WordPress since
 *   (hash differs), or when it differs from the `expected` state Cloud based
 *   the update on (content hash, slug, SEO hash). `force` (a human approved
 *   the overwrite in Cloud) skips the manual-edit check but still requires
 *   `expected.content_hash` to match, so a second edit made after the
 *   approval is never overwritten.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Content;

use SiteXPost\WP\Security\HtmlSanitizer;
use SiteXPost\WP\Seo\SeoManager;
use SiteXPost\WP\Support\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PostService {

	const META_MANAGED = '_sitex_post_managed';
	const META_KEY     = '_sitex_post_idempotency_key';
	const META_SOURCE  = '_sitex_post_source';
	const META_WRITTEN = '_sitex_post_written_hash';
	const META_SCHEMA  = '_sitex_post_schema';
	const STATUSES     = array( 'draft', 'pending', 'publish', 'future' );
	const TYPES        = array( 'post', 'page' );

	/**
	 * @param array    $data Validated below.
	 * @param int|null $id   Target post for PUT.
	 * @return array|WP_Error
	 */
	public function upsert( array $data, $id ) {
		$key = isset( $data['idempotency_key'] ) ? (string) $data['idempotency_key'] : '';
		if ( ! self::valid_key( $key ) ) {
			return new WP_Error( 'sitex_invalid_idempotency_key', __( 'A valid idempotency_key is required.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		$type = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'sitex_invalid_post_type', __( 'Unsupported post type.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		if ( '' === $title || strlen( $title ) > 500 ) {
			return new WP_Error( 'sitex_invalid_title', __( 'A title (max 500 characters) is required.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		$status = isset( $data['status'] ) ? (string) $data['status'] : 'draft';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'sitex_invalid_status', __( 'Unsupported post status.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		$lock = 'sitex_post_lock_' . md5( $key );
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			$since = (int) get_option( $lock );
			if ( $since > time() - 60 ) {
				return new WP_Error( 'sitex_in_progress', __( 'The same document is being processed. Retry shortly.', 'sitex-post' ), array( 'status' => 409 ) );
			}
			update_option( $lock, time(), false ); // stale lock from a crashed request.
		}

		try {
			return $this->write( $data, $id, $key, $type, $title, $status );
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * @param array    $data   Input.
	 * @param int|null $id     Target id (PUT) or null (POST).
	 * @param string   $key    Idempotency key.
	 * @param string   $type   post|page.
	 * @param string   $title  Sanitized title.
	 * @param string   $status Status.
	 * @return array|WP_Error
	 */
	private function write( array $data, $id, $key, $type, $title, $status ) {
		$existing = self::find_by_key( $key );

		if ( null !== $id ) {
			$post = get_post( $id );
			if ( ! $post || 'trash' === $post->post_status ) {
				return new WP_Error( 'sitex_post_not_found', __( 'Post not found.', 'sitex-post' ), array( 'status' => 404 ) );
			}
			if ( '1' !== (string) get_post_meta( $post->ID, self::META_MANAGED, true ) ) {
				return new WP_Error( 'sitex_not_managed', __( 'This post was not created by SiteX Post and cannot be modified by it.', 'sitex-post' ), array( 'status' => 403 ) );
			}
			if ( null !== $existing && (int) $existing->ID !== (int) $post->ID ) {
				return new WP_Error( 'sitex_idempotency_conflict', __( 'The idempotency key belongs to another post.', 'sitex-post' ), array( 'status' => 409 ) );
			}
			$existing = $post;
		}

		if ( null !== $existing && 'trash' === $existing->post_status ) {
			return new WP_Error( 'sitex_post_trashed', __( 'The post for this document was moved to the trash on WordPress.', 'sitex-post' ), array( 'status' => 410 ) );
		}

		if ( null !== $existing ) {
			$expected  = isset( $data['expected'] ) && is_array( $data['expected'] ) ? $data['expected'] : array();
			$conflicts = self::conflicts( $existing, $expected, ! empty( $data['force'] ) );
			if ( $conflicts ) {
				Logger::warning( 'conflict', sprintf( /* translators: %d: post ID */ __( 'Update of post #%d refused: it was changed in WordPress.', 'sitex-post' ), $existing->ID ), array( 'types' => implode( ',', $conflicts ) ) );
				return new WP_Error(
					'sitex_conflict',
					__( 'The post was changed in WordPress since SiteX Post last wrote it. Resolve the conflict in SiteX Post Cloud.', 'sitex-post' ),
					array(
						'status'    => 409,
						'conflicts' => $conflicts,
						'state'     => self::state( $existing ),
					)
				);
			}
		}

		$type_object = get_post_type_object( $existing ? $existing->post_type : $type );
		if ( ! $type_object ) {
			return new WP_Error( 'sitex_invalid_post_type', __( 'Unsupported post type.', 'sitex-post' ), array( 'status' => 422 ) );
		}

		if ( $existing ) {
			if ( ! current_user_can( 'edit_post', $existing->ID ) ) {
				return self::forbidden();
			}
		} elseif ( ! current_user_can( $type_object->cap->create_posts ) ) {
			return self::forbidden();
		}
		if ( in_array( $status, array( 'publish', 'future' ), true ) && ! current_user_can( $type_object->cap->publish_posts ) ) {
			return new WP_Error( 'sitex_cannot_publish', __( 'The publishing account is not allowed to publish. Use draft or pending status.', 'sitex-post' ), array( 'status' => 403 ) );
		}

		$postarr = array(
			'post_type'    => $existing ? $existing->post_type : $type,
			'post_title'   => $title,
			'post_content' => HtmlSanitizer::clean( isset( $data['content_html'] ) ? (string) $data['content_html'] : '' ),
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( (string) $data['excerpt'] ) : '',
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $data['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( 'future' === $status ) {
			$date = isset( $data['date_gmt'] ) ? strtotime( (string) $data['date_gmt'] ) : false;
			if ( ! $date || $date <= time() ) {
				return new WP_Error( 'sitex_invalid_date', __( 'Scheduled posts need a future date_gmt.', 'sitex-post' ), array( 'status' => 422 ) );
			}
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $date );
			$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
		}
		if ( $existing ) {
			$postarr['ID']          = $existing->ID;
			$postarr['post_author'] = $existing->post_author;
		}

		// SEO meta is written BEFORE the post is saved (meta_input for new posts),
		// so save_post listeners of Yoast / Rank Math index the final values.
		$seo_input = isset( $data['seo'] ) && is_array( $data['seo'] ) ? $data['seo'] : array();
		if ( $existing ) {
			SeoManager::apply( $existing->ID, $seo_input );
		} else {
			$postarr['meta_input'] = SeoManager::meta_input( $seo_input );
		}

		// Content was already sanitized with our allowlist; prevent a second kses pass from mangling block markup.
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$post_id = $existing ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
		if ( $kses ) {
			kses_init_filters();
		}
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'sitex_write_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, self::META_MANAGED, '1' );
		update_post_meta( $post_id, self::META_KEY, $key );
		update_post_meta( $post_id, '_sitex_post_updated_at', time() );
		if ( isset( $data['sitex'] ) && is_array( $data['sitex'] ) ) {
			update_post_meta( $post_id, self::META_SOURCE, wp_json_encode( array_map( 'sanitize_text_field', array_filter( $data['sitex'], 'is_scalar' ) ) ) );
		}

		if ( 'post' === get_post_type( $post_id ) ) {
			$this->assign_terms( $post_id, $data );
		}

		$featured = $this->set_featured( $post_id, isset( $data['featured_media_id'] ) ? (int) $data['featured_media_id'] : 0 );
		if ( is_wp_error( $featured ) ) {
			return $featured;
		}

		$seo = $existing ? SeoManager::adapter()->name() : SeoManager::apply( $post_id, $seo_input );

		if ( array_key_exists( 'schema', $data ) ) {
			$schema = is_array( $data['schema'] ) ? self::clean_schema( $data['schema'], 0 ) : array();
			if ( $schema ) {
				update_post_meta( $post_id, self::META_SCHEMA, wp_slash( (string) wp_json_encode( $schema ) ) );
			} else {
				delete_post_meta( $post_id, self::META_SCHEMA );
			}
		}

		$saved = get_post( $post_id );
		update_post_meta( $post_id, self::META_WRITTEN, self::content_hash( $saved ) );
		$state = self::state( $saved );

		Logger::info(
			$existing ? 'post_updated' : 'post_created',
			sprintf( /* translators: %d: post ID */ __( 'Post #%d saved.', 'sitex-post' ), $post_id ),
			array( 'post_id' => $post_id, 'status' => get_post_status( $post_id ) )
		);

		return array(
			'id'          => (int) $post_id,
			'created'     => ! $existing,
			'post_type'   => get_post_type( $post_id ),
			'status'      => get_post_status( $post_id ),
			'url'         => get_permalink( $post_id ),
			'seo_adapter' => $seo,
			'state'       => $state,
		);
	}

	/**
	 * Why an update must not overwrite the post (empty = safe).
	 *
	 * @param \WP_Post $post     Current post.
	 * @param array    $expected State Cloud based the update on.
	 * @param bool     $force    A human approved overwriting manual edits.
	 * @return string[]
	 */
	public static function conflicts( $post, array $expected, $force ) {
		$out     = array();
		$current = self::content_hash( $post );
		$written = (string) get_post_meta( $post->ID, self::META_WRITTEN, true );

		if ( ! $force ) {
			if ( '' !== $written && ! hash_equals( $written, $current ) ) {
				$out[] = 'manual_edit';
			} elseif ( '' === $written ) {
				// Posts written before Phase 6: compare the modification time with our last write.
				$ours = (int) get_post_meta( $post->ID, '_sitex_post_updated_at', true );
				if ( $ours > 0 && strtotime( $post->post_modified_gmt . ' UTC' ) > $ours + 5 ) {
					$out[] = 'manual_edit';
				}
			}
			if ( isset( $expected['slug'] ) && is_string( $expected['slug'] ) && '' !== $expected['slug'] && $expected['slug'] !== $post->post_name ) {
				$out[] = 'slug_changed';
			}
			if ( isset( $expected['seo_hash'] ) && is_string( $expected['seo_hash'] ) && '' !== $expected['seo_hash'] && ! hash_equals( $expected['seo_hash'], SeoManager::state_hash( $post->ID ) ) ) {
				$out[] = 'seo_modified';
			}
		}
		if ( isset( $expected['content_hash'] ) && is_string( $expected['content_hash'] ) && '' !== $expected['content_hash'] && ! hash_equals( $expected['content_hash'], $current ) ) {
			$out[] = 'manual_edit';
		} elseif ( $force && ( ! isset( $expected['content_hash'] ) || ! is_string( $expected['content_hash'] ) || '' === $expected['content_hash'] ) ) {
			$out[] = 'expected_state_required';
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Current state of a post for Cloud (conflict detection, reconciliation).
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function state( $post ) {
		$revisions = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1, 'fields' => 'ids' ) );
		$seo       = SeoManager::read( $post->ID );
		return array(
			'id'                => (int) $post->ID,
			'post_type'         => $post->post_type,
			'status'            => $post->post_status,
			'title'             => get_the_title( $post ),
			'slug'              => $post->post_name,
			'url'               => get_permalink( $post ),
			'modified_gmt'      => mysql2date( 'c', $post->post_modified_gmt, false ),
			'content_hash'      => self::content_hash( $post ),
			'written_hash'      => (string) get_post_meta( $post->ID, self::META_WRITTEN, true ),
			'managed'           => '1' === (string) get_post_meta( $post->ID, self::META_MANAGED, true ),
			'idempotency_key'   => (string) get_post_meta( $post->ID, self::META_KEY, true ),
			'revision_id'       => $revisions ? (int) reset( $revisions ) : null,
			'featured_media_id' => (int) get_post_thumbnail_id( $post->ID ),
			'seo'               => $seo,
			'seo_hash'          => SeoManager::state_hash( $post->ID ),
			'seo_adapter'       => SeoManager::adapter()->name(),
		);
	}

	/**
	 * @param \WP_Post|null $post Post.
	 * @return string
	 */
	public static function content_hash( $post ) {
		return hash( 'sha256', $post ? (string) $post->post_content : '' );
	}

	/**
	 * JSON-LD from Cloud: scalars sanitized, depth and size bounded. It is
	 * printed with wp_json_encode + JSON_HEX_TAG, so it cannot close the
	 * script element.
	 *
	 * @param array $node  Node.
	 * @param int   $depth Depth.
	 * @return array
	 */
	private static function clean_schema( array $node, $depth ) {
		if ( $depth > 6 ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $node, 0, 60, true ) as $k => $v ) {
			$key = is_int( $k ) ? $k : preg_replace( '/[^A-Za-z0-9@_:-]/', '', (string) $k );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $key ] = self::clean_schema( $v, $depth + 1 );
			} elseif ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
				$out[ $key ] = $v;
			} elseif ( is_string( $v ) ) {
				$out[ $key ] = preg_match( '#^https?://#i', $v ) ? esc_url_raw( $v ) : mb_substr( sanitize_text_field( $v ), 0, 2000 );
			}
		}
		return $out;
	}

	/**
	 * @param int   $post_id Post.
	 * @param array $data    Input.
	 */
	private function assign_terms( $post_id, array $data ) {
		if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
			$ids = array();
			foreach ( $data['categories'] as $cat ) {
				$term = get_term( (int) $cat, 'category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ( $ids ) {
				wp_set_post_categories( $post_id, $ids, false );
			}
		}
		if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$tags = array_slice( array_filter( array_map( 'sanitize_text_field', array_filter( $data['tags'], 'is_scalar' ) ) ), 0, 30 );
			wp_set_post_tags( $post_id, $tags, false );
		}
	}

	/**
	 * Any image of the Media Library (uploaded by SiteX Post or chosen by the
	 * customer from the library) can be the featured image.
	 *
	 * @param int $post_id  Post.
	 * @param int $media_id Attachment.
	 * @return true|WP_Error
	 */
	private function set_featured( $post_id, $media_id ) {
		if ( $media_id <= 0 ) {
			return true;
		}
		$attachment = get_post( $media_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $media_id ) ) {
			return new WP_Error( 'sitex_invalid_featured_media', __( 'The featured image must be an image of the Media Library.', 'sitex-post' ), array( 'status' => 422 ) );
		}
		set_post_thumbnail( $post_id, $media_id );
		if ( ! $attachment->post_parent ) {
			wp_update_post( array( 'ID' => $media_id, 'post_parent' => $post_id ) );
		}
		return true;
	}

	/**
	 * @param string $key Idempotency key.
	 * @return \WP_Post|null
	 */
	public static function find_by_key( $key, $post_type = 'any_content' ) {
		$types = 'any_content' === $post_type ? self::TYPES : array( $post_type );
		$posts = get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'meta_key'               => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'             => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * @param string $key Key.
	 * @return bool
	 */
	public static function valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9:._-]{8,100}$/', $key );
	}

	/**
	 * @return WP_Error
	 */
	private static function forbidden() {
		return new WP_Error( 'sitex_forbidden', __( 'The publishing account does not have permission for this action.', 'sitex-post' ), array( 'status' => 403 ) );
	}
}
