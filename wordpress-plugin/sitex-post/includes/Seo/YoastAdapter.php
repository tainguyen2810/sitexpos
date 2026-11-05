<?php
/**
 * Yoast SEO adapter (post meta used by Yoast SEO ≥ 14).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

final class YoastAdapter implements SeoAdapter {

	const MAP = array(
		'title'         => '_yoast_wpseo_title',
		'description'   => '_yoast_wpseo_metadesc',
		'focus_keyword' => '_yoast_wpseo_focuskw',
		'canonical'     => '_yoast_wpseo_canonical',
	);

	public function name() {
		return 'yoast';
	}

	public function label() {
		return 'Yoast SEO';
	}

	public function apply( $post_id, array $seo ) {
		$map = self::MAP;
		foreach ( $map as $field => $meta ) {
			if ( array_key_exists( $field, $seo ) ) {
				SeoManager::set_or_delete( $post_id, $meta, $seo[ $field ] );
			}
		}
		if ( array_key_exists( 'noindex', $seo ) ) {
			SeoManager::set_or_delete( $post_id, '_yoast_wpseo_meta-robots-noindex', $seo['noindex'] ? '1' : '' );
		}
	}
	/**
	 * @param array $seo Sanitized values.
	 * @return array meta key => value
	 */
	public function meta_map( array $seo ) {
		$out = array();
		foreach ( self::MAP as $field => $meta ) {
			if ( array_key_exists( $field, $seo ) ) {
				$out[ $meta ] = $seo[ $field ];
			}
		}
		if ( array_key_exists( 'noindex', $seo ) ) {
			$out['_yoast_wpseo_meta-robots-noindex'] = $seo['noindex'] ? '1' : '';
		}
		return $out;
	}

	/**
	 * Yoast stores its values in post meta (and builds its indexable from
	 * them on save_post). Template variables such as %%title%% are returned
	 * as stored.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public function read( $post_id ) {
		return array(
			'title'         => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'description'   => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			'canonical'     => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'noindex'       => '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
		);
	}
}
