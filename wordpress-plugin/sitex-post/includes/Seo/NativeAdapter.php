<?php
/**
 * Fallback when no SEO plugin is active: the plugin prints the title,
 * meta description, canonical and robots itself (see SeoManager hooks).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

final class NativeAdapter implements SeoAdapter {

	public function name() {
		return 'native';
	}

	public function label() {
		return __( 'SiteX Post (built-in meta tags)', 'sitex-post' );
	}

	public function apply( $post_id, array $seo ) {
		// The canonical _sitex_post_seo_* meta is always written by SeoManager.
	}
}
