<?php
/**
 * Writes SEO metadata into the SEO plugin active on the site.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

interface SeoAdapter {

	/**
	 * @return string machine name (yoast, rank_math, native)
	 */
	public function name();

	/**
	 * @return string human label
	 */
	public function label();

	/**
	 * @param int   $post_id Post.
	 * @param array $seo     Sanitized: title, description, focus_keyword, canonical, noindex.
	 */
	public function apply( $post_id, array $seo );
}
