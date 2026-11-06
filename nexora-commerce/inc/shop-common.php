<?php
/**
 * Shop helpers used by both store engines (WooCommerce and the toolkit's built-in store).
 * They only call product methods that both engines' products provide.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discount label for a product on sale, or '' when it cannot be stated accurately.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function nexora_discount_label( $product ) {
	if ( ! $product->is_on_sale() ) {
		return '';
	}
	$percents = array();
	if ( $product->is_type( 'variable' ) ) {
		$prices = $product->get_variation_prices( true );
		foreach ( $prices['regular_price'] as $vid => $regular ) {
			$sale = isset( $prices['sale_price'][ $vid ] ) ? (float) $prices['sale_price'][ $vid ] : 0;
			if ( (float) $regular > 0 && $sale > 0 && $sale < (float) $regular ) {
				$percents[] = (int) round( ( (float) $regular - $sale ) / (float) $regular * 100 );
			}
		}
	} elseif ( $product->is_type( array( 'simple', 'external' ) ) ) {
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
			$percents[] = (int) round( ( $regular - $sale ) / $regular * 100 );
		}
	}
	$percents = array_filter( $percents );
	if ( ! $percents ) {
		return '';
	}
	if ( min( $percents ) === max( $percents ) ) {
		/* translators: %d: discount percentage */
		return sprintf( __( '-%d%%', 'nexora-commerce' ), max( $percents ) );
	}
	/* translators: %d: maximum discount percentage */
	return sprintf( __( 'Up to -%d%%', 'nexora-commerce' ), max( $percents ) );
}

/**
 * Badges for a product (sale, new, sold out, low stock).
 *
 * @param WC_Product $product Product.
 * @return array<int,array{class:string,label:string}>
 */
function nexora_product_badges( $product ) {
	$badges = array();

	if ( ! $product->is_in_stock() ) {
		$badges[] = array( 'class' => 'soldout', 'label' => __( 'Sold out', 'nexora-commerce' ) );
	} elseif ( $product->is_on_sale() ) {
		$discount = nexora_option( 'badge_discount' ) ? nexora_discount_label( $product ) : '';
		$badges[] = array( 'class' => 'sale', 'label' => $discount ? $discount : __( 'Sale', 'nexora-commerce' ) );
	}

	$new_days = (int) nexora_option( 'badge_new_days' );
	$created  = $product->get_date_created();
	if ( $new_days > 0 && $created && ( time() - $created->getTimestamp() ) < $new_days * DAY_IN_SECONDS ) {
		$badges[] = array( 'class' => 'new', 'label' => __( 'New', 'nexora-commerce' ) );
	}

	$threshold = (int) nexora_option( 'low_stock_threshold' );
	if ( $threshold > 0 && $product->is_in_stock() && $product->managing_stock() && ! $product->is_type( 'variable' ) ) {
		$qty = (int) $product->get_stock_quantity();
		if ( $qty > 0 && $qty <= $threshold ) {
			$badges[] = array( 'class' => 'low', 'label' => __( 'Low stock', 'nexora-commerce' ) );
		}
	}

	return apply_filters( 'nexora_product_badges', $badges, $product );
}

/**
 * Print product badges.
 *
 * @param WC_Product $product Product.
 */
function nexora_render_badges( $product ) {
	$badges = nexora_product_badges( $product );
	if ( ! $badges ) {
		return;
	}
	echo '<div class="nx-badges">';
	foreach ( $badges as $badge ) {
		printf( '<span class="nx-badge nx-badge--%1$s">%2$s</span>', esc_attr( $badge['class'] ), esc_html( $badge['label'] ) );
	}
	echo '</div>';
}

/**
 * Whether the built-in wishlist UI should render (enabled by the Freshtastic Food Hub Toolkit plugin).
 *
 * @return bool
 */
function nexora_wishlist_enabled() {
	return (bool) apply_filters( 'nexora_wishlist_enabled', false );
}

/**
 * Wishlist toggle button markup. State is applied client-side so the markup is cache-safe.
 *
 * @param WC_Product $product Product.
 * @param string     $extra   Extra class.
 */
function nexora_wishlist_button( $product, $extra = '' ) {
	if ( ! nexora_wishlist_enabled() ) {
		return;
	}
	/* translators: %s: product name */
	$label = sprintf( __( 'Save %s to wishlist', 'nexora-commerce' ), $product->get_name() );
	printf(
		'<button type="button" class="nx-wishlist-btn %1$s" data-nexora-wishlist="%2$d" aria-pressed="false" aria-label="%3$s">%4$s%5$s</button>',
		esc_attr( $extra ),
		(int) $product->get_id(),
		esc_attr( $label ),
		nexora_icon( 'heart', 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'' !== $extra ? '<span class="nx-wishlist-btn__text" aria-hidden="true">' . esc_html__( 'Wishlist', 'nexora-commerce' ) . '</span>' : ''
	);
}

/**
 * Top-level product categories for the mega menu (cached by WordPress term cache).
 *
 * @return WP_Term[]
 */
function nexora_menu_categories() {
	$uncategorized = (int) get_option( 'default_product_cat' );
	$terms         = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'exclude'    => $uncategorized ? array( $uncategorized ) : array(),
			'orderby'    => 'menu_order',
			'number'     => 8,
		)
	);
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	// Products usually sit in subcategories, so a parent's own count can be zero. WooCommerce fills this in itself.
	foreach ( $terms as $term ) {
		if ( ! $term->count ) {
			foreach ( get_term_children( $term->term_id, 'product_cat' ) as $child ) {
				$child_term   = get_term( $child, 'product_cat' );
				$term->count += $child_term instanceof WP_Term ? (int) $child_term->count : 0;
			}
		}
	}
	return $terms;
}

/**
 * Category thumbnail markup.
 *
 * @param WP_Term $term Term.
 * @param string  $size Image size.
 * @return string
 */
function nexora_category_image( $term, $size = 'woocommerce_thumbnail' ) {
	$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
	if ( $thumb ) {
		return wp_get_attachment_image( $thumb, $size, false, array( 'alt' => '', 'loading' => 'lazy' ) );
	}
	return '<span class="nx-cat-placeholder" aria-hidden="true">' . esc_html( mb_substr( $term->name, 0, 1 ) ) . '</span>';
}
