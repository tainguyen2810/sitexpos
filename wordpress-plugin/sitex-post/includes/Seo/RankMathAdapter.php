<?php
/**
 * Rank Math SEO adapter.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

final class RankMathAdapter implements SeoAdapter {

	public function name() {
		return 'rank_math';
	}

	public function label() {
		return 'Rank Math';
	}

	public function apply( $post_id, array $seo ) {
		$map = array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
			'canonical'     => 'rank_math_canonical_url',
		);
		foreach ( $map as $field => $meta ) {
			if ( array_key_exists( $field, $seo ) ) {
				SeoManager::set_or_delete( $post_id, $meta, $seo[ $field ] );
			}
		}
		if ( array_key_exists( 'noindex', $seo ) ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			$robots = is_array( $robots ) ? array_values( array_diff( $robots, array( 'index', 'noindex' ) ) ) : array();
			$robots[] = $seo['noindex'] ? 'noindex' : 'index';
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}
	}
}
