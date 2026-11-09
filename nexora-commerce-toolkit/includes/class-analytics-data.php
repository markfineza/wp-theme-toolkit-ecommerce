<?php
/**
 * Figures behind Analytics and the daily report, for the built-in store and WooCommerce.
 *
 * Each engine turns its orders into the same plain records, and everything else works from those,
 * so both show the same figures by the same rules. Days follow the site's timezone.
 *
 * What counts as a sale matches WooCommerce's Analytics: every order except pending payment,
 * cancelled, failed and refunded. Orders count on the date they were placed.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analytics data.
 */
class Nexora_Toolkit_Analytics_Data {

	const BATCH    = 300;
	const MAX_DAYS = 366;

	/**
	 * Statuses that are not sales.
	 *
	 * @var string[]
	 */
	private static $not_sales = array( 'pending', 'cancelled', 'failed', 'refunded', 'trash', 'auto-draft', 'checkout-draft' );

	/* ------------------------------------------------------------------- dates */

	/**
	 * Named date ranges.
	 *
	 * @return array<string,string>
	 */
	public static function presets() {
		return array(
			'today'      => __( 'Today', 'nexora-commerce-toolkit' ),
			'yesterday'  => __( 'Yesterday', 'nexora-commerce-toolkit' ),
			'7d'         => __( 'Last 7 days', 'nexora-commerce-toolkit' ),
			'30d'        => __( 'Last 30 days', 'nexora-commerce-toolkit' ),
			'month'      => __( 'This month', 'nexora-commerce-toolkit' ),
			'last-month' => __( 'Last month', 'nexora-commerce-toolkit' ),
			'year'       => __( 'This year', 'nexora-commerce-toolkit' ),
			'custom'     => __( 'Custom dates', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Turn a choice into a date range, with the earlier period to compare it with.
	 * While a period is still running, the comparison covers only the same amount of time.
	 *
	 * @param string $preset Preset key.
	 * @param string $from   Custom start, Y-m-d.
	 * @param string $to     Custom last day, Y-m-d.
	 * @return array
	 */
	public static function range( $preset, $from = '', $to = '' ) {
		$now      = current_datetime();
		$today    = $now->setTime( 0, 0 );
		$tomorrow = $today->modify( '+1 day' );
		$preset   = isset( self::presets()[ $preset ] ) ? $preset : '30d';
		switch ( $preset ) {
			case 'today':
				$start = $today;
				$end   = $tomorrow;
				break;
			case 'yesterday':
				$start = $today->modify( '-1 day' );
				$end   = $today;
				break;
			case '7d':
				$start = $today->modify( '-6 days' );
				$end   = $tomorrow;
				break;
			case 'month':
				$start = $today->modify( 'first day of this month' );
				$end   = $start->modify( '+1 month' );
				break;
			case 'last-month':
				$end   = $today->modify( 'first day of this month' );
				$start = $end->modify( '-1 month' );
				break;
			case 'year':
				$start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				$end   = $start->modify( '+1 year' );
				break;
			case 'custom':
				$start = self::parse_day( $from );
				$last  = self::parse_day( $to );
				if ( ! $start || ! $last ) {
					return self::range( '30d' );
				}
				if ( $start > $last ) {
					list( $start, $last ) = array( $last, $start );
				}
				$end = min( $last->modify( '+1 day' ), $tomorrow );
				if ( $start >= $end ) {
					$start = $today;
					$end   = $tomorrow;
				}
				if ( self::days_between( $start, $end ) > self::MAX_DAYS ) {
					$start = $end->modify( '-' . self::MAX_DAYS . ' days' );
				}
				break;
			default:
				$start = $today->modify( '-29 days' );
				$end   = $tomorrow;
		}
		$days       = self::days_between( $start, $end );
		$prev_start = $start->modify( '-' . $days . ' days' );
		$prev_end   = $start;
		if ( $end > $now ) {
			$elapsed  = $now->getTimestamp() - $start->getTimestamp();
			$prev_end = $prev_start->setTimestamp( min( $prev_start->getTimestamp() + $elapsed, $start->getTimestamp() ) );
		}
		if ( 1 === $days ) {
			$unit = 'hour';
		} elseif ( $days <= 62 ) {
			$unit = 'day';
		} elseif ( $days <= 183 ) {
			$unit = 'week';
		} else {
			$unit = 'month';
		}
		return array(
			'preset'     => $preset,
			'start'      => $start,
			'end'        => $end,
			'prev_start' => $prev_start,
			'prev_end'   => $prev_end,
			'unit'       => $unit,
			'days'       => $days,
			'from'       => $start->format( 'Y-m-d' ),
			'to'         => $end->modify( '-1 day' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * Range for one day.
	 *
	 * @param DateTimeImmutable $day Day.
	 * @return array Start and end.
	 */
	public static function day_bounds( $day ) {
		$start = $day->setTime( 0, 0 );
		return array( $start, $start->modify( '+1 day' ) );
	}

	/**
	 * Read Y-m-d in the site's timezone.
	 *
	 * @param string $value Date.
	 * @return DateTimeImmutable|null
	 */
	public static function parse_day( $value ) {
		$value = (string) $value;
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
		return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	/**
	 * Whole days between two midnights. Rounding absorbs daylight-saving changes.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return int
	 */
	private static function days_between( $start, $end ) {
		return (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS );
	}

	/**
	 * Start of every chart point, plus the end of the last. Time that has not happened yet is left out.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @param string            $unit  hour|day|week|month.
	 * @return int[]
	 */
	public static function boundaries( $start, $end, $unit ) {
		$steps = array(
			'hour'  => '+1 hour',
			'day'   => '+1 day',
			'week'  => '+7 days',
			'month' => 'first day of next month',
		);
		$limit = min( $end->getTimestamp(), max( time(), $start->getTimestamp() + 1 ) );
		$out   = array();
		for ( $at = $start; $at->getTimestamp() < $limit; $at = $at->modify( $steps[ $unit ] ) ) {
			$out[] = $at->getTimestamp();
		}
		$out[] = $limit;
		return $out;
	}

	/* ------------------------------------------------------------------ orders */

	/**
	 * Is this status a sale?
	 *
	 * @param string $status Status without the wc- prefix.
	 * @return bool
	 */
	public static function is_sale( $status ) {
		return ! in_array( $status, self::$not_sales, true );
	}

	/**
	 * Call back with plain records of the orders placed in a period, in batches.
	 *
	 * @param int      $from Start timestamp.
	 * @param int      $to   End timestamp, not included.
	 * @param callable $each Receives one record.
	 */
	private static function each_order( $from, $to, $each ) {
		if ( $from >= $to ) {
			return;
		}
		if ( Nexora_Store::active() ) {
			self::each_native( $from, $to, $each );
		} elseif ( function_exists( 'wc_get_orders' ) ) {
			self::each_woocommerce( $from, $to, $each );
		}
	}

	/**
	 * Built-in store orders.
	 *
	 * @param int      $from Start.
	 * @param int      $to   End.
	 * @param callable $each Callback.
	 */
	private static function each_native( $from, $to, $each ) {
		global $wpdb;
		$after = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names built from $wpdb->prefix; ids are cast to integers.
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, status, user_id, email, subtotal, discount_total, shipping_total, total, payment_title, fulfilment_type FROM {$wpdb->prefix}nx_orders WHERE created_at >= %s AND created_at < %s AND id > %d ORDER BY id ASC LIMIT %d", gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ), $after, self::BATCH ) );
			if ( ! $rows ) {
				break;
			}
			$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
			$items = array();
			foreach ( $wpdb->get_results( "SELECT order_id, product_id, name, qty, line_total FROM {$wpdb->prefix}nx_order_items WHERE order_id IN (" . implode( ',', $ids ) . ')' ) as $line ) {
				$items[ (int) $line->order_id ][] = array(
					'product' => (int) $line->product_id,
					'name'    => $line->name,
					'qty'     => (int) $line->qty,
					'total'   => (float) $line->line_total,
				);
			}
			foreach ( $rows as $row ) {
				$id    = (int) $row->id;
				$after = $id;
				$each(
					array(
						'time'       => (int) strtotime( $row->created_at . ' UTC' ),
						'status'     => $row->status,
						'customer'   => $row->user_id ? 'u' . $row->user_id : 'e' . strtolower( $row->email ),
						'subtotal'   => (float) $row->subtotal,
						'discount'   => (float) $row->discount_total,
						'shipping'   => (float) $row->shipping_total,
						'tax'        => 0.0,
						'total'      => (float) $row->total,
						'refunded'   => 'refunded' === $row->status ? (float) $row->total : 0.0,
						'payment'    => (string) $row->payment_title,
						'fulfilment' => (string) $row->fulfilment_type,
						'items'      => isset( $items[ $id ] ) ? $items[ $id ] : array(),
					)
				);
			}
		} while ( count( $rows ) === self::BATCH );
		// phpcs:enable
	}

	/**
	 * WooCommerce orders. Refund records are not orders here; each order reports its own refunded amount.
	 *
	 * @param int      $from Start.
	 * @param int      $to   End.
	 * @param callable $each Callback.
	 */
	private static function each_woocommerce( $from, $to, $each ) {
		$page = 1;
		do {
			$orders = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array_keys( wc_get_order_statuses() ),
					'date_created' => $from . '...' . ( $to - 1 ),
					'limit'        => self::BATCH,
					'paged'        => $page,
					'orderby'      => 'ID',
					'order'        => 'ASC',
				)
			);
			foreach ( $orders as $order ) {
				$created = $order->get_date_created();
				$items   = array();
				foreach ( $order->get_items() as $line ) {
					$items[] = array(
						'product' => (int) $line->get_product_id(),
						'name'    => $line->get_name(),
						'qty'     => (int) $line->get_quantity(),
						'total'   => (float) $line->get_subtotal(),
					);
				}
				$each(
					array(
						'time'       => $created ? $created->getTimestamp() : $from,
						'status'     => $order->get_status(),
						'customer'   => $order->get_customer_id() ? 'u' . $order->get_customer_id() : 'e' . strtolower( $order->get_billing_email() ),
						'subtotal'   => (float) $order->get_subtotal(),
						'discount'   => (float) $order->get_discount_total(),
						'shipping'   => (float) $order->get_shipping_total(),
						'tax'        => (float) $order->get_total_tax(),
						'total'      => (float) $order->get_total(),
						'refunded'   => (float) $order->get_total_refunded(),
						'payment'    => (string) $order->get_payment_method_title(),
						'fulfilment' => (string) $order->get_meta( '_nexora_fulfilment_type' ),
						'items'      => $items,
					)
				);
			}
			++$page;
		} while ( count( $orders ) === self::BATCH );
	}

	/**
	 * Add up a period. Longer periods are kept for five minutes, since they take the most work.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @param string            $unit  Chart unit.
	 * @return array
	 */
	public static function summary( $start, $end, $unit = 'day' ) {
		$days  = self::days_between( $start, $end );
		$cache = 'nx_an_' . md5( $start->getTimestamp() . '|' . $end->getTimestamp() . '|' . $unit . '|' . Nexora_Store::engine() );
		if ( $days > 31 ) {
			$saved = get_transient( $cache );
			if ( is_array( $saved ) ) {
				return $saved;
			}
		}
		$edges = self::boundaries( $start, $end, $unit );
		$slots = max( 1, count( $edges ) - 1 );
		$out   = array(
			'sales'      => 0.0,
			'net'        => 0.0,
			'gross'      => 0.0,
			'discount'   => 0.0,
			'shipping'   => 0.0,
			'tax'        => 0.0,
			'refunds'    => 0.0,
			'orders'     => 0,
			'items'      => 0,
			'customers'  => 0,
			'average'    => 0.0,
			'cancelled'  => 0,
			'unpaid'     => 0,
			'unpaid_sum' => 0.0,
			'edges'      => $edges,
			'series'     => array_fill( 0, $slots, 0.0 ),
			'counts'     => array_fill( 0, $slots, 0 ),
			'statuses'   => array(),
			'payments'   => array(),
			'fulfilment' => array(),
			'products'   => array(),
		);
		$seen  = array();
		self::each_order(
			$start->getTimestamp(),
			$end->getTimestamp(),
			static function ( $order ) use ( &$out, &$seen, $edges, $slots ) {
				$status                   = $order['status'];
				$out['statuses'][ $status ] = ( isset( $out['statuses'][ $status ] ) ? $out['statuses'][ $status ] : 0 ) + 1;
				$out['refunds']          += $order['refunded'];
				if ( ! self::is_sale( $status ) ) {
					if ( 'pending' === $status ) {
						++$out['unpaid'];
						$out['unpaid_sum'] += $order['total'];
					} elseif ( 'cancelled' === $status ) {
						++$out['cancelled'];
					}
					return;
				}
				$sale = $order['total'] - $order['refunded'];
				$slot = min( $slots - 1, self::slot( $edges, $order['time'] ) );
				++$out['orders'];
				$out['sales']          += $sale;
				$out['gross']          += $order['subtotal'];
				$out['discount']       += $order['discount'];
				$out['shipping']       += $order['shipping'];
				$out['tax']            += $order['tax'];
				$out['series'][ $slot ] += $sale;
				++$out['counts'][ $slot ];
				$seen[ $order['customer'] ] = true;
				self::tally( $out['payments'], '' !== $order['payment'] ? $order['payment'] : __( 'Not recorded', 'nexora-commerce-toolkit' ), $sale );
				self::tally( $out['fulfilment'], $order['fulfilment'], $sale );
				$counted = array();
				foreach ( $order['items'] as $line ) {
					$key             = $line['product'] ? $line['product'] : 'n' . md5( $line['name'] );
					$out['items']   += $line['qty'];
					if ( ! isset( $out['products'][ $key ] ) ) {
						$out['products'][ $key ] = array(
							'id'     => $line['product'],
							'name'   => $line['name'],
							'units'  => 0,
							'sales'  => 0.0,
							'orders' => 0,
						);
					}
					$out['products'][ $key ]['units'] += $line['qty'];
					$out['products'][ $key ]['sales'] += $line['total'];
					if ( empty( $counted[ $key ] ) ) {
						++$out['products'][ $key ]['orders'];
						$counted[ $key ] = true;
					}
				}
			}
		);
		$out['customers'] = count( $seen );
		$out['net']       = $out['sales'] - $out['shipping'] - $out['tax'];
		$out['average']   = $out['orders'] ? $out['sales'] / $out['orders'] : 0.0;
		uasort(
			$out['products'],
			static function ( $a, $b ) {
				return $b['sales'] <=> $a['sales'];
			}
		);
		foreach ( array( 'payments', 'fulfilment' ) as $group ) {
			uasort(
				$out[ $group ],
				static function ( $a, $b ) {
					return $b['sales'] <=> $a['sales'];
				}
			);
		}
		if ( $days > 31 ) {
			set_transient( $cache, $out, 5 * MINUTE_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Add one order to a group.
	 *
	 * @param array  $group Group.
	 * @param string $key   Key.
	 * @param float  $sale  Amount.
	 */
	private static function tally( &$group, $key, $sale ) {
		if ( ! isset( $group[ $key ] ) ) {
			$group[ $key ] = array(
				'orders' => 0,
				'sales'  => 0.0,
			);
		}
		++$group[ $key ]['orders'];
		$group[ $key ]['sales'] += $sale;
	}

	/**
	 * Which chart point a moment belongs to.
	 *
	 * @param int[] $edges Boundaries.
	 * @param int   $time  Timestamp.
	 * @return int
	 */
	private static function slot( $edges, $time ) {
		$low  = 0;
		$high = count( $edges ) - 1;
		while ( $high - $low > 1 ) {
			$mid = (int) ( ( $low + $high ) / 2 );
			if ( $time >= $edges[ $mid ] ) {
				$low = $mid;
			} else {
				$high = $mid;
			}
		}
		return $low;
	}

	/**
	 * Name of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		if ( Nexora_Store::active() ) {
			return Nexora_Store_Orders::status_label( $status );
		}
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status;
	}

	/**
	 * Name of a fulfilment type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function fulfilment_label( $type ) {
		$names = array(
			'pickup'  => __( 'Store pickup', 'nexora-commerce-toolkit' ),
			'courier' => __( 'Courier delivery', 'nexora-commerce-toolkit' ),
		);
		return isset( $names[ $type ] ) ? $names[ $type ] : __( 'Not recorded', 'nexora-commerce-toolkit' );
	}

	/**
	 * Format money as plain text, for screens, emails and charts.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		if ( ! Nexora_Store::active() && function_exists( 'wc_get_price_decimals' ) ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			$number = number_format( abs( (float) $amount ), wc_get_price_decimals() );
			return ( (float) $amount < 0 ? '-' : '' ) . $symbol . $number;
		}
		return Nexora_Store::money( $amount );
	}

	/**
	 * Currency symbol for chart axes.
	 *
	 * @return string
	 */
	public static function symbol() {
		if ( ! Nexora_Store::active() && function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}
		return (string) Nexora_Store::get( 'symbol' );
	}

	/* ---------------------------------------------------------------- products */

	/**
	 * Sales by category. A product in two categories counts in both.
	 *
	 * @param array $products Products from summary().
	 * @return array
	 */
	public static function categories( $products ) {
		$ids = array_values( array_filter( wp_list_pluck( $products, 'id' ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out   = array();
		$terms = wp_get_object_terms( $ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$by_id = array();
		foreach ( $products as $row ) {
			$by_id[ $row['id'] ] = $row;
		}
		foreach ( $terms as $term ) {
			if ( ! isset( $by_id[ $term->object_id ] ) ) {
				continue;
			}
			if ( ! isset( $out[ $term->term_id ] ) ) {
				$out[ $term->term_id ] = array(
					'name'  => $term->name,
					'units' => 0,
					'sales' => 0.0,
				);
			}
			$out[ $term->term_id ]['units'] += $by_id[ $term->object_id ]['units'];
			$out[ $term->term_id ]['sales'] += $by_id[ $term->object_id ]['sales'];
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['sales'] <=> $a['sales'];
			}
		);
		return $out;
	}

	/**
	 * Published products with no sale in the period.
	 *
	 * @param array $products Products from summary().
	 * @return array{count:int,items:WP_Post[]}
	 */
	public static function not_selling( $products ) {
		$sold = array_values( array_filter( wp_list_pluck( $products, 'id' ) ) );
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => 15,
			'post__not_in'           => $sold, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- a small catalogue on an admin screen.
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'update_post_term_cache' => false,
		);
		$query = new WP_Query( $args );
		return array(
			'count' => (int) $query->found_posts,
			'items' => $query->posts,
		);
	}

	/**
	 * Low and sold-out stock.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function stock_alerts( $limit = 25 ) {
		global $wpdb;
		$threshold = Nexora_Store::active() ? (int) Nexora_Store::get( 'low_stock' ) : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_parent, q.meta_value stock, st.meta_value status
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock'
				LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = '_stock'
				LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock_status'
				WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish'
				AND ( ( m.meta_value = 'yes' AND q.meta_value + 0 <= %d ) OR st.meta_value = 'outofstock' )
				ORDER BY ( st.meta_value = 'outofstock' ) DESC, q.meta_value + 0 ASC, p.post_title ASC LIMIT %d",
				$threshold,
				$limit
			)
		);
		// phpcs:enable
		return array(
			'threshold' => $threshold,
			'rows'      => is_array( $rows ) ? $rows : array(),
		);
	}

	/**
	 * Orders due for pickup or rider collection on a day.
	 *
	 * @param string $day Y-m-d.
	 * @return int
	 */
	public static function scheduled( $day ) {
		global $wpdb;
		if ( Nexora_Store::active() ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nx_orders WHERE fulfilment_date = %s AND status IN ('processing','nx-ready')", $day ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		return count(
			wc_get_orders(
				array(
					'type'       => 'shop_order',
					'status'     => array( 'processing', 'nx-ready' ),
					'limit'      => -1,
					'return'     => 'ids',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_nexora_fulfilment_date',
							'value' => $day,
						),
					),
				)
			)
		);
	}

	/* ----------------------------------------------------------------- reviews */

	/**
	 * Review figures for a period. Rating figures use approved reviews only.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return array
	 */
	public static function reviews( $start, $end ) {
		$rows = get_comments(
			array(
				'type'      => 'review',
				'post_type' => 'product',
				'status'    => 'all',
				'orderby'   => 'comment_date_gmt',
				'order'     => 'DESC',
				'date_query' => array(
					array(
						'column'    => 'comment_date_gmt',
						'after'     => gmdate( 'Y-m-d H:i:s', $start->getTimestamp() - 1 ),
						'before'    => gmdate( 'Y-m-d H:i:s', $end->getTimestamp() ),
						'inclusive' => false,
					),
				),
			)
		);
		$out  = array(
			'total'    => 0,
			'approved' => 0,
			'pending'  => 0,
			'rated'    => 0,
			'sum'      => 0,
			'average'  => 0.0,
			'stars'    => array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 ),
			'products' => array(),
			'list'     => array(),
		);
		foreach ( $rows as $review ) {
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			$rating = $rating >= 1 && $rating <= 5 ? $rating : 0;
			++$out['total'];
			$approved = '1' === (string) $review->comment_approved;
			if ( $approved ) {
				++$out['approved'];
			} else {
				++$out['pending'];
			}
			$out['list'][] = array(
				'review' => $review,
				'rating' => $rating,
			);
			if ( ! $approved || ! $rating ) {
				continue;
			}
			++$out['rated'];
			$out['sum'] += $rating;
			++$out['stars'][ $rating ];
			$id = (int) $review->comment_post_ID;
			if ( ! isset( $out['products'][ $id ] ) ) {
				$out['products'][ $id ] = array(
					'id'    => $id,
					'count' => 0,
					'sum'   => 0,
				);
			}
			++$out['products'][ $id ]['count'];
			$out['products'][ $id ]['sum'] += $rating;
		}
		$out['average'] = $out['rated'] ? $out['sum'] / $out['rated'] : 0.0;
		return $out;
	}

	/**
	 * Reviews waiting for approval, of any age.
	 *
	 * @return int
	 */
	public static function reviews_waiting() {
		return (int) get_comments(
			array(
				'type'      => 'review',
				'post_type' => 'product',
				'status'    => 'hold',
				'count'     => true,
			)
		);
	}
}
