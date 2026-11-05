<?php
/**
 * Market storefront helpers: units, origin, price change, price-per-kg tables, board data and
 * quotation (RFQ) links. Loaded only when WooCommerce is active.
 *
 * Market data comes from the Freshtastic Food Hub Toolkit (filters `nexora_market_data`,
 * `nexora_unit_prices`, `nexora_market_movers`, `nexora_rfq_enabled`, `nexora_rfq_url`).
 * Without the toolkit these helpers return empty values and the related UI is hidden.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Market data for a product.
 *
 * @param WC_Product $product Product.
 * @return array{unit:string,unit_label:string,origin:string,change:?float,previous:?float,since:?int,history:array,measure_unit:string,from_per:?array}
 */
function nexora_market( $product ) {
	static $cache = array();
	$id = $product->get_id();
	if ( ! isset( $cache[ $id ] ) ) {
		$cache[ $id ] = wp_parse_args(
			(array) apply_filters( 'nexora_market_data', array(), $product ),
			array( 'unit' => '', 'unit_label' => '', 'origin' => '', 'change' => null, 'previous' => null, 'since' => null, 'history' => array(), 'measure_unit' => '', 'from_per' => null )
		);
	}
	return $cache[ $id ];
}

/**
 * Price change chip: ▲ 4.0% / ▼ 2.5% with accessible text and the date of the change.
 *
 * @param float|null $change Percent.
 * @param int|null   $since  Timestamp of the latest price update.
 * @param string     $extra  Extra class.
 * @return string
 */
function nexora_change_chip( $change, $since = null, $extra = '' ) {
	if ( null === $change ) {
		return '';
	}
	$change = (float) $change;
	$dir    = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'flat' );
	$arrow  = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$words  = array(
		/* translators: %s: percent */
		'up'   => __( 'up %s since the previous price', 'nexora-commerce' ),
		/* translators: %s: percent */
		'down' => __( 'down %s since the previous price', 'nexora-commerce' ),
		/* translators: %s: percent */
		'flat' => __( 'unchanged (%s)', 'nexora-commerce' ),
	);
	$pct   = number_format_i18n( abs( $change ), 1 ) . '%';
	$title = $since ? sprintf( /* translators: %s: date */ __( 'Price updated %s', 'nexora-commerce' ), wp_date( get_option( 'date_format' ), $since ) ) : '';
	return sprintf(
		'<span class="nx-chg is-%1$s %2$s" title="%3$s"><span aria-hidden="true">%4$s %5$s</span><span class="screen-reader-text">%6$s</span></span>',
		esc_attr( $dir ),
		esc_attr( $extra ),
		esc_attr( $title ),
		esc_html( $arrow ),
		esc_html( $pct ),
		esc_html( sprintf( $words[ $dir ], $pct ) )
	);
}

/**
 * Whether quotation requests are available (Freshtastic Food Hub Toolkit).
 *
 * @return bool
 */
function nexora_rfq_enabled() {
	return (bool) apply_filters( 'nexora_rfq_enabled', false );
}

/**
 * Quotation page URL, optionally prefilled with an item name and product.
 *
 * @param string $item       Item.
 * @param int    $product_id Product.
 * @return string
 */
function nexora_rfq_url( $item = '', $product_id = 0 ) {
	$url = (string) apply_filters( 'nexora_rfq_url', home_url( '/request-a-quote/' ), $item );
	return $product_id ? add_query_arg( 'rfq_product', (int) $product_id, $url ) : $url;
}

/**
 * "Request quote" button.
 *
 * @param WC_Product|null $product Product (prefills the form) or null.
 * @param string          $label   Label.
 * @param string          $class   Classes.
 * @return string
 */
