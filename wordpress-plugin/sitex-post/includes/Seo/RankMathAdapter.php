<?php
/**
 * Rank Math SEO adapter.
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

final class RankMathAdapter implements SeoAdapter {

	const MAP = array(
		'title'         => 'rank_math_title',
		'description'   => 'rank_math_description',
		'focus_keyword' => 'rank_math_focus_keyword',
		'canonical'     => 'rank_math_canonical_url',
	);

	public function name() {
		return 'rank_math';
	}

	public function label() {
		return 'Rank Math';
	}

	public function apply( $post_id, array $seo ) {
		$map = self::MAP;
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
			$out['rank_math_robots'] = array( $seo['noindex'] ? 'noindex' : 'index' );
		}
		return $out;
	}

	/**
	 * Rank Math reads these meta keys at render time; robots is an array.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public function read( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		return array(
			'title'         => (string) get_post_meta( $post_id, 'rank_math_title', true ),
			'description'   => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			'canonical'     => (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
			'noindex'       => is_array( $robots ) && in_array( 'noindex', $robots, true ),
		);
	}
}
