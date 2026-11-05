<?php
/**
 * Trending now: top rated, best selling, on sale or manually curated.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_source = nexora_option( 'trending_source' );
get_template_part(
	'template-parts/homepage/product-section',
	null,
	array(
		'key'     => 'trending',
		'title'   => nexora_option( 'trending_title' ),
		'type'    => $nexora_source,
		'limit'   => nexora_option( 'trending_limit' ),
		'include' => 'manual' === $nexora_source ? explode( ',', (string) nexora_option( 'trending_ids' ) ) : array(),
		'eyebrow' => __( 'What shoppers love', 'nexora-commerce' ),
		'class'   => 'nx-section--tint',
	)
);