function nexora_rfq_button( $product = null, $label = '', $class = 'nx-btn nx-btn--accent nx-btn--sm' ) {
	if ( ! nexora_rfq_enabled() ) {
		return '';
	}
	$label = $label ? $label : __( 'Request quote', 'nexora-commerce' );
	$url   = $product ? nexora_rfq_url( $product->get_name(), $product->get_id() ) : nexora_rfq_url();
	return sprintf(
		'<a class="%1$s" href="%2$s">%3$s%4$s%5$s</a>',
		esc_attr( $class ),
		esc_url( $url ),
		nexora_icon( 'clipboard', 16 ),
		esc_html( $label ),
		$product ? '<span class="screen-reader-text"> ' . esc_html( sprintf( /* translators: %s: product */ __( 'for %s', 'nexora-commerce' ), $product->get_name() ) ) . '</span>' : ''
	);
}

/**
 * Inline SVG sparkline for a price history.
 *
 * @param array $history Entries ['t','p'].
 * @param int   $w       Width.
 * @param int   $h       Height.
 * @return string
 */
function nexora_sparkline( $history, $w = 120, $h = 36 ) {
	$points = array_values(
		array_map(
			static function ( $e ) {
				return (float) $e['p'];
			},
			(array) $history
		)
	);
	if ( count( $points ) < 2 ) {
		return '';
	}
	$min  = min( $points );
	$max  = max( $points );
	$span = max( 0.0001, $max - $min );
	$n    = count( $points ) - 1;
	$d    = '';
	foreach ( $points as $i => $p ) {
		$x  = round( $i / $n * ( $w - 4 ) + 2, 1 );
		$y  = round( $h - 4 - ( $p - $min ) / $span * ( $h - 8 ), 1 );
		$d .= ( $i ? ' L' : 'M' ) . $x . ' ' . $y;
	}
	$dir = $points[ $n ] >= $points[ $n - 1 ] ? 'up' : 'down';
	return sprintf( '<svg class="nx-spark is-%1$s" width="%2$d" height="%3$d" viewBox="0 0 %2$d %3$d" aria-hidden="true" focusable="false"><path d="%4$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>', esc_attr( $dir ), (int) $w, (int) $h, esc_attr( $d ) );
}

/**
 * Stock line for cards and boards ("220 kg left", "Out of stock").
 *
 * @param WC_Product $product Product.
 * @return string
 */
function nexora_stock_line( $product ) {
	if ( ! $product->is_in_stock() ) {
		return '<span class="nx-stock is-out">' . esc_html__( 'Out of stock', 'nexora-commerce' ) . '</span>';
	}
	$m   = nexora_market( $product );
	$qty = $product->managing_stock() && ! $product->is_type( 'variable' ) ? (int) $product->get_stock_quantity() : null;
	if ( null === $qty ) {
		return '<span class="nx-stock is-in">' . esc_html__( 'In stock', 'nexora-commerce' ) . '</span>';
	}
	$low    = (int) nexora_option( 'low_stock_threshold' );
	$is_low = $low > 0 && $qty <= $low;
	// Measured units read naturally after a number ("38 kg"); count units do not ("85 bottle").
	$amount = number_format_i18n( $qty ) . ( in_array( $m['unit'], array( 'kg', 'liter' ), true ) ? ' ' . $m['unit_label'] : '' );
	/* translators: %s: quantity, e.g. "3 kg" */
	$text = $is_low ? sprintf( __( 'Only %s left', 'nexora-commerce' ), $amount ) : sprintf( __( '%s in stock', 'nexora-commerce' ), $amount );
	return '<span class="nx-stock ' . ( $is_low ? 'is-low' : 'is-in' ) . '">' . esc_html( $text ) . '</span>';
}

/**
 * Remove the "/ unit" suffix from price HTML (where the unit is shown separately).
 *
 * @param string $html Price HTML.
 * @return string
 */
function nexora_strip_unit( $html ) {
	return (string) preg_replace( '#\s*<span class="nexora-unit">.*?</span>#', '', (string) $html );
}

/**
 * Products for the hero board and price board: recent movers first, then best sellers.
 *
 * @param int  $limit       Limit.
 * @param bool $movers_only Only products with a recorded price change.
 * @return WC_Product[]
 */
