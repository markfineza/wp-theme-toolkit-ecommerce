<?php
/**
 * Connects the theme to the toolkit's built-in store: the same helper functions inc/woocommerce.php
 * provides when WooCommerce runs. Loaded only while the built-in store is running.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product IDs for a homepage section.
 *
 * @param string $type  featured|latest|best_selling|top_rated|on_sale|manual|ids.
 * @param int    $limit Limit.
 * @param array  $extra include, exclude, category.
 * @return int[]
 */
function nexora_get_product_ids( $type, $limit = 8, $extra = array() ) {
	return Nexora_Store_Catalog::section_ids( $type, $limit, $extra );
}

/**
 * Product grid.
 *
 * @param int[] $ids     Product IDs.
 * @param array $options columns, heading.
 */
function nexora_product_grid( $ids, $options = array() ) {
	$options = wp_parse_args( $options, array( 'columns' => 4, 'heading' => 'h3' ) );
	Nexora_Store_Catalog::grid( $ids, (int) $options['columns'], array( 'heading' => $options['heading'] ) );
}

/**
 * Cart count badge. The count comes from a cookie, so cached pages still show it correctly.
 *
 * @return string
 */
function nexora_cart_count_html() {
	$count = isset( $_COOKIE[ Nexora_Store_Cart::COUNT_COOKIE ] ) ? absint( $_COOKIE[ Nexora_Store_Cart::COUNT_COOKIE ] ) : 0;
	return sprintf( '<span class="nx-cart-count%1$s" data-count="%2$d">%2$d</span>', $count ? '' : ' is-empty', $count );
}

/**
 * The free-shipping bar is a WooCommerce feature; free delivery here is shown on the delivery option.
 *
 * @return string
 */
function nexora_free_shipping_progress() {
	return '';
}

/**
 * Whether free delivery is offered.
 *
 * @return bool
 */
function nexora_store_offers_free_shipping() {
	return Nexora_Store::get( 'delivery_enabled' ) && (float) Nexora_Store::get( 'delivery_free_over' ) > 0;
}

/**
 * Recently viewed products are not tracked in this mode, so the section stays empty.
 */
function nexora_recently_viewed_section() {}
