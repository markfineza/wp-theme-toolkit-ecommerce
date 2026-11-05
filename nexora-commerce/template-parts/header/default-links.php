<?php
/**
 * Fallback primary links shown until a "Primary" menu is assigned.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_links = array();
if ( nexora_is_woocommerce_active() ) {
	$nexora_links[] = array( nexora_shop_url(), __( 'Shop', 'nexora-commerce' ) );
	$nexora_links[] = array( nexora_shop_url( array( 'orderby' => 'date' ) ), __( 'New Arrivals', 'nexora-commerce' ) );
	$nexora_links[] = array( nexora_shop_url( array( 'orderby' => 'popularity' ) ), __( 'Best Sellers', 'nexora-commerce' ) );
	$nexora_links[] = array( nexora_shop_url( array( 'on_sale' => 1 ) ), __( 'Deals', 'nexora-commerce' ) );
}
$nexora_about = get_page_by_path( 'about' );
if ( $nexora_about ) {
	$nexora_links[] = array( get_permalink( $nexora_about ), __( 'About', 'nexora-commerce' ) );
}
foreach ( $nexora_links as $nexora_link ) {
	printf( '<li class="menu-item"><a href="%1$s">%2$s</a></li>', esc_url( $nexora_link[0] ), esc_html( $nexora_link[1] ) );
}
