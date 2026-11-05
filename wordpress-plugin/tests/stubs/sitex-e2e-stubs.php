<?php
/**
 * E2E ONLY — simulates WooCommerce (product post type, product_cat/tag,
 * WC_VERSION, currency) and the presence of Yoast SEO (WPSEO_VERSION) so
 * the connector's product sync and Yoast adapter can be tested without the
 * real plugins. Never ship this file.
 */
define( 'WC_VERSION', '9.9.0-e2e-stub' );
define( 'WPSEO_VERSION', '25.0-e2e-stub' );
add_action(
	'init',
	function () {
		register_post_type( 'product', array( 'public' => true, 'label' => 'Products', 'supports' => array( 'title', 'editor', 'thumbnail' ) ) );
		register_taxonomy( 'product_cat', 'product', array( 'public' => true, 'hierarchical' => true ) );
		register_taxonomy( 'product_tag', 'product', array( 'public' => true ) );
	},
	0
);
add_filter( 'pre_option_woocommerce_currency', function () { return 'VND'; } );