function nexora_board_products( $limit = 12, $movers_only = false ) {
	$ids = array();
	foreach ( (array) apply_filters( 'nexora_market_movers', array(), 50 ) as $mover ) {
		$ids[] = (int) $mover['id'];
	}
	if ( ! $movers_only ) {
		$ids = array_merge( $ids, nexora_get_product_ids( 'best_selling', 24 ) );
	}
	$ids      = array_slice( array_values( array_unique( $ids ) ), 0, max( 1, (int) $limit ) );
	$products = array_filter( array_map( 'wc_get_product', $ids ) );
	return array_values(
		array_filter(
			$products,
			static function ( $p ) {
				return $p->is_visible();
			}
		)
	);
}

/**
 * Category slugs for a product, including ancestors (for board filter tabs).
 *
 * @param WC_Product $product Product.
 * @return string[]
 */
function nexora_product_cat_slugs( $product ) {
	$slugs = array();
	foreach ( wc_get_product_terms( $product->get_id(), 'product_cat' ) as $term ) {
		$slugs[] = $term->slug;
		foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $ancestor ) {
			$a = get_term( $ancestor, 'product_cat' );
			if ( $a && ! is_wp_error( $a ) ) {
				$slugs[] = $a->slug;
			}
		}
	}
	return array_values( array_unique( $slugs ) );
}

