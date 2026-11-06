<?php
/**
 * Allowlist HTML sanitizer for content pushed by Cloud. Content is treated as
 * untrusted even though the request is signed (AI output, copied sources).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Security;

defined( 'ABSPATH' ) || exit;

final class HtmlSanitizer {

	/**
	 * @param string $html HTML.
	 * @return string
	 */
	public static function clean( $html ) {
		$html = (string) $html;
		// Drop whole dangerous elements including their text content.
		$html = preg_replace( '#<(script|style|iframe|object|embed|form|noscript|template|svg|math)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$html = preg_replace( '#<(script|style|iframe|object|embed|form|noscript|template|svg|math|link|meta|base)\b[^>]*/?>#i', '', $html );

		$clean = wp_kses( $html, self::allowed_tags(), array( 'http', 'https', 'mailto', 'tel' ) );

		// Block-editor comments (<!-- wp:paragraph -->) are kept by kses; strip any other comments.
		$clean = preg_replace( '#<!--(?!\s*/?wp:)(.*?)-->#s', '', $clean );

		return trim( (string) $clean );
	}

	/**
	 * @return array
	 */
	public static function allowed_tags() {
		$common = array(
			'class' => true,
			'id'    => true,
			'title' => true,
			'lang'  => true,
			'dir'   => true,
		);
		$tags   = array();
		foreach ( array( 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark', 'small', 'code', 'pre', 'hr', 'br', 'span', 'div', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'dl', 'dt', 'dd', 'abbr', 'cite', 'q', 'details', 'summary' ) as $tag ) {
			$tags[ $tag ] = $common;
		}
		$tags['a']   = $common + array(
			'href'   => true,
			'rel'    => true,
			'target' => true,
		);
		$tags['img'] = $common + array(
			'src'      => true,
			'alt'      => true,
			'width'    => true,
			'height'   => true,
			'loading'  => true,
			'decoding' => true,
			'srcset'   => true,
			'sizes'    => true,
		);
		$tags['th']  = $common + array(
			'scope'   => true,
			'colspan' => true,
			'rowspan' => true,
		);
		$tags['td']  = $common + array(
			'colspan' => true,
			'rowspan' => true,
		);
		$tags['ol'] += array(
			'start'    => true,
			'reversed' => true,
			'type'     => true,
		);

		/**
		 * Filters the HTML allowed in content pushed by SiteX Post Cloud.
		 *
		 * @param array $tags kses allowlist.
		 */
		return apply_filters( 'sitex_post_allowed_html', $tags );
	}
}
