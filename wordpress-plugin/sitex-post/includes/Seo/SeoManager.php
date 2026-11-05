<?php
/**
 * SEO metadata: sanitizes the input, always stores SiteX's own copy
 * (_sitex_post_seo_*) and writes it to the active adapter (Yoast SEO,
 * Rank Math or the built-in fallback that prints meta tags).
 *
 * @package SiteXPost\WP
 */

namespace SiteXPost\WP\Seo;

defined( 'ABSPATH' ) || exit;

final class SeoManager {

	const PREFIX = '_sitex_post_seo_';

	/**
	 * @return SeoAdapter
	 */
	public static function adapter() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			$adapter = new YoastAdapter();
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$adapter = new RankMathAdapter();
		} else {
			$adapter = new NativeAdapter();
		}
		/**
		 * Filters the SEO adapter used for SiteX Post content.
		 *
		 * @param SeoAdapter $adapter Adapter.
		 */
		$filtered = apply_filters( 'sitex_post_seo_adapter', $adapter );
		return $filtered instanceof SeoAdapter ? $filtered : $adapter;
	}

	/**
	 * @param int   $post_id Post.
	 * @param array $input   Raw seo input.
	 * @return string adapter name used
	 */
	public static function apply( $post_id, array $input ) {
		$seo = self::sanitize( $input );
		if ( ! $seo ) {
			return self::adapter()->name();
		}
		foreach ( $seo as $field => $value ) {
			self::set_or_delete( $post_id, self::PREFIX . $field, is_bool( $value ) ? ( $value ? '1' : '' ) : $value );
		}
		$adapter = self::adapter();
		$adapter->apply( $post_id, $seo );
		update_post_meta( $post_id, self::PREFIX . 'adapter', $adapter->name() );
		return $adapter->name();
	}

	/**
	 * @param array $input Raw.
	 * @return array
	 */
	/**
	 * Meta to pass as `meta_input` when a post is created, so SEO plugins see
	 * the values in their save_post listeners (Yoast indexables, Rank Math).
	 *
	 * @param array $input Raw SEO input.
	 * @return array meta key => value
	 */
	public static function meta_input( array $input ) {
		$seo = self::sanitize( $input );
		$out = array();
		foreach ( $seo as $field => $value ) {
			$v = is_bool( $value ) ? ( $value ? '1' : '' ) : $value;
			if ( '' !== $v ) {
				$out[ self::PREFIX . $field ] = $v;
			}
		}
		$adapter = self::adapter();
		if ( method_exists( $adapter, 'meta_map' ) ) {
			foreach ( $adapter->meta_map( $seo ) as $key => $value ) {
				if ( '' !== $value && null !== $value ) {
					$out[ $key ] = $value;
				}
			}
		}
		if ( $seo ) {
			$out[ self::PREFIX . 'adapter' ] = $adapter->name();
		}
		return $out;
	}

	/**
	 * Values the active SEO plugin will actually use, read back from its own
	 * storage (not from what we sent), so Cloud can verify the write.
	 *
	 * @param int $post_id Post.
	 * @return array{title:string,description:string,focus_keyword:string,canonical:string,noindex:bool}
	 */
	public static function read( $post_id ) {
		$adapter = self::adapter();
		if ( method_exists( $adapter, 'read' ) ) {
			$values = $adapter->read( $post_id );
		} else {
			$values = array();
		}
		$native = array(
			'title'         => (string) get_post_meta( $post_id, self::PREFIX . 'title', true ),
			'description'   => (string) get_post_meta( $post_id, self::PREFIX . 'description', true ),
			'focus_keyword' => (string) get_post_meta( $post_id, self::PREFIX . 'focus_keyword', true ),
			'canonical'     => (string) get_post_meta( $post_id, self::PREFIX . 'canonical', true ),
			'noindex'       => '1' === (string) get_post_meta( $post_id, self::PREFIX . 'noindex', true ),
		);
		return array_merge( $native, is_array( $values ) ? $values : array() );
	}

	/**
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function state_hash( $post_id ) {
		$v = self::read( $post_id );
		return hash( 'sha256', (string) wp_json_encode( array( (string) $v['title'], (string) $v['description'], (string) $v['focus_keyword'], (string) $v['canonical'], (bool) $v['noindex'] ) ) );
	}

	public static function sanitize( array $input ) {
		$out = array();
		if ( isset( $input['title'] ) && is_scalar( $input['title'] ) ) {
			$out['title'] = mb_substr( sanitize_text_field( (string) $input['title'] ), 0, 200 );
		}
		if ( isset( $input['description'] ) && is_scalar( $input['description'] ) ) {
			$out['description'] = mb_substr( sanitize_textarea_field( (string) $input['description'] ), 0, 500 );
		}
		if ( isset( $input['focus_keyword'] ) && is_scalar( $input['focus_keyword'] ) ) {
			$out['focus_keyword'] = mb_substr( sanitize_text_field( (string) $input['focus_keyword'] ), 0, 100 );
		}
		if ( isset( $input['canonical'] ) && is_scalar( $input['canonical'] ) ) {
			$url              = esc_url_raw( (string) $input['canonical'], array( 'http', 'https' ) );
			$out['canonical'] = $url;
		}
		if ( array_key_exists( 'noindex', $input ) ) {
			$out['noindex'] = (bool) $input['noindex'];
		}
		return $out;
	}

	/**
	 * @param int    $post_id Post.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value ('' deletes).
	 */
	public static function set_or_delete( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Front-end output for the built-in adapter only (never duplicates the
	 * tags printed by Yoast SEO or Rank Math).
	 */
	public static function register_frontend_hooks() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'meta_tags' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'schema' ), 20 );
	}

	/**
	 * @return int
	 */
	private static function native_post_id() {
		if ( is_admin() || ! is_singular() || 'native' !== self::adapter()->name() ) {
			return 0;
		}
		$id = (int) get_queried_object_id();
		return '1' === (string) get_post_meta( $id, '_sitex_post_managed', true ) ? $id : 0;
	}

	/**
	 * @param string $title Title.
	 * @return string
	 */
	/**
	 * Prints the JSON-LD Cloud attached to a SiteX Post managed post. Cloud
	 * only sends schema when the customer enabled injection for the site and
	 * never sends types an active SEO plugin already outputs.
	 */
	public static function schema() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id || '1' !== (string) get_post_meta( $id, '_sitex_post_managed', true ) ) {
			return;
		}
		$raw  = (string) get_post_meta( $id, '_sitex_post_schema', true );
		$data = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! $data ) {
			return;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HEX_TAG cannot break out of the script element.
	}

	public static function document_title( $title ) {
		$id = self::native_post_id();
		if ( $id ) {
			$seo = (string) get_post_meta( $id, self::PREFIX . 'title', true );
			if ( '' !== $seo ) {
				return $seo;
			}
		}
		return $title;
	}

	public static function meta_tags() {
		$id = self::native_post_id();
		if ( ! $id ) {
			return;
		}
		$description = (string) get_post_meta( $id, self::PREFIX . 'description', true );
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
	}

	/**
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		$id = self::native_post_id();
		if ( $id && '1' === (string) get_post_meta( $id, self::PREFIX . 'noindex', true ) ) {
			$robots['noindex'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	/**
	 * @param string   $url  Canonical URL.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function canonical( $url, $post ) {
		if ( 'native' !== self::adapter()->name() || ! $post ) {
			return $url;
		}
		$custom = (string) get_post_meta( $post->ID, self::PREFIX . 'canonical', true );
		return '' !== $custom ? $custom : $url;
	}
}
