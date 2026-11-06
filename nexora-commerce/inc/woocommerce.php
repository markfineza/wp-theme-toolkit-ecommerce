<?php
/**
 * WooCommerce integration. Loaded only when WooCommerce is active.
 *
 * Design rule: rearrange presentation through WooCommerce hooks; never bypass
 * WooCommerce for pricing, stock, cart validation, coupons, tax or checkout.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Wrappers and global layout
 * ---------------------------------------------------------------------- */

remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/**
 * Open the main content wrapper for WooCommerce templates.
 */
function nexora_wc_wrapper_start() {
	echo '<main id="primary" class="site-main nx-shop-main"><div class="nx-container">';
	nexora_breadcrumbs();
}
add_action( 'woocommerce_before_main_content', 'nexora_wc_wrapper_start', 10 );

/**
 * Close the main content wrapper.
 */
function nexora_wc_wrapper_end() {
	echo '</div></main>';
}
add_action( 'woocommerce_after_main_content', 'nexora_wc_wrapper_end', 10 );

/**
 * Products per row/page come from WooCommerce > Customizer > Product Catalog.
 * Related products: four in a row.
 *
 * @param array $args Args.
 * @return array
 */
function nexora_related_products_args( $args ) {
	$args['posts_per_page'] = 4;
	$args['columns']        = 4;
	return $args;
}
add_filter( 'woocommerce_output_related_products_args', 'nexora_related_products_args' );
add_filter( 'woocommerce_upsell_display_args', 'nexora_related_products_args' );
add_filter( 'woocommerce_cross_sells_columns', static function () {
	return 4;
} );
add_filter( 'woocommerce_product_thumbnails_columns', static function () {
	return 5;
} );

/**
 * Gallery slider: product images are a consistent (square) ratio, so animated height only causes layout shift.
 *
 * @param array $options FlexSlider options.
 * @return array
 */
function nexora_gallery_options( $options ) {
	$options['smoothHeight'] = false;
	return $options;
}
add_filter( 'woocommerce_single_product_carousel_options', 'nexora_gallery_options' );

/* -------------------------------------------------------------------------
 * Product card (see woocommerce/content-product.php)
 * ---------------------------------------------------------------------- */

// The card template renders these pieces explicitly, so remove the default loop callbacks.
remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );
remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

/**
 * Extra data attributes on loop add-to-cart buttons (used for analytics events and feedback).
 *
 * @param array      $args    Args.
 * @param WC_Product $product Product.
 * @return array
 */
function nexora_loop_add_to_cart_args( $args, $product ) {
	$args['class']                        .= ' nx-btn nx-btn--sm';
	$args['attributes']['data-product_name'] = $product->get_name();
	$args['attributes']['data-price']        = wc_get_price_to_display( $product );
	return $args;
}
add_filter( 'woocommerce_loop_add_to_cart_args', 'nexora_loop_add_to_cart_args', 10, 2 );

/**
 * Descriptive button text for products that cannot be added from a card (WooCommerce's
 * default "Read more" is not meaningful out of context).
 *
 * @param string     $text    Text.
 * @param WC_Product $product Product.
 * @return string
 */
function nexora_loop_button_text( $text, $product ) {
	if ( $product->is_type( 'simple' ) && ! $product->is_in_stock() ) {
		return __( 'View product', 'nexora-commerce' );
	}
	return $text;
}
add_filter( 'woocommerce_product_add_to_cart_text', 'nexora_loop_button_text', 10, 2 );

/* -------------------------------------------------------------------------
 * Product queries for homepage sections (cached, invalidated on product changes)
 * ---------------------------------------------------------------------- */

/**
 * Bump the product-query cache version.
 */
function nexora_flush_product_cache() {
	update_option( 'nexora_products_cache_v', time(), false );
}
add_action( 'woocommerce_update_product', 'nexora_flush_product_cache' );
add_action( 'woocommerce_new_product', 'nexora_flush_product_cache' );
add_action( 'woocommerce_delete_product', 'nexora_flush_product_cache' );
add_action( 'woocommerce_trash_product', 'nexora_flush_product_cache' );
add_action( 'woocommerce_product_set_stock_status', 'nexora_flush_product_cache' );
add_action( 'woocommerce_variation_set_stock_status', 'nexora_flush_product_cache' );
add_action( 'woocommerce_order_status_completed', 'nexora_flush_product_cache' );

