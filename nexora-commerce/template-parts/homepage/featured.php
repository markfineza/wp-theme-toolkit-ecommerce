<?php
/**
 * Featured products (WooCommerce "Featured" flag).
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/homepage/product-section',
	null,
	array(
		'key'     => 'featured',
		'title'   => nexora_option( 'featured_title' ),
		'type'    => 'featured',
		'limit'   => nexora_option( 'featured_limit' ),
		'eyebrow' => __( 'Hand-picked', 'nexora-commerce' ),
		'link'    => nexora_has_store() ? nexora_shop_url() : '',
	)
);
