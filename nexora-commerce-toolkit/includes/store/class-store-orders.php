<?php
/**
 * Orders of the built-in store: creation with safe stock handling, status changes, notes, emails and privacy.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orders.
 */
class Nexora_Store_Orders {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'pending'    => __( 'Pending payment', 'nexora-commerce-toolkit' ),
			'on-hold'    => __( 'On hold', 'nexora-commerce-toolkit' ),
			'processing' => __( 'Processing', 'nexora-commerce-toolkit' ),
			'nx-ready'   => __( 'Ready for pickup', 'nexora-commerce-toolkit' ),
			'completed'  => __( 'Completed', 'nexora-commerce-toolkit' ),
			'cancelled'  => __( 'Cancelled', 'nexora-commerce-toolkit' ),
			'refunded'   => __( 'Refunded', 'nexora-commerce-toolkit' ),
			'failed'     => __( 'Failed', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$all = self::statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}

	/**
	 * Statuses of an order still in progress.
	 *
	 * @return string[]
	 */
	public static function active_statuses() {
		return array( 'pending', 'on-hold', 'processing', 'nx-ready' );
	}

	/**
	 * Load an order.
	 *
	 * @param int $id Order ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nx_orders WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return null;
		}
		$row->discounts  = (array) json_decode( (string) $row->discounts, true );
		$row->fulfilment = (array) json_decode( (string) $row->fulfilment, true );
		$row->address    = (array) json_decode( (string) $row->address, true );
		return $row;
	}

	/**
	 * Order lines.
	 *
	 * @param int $id Order ID.
	 * @return object[]
	 */
	public static function items( $id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nx_order_items WHERE order_id = %d ORDER BY id", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Order history.
	 *
	 * @param int $id Order ID.
	 * @return object[]
	 */
	public static function notes( $id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nx_order_notes WHERE order_id = %d ORDER BY id DESC", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Add to the history.
	 *
	 * @param int    $id     Order.
	 * @param string $note   Note.
	 * @param string $author Author.
	 */
	public static function add_note( $id, $note, $author = '' ) {
		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"{$wpdb->prefix}nx_order_notes",
			array(
				'order_id'   => $id,
				'note'       => $note,
				'author'     => $author ? $author : ( $user->exists() ? $user->display_name : __( 'System', 'nexora-commerce-toolkit' ) ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Customer's orders.
	 *
	 * @param int      $user_id  User.
	 * @param int      $limit    Limit.
	 * @param int      $offset   Offset.
	 * @param string[] $statuses Only these statuses.
	 * @return object[]
	 */
	public static function for_user( $user_id, $limit = 10, $offset = 0, $statuses = array() ) {
		global $wpdb;
		$where = $wpdb->prepare( 'user_id = %d', $user_id );
		if ( $statuses ) {
			$where .= " AND status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- built from prepared parts.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nx_orders WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
		return array_filter( array_map( array( __CLASS__, 'get' ), $ids ) );
	}

	/**
	 * Count of a customer's orders.
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function count_for_user( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nx_orders WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Lifetime spend of a customer.
	 *
	 * @param int $user_id User.
	 * @return float
	 */
	public static function spent_by_user( $user_id ) {
		global $wpdb;
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$wpdb->prefix}nx_orders WHERE user_id = %d AND paid_at IS NOT NULL AND status NOT IN ('cancelled','refunded','failed')", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* --------------------------------------------------------------- creation */

	/**
	 * Take stock for an item. The update only succeeds if enough is left, so two customers cannot
	 * buy the last unit at the same moment.
	 *
	 * @param Nexora_Store_Product $item Item.
	 * @param int                  $qty  Quantity.
	 * @return bool
	 */
	private static function take_stock( $item, $qty ) {
		global $wpdb;
		if ( ! $item->managing_stock() ) {
			return true;
		}
		$owner = $item->stock_owner();
		$id    = $owner->get_id();
		if ( $item->backorders_allowed() ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS DECIMAL(14,3)) - %d WHERE post_id = %d AND meta_key = '_stock'", $qty, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $id, 'post_meta' );
			return true;
		}
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS DECIMAL(14,3)) - %d WHERE post_id = %d AND meta_key = '_stock' AND CAST(meta_value AS DECIMAL(14,3)) >= %d", $qty, $id, $qty ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $id, 'post_meta' );
		return (bool) $done;
	}

	/**
	 * Update the stock status flags after a change.
	 *
	 * @param int $id Product or variation holding the stock.
	 */
	private static function sync_stock_status( $id ) {
		if ( ! in_array( get_post_meta( $id, '_manage_stock', true ), array( 'yes', '1' ), true ) ) {
			return;
		}
		$backorder = in_array( get_post_meta( $id, '_backorders', true ), array( 'yes', 'notify' ), true );
		$in        = $backorder || (float) get_post_meta( $id, '_stock', true ) > 0;
		update_post_meta( $id, '_stock_status', $in ? 'instock' : 'outofstock' );
		$product = 'product' === get_post_type( $id ) ? $id : (int) wp_get_post_parent_id( $id );
		if ( $product && 'product' === get_post_type( $id ) ) {
			if ( $in ) {
				wp_remove_object_terms( $product, 'outofstock', 'product_visibility' );
			} else {
				wp_set_object_terms( $product, 'outofstock', 'product_visibility', true );
			}
		}
		Nexora_Store_Catalog::flush();
	}

	/**
	 * Add to or subtract from a product's sales count.
	 *
	 * @param int $product_id Product.
	 * @param int $qty        Change.
	 */
	private static function bump_sales( $product_id, $qty ) {
		update_post_meta( $product_id, 'total_sales', max( 0, (int) get_post_meta( $product_id, 'total_sales', true ) + $qty ) );
	}

	/**
	 * Create an order from calculated totals. All-or-nothing: if any item or coupon cannot be taken,
	 * everything is rolled back and the customer sees why.
	 *
	 * @param array $t    Totals from Nexora_Store_Cart::totals().
	 * @param array $data user_id, email, phone, address, fulfilment, type, date, note, payment.
	 * @return int|WP_Error Order ID.
	 */
	public static function create( $t, $data ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
		$rollback = static function ( $message ) use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'nexora_order', $message );
		};

		foreach ( $t['lines'] as $line ) {
			if ( ! self::take_stock( $line['item'], (int) $line['qty'] ) ) {
				/* translators: %s: product */
				return $rollback( sprintf( __( 'Sorry, %s just sold out or there is not enough left. Please update your cart.', 'nexora-commerce-toolkit' ), $line['product']->get_name() ) );
			}
		}
		$who = $data['user_id'] ? (string) $data['user_id'] : strtolower( $data['email'] );
		foreach ( $t['discounts'] as $discount ) {
			if ( ! empty( $discount['code'] ) && ! Nexora_Store_Coupons::use_coupon( $discount['code'], $who ) ) {
				/* translators: %s: coupon code */
				return $rollback( sprintf( __( 'Coupon %s has just reached its usage limit. Please remove it and try again.', 'nexora-commerce-toolkit' ), $discount['code'] ) );
			}
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			"{$wpdb->prefix}nx_orders",
			array(
				'order_key'       => wp_generate_password( 32, false ),
				'user_id'         => (int) $data['user_id'],
				'status'          => 'pending',
				'email'           => $data['email'],
				'phone'           => $data['phone'],
				'currency'        => (string) Nexora_Store::get( 'currency' ),
				'subtotal'        => $t['subtotal'],
				'discount_total'  => $t['discount_total'],
				'shipping_total'  => $t['shipping_cost'],
				'total'           => $t['total'],
				'discounts'       => wp_json_encode( array_values( $t['discounts'] ) ),
				'payment_method'  => $data['payment']['id'],
				'payment_title'   => $data['payment']['title'],
				'payment_ref'     => $data['payment']['ref'],
				'fulfilment_type' => $data['type'],
				'fulfilment_date' => $data['date'] ? $data['date'] : null,
				'fulfilment'      => wp_json_encode( $data['fulfilment'] ),
				'address'         => wp_json_encode( $data['address'] ),
				'note'            => $data['note'],
				'stock_reduced'   => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( ! $ok ) {
			return $rollback( __( 'Your order could not be saved. Please try again.', 'nexora-commerce-toolkit' ) );
		}
		$id = (int) $wpdb->insert_id;
		foreach ( $t['lines'] as $line ) {
			$wpdb->insert(
				"{$wpdb->prefix}nx_order_items",
				array(
					'order_id'     => $id,
					'product_id'   => $line['product']->get_id(),
					'variation_id' => $line['item']->get_parent_id() ? $line['item']->get_id() : 0,
					'name'         => $line['product']->get_name(),
					'option_label' => $line['option'],
					'sku'          => $line['item']->get_sku(),
					'qty'          => (int) $line['qty'],
					'price'        => $line['price'],
					'line_total'   => $line['total'],
				)
			);
		}
		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		foreach ( $t['lines'] as $line ) {
			self::sync_stock_status( $line['item']->stock_owner()->get_id() );
			self::bump_sales( $line['product']->get_id(), (int) $line['qty'] );
		}
		self::flush_kpis();
		self::add_note( $id, __( 'Order placed.', 'nexora-commerce-toolkit' ), __( 'Customer', 'nexora-commerce-toolkit' ) );
		return $id;
	}

	/**
	 * Clear cached dashboard figures.
	 */
	public static function flush_kpis() {
		delete_transient( 'nexora_store_kpis' );
	}

	/* --------------------------------------------------------------- statuses */

	/**
	 * Change an order's status: stock, coupons, dates, history and emails follow.
	 *
	 * @param int    $id     Order.
	 * @param string $status New status.
	 * @param string $note   Extra note.
	 * @return true|WP_Error
	 */
	public static function set_status( $id, $status, $note = '' ) {
		global $wpdb;
		$order = self::get( $id );
		if ( ! $order || ! isset( self::statuses()[ $status ] ) ) {
			return new WP_Error( 'nexora_order', __( 'Invalid order or status.', 'nexora-commerce-toolkit' ) );
		}
		if ( $order->status === $status ) {
			return true;
		}
		$closing = in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true );
		$fields  = array(
			'status'     => $status,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $closing && $order->stock_reduced ) {
			self::restore_stock( $order );
			$fields['stock_reduced'] = 0;
		} elseif ( ! $closing && ! $order->stock_reduced ) {
			$taken = self::retake_stock( $order );
			if ( is_wp_error( $taken ) ) {
				return $taken;
			}
			$fields['stock_reduced'] = 1;
		}
		if ( ! $order->paid_at && ( 'completed' === $status || ( 'processing' === $status && 'cod' !== $order->payment_method ) ) ) {
			$fields['paid_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		if ( 'nx-ready' === $status ) {
			$fields['ready_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		if ( 'completed' === $status ) {
			$fields['completed_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		$wpdb->update( "{$wpdb->prefix}nx_orders", $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		/* translators: 1: old status, 2: new status */
		self::add_note( $id, trim( sprintf( __( 'Status changed from %1$s to %2$s.', 'nexora-commerce-toolkit' ), self::status_label( $order->status ), self::status_label( $status ) ) . ' ' . $note ) );
		self::flush_kpis();
		self::mail_status( $id, $status, $order->status );
		do_action( 'nexora_store_order_status', $id, $status, $order->status );
		return true;
	}

	/**
	 * Put stock, sales counts and coupon uses back.
	 *
	 * @param object $order Order.
	 */
	private static function restore_stock( $order ) {
		global $wpdb;
		foreach ( self::items( $order->id ) as $line ) {
			$item = Nexora_Store_Product::get( $line->variation_id ? $line->variation_id : $line->product_id );
			if ( $item && $item->managing_stock() ) {
				$owner = $item->stock_owner()->get_id();
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS DECIMAL(14,3)) + %d WHERE post_id = %d AND meta_key = '_stock'", (int) $line->qty, $owner ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_delete( $owner, 'post_meta' );
				self::sync_stock_status( $owner );
			}
			self::bump_sales( (int) $line->product_id, - (int) $line->qty );
		}
		$who = $order->user_id ? (string) $order->user_id : strtolower( $order->email );
		foreach ( $order->discounts as $discount ) {
			if ( ! empty( $discount['code'] ) ) {
				Nexora_Store_Coupons::release_coupon( $discount['code'], $who );
			}
		}
	}

	/**
	 * Take stock again when a cancelled order is reopened.
	 *
	 * @param object $order Order.
	 * @return true|WP_Error
	 */
	private static function retake_stock( $order ) {
		$taken = array();
		foreach ( self::items( $order->id ) as $line ) {
			$item = Nexora_Store_Product::get( $line->variation_id ? $line->variation_id : $line->product_id );
			if ( $item && ! self::take_stock( $item, (int) $line->qty ) ) {
				foreach ( $taken as $back ) {
					global $wpdb;
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS DECIMAL(14,3)) + %d WHERE post_id = %d AND meta_key = '_stock'", $back[1], $back[0] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					wp_cache_delete( $back[0], 'post_meta' );
				}
				/* translators: %s: product */
				return new WP_Error( 'nexora_order', sprintf( __( 'Cannot reopen this order: not enough stock of %s.', 'nexora-commerce-toolkit' ), $line->name ) );
			}
			if ( $item && $item->managing_stock() ) {
				$taken[] = array( $item->stock_owner()->get_id(), (int) $line->qty );
			}
		}
		foreach ( self::items( $order->id ) as $line ) {
			$item = Nexora_Store_Product::get( $line->variation_id ? $line->variation_id : $line->product_id );
			if ( $item ) {
				self::sync_stock_status( $item->stock_owner()->get_id() );
			}
			self::bump_sales( (int) $line->product_id, (int) $line->qty );
		}
		// Cancelling gave the coupon uses back, so reopening has to take them again.
		$who = $order->user_id ? (string) $order->user_id : strtolower( $order->email );
		foreach ( $order->discounts as $discount ) {
			if ( ! empty( $discount['code'] ) && ! Nexora_Store_Coupons::use_coupon( $discount['code'], $who ) ) {
				/* translators: %s: coupon code */
				self::add_note( $order->id, sprintf( __( 'Reopened, but coupon %s had already been used again. Please check the discount on this order.', 'nexora-commerce-toolkit' ), $discount['code'] ) );
			}
		}
		return true;
	}

	/**
	 * Optional: cancel unpaid orders after the number of days set in Store settings, releasing their stock.
	 */
	public static function cancel_stale() {
		global $wpdb;
		$days = (int) Nexora_Store::get( 'hold_days' );
		if ( $days < 1 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nx_orders WHERE status IN ('pending','on-hold') AND created_at < %s LIMIT 100", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $ids as $id ) {
			self::set_status( (int) $id, 'cancelled', __( 'Unpaid past the time limit.', 'nexora-commerce-toolkit' ) );
		}
	}

	/* ------------------------------------------------------------------ views */

	/**
	 * Fulfilment details as "when" text.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function when( $order ) {
		$f = $order->fulfilment;
		return Nexora_Toolkit_Schedule::when( isset( $f['date'] ) ? $f['date'] : '', isset( $f['slot'] ) ? $f['slot'] : '' );
	}

	/**
	 * Customer-facing card: what happens next.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function fulfilment_card( $order ) {
		$f = $order->fulfilment;
		if ( empty( $f['type'] ) ) {
			return '';
		}
		$type  = $f['type'];
		$ready = 'nx-ready' === $order->status;
		$done  = in_array( $order->status, array( 'completed', 'cancelled', 'refunded', 'failed' ), true );
		$title = array(
			'pickup'   => __( 'Store pickup', 'nexora-commerce-toolkit' ),
			'courier'  => __( 'Courier delivery (you book)', 'nexora-commerce-toolkit' ),
			'delivery' => __( 'Store delivery', 'nexora-commerce-toolkit' ),
		);
		$msg   = '';
		if ( ! $done ) {
			if ( 'pickup' === $type ) {
				$msg = $ready ? __( 'Your order is packed and ready. Bring your order number when you collect it.', 'nexora-commerce-toolkit' ) : __( 'We are preparing your order. We will email you when it is ready to collect.', 'nexora-commerce-toolkit' );
			} elseif ( 'courier' === $type ) {
				$msg = $ready ? __( 'Your order is packed. Book your rider now and give them your order number.', 'nexora-commerce-toolkit' ) : __( 'We are preparing your order. We will email you when it is ready, so you can book your rider.', 'nexora-commerce-toolkit' );
			} else {
				$msg = $ready ? __( 'Your order is packed and will be delivered at the time below.', 'nexora-commerce-toolkit' ) : __( 'We are preparing your order for delivery.', 'nexora-commerce-toolkit' );
			}
		}
		$rows = array();
		$when = self::when( $order );
		if ( $when ) {
			$rows[ 'pickup' === $type ? __( 'Pickup time', 'nexora-commerce-toolkit' ) : ( 'courier' === $type ? __( 'Rider pickup time', 'nexora-commerce-toolkit' ) : __( 'Delivery time', 'nexora-commerce-toolkit' ) ) ] = esc_html( $when );
		}
		if ( ! empty( $f['courier'] ) ) {
			$rows[ __( 'Courier', 'nexora-commerce-toolkit' ) ] = esc_html( $f['courier'] );
		}
		if ( ! empty( $f['location'] ) ) {
			$rows[ __( 'Pickup point', 'nexora-commerce-toolkit' ) ] = esc_html( $f['location'] ) . ( ! empty( $f['hours'] ) ? '<br><span class="nexora-fulfilment__muted">' . esc_html( $f['hours'] ) . '</span>' : '' );
		}
		if ( ! empty( $f['address'] ) ) {
			$rows[ 'courier' === $type ? __( 'Rider collects from', 'nexora-commerce-toolkit' ) : __( 'Address', 'nexora-commerce-toolkit' ) ] = esc_html( $f['address'] );
		}
		if ( ! empty( $f['contact'] ) ) {
			$rows[ __( 'Store contact', 'nexora-commerce-toolkit' ) ] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $f['contact'] ) ) . '">' . esc_html( $f['contact'] ) . '</a>';
		}
		$rows[ __( 'Order number', 'nexora-commerce-toolkit' ) ] = '<strong>' . (int) $order->id . '</strong>';

		$out = '<section class="nexora-fulfilment-card' . ( $ready ? ' is-ready' : '' ) . '"><h2 class="nexora-fulfilment-card__title">' . esc_html( isset( $title[ $type ] ) ? $title[ $type ] : $type ) . '</h2>';
		if ( $msg ) {
			$out .= '<p class="nexora-fulfilment-card__status">' . esc_html( $msg ) . '</p>';
		}
		$out .= '<dl class="nexora-fulfilment-card__list">';
		foreach ( $rows as $label => $value ) {
			$out .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
		}
		return $out . '</dl></section>';
	}

	/**
	 * Items and totals table, shared by the thank-you page and order view.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function details_table( $order ) {
		$out = '<table class="woocommerce-table woocommerce-table--order-details shop_table order_details"><thead><tr><th class="product-name">' . esc_html__( 'Product', 'nexora-commerce-toolkit' ) . '</th><th class="product-total">' . esc_html__( 'Total', 'nexora-commerce-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( self::items( $order->id ) as $line ) {
			$link = get_permalink( (int) $line->product_id );
			$name = esc_html( $line->name . ( $line->option_label ? ' — ' . $line->option_label : '' ) );
			$out .= '<tr class="order_item"><td class="product-name">' . ( $link && 'product' === get_post_type( (int) $line->product_id ) ? '<a href="' . esc_url( $link ) . '">' . $name . '</a>' : $name ) . ' <strong class="product-quantity">&times;&nbsp;' . (int) $line->qty . '</strong></td><td class="product-total">' . ( (float) $line->line_total > 0 ? Nexora_Store::price( $line->line_total ) : esc_html__( 'Free', 'nexora-commerce-toolkit' ) ) . '</td></tr>';
		}
		$out .= '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'Subtotal', 'nexora-commerce-toolkit' ) . '</th><td>' . Nexora_Store::price( $order->subtotal ) . '</td></tr>';
		foreach ( $order->discounts as $d ) {
			$out .= '<tr><th scope="row">' . esc_html( $d['label'] ) . '</th><td>' . Nexora_Store_Coupons::discount_value( $d ) . '</td></tr>';
		}
		$f    = $order->fulfilment;
		$out .= '<tr><th scope="row">' . esc_html( ! empty( $f['method'] ) ? $f['method'] : __( 'Delivery', 'nexora-commerce-toolkit' ) ) . '</th><td>' . ( (float) $order->shipping_total > 0 ? Nexora_Store::price( $order->shipping_total ) : esc_html__( 'Free', 'nexora-commerce-toolkit' ) ) . '</td></tr>';
		$out .= '<tr class="order-total"><th scope="row">' . esc_html__( 'Total', 'nexora-commerce-toolkit' ) . '</th><td>' . Nexora_Store::price( $order->total ) . '</td></tr>';
		$out .= '<tr><th scope="row">' . esc_html__( 'Payment', 'nexora-commerce-toolkit' ) . '</th><td>' . esc_html( $order->payment_title . ( $order->payment_ref ? ' (' . $order->payment_ref . ')' : '' ) ) . '</td></tr>';
		return $out . '</tfoot></table>';
	}

	/**
	 * Address as lines.
	 *
	 * @param object $order Order.
	 * @return string[]
	 */
	public static function address_lines( $order ) {
		$a     = $order->address;
		$lines = array( trim( ( isset( $a['first_name'] ) ? $a['first_name'] : '' ) . ' ' . ( isset( $a['last_name'] ) ? $a['last_name'] : '' ) ) );
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $key ) {
			if ( ! empty( $a[ $key ] ) ) {
				$lines[] = $a[ $key ];
			}
		}
		return array_filter( $lines );
	}

	/* ------------------------------------------------------------------ email */

	/**
	 * Wrap a message in the email layout.
	 *
	 * @param string $heading Heading.
	 * @param string $body    Body HTML.
	 * @return string
	 */
	private static function wrap_mail( $heading, $body ) {
		$logo    = (string) apply_filters( 'nexora_store_email_logo', '' );
		$primary = sanitize_hex_color( (string) get_theme_mod( 'nexora_color_primary', '#12306E' ) );
		$primary = $primary ? $primary : '#12306E';
		$name    = get_bloginfo( 'name' );
		return '<!doctype html><html><body style="margin:0;background:#f5f7fb;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#0f1b3d;">'
			. '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e6e8f0;">'
			. ( $logo ? '<div style="padding:18px;text-align:center;"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" width="110" style="height:auto;"></div>' : '' )
			. '<div style="background:' . esc_attr( $primary ) . ';color:#fff;padding:20px 24px;"><h1 style="margin:0;font-size:20px;color:#fff;">' . esc_html( $heading ) . '</h1></div>'
			. '<div style="padding:24px;font-size:14px;line-height:1.6;">' . $body . '</div>'
			. '<div style="padding:14px 24px;background:#f5f7fb;font-size:12px;color:#5f6676;">' . esc_html( $name ) . '</div>'
			. '</div></body></html>';
	}

	/**
	 * Send an HTML email.
	 *
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $heading Heading.
	 * @param string $body    Body.
	 * @return bool
	 */
	public static function send_mail( $to, $subject, $heading, $body ) {
		return wp_mail( $to, $subject, self::wrap_mail( $heading, $body ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Order summary for emails.
	 *
	 * @param object $order Order.
	 * @return string
	 */
	private static function mail_summary( $order ) {
		$cell = 'padding:8px;border-bottom:1px solid #e6e8f0;';
		$out  = '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:16px 0;">';
		foreach ( self::items( $order->id ) as $line ) {
			$out .= '<tr><td style="' . $cell . '">' . esc_html( $line->name . ( $line->option_label ? ' — ' . $line->option_label : '' ) ) . ' &times; ' . (int) $line->qty . '</td><td style="' . $cell . 'text-align:right;">' . esc_html( (float) $line->line_total > 0 ? Nexora_Store::money( $line->line_total ) : __( 'Free', 'nexora-commerce-toolkit' ) ) . '</td></tr>';
		}
		foreach ( $order->discounts as $d ) {
			$out .= '<tr><td style="' . $cell . '">' . esc_html( $d['label'] ) . '</td><td style="' . $cell . 'text-align:right;">' . esc_html( Nexora_Store_Coupons::discount_value( $d, true ) ) . '</td></tr>';
		}
		$f    = $order->fulfilment;
		$out .= '<tr><td style="' . $cell . '">' . esc_html( ! empty( $f['method'] ) ? $f['method'] : '' ) . '</td><td style="' . $cell . 'text-align:right;">' . esc_html( Nexora_Store::money( $order->shipping_total ) ) . '</td></tr>';
		$out .= '<tr><td style="padding:8px;font-weight:bold;">' . esc_html__( 'Total', 'nexora-commerce-toolkit' ) . '</td><td style="padding:8px;font-weight:bold;text-align:right;">' . esc_html( Nexora_Store::money( $order->total ) ) . '</td></tr></table>';
		$rows = array();
		$when = self::when( $order );
		if ( $when ) {
			$rows[] = array( __( 'Time', 'nexora-commerce-toolkit' ), $when );
		}
		if ( ! empty( $f['courier'] ) ) {
			$rows[] = array( __( 'Courier', 'nexora-commerce-toolkit' ), $f['courier'] );
		}
		if ( ! empty( $f['location'] ) ) {
			$rows[] = array( __( 'Pickup point', 'nexora-commerce-toolkit' ), $f['location'] );
		}
		if ( ! empty( $f['address'] ) ) {
			$rows[] = array( 'courier' === $f['type'] ? __( 'Rider collects from', 'nexora-commerce-toolkit' ) : __( 'Address', 'nexora-commerce-toolkit' ), $f['address'] );
		}
		if ( ! empty( $f['contact'] ) ) {
			$rows[] = array( __( 'Store contact', 'nexora-commerce-toolkit' ), $f['contact'] );
		}
		$rows[] = array( __( 'Payment', 'nexora-commerce-toolkit' ), $order->payment_title . ( $order->payment_ref ? ' (' . $order->payment_ref . ')' : '' ) );
		foreach ( $rows as $row ) {
			$out .= '<p style="margin:4px 0;"><strong>' . esc_html( $row[0] ) . ':</strong> ' . esc_html( $row[1] ) . '</p>';
		}
		return $out;
	}

	/**
	 * Emails sent when an order is placed.
	 *
	 * @param int $id Order.
	 */
	public static function mail_new_order( $id ) {
		$order = self::get( $id );
		if ( ! $order ) {
			return;
		}
		$name = get_bloginfo( 'name' );
		$a    = $order->address;
		$first = isset( $a['first_name'] ) ? $a['first_name'] : '';
		$extra = 'bank' === $order->payment_method && Nexora_Store::get( 'bank_details' )
			? '<p><strong>' . esc_html__( 'Bank details', 'nexora-commerce-toolkit' ) . ':</strong><br>' . nl2br( esc_html( (string) Nexora_Store::get( 'bank_details' ) ) ) . '</p>'
			: '';
		/* translators: 1: site name, 2: order number */
		$subject = sprintf( __( 'Your %1$s order #%2$d', 'nexora-commerce-toolkit' ), $name, $id );
		/* translators: %s: first name */
		$body = '<p>' . esc_html( sprintf( __( 'Hi %s, we received your order. Thank you!', 'nexora-commerce-toolkit' ), $first ) ) . '</p>' . $extra . self::mail_summary( $order ) . '<p><a href="' . esc_url( Nexora_Store_Checkout::received_url( $order ) ) . '">' . esc_html__( 'View your order', 'nexora-commerce-toolkit' ) . '</a></p>';
		self::send_mail( $order->email, $subject, __( 'Order received', 'nexora-commerce-toolkit' ), $body );

		$admin = Nexora_Store::get( 'order_email' ) ? (string) Nexora_Store::get( 'order_email' ) : (string) get_option( 'admin_email' );
		/* translators: 1: order number, 2: customer name */
		$subject = sprintf( __( 'New order #%1$d from %2$s', 'nexora-commerce-toolkit' ), $id, trim( $first . ' ' . ( isset( $a['last_name'] ) ? $a['last_name'] : '' ) ) );
		$body    = '<p>' . esc_html( $order->email . ' · ' . $order->phone ) . '</p>' . self::mail_summary( $order ) . '<p><a href="' . esc_url( admin_url( 'admin.php?page=nexora-store-orders&order=' . $id ) ) . '">' . esc_html__( 'Open the order', 'nexora-commerce-toolkit' ) . '</a></p>';
		self::send_mail( $admin, $subject, __( 'New order', 'nexora-commerce-toolkit' ), $body );
	}

	/**
	 * Customer email for a status change.
	 *
	 * @param int    $id     Order.
	 * @param string $status New status.
	 * @param string $old    Old status.
	 */
	private static function mail_status( $id, $status, $old ) {
		$order = self::get( $id );
		if ( ! $order || ! $order->email || 'pending' === $old && 'on-hold' === $status ) {
			return;
		}
		$type = isset( $order->fulfilment['type'] ) ? $order->fulfilment['type'] : '';
		$map  = array(
			'processing' => array( __( 'We are preparing your order', 'nexora-commerce-toolkit' ), __( 'Your order is confirmed and we are preparing it now.', 'nexora-commerce-toolkit' ) ),
			'nx-ready'   => array(
				__( 'Your order is packed and ready', 'nexora-commerce-toolkit' ),
				'courier' === $type ? __( 'Your order is packed and waiting for your rider. You can book your courier now.', 'nexora-commerce-toolkit' ) : ( 'pickup' === $type ? __( 'Your order is packed and ready to collect. Please bring your order number; someone else can collect it for you.', 'nexora-commerce-toolkit' ) : __( 'Your order is packed and will be delivered soon.', 'nexora-commerce-toolkit' ) ),
			),
			'completed'  => array( __( 'Your order is complete', 'nexora-commerce-toolkit' ), __( 'Your order is complete. Thank you for buying from our market!', 'nexora-commerce-toolkit' ) ),
			'cancelled'  => array( __( 'Your order was cancelled', 'nexora-commerce-toolkit' ), __( 'Your order was cancelled. Please contact us if this is unexpected.', 'nexora-commerce-toolkit' ) ),
			'refunded'   => array( __( 'Your order was refunded', 'nexora-commerce-toolkit' ), __( 'Your order was marked as refunded.', 'nexora-commerce-toolkit' ) ),
		);
		if ( ! isset( $map[ $status ] ) ) {
			return;
		}
		/* translators: 1: site name, 2: order number, 3: heading */
		$subject = sprintf( __( '%1$s order #%2$d: %3$s', 'nexora-commerce-toolkit' ), get_bloginfo( 'name' ), $id, $map[ $status ][0] );
		$body    = '<p>' . esc_html( $map[ $status ][1] ) . '</p>' . self::mail_summary( $order ) . '<p><a href="' . esc_url( Nexora_Store_Checkout::received_url( $order ) ) . '">' . esc_html__( 'View your order', 'nexora-commerce-toolkit' ) . '</a></p>';
		self::send_mail( $order->email, $subject, $map[ $status ][0], $body );
	}

	/* ---------------------------------------------------------------- privacy */

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nexora-store-orders'] = array(
			'exporter_friendly_name' => __( 'Store orders', 'nexora-commerce-toolkit' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['nexora-store-orders'] = array(
			'eraser_friendly_name' => __( 'Store orders', 'nexora-commerce-toolkit' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export a customer's orders.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nx_orders WHERE email = %s ORDER BY id LIMIT 50 OFFSET %d", $email, 50 * ( max( 1, (int) $page ) - 1 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = array();
		foreach ( $ids as $id ) {
			$order  = self::get( (int) $id );
			$data[] = array(
				'group_id'    => 'nexora-store-orders',
				'group_label' => __( 'Store orders', 'nexora-commerce-toolkit' ),
				'item_id'     => 'nexora-order-' . $id,
				'data'        => array(
					array( 'name' => __( 'Order', 'nexora-commerce-toolkit' ), 'value' => '#' . $id ),
					array( 'name' => __( 'Date', 'nexora-commerce-toolkit' ), 'value' => $order->created_at ),
					array( 'name' => __( 'Total', 'nexora-commerce-toolkit' ), 'value' => Nexora_Store::money( $order->total ) ),
					array( 'name' => __( 'Email', 'nexora-commerce-toolkit' ), 'value' => $order->email ),
					array( 'name' => __( 'Phone', 'nexora-commerce-toolkit' ), 'value' => $order->phone ),
					array( 'name' => __( 'Address', 'nexora-commerce-toolkit' ), 'value' => implode( ', ', self::address_lines( $order ) ) ),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < 50,
		);
	}

	/**
	 * Remove personal details from a customer's finished orders. Orders in progress are kept and reported.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, status FROM {$wpdb->prefix}nx_orders WHERE email = %s LIMIT 50", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$removed  = false;
		$retained = false;
		$messages = array();
		foreach ( $rows as $row ) {
			if ( in_array( $row->status, self::active_statuses(), true ) ) {
				$retained = true;
				/* translators: %d: order number */
				$messages[] = sprintf( __( 'Order #%d is still in progress and was kept.', 'nexora-commerce-toolkit' ), $row->id );
				continue;
			}
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"{$wpdb->prefix}nx_orders",
				array(
					'email'   => 'deleted-' . (int) $row->id . '@invalid.example',
					'phone'   => '',
					'address' => '[]',
					'note'    => '',
					'user_id' => 0,
				),
				array( 'id' => $row->id )
			);
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $retained || count( $rows ) < 50,
		);
	}
}