/**
 * Board action cell: add to cart, choose options, or request a quote when out of stock.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function nexora_board_action( $product ) {
	if ( ! $product->is_in_stock() ) {
		$rfq = nexora_rfq_button( $product, __( 'Quote', 'nexora-commerce' ), 'nx-btn nx-btn--accent nx-btn--xs' );
		return $rfq ? $rfq : '<span class="nx-stock is-out">' . esc_html__( 'Out of stock', 'nexora-commerce' ) . '</span>';
	}
	if ( $product->is_type( 'simple' ) && $product->is_purchasable() ) {
		// Run WooCommerce's loop filter so extensions (e.g. analytics) can add their data attributes.
		$args  = apply_filters(
			'woocommerce_loop_add_to_cart_args',
			array(
				'quantity'   => 1,
				'class'      => 'button add_to_cart_button ajax_add_to_cart',
				'attributes' => array(
					'data-product_id'  => $product->get_id(),
					'data-product_sku' => $product->get_sku(),
					/* translators: %s: product */
					'aria-label'       => sprintf( __( 'Add one %s to cart', 'nexora-commerce' ), $product->get_name() ),
					'rel'              => 'nofollow',
				),
			),
			$product
		);
		$attrs = '';
		foreach ( (array) $args['attributes'] as $name => $value ) {
			$attrs .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return sprintf(
			'<a href="%1$s" data-quantity="%2$d" class="%3$s nx-btn--xs"%4$s>%5$s</a>',
			esc_url( $product->add_to_cart_url() ),
			(int) $args['quantity'],
			esc_attr( $args['class'] ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			esc_html__( 'Add', 'nexora-commerce' )
		);
	}
	return sprintf( '<a class="nx-btn nx-btn--ghost nx-btn--xs" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( $product->get_permalink() ), esc_html__( 'Options', 'nexora-commerce' ), esc_html( $product->get_name() ) );
}

/* -------------------------------------------------------------------------
 * Single product additions
 * ---------------------------------------------------------------------- */

/**
 * Origin, price change (with sparkline) and "from ₱/kg" under the price.
 */
function nexora_single_market_meta() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$m = nexora_market( $product );
	if ( ! $m['origin'] && null === $m['change'] && ! $m['from_per'] ) {
		return;
	}
	echo '<div class="nx-market-meta">';
	if ( null !== $m['change'] ) {
		echo '<span class="nx-market-meta__chg">' . nexora_change_chip( $m['change'], $m['since'] ) . ' <small>' . esc_html__( 'vs previous price', 'nexora-commerce' ) . '</small></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo nexora_sparkline( $m['history'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	if ( $m['from_per'] ) {
		/* translators: 1: price, 2: kg or L */
		echo '<span class="nx-market-meta__per">' . wp_kses_post( sprintf( __( 'From %1$s / %2$s', 'nexora-commerce' ), wc_price( $m['from_per']['per'] ), esc_html( $m['from_per']['measure'] ) ) ) . '</span>';
	}
	if ( $m['origin'] ) {
		/* translators: %s: origin */
		echo '<span class="nx-origin">' . nexora_icon( 'pin', 14 ) . esc_html( sprintf( __( 'Origin: %s', 'nexora-commerce' ), $m['origin'] ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';
}
add_action( 'woocommerce_single_product_summary', 'nexora_single_market_meta', 11 );

/**
 * Price per kg/L comparison for packs and sacks.
 */
function nexora_single_unit_prices() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$rows = (array) apply_filters( 'nexora_unit_prices', array(), $product );
	if ( count( $rows ) < 2 ) {
		return;
	}
	$best    = $rows[0]['per'];
	$measure = $rows[0]['measure'];
	echo '<div class="nx-unit-table"><table><caption>' . esc_html( sprintf( /* translators: %s: kg or L */ __( 'Price per %s by pack size', 'nexora-commerce' ), $measure ) ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Pack', 'nexora-commerce' ) . '</th><th scope="col">' . esc_html__( 'Price', 'nexora-commerce' ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: kg or L */ __( 'Per %s', 'nexora-commerce' ), $measure ) ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$is_best = abs( $row['per'] - $best ) < 0.005;
		$tag     = '';
		if ( $is_best ) {
			$tag = ' <span class="nx-tag nx-tag--success">' . esc_html__( 'Best value', 'nexora-commerce' ) . '</span>';
		} elseif ( ! $row['in_stock'] ) {
			$tag = ' <span class="nx-tag">' . esc_html__( 'Out of stock', 'nexora-commerce' ) . '</span>';
		}
		printf(
			'<tr%1$s><th scope="row">%2$s%3$s</th><td>%4$s</td><td>%5$s</td></tr>',
			$is_best ? ' class="is-best"' : '',
			esc_html( $row['label'] ),
			$tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			wp_kses_post( wc_price( $row['price'] ) ),
			wp_kses_post( wc_price( $row['per'] ) )
		);
	}
	echo '</tbody></table></div>';
}
add_action( 'woocommerce_single_product_summary', 'nexora_single_unit_prices', 25 );

/**
 * Quotation prompt — prominent when out of stock, a subtle bulk note otherwise.
 */
function nexora_single_rfq() {
	global $product;
	if ( ! $product instanceof WC_Product || ! nexora_rfq_enabled() ) {
		return;
	}
	if ( ! $product->is_in_stock() ) {
		printf(
			'<div class="nx-rfq-box"><p class="nx-rfq-box__title">%1$s %2$s</p><p>%3$s</p>%4$s</div>',
			nexora_icon( 'clipboard', 20 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Currently unavailable', 'nexora-commerce' ),
			esc_html__( 'Tell us how much you need and when — we will check supply and send you a quotation.', 'nexora-commerce' ),
			nexora_rfq_button( $product, __( 'Request a quotation', 'nexora-commerce' ), 'nx-btn nx-btn--accent' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		return;
	}
	printf(
		'<p class="nx-bulk-note">%1$s %2$s <a href="%3$s">%4$s</a></p>',
		nexora_icon( 'clipboard', 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Buying in bulk or for your business?', 'nexora-commerce' ),
		esc_url( nexora_rfq_url( $product->get_name(), $product->get_id() ) ),
		esc_html__( 'Request a volume quotation', 'nexora-commerce' )
	);
}
add_action( 'woocommerce_single_product_summary', 'nexora_single_rfq', 32 );
