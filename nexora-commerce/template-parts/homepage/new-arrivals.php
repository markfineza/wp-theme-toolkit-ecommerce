<?php
/**
 * New arrivals: latest published products.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/homepage/product-section',
	null,
	array(
		'key'        => 'new',
		'title'      => nexora_option( 'new_title' ),
		'type'       => 'latest',
		'limit'      => nexora_option( 'new_limit' ),
		'eyebrow'    => __( 'Just landed', 'nexora-commerce' ),
		'link'       => nexora_is_woocommerce_active() ? nexora_shop_url( array( 'orderby' => 'date' ) ) : '',
		'link_label' => __( 'Shop new arrivals', 'nexora-commerce' ),
	)
);
