<?php
/**
 * Customer dashboard data. Courier/pickup details, quotations and the wishlist come from the
 * Freshtastic Food Hub Toolkit when it is active; the dashboard still works without it.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hide "Downloads" for customers with nothing to download (a food store rarely sells files).
 *
 * @param array $items Menu items.
 * @return array
 */
function nexora_account_menu_items( $items ) {
	if ( isset( $items['downloads'] ) && WC()->customer && ! WC()->customer->get_downloadable_products() ) {
		unset( $items['downloads'] );
	}
	return $items;
}
add_filter( 'woocommerce_account_menu_items', 'nexora_account_menu_items', 20 );

/**
 * Statuses that mean an order is still in progress.
 *
 * @return string[]
 */
function nexora_account_active_statuses() {
	$statuses = array( 'pending', 'on-hold', 'processing' );
	if ( class_exists( 'Nexora_Toolkit_Fulfilment' ) ) {
		$statuses[] = Nexora_Toolkit_Fulfilment::STATUS;
	}
	return $statuses;
}

/**
 * Everything the dashboard shows for one customer.
 *
 * @param int $user_id User ID.
 * @return array
 */
function nexora_account_summary( $user_id ) {
	$recent = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'type'        => 'shop_order',
			'limit'       => 4,
			'paginate'    => true,
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);
	$active = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'type'        => 'shop_order',
			'limit'       => 50,
			'status'      => nexora_account_active_statuses(),
			'orderby'     => 'date',
			'order'       => 'DESC',
		)
	);

	return array(
		'recent'     => $recent->orders,
		'total'      => (int) $recent->total,
		'active'     => $active,
		'spent'      => (float) wc_get_customer_total_spent( $user_id ),
		'quotations' => class_exists( 'Nexora_Toolkit_RFQ' ) ? Nexora_Toolkit_RFQ::count_for_current_user() : null,
		'wishlist'   => class_exists( 'Nexora_Toolkit_Wishlist' ) && nexora_wishlist_enabled() ? count( Nexora_Toolkit_Wishlist::get_ids( $user_id ) ) : null,
		'next'       => nexora_account_next_order( $active ),
		'buy_again'  => nexora_account_buy_again( $recent->orders ),
	);
}

/**
 * The pickup or rider collection to show first: ready orders, then the earliest scheduled.
 *
 * @param WC_Order[] $orders Orders in progress.
 * @return array|null [order, info]
 */
function nexora_account_next_order( $orders ) {
	if ( ! class_exists( 'Nexora_Toolkit_Fulfilment' ) ) {
		return null;
	}
	$best = null;
	foreach ( $orders as $order ) {
		$info = Nexora_Toolkit_Fulfilment::for_order( $order );
		if ( ! $info ) {
			continue;
		}
		$rank = ( $order->has_status( Nexora_Toolkit_Fulfilment::STATUS ) ? '0' : '1' ) . ( empty( $info['date'] ) ? '9999' : $info['date'] );
		if ( ! $best || $rank < $best['rank'] ) {
			$best = array(
				'rank'  => $rank,
				'order' => $order,
				'info'  => $info,
			);
		}
	}
	return $best;
}

/**
 * Products from recent orders that can be bought again.
 *
 * @param WC_Order[] $orders Orders.
 * @param int        $limit  Limit.
 * @return WC_Product[]
 */
function nexora_account_buy_again( $orders, $limit = 4 ) {
	$products = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || isset( $products[ $product->get_id() ] ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			$products[ $product->get_id() ] = $product;
			if ( count( $products ) >= $limit ) {
				return array_values( $products );
			}
		}
	}
	return array_values( $products );
}

/**
 * Greeting for the store's local time.
 *
 * @return string
 */
function nexora_account_greeting() {
	$hour = (int) current_datetime()->format( 'G' );
	if ( $hour < 12 ) {
		return __( 'Good morning', 'nexora-commerce' );
	}
	return $hour < 18 ? __( 'Good afternoon', 'nexora-commerce' ) : __( 'Good evening', 'nexora-commerce' );
}

/**
 * Progress steps for the "next up" card.
 *
 * @param WC_Order $order Order.
 * @return array<int,array{0:string,1:string}> Label and state (done|current|todo).
 */
function nexora_account_order_steps( $order ) {
	$ready   = class_exists( 'Nexora_Toolkit_Fulfilment' ) && $order->has_status( Nexora_Toolkit_Fulfilment::STATUS );
	$packing = $order->has_status( 'processing' );
	return array(
		array( __( 'Order placed', 'nexora-commerce' ), 'done' ),
		array( __( 'Packing', 'nexora-commerce' ), $ready ? 'done' : ( $packing ? 'current' : 'todo' ) ),
		array( __( 'Ready', 'nexora-commerce' ), $ready ? 'current' : 'todo' ),
		array( __( 'Collected', 'nexora-commerce' ), 'todo' ),
	);
}
