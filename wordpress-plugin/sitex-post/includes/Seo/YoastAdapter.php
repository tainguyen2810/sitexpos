<?php
/**
 * Yoast SEO adapter (post meta used by Yoast SEO ≥ 14).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

final class YoastAdapter implements SeoAdapter {

	public function name() {
		return 'yoast';
	}

	public function label() {
		return 'Yoast SEO';
	}

	public function apply( $post_id, array $seo ) {
		$map = array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
			'canonical'     => '_yoast_wpseo_canonical',
		);
		foreach ( $map as $field => $meta ) {
			if ( array_key_exists( $field, $seo ) ) {
				SeoManager::set_or_delete( $post_id, $meta, $seo[ $field ] );
			}
		}
		if ( array_key_exists( 'noindex', $seo ) ) {
			SeoManager::set_or_delete( $post_id, '_yoast_wpseo_meta-robots-noindex', $seo['noindex'] ? '1' : '' );
		}
	}
}
