<?php
/**
 * E2E helper: prints WordPress state as JSON so the driver can assert on
 * what really landed in the database.
 *
 *   php wp-inspect.php <wordpress-dir> connection|managed
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: php wp-inspect.php <wordpress-dir> connection|managed\n" );
	exit( 1 );
}
$dir = rtrim( realpath( $argv[1] ), '/' );
$url = '';
if ( preg_match( "/define\\( 'WP_HOME', '([^']+)'/", (string) file_get_contents( "{$dir}/wp-config.php" ), $m ) ) {
	$url = $m[1];
}
$parts                  = parse_url( $url );
$_SERVER['HTTP_HOST']   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
$_SERVER['REQUEST_URI'] = '/';
require_once "{$dir}/wp-load.php";

switch ( $argv[2] ) {
	case 'connection':
		echo wp_json_encode( get_option( 'sitex_post_connection', null ) );
		break;

	case 'managed':
		$out   = array();
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_sitex_post_managed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $posts as $p ) {
			$meta = array();
			foreach ( get_post_meta( $p->ID ) as $k => $v ) {
				if ( 0 === strpos( $k, '_sitex_post' ) || 0 === strpos( $k, '_yoast_' ) || 0 === strpos( $k, 'rank_math_' ) || '_thumbnail_id' === $k ) {
					$meta[ $k ] = $v[0];
				}
			}
			$out['posts'][] = array(
				'id'      => $p->ID,
				'title'   => $p->post_title,
				'status'  => $p->post_status,
				'author'  => (int) $p->post_author,
				'content' => $p->post_content,
				'meta'    => $meta,
			);
		}
		$out['attachments'] = count(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_sitex_post_managed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
		$out['author_id'] = get_user_by( 'login', 'sitex-author' )->ID;
		echo wp_json_encode( $out );
		break;
}