/**
 * Get product IDs for a homepage section.
 *
 * @param string $type  featured|latest|best_selling|top_rated|on_sale|manual|ids.
 * @param int    $limit Limit.
 * @param array  $extra Extra args (e.g. 'include' => ids, 'category' => slugs, 'exclude' => ids).
 * @return int[]
 */
function nexora_get_product_ids( $type, $limit = 8, $extra = array() ) {
	$limit = max( 1, min( 24, (int) $limit ) );
	$args  = array(
		'status'     => 'publish',
		'limit'      => $limit,
		'visibility' => 'catalog',
		'return'     => 'ids',
	);
	if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
		$args['stock_status'] = 'instock';
	}

	switch ( $type ) {
		case 'featured':
			$args['featured'] = true;
			$args['orderby']  = 'menu_order date';
			break;
		case 'best_selling':
			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' );
			break;
		case 'top_rated':
			$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' );
			break;
		case 'on_sale':
			$args['include'] = array_values( array_merge( array( 0 ), wc_get_product_ids_on_sale() ) );
			break;
		case 'manual':
		case 'ids':
			$ids = isset( $extra['include'] ) ? array_filter( array_map( 'absint', (array) $extra['include'] ) ) : array();
			if ( ! $ids ) {
				return array();
			}
			$args['include'] = $ids;
			$args['orderby'] = 'post__in';
			break;
		default: // latest.
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
	}
	if ( ! empty( $extra['exclude'] ) ) {
		$args['exclude'] = array_map( 'absint', (array) $extra['exclude'] );
	}
	if ( ! empty( $extra['category'] ) ) {
		$args['category'] = (array) $extra['category'];
	}

	$args = apply_filters( 'nexora_product_section_query_args', $args, $type );
	$key  = 'nexora_ids_' . md5( wp_json_encode( $args ) . get_option( 'nexora_products_cache_v', '1' ) );
	$ids  = get_transient( $key );
	if ( false === $ids ) {
		$ids = wc_get_products( $args );
		set_transient( $key, $ids, HOUR_IN_SECONDS );
	}
	return array_map( 'intval', (array) $ids );
}

/**
 * Render a product grid using the standard WooCommerce loop (so extensions hooking the loop still work).
 *
 * @param int[] $ids     Product IDs.
 * @param array $options columns, name, class, heading.
 */
