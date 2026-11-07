<?php
/**
 * Read-only index of taxonomies and content (posts, pages, WooCommerce
 * products) for Cloud's content map and internal linking.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Content;

use SiteXPost\WP\Support\Environment;
use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class IndexService {

	const TAXONOMIES = array( 'category', 'post_tag', 'product_cat', 'product_tag' );
	const TYPES      = array( 'post', 'page', 'product' );
	const STATUSES   = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param int    $page     Page (1-based).
	 * @param int    $per_page Page size.
	 * @return array|WP_Error
	 */
	public function terms( $taxonomy, $page, $per_page ) {
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) || ! taxonomy_exists( $taxonomy ) ) {
			return array( 'items' => array(), 'total' => 0, 'total_pages' => 0, 'page' => $page );
		}
		$per_page = max( 1, min( 100, $per_page ) );
		$total    = (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( max( 1, $page ) - 1 ) * $per_page,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return new WP_Error( 'sitex_terms_failed', $terms->get_error_message(), array( 'status' => 500 ) );
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'     => (int) $term->term_id,
				'name'   => wp_specialchars_decode( $term->name, ENT_QUOTES ),
				'slug'   => $term->slug,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * Public author info (id + display name, as shown on the author archive).
	 * Never the login, e-mail or any other account field.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array|null
	 */
	private function author( $user_id ) {
		if ( $user_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		return array(
			'id'   => $user_id,
			'name' => wp_strip_all_tags( (string) $user->display_name ),
		);
	}

	/**
	 * @param string $type           post|page|product.
	 * @param int    $page           Page.
	 * @param int    $per_page       Page size.
	 * @param string $modified_after ISO date (optional, incremental sync).
	 * @return array|WP_Error
	 */
	public function content( $type, $page, $per_page, $modified_after = '' ) {
		if ( ! in_array( $type, self::TYPES, true ) || ! post_type_exists( $type ) || ( 'product' === $type && ! Environment::has_woocommerce() ) ) {
			return array( 'items' => array(), 'total' => 0, 'total_pages' => 0, 'page' => $page );
		}
		$per_page = max( 1, min( 100, $per_page ) );
		$args     = array(
			'post_type'              => $type,
			'post_status'            => self::STATUSES,
			'posts_per_page'         => $per_page,
			'paged'                  => max( 1, $page ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'suppress_filters'       => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
		);
		if ( '' !== $modified_after && strtotime( $modified_after ) ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', strtotime( $modified_after ) ),
				),
			);
		}
		$query = new WP_Query( $args );

		$taxonomies = 'product' === $type ? array( 'product_cat', 'product_tag' ) : ( 'post' === $type ? array( 'category', 'post_tag' ) : array() );
		$items      = array();
		foreach ( $query->posts as $post ) {
			$term_ids = array();
			foreach ( $taxonomies as $tax ) {
				if ( taxonomy_exists( $tax ) ) {
					$ids = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $ids ) ) {
						$term_ids = array_merge( $term_ids, array_map( 'intval', $ids ) );
					}
				}
			}
			$excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 40, '…' );
			$item    = array(
				'id'           => (int) $post->ID,
				'status'       => $post->post_status,
				'title'        => wp_strip_all_tags( get_the_title( $post ) ),
				'url'          => 'publish' === $post->post_status ? get_permalink( $post ) : null,
				'slug'         => $post->post_name,
				'excerpt'      => wp_strip_all_tags( $excerpt ),
				'date_gmt'     => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : mysql2date( 'c', $post->post_date_gmt, false ),
				'modified_gmt' => mysql2date( 'c', $post->post_modified_gmt, false ),
				'term_ids'     => array_values( array_unique( $term_ids ) ),
				'managed'      => '1' === (string) get_post_meta( $post->ID, PostService::META_MANAGED, true ),
				'author'       => $this->author( (int) $post->post_author ),
			);
			if ( 'product' === $type ) {
				$item['product'] = $this->product( $post->ID );
			}
			$items[] = $item;
		}

		return array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * @param int $post_id Product id.
	 * @return array
	 */
	private function product( $post_id ) {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (string) get_option( 'woocommerce_currency', '' );
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				return array(
					'type'          => $product->get_type(),
					'sku'           => (string) $product->get_sku(),
					'price'         => (string) $product->get_price(),
					'regular_price' => (string) $product->get_regular_price(),
					'sale_price'    => (string) $product->get_sale_price(),
					'stock_status'  => (string) $product->get_stock_status(),
					'currency'      => $currency,
					'image_ids'     => self::image_ids( $post_id, method_exists( $product, 'get_gallery_image_ids' ) ? $product->get_gallery_image_ids() : array() ),
				);
			}
		}
		return array(
			'type'          => 'simple',
			'sku'           => (string) get_post_meta( $post_id, '_sku', true ),
			'price'         => (string) get_post_meta( $post_id, '_price', true ),
			'regular_price' => (string) get_post_meta( $post_id, '_regular_price', true ),
			'sale_price'    => (string) get_post_meta( $post_id, '_sale_price', true ),
			'stock_status'  => (string) get_post_meta( $post_id, '_stock_status', true ),
			'currency'      => $currency,
			'image_ids'     => self::image_ids( $post_id, explode( ',', (string) get_post_meta( $post_id, '_product_image_gallery', true ) ) ),
		);
	}

	/**
	 * Featured image + gallery (max 5), ids only: Cloud imports them as
	 * real product photos through GET /media.
	 *
	 * @param int   $post_id Product id.
	 * @param array $gallery Gallery attachment ids.
	 * @return int[]
	 */
	private static function image_ids( $post_id, $gallery ) {
		$ids = array_merge( array( (int) get_post_thumbnail_id( $post_id ) ), array_map( 'intval', (array) $gallery ) );
		return array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 5 );
	}
}
