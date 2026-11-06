<?php
/**
 * Installs a throw-away WordPress test site for the connector E2E test.
 *
 *   php install-wp.php <wordpress-dir> <db-name> <port> [--stubs]
 *
 * Writes wp-config.php (DB user wp/wp on 127.0.0.1), installs WordPress,
 * links + activates the SiteX Post plugin, creates an Author account and
 * sample categories/tags/posts/pages. --stubs adds a must-use plugin that
 * simulates WooCommerce (product post type + prices) and Yoast SEO (only
 * the WPSEO_VERSION constant) so adapters can be exercised without
 * downloading those plugins.
 *
 * Environment: SITEX_CLOUD_URL (default http://127.0.0.1:8000),
 * WP_DB_USER / WP_DB_PASSWORD / WP_DB_HOST (default wp / wp / 127.0.0.1).
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "usage: php install-wp.php <wordpress-dir> <db-name> <port> [--stubs]\n" );
	exit( 1 );
}
list( , $dir, $db, $port ) = $argv;
$stubs   = in_array( '--stubs', $argv, true );
$dir     = rtrim( realpath( $dir ), '/' );
$url     = "http://127.0.0.1:{$port}";
$plugin  = realpath( __DIR__ . '/../../sitex-post' );
$cloud   = getenv( 'SITEX_CLOUD_URL' ) ? getenv( 'SITEX_CLOUD_URL' ) : 'http://127.0.0.1:8000';
$db_user = getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'wp';
$db_pass = getenv( 'WP_DB_PASSWORD' ) !== false ? getenv( 'WP_DB_PASSWORD' ) : 'wp';
$db_host = getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : '127.0.0.1';

$config = <<<PHP
<?php
define( 'DB_NAME', '{$db}' );
define( 'DB_USER', '{$db_user}' );
define( 'DB_PASSWORD', '{$db_pass}' );
define( 'DB_HOST', '{$db_host}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';
define( 'AUTH_KEY', 'e2e-auth' ); define( 'SECURE_AUTH_KEY', 'e2e-sauth' ); define( 'LOGGED_IN_KEY', 'e2e-li' ); define( 'NONCE_KEY', 'e2e-nonce' );
define( 'AUTH_SALT', 'e2e-as' ); define( 'SECURE_AUTH_SALT', 'e2e-sas' ); define( 'LOGGED_IN_SALT', 'e2e-lis' ); define( 'NONCE_SALT', 'e2e-ns' );
define( 'WP_HOME', '{$url}' );
define( 'WP_SITEURL', '{$url}' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'SITEX_POST_CLOUD_URL', '{$cloud}' );
define( 'SITEX_POST_ALLOW_INSECURE', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP;
file_put_contents( "{$dir}/wp-config.php", $config );

@mkdir( "{$dir}/wp-content/plugins", 0777, true );
@mkdir( "{$dir}/wp-content/mu-plugins", 0777, true );
@unlink( "{$dir}/wp-content/plugins/sitex-post" );
symlink( $plugin, "{$dir}/wp-content/plugins/sitex-post" );
@unlink( "{$dir}/wp-content/mu-plugins/sitex-e2e-stubs.php" );
if ( $stubs ) {
	copy( __DIR__ . '/../stubs/sitex-e2e-stubs.php', "{$dir}/wp-content/mu-plugins/sitex-e2e-stubs.php" );
}

define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = "127.0.0.1:{$port}";
$_SERVER['REQUEST_URI'] = '/';
require_once "{$dir}/wp-load.php";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'SiteX E2E', 'admin', 'admin@example.test', true, '', 'AdminPass123!' );
}
update_option( 'permalink_structure', '/%postname%/' );

if ( ! get_user_by( 'login', 'sitex-author' ) ) {
	wp_insert_user( array( 'user_login' => 'sitex-author', 'user_pass' => 'AuthorPass123!', 'user_email' => 'author@example.test', 'role' => 'author', 'display_name' => 'SiteX Author' ) );
}
if ( ! get_user_by( 'login', 'contrib' ) ) {
	wp_insert_user( array( 'user_login' => 'contrib', 'user_pass' => 'ContribPass123!', 'user_email' => 'contrib@example.test', 'role' => 'contributor', 'display_name' => 'Contributor' ) );
}

$cats = array();
foreach ( array( 'Tin tức', 'Hướng dẫn', 'Sản phẩm' ) as $name ) {
	$t      = term_exists( $name, 'category' );
	$t      = $t ? $t : wp_insert_term( $name, 'category' );
	$cats[] = (int) $t['term_id'];
}
foreach ( array( 'seo', 'wordpress' ) as $tag ) {
	if ( ! term_exists( $tag, 'post_tag' ) ) {
		wp_insert_term( $tag, 'post_tag' );
	}
}
if ( ! get_page_by_path( 'bai-mau-1', OBJECT, 'post' ) ) {
	for ( $i = 1; $i <= 3; $i++ ) {
		wp_insert_post( array( 'post_title' => "Bài mẫu {$i}", 'post_name' => "bai-mau-{$i}", 'post_content' => "<p>Nội dung bài mẫu {$i}.</p>", 'post_status' => 'publish', 'post_category' => array( $cats[ $i - 1 ] ), 'tags_input' => array( 'seo' ), 'post_author' => 1 ) );
	}
	wp_insert_post( array( 'post_title' => 'Giới thiệu', 'post_type' => 'page', 'post_content' => '<p>Trang giới thiệu.</p>', 'post_status' => 'publish' ) );
}
if ( $stubs && ! get_page_by_path( 'ao-thun-sitex', OBJECT, 'product' ) ) {
	$pid = wp_insert_post( array( 'post_title' => 'Áo thun SiteX', 'post_name' => 'ao-thun-sitex', 'post_type' => 'product', 'post_status' => 'publish', 'post_content' => 'Áo thun cotton.' ) );
	update_post_meta( $pid, '_sku', 'SX-TEE-01' );
	update_post_meta( $pid, '_price', '199000' );
	update_post_meta( $pid, '_regular_price', '249000' );
	update_post_meta( $pid, '_sale_price', '199000' );
	update_post_meta( $pid, '_stock_status', 'instock' );
	$t = term_exists( 'Áo', 'product_cat' );
	$t = $t ? $t : wp_insert_term( 'Áo', 'product_cat' );
	wp_set_object_terms( $pid, array( (int) $t['term_id'] ), 'product_cat' );
}

$result = activate_plugin( 'sitex-post/sitex-post.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'activation failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}
echo 'WordPress ' . get_bloginfo( 'version' ) . " ready at {$url} (plugin active, stubs: " . ( $stubs ? 'yes' : 'no' ) . ")\n";
