<?php
/**
 * Best sellers, ordered by WooCommerce's total_sales counter.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_template_part(
	'template-parts/homepage/product-section',
	null,
	array(
		'key'        => 'best',
		'title'      => nexora_option( 'best_title' ),
		'type'       => 'best_selling',
		'limit'      => nexora_option( 'best_limit' ),
		'eyebrow'    => __( 'Most popular', 'nexora-commerce' ),
		'link'       => nexora_is_woocommerce_active() ? nexora_shop_url( array( 'orderby' => 'popularity' ) ) : '',
		'link_label' => __( 'See all best sellers', 'nexora-commerce' ),
	)
);