function nexora_product_grid( $ids, $options = array() ) {
	if ( ! $ids ) {
		return;
	}
	$options = wp_parse_args(
		$options,
		array(
			'columns' => 4,
			'name'    => 'nexora_section',
			'heading' => 'h3',
		)
	);

	$query = new WP_Query(
		array(
			'post_type'           => 'product',
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'posts_per_page'      => count( $ids ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);

	if ( ! $query->have_posts() ) {
		return;
	}

	$previous_columns = wc_get_loop_prop( 'columns' );
	$previous_name    = wc_get_loop_prop( 'name' );
	$previous_heading = wc_get_loop_prop( 'nexora_heading', 'h2' );
	wc_set_loop_prop( 'columns', (int) $options['columns'] );
	wc_set_loop_prop( 'name', $options['name'] );
	wc_set_loop_prop( 'nexora_heading', $options['heading'] );

	woocommerce_product_loop_start();
	while ( $query->have_posts() ) {
		$query->the_post();
		wc_get_template_part( 'content', 'product' );
	}
	woocommerce_product_loop_end();

	wp_reset_postdata();
	wc_set_loop_prop( 'columns', $previous_columns );
	wc_set_loop_prop( 'name', $previous_name );
	wc_set_loop_prop( 'nexora_heading', $previous_heading );
}

/* -------------------------------------------------------------------------
 * Cart: header count, mini-cart drawer and free-shipping progress
 * ---------------------------------------------------------------------- */

/**
 * Cart count badge markup.
 *
 * @return string
 */
function nexora_cart_count_html() {
	$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	return sprintf( '<span class="nx-cart-count%1$s" data-count="%2$d">%2$d</span>', $count ? '' : ' is-empty', (int) $count );
}

/**
 * Cart subtotal used for free-shipping progress (mirrors WooCommerce's free shipping "after discounts" logic).
 *
 * @return float
 */
function nexora_cart_subtotal_for_shipping() {
	if ( ! WC()->cart ) {
		return 0.0;
	}
	$total = (float) WC()->cart->get_displayed_subtotal();
	if ( WC()->cart->display_prices_including_tax() ) {
		$total -= (float) WC()->cart->get_discount_tax();
	}
	$total -= (float) WC()->cart->get_discount_total();
	return max( 0.0, $total );
}

/**
 * Free-shipping progress bar markup.
 *
 * @param string $context drawer|cart|checkout.
 * @return string
 */
function nexora_free_shipping_progress( $context = 'cart' ) {
	$threshold = (float) nexora_option( 'free_shipping_threshold' );
	if ( $threshold <= 0 || ! nexora_store_offers_free_shipping() || ! WC()->cart || WC()->cart->is_empty() || ! WC()->cart->needs_shipping() ) {
		return sprintf( '<div class="nx-freeship" data-context="%s" hidden></div>', esc_attr( $context ) );
	}
	$subtotal = nexora_cart_subtotal_for_shipping();
	$percent  = min( 100, (int) floor( $subtotal / $threshold * 100 ) );
	$left     = max( 0, $threshold - $subtotal );

	if ( $left <= 0 ) {
		$message = esc_html__( 'You have unlocked free shipping!', 'nexora-commerce' );
	} else {
		/* translators: %s: formatted amount remaining */
		$message = sprintf( esc_html__( 'Add %s more for free shipping', 'nexora-commerce' ), '<strong>' . wp_kses_post( wc_price( $left ) ) . '</strong>' );
	}

	return sprintf(
		'<div class="nx-freeship%1$s" data-context="%2$s" data-threshold="%3$s"><p class="nx-freeship__msg">%4$s%5$s</p><div class="nx-freeship__track" role="progressbar" aria-label="%6$s" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%7$d"><span style="width:%7$d%%"></span></div></div>',
		$left <= 0 ? ' is-complete' : '',
		esc_attr( $context ),
		esc_attr( $threshold ),
		nexora_icon( $left <= 0 ? 'check' : 'truck', 18 ),
		$message,
		esc_attr__( 'Progress towards free shipping', 'nexora-commerce' ),
		$percent
	);
}

/**
 * Cart fragments: header count, drawer contents and drawer free-shipping bar.
 *
 * @param array $fragments Fragments.
 * @return array
 */
function nexora_cart_fragments( $fragments ) {
	$fragments['span.nx-cart-count'] = nexora_cart_count_html();

	ob_start();
	echo '<div class="nx-mini-cart-content widget_shopping_cart_content">';
	woocommerce_mini_cart();
	echo '</div>';
	$fragments['div.nx-mini-cart-content'] = ob_get_clean();

	$fragments['div.nx-freeship[data-context="drawer"]'] = nexora_free_shipping_progress( 'drawer' );
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'nexora_cart_fragments' );

/**
 * Free-shipping bar on the classic cart and checkout.
 */
function nexora_cart_page_progress() {
	echo nexora_free_shipping_progress( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in builder.
}
add_action( 'woocommerce_before_cart', 'nexora_cart_page_progress', 5 );
add_action( 'woocommerce_before_checkout_form', 'nexora_cart_page_progress', 5 );

/**
 * Free-shipping bar above the Cart block. main.js keeps it in sync with the block's data store.
 *
 * @param string $content Block content.
 * @param array  $block   Block.
 * @return string
 */
function nexora_cart_block_progress( $content, $block ) {
	if ( 'woocommerce/cart' === $block['blockName'] && ! is_admin() ) {
		$content = nexora_free_shipping_progress( 'block' ) . $content;
	}
	return $content;
}
add_filter( 'render_block', 'nexora_cart_block_progress', 10, 2 );

/**
 * Empty-cart page: suggest categories and popular products.
 */
function nexora_empty_cart_suggestions() {
	$ids = nexora_get_product_ids( 'best_selling', 4 );
	if ( ! $ids ) {
		return;
	}
	echo '<section class="nx-empty-suggest" aria-labelledby="nx-empty-suggest-title">';
	nexora_section_header( __( 'Popular right now', 'nexora-commerce' ), nexora_shop_url(), __( 'Browse the shop', 'nexora-commerce' ), '', 'nx-empty-suggest-title' );
	nexora_product_grid( $ids, array( 'columns' => 4 ) );
	echo '</section>';
}
add_action( 'woocommerce_cart_is_empty', 'nexora_empty_cart_suggestions', 20 );

/* -------------------------------------------------------------------------
 * Single product
 * ---------------------------------------------------------------------- */

remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );

/**
 * Wrap gallery + summary so the sticky buy box is constrained to the top section
 * (and does not slide over tabs and related products).
 */
function nexora_single_top_open() {
	echo '<div class="nx-product-top">';
}
add_action( 'woocommerce_before_single_product_summary', 'nexora_single_top_open', 1 );

/**
 * Close the top section wrapper.
 */
function nexora_single_top_close() {
	echo '</div>';
}
add_action( 'woocommerce_after_single_product_summary', 'nexora_single_top_close', 1 );

/**
 * Badges above the product title.
 */
function nexora_single_badges() {
	global $product;
	if ( $product instanceof WC_Product ) {
		nexora_render_badges( $product );
	}
}
add_action( 'woocommerce_single_product_summary', 'nexora_single_badges', 3 );

/**
 * "Only N left" availability text based on real inventory.
 *
 * @param string     $text    Text.
 * @param WC_Product $product Product or variation.
 * @return string
 */
function nexora_low_stock_text( $text, $product ) {
	$threshold = (int) nexora_option( 'low_stock_threshold' );
	if ( $threshold > 0 && $product->managing_stock() && $product->is_in_stock() && ! $product->is_on_backorder( 1 ) ) {
		$qty = (int) $product->get_stock_quantity();
		if ( $qty > 0 && $qty <= $threshold ) {
			/* translators: %d: stock quantity */
			return sprintf( _n( 'Only %d left in stock', 'Only %d left in stock', $qty, 'nexora-commerce' ), $qty );
		}
	}
	return $text;
}
add_filter( 'woocommerce_get_availability_text', 'nexora_low_stock_text', 10, 2 );

/**
 * Buy-now button: adds to cart through the normal WooCommerce form handler, then redirects to checkout.
 */
function nexora_buy_now_button() {
	global $product;
	if ( ! nexora_option( 'buy_now' ) || ! $product instanceof WC_Product || ! $product->is_type( array( 'simple', 'variable' ) ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return;
	}
	$action = add_query_arg( 'nexora_buy_now', '1', $product->get_permalink() );
	if ( $product->is_type( 'simple' ) ) {
		printf(
			'<button type="submit" name="add-to-cart" value="%1$d" formaction="%2$s" class="single_add_to_cart_button button nx-buy-now">%3$s</button>',
			(int) $product->get_id(),
			esc_url( $action ),
			esc_html__( 'Buy now', 'nexora-commerce' )
		);
	} else {
		// Variable products already post add-to-cart via a hidden field; WooCommerce's variation script disables this button until a valid variation is chosen.
		printf(
			'<button type="submit" formaction="%1$s" class="single_add_to_cart_button button nx-buy-now disabled wc-variation-selection-needed">%2$s</button>',
			esc_url( $action ),
			esc_html__( 'Buy now', 'nexora-commerce' )
		);
	}
}
add_action( 'woocommerce_after_add_to_cart_button', 'nexora_buy_now_button', 5 );

/**
 * Redirect buy-now submissions to checkout after a successful add.
 *
 * @param string $url Redirect URL.
 * @return string
 */
function nexora_buy_now_redirect( $url ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag; WooCommerce validates the add-to-cart request itself.
	if ( isset( $_GET['nexora_buy_now'] ) && ! wc_notice_count( 'error' ) ) {
		return wc_get_checkout_url();
	}
	return $url;
}
add_filter( 'woocommerce_add_to_cart_redirect', 'nexora_buy_now_redirect', 20 );

/**
 * Trust/shipping/payment lines below the add-to-cart form.
 */
function nexora_single_trust_lines() {
	$lines = array_filter( array_map( 'trim', explode( "\n", (string) nexora_option( 'product_trust' ) ) ) );
	if ( ! $lines ) {
		return;
	}
	echo '<ul class="nx-trust-list">';
	foreach ( $lines as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		$icon  = isset( $parts[1] ) ? $parts[1] : 'check';
		printf( '<li>%1$s<span>%2$s</span></li>', nexora_icon( $icon, 18 ) ? nexora_icon( $icon, 18 ) : nexora_icon( 'check', 18 ), esc_html( $parts[0] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</ul>';
	$payments = nexora_payment_labels();
	if ( $payments ) {
		printf(
			'<p class="nx-pay-note"><span>%1$s</span> %2$s</p>',
			esc_html__( 'Pay with:', 'nexora-commerce' ),
			esc_html( implode( ' · ', $payments ) )
		);
	}
}
add_action( 'woocommerce_single_product_summary', 'nexora_single_trust_lines', 35 );

/**
 * Wishlist button next to the add-to-cart button.
 */
function nexora_single_wishlist() {
	global $product;
	if ( $product instanceof WC_Product ) {
		nexora_wishlist_button( $product, 'nx-wishlist-btn--single' );
	}
}
add_action( 'woocommerce_single_product_summary', 'nexora_single_wishlist', 31 );

/**
 * "Pairs well with" (cross-sells) on the product page. Real bundle discounts need a bundles extension.
 */
function nexora_single_pairs_with() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$ids = array_slice( array_filter( array_map( 'absint', $product->get_cross_sell_ids() ) ), 0, 4 );
	$ids = array_values( array_filter( $ids, static function ( $id ) {
		$p = wc_get_product( $id );
		return $p && $p->is_visible();
	} ) );
	if ( ! $ids ) {
		return;
	}
	echo '<section class="nx-pairs" aria-labelledby="nx-pairs-title">';
	nexora_section_header( __( 'Frequently bought together', 'nexora-commerce' ), '', '', '', 'nx-pairs-title' );
	nexora_product_grid( $ids, array( 'columns' => 4, 'name' => 'cross-sells' ) );
	echo '</section>';
}
add_action( 'woocommerce_after_single_product_summary', 'nexora_single_pairs_with', 12 );

/**
 * Recently viewed placeholder (filled client-side from localStorage, so it is cache-safe).
 */
function nexora_single_recently_viewed() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	nexora_recently_viewed_section( $product->get_id() );
}
add_action( 'woocommerce_after_single_product_summary', 'nexora_single_recently_viewed', 25 );

/**
 * Recently viewed products container.
 *
 * @param int    $exclude Product ID to exclude.
 * @param string $title   Title.
 */
function nexora_recently_viewed_section( $exclude = 0, $title = '' ) {
	$title = $title ? $title : __( 'Recently viewed', 'nexora-commerce' );
	printf(
		'<section class="nx-recent" data-nexora-recent data-exclude="%1$d" aria-labelledby="nx-recent-title" hidden><div class="nx-section-head"><h2 class="nx-section-title" id="nx-recent-title">%2$s</h2></div><div class="nx-recent__grid" data-nexora-recent-grid></div></section>',
		(int) $exclude,
		esc_html( $title )
	);
}

/**
 * Record a product view client-side.
 */
function nexora_track_view_marker() {
	if ( is_product() ) {
		printf( '<span hidden data-nexora-viewed="%d"></span>', (int) get_queried_object_id() );
	}
}
add_action( 'wp_footer', 'nexora_track_view_marker' );

/**
 * Sticky add-to-cart bar for product pages.
 */
function nexora_sticky_add_to_cart() {
	if ( ! is_product() || ! nexora_option( 'sticky_atc' ) ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product || ! $product->is_purchasable() && ! $product->is_type( 'external' ) ) {
		return;
	}
	wc_get_template( 'single-product/nexora-sticky-atc.php', array( 'product' => $product ) );
}
add_action( 'wp_footer', 'nexora_sticky_add_to_cart', 5 );

/* -------------------------------------------------------------------------
 * Checkout and account touches
 * ---------------------------------------------------------------------- */

/**
 * Honest payment note under the place-order button.
 */
function nexora_checkout_secure_note() {
	printf( '<p class="nx-secure-note">%1$s %2$s</p>', nexora_icon( 'shield', 16 ), esc_html__( 'Payments are handled by the payment provider you select. We never store your card details on this site.', 'nexora-commerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_review_order_after_submit', 'nexora_checkout_secure_note' );

/* -------------------------------------------------------------------------
 * Header helpers
 * ---------------------------------------------------------------------- */

