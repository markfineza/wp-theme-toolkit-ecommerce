<?php
/**
 * Analytics screens: Sales, Products, Reviews and Daily report, with CSV downloads.
 * Staff who only handle orders do not see these, since they show the store's money.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analytics screens.
 */
class Nexora_Toolkit_Analytics {

	const PAGE = 'nexora-analytics';

	/**
	 * Hook up.
	 */
	public static function init() {
		Nexora_Toolkit_Analytics_Report::init();
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_nexora_analytics_export', array( __CLASS__, 'export' ) );
	}

	/**
	 * Who may see the figures.
	 *
	 * @return string
	 */
	public static function cap() {
		return Nexora_Store::active() ? Nexora_Store_Admin::CAP_STORE : 'view_woocommerce_reports';
	}

	/**
	 * Menu entry, under Store or WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			Nexora_Store::active() ? 'nexora-store' : 'woocommerce',
			__( 'Analytics', 'nexora-commerce-toolkit' ),
			Nexora_Store::active() ? __( 'Analytics', 'nexora-commerce-toolkit' ) : __( 'Freshtastic Analytics', 'nexora-commerce-toolkit' ),
			self::cap(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the files on this screen only.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::PAGE ) ) {
			wp_enqueue_style( 'nexora-analytics', NEXORA_TOOLKIT_URL . 'assets/css/analytics.css', array(), NEXORA_TOOLKIT_VERSION );
			wp_enqueue_script( 'nexora-analytics', NEXORA_TOOLKIT_URL . 'assets/js/analytics.js', array(), NEXORA_TOOLKIT_VERSION, true );
		}
	}

	/* ----------------------------------------------------------------- helpers */

	/**
	 * Tabs.
	 *
	 * @return array<string,string>
	 */
	private static function tabs() {
		return array(
			'sales'    => __( 'Sales', 'nexora-commerce-toolkit' ),
			'products' => __( 'Products', 'nexora-commerce-toolkit' ),
			'reviews'  => __( 'Reviews', 'nexora-commerce-toolkit' ),
			'daily'    => __( 'Daily report', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * A query value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function arg( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * The chosen date range.
	 *
	 * @return array
	 */
	private static function current_range() {
		return Nexora_Toolkit_Analytics_Data::range( self::arg( 'range' ), self::arg( 'from' ), self::arg( 'to' ) );
	}

	/**
	 * The chosen report day. Defaults to yesterday, the last complete day.
	 *
	 * @return DateTimeImmutable
	 */
	private static function current_day() {
		$today = current_datetime()->setTime( 0, 0 );
		$day   = Nexora_Toolkit_Analytics_Data::parse_day( self::arg( 'date' ) );
		return $day && $day <= $today ? $day : $today->modify( '-1 day' );
	}

	/**
	 * Screen address.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function url( $args ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Two dates as a short range.
	 *
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End, not included.
	 * @return string
	 */
	private static function range_label( $start, $end ) {
		$first = $start->getTimestamp();
		$last  = $end->getTimestamp() - 1;
		$a     = wp_date( 'M j, Y', $first );
		$b     = wp_date( 'M j, Y', $last );
		return $a === $b ? $a : $a . ' – ' . $b;
	}

	/**
	 * Compare a figure with an earlier one. Arrows and words carry the meaning, not colour.
	 *
	 * @param float  $now    Now.
	 * @param float  $before Before.
	 * @param string $with   What it is compared with.
	 * @return string
	 */
	private static function change( $now, $before, $with ) {
		if ( abs( $now - $before ) < 0.005 ) {
			/* translators: %s: comparison period */
			return sprintf( __( 'No change from %s', 'nexora-commerce-toolkit' ), $with );
		}
		if ( $before <= 0 ) {
			/* translators: %s: comparison period */
			return sprintf( __( '▲ Up from zero (%s)', 'nexora-commerce-toolkit' ), $with );
		}
		$percent = number_format_i18n( abs( $now - $before ) / $before * 100, 1 );
		/* translators: 1: arrow, 2: percentage, 3: comparison period */
		return sprintf( __( '%1$s %2$s%% from %3$s', 'nexora-commerce-toolkit' ), $now > $before ? '▲' : '▼', $percent, $with );
	}

	/**
	 * Row of figures.
	 *
	 * @param array $tiles Each: label, value, note.
	 */
	private static function tiles( $tiles ) {
		echo '<ul class="nx-an-tiles">';
		foreach ( $tiles as $tile ) {
			echo '<li class="nx-an-tile"><span class="nx-an-tile__label">' . esc_html( $tile[0] ) . '</span><strong class="nx-an-tile__value">' . esc_html( $tile[1] ) . '</strong>';
			foreach ( array_slice( $tile, 2 ) as $note ) {
				echo '<span class="nx-an-tile__note">' . esc_html( $note ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Names of the chart points.
	 *
	 * @param int[]  $edges Boundaries.
	 * @param string $unit  Unit.
	 * @return string[]
	 */
	private static function point_labels( $edges, $unit ) {
		$formats = array(
			'hour'  => 'g a',
			'day'   => 'M j',
			'week'  => 'M j',
			'month' => 'M Y',
		);
		$out     = array();
		foreach ( array_slice( $edges, 0, -1 ) as $at ) {
			$out[] = wp_date( $formats[ $unit ], $at );
		}
		return $out;
	}

	/**
	 * Line chart with its legend. The page's tables hold the same figures for anyone not using the chart.
	 *
	 * @param array  $now    Summary.
	 * @param array  $before Earlier summary, or empty.
	 * @param string $unit   Unit.
	 * @param string $title  Chart title.
	 * @param string $current_name  Name of the main line.
	 * @param string $previous_name Name of the comparison line.
	 */
	private static function chart( $now, $before, $unit, $title, $current_name, $previous_name ) {
		$labels  = self::point_labels( $now['edges'], $unit );
		$count   = count( $labels );
		$prior   = $before ? array_slice( $before['series'], 0, $count ) : array();
		$format  = static function ( $values ) {
			return array_map( array( 'Nexora_Toolkit_Analytics_Data', 'money' ), $values );
		};
		$data    = array(
			'labels'       => $labels,
			'prevLabels'   => $before ? array_slice( self::point_labels( $before['edges'], $unit ), 0, $count ) : array(),
			'current'      => array_map( 'floatval', $now['series'] ),
			'previous'     => array_map( 'floatval', $prior ),
			'currentText'  => $format( $now['series'] ),
			'previousText' => $format( $prior ),
			'currentName'  => $current_name,
			'previousName' => $previous_name,
			'symbol'       => Nexora_Toolkit_Analytics_Data::symbol(),
		);
		?>
		<section class="nx-an-card nx-an-card--chart">
			<div class="nx-an-card__head">
				<h2><?php echo esc_html( $title ); ?></h2>
				<ul class="nx-an-legend">
					<li><span class="nx-an-key"></span><?php echo esc_html( $current_name ); ?></li>
					<?php if ( $before ) : ?>
						<li><span class="nx-an-key is-previous"></span><?php echo esc_html( $previous_name ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
			<div class="nx-an-chart" data-nx-chart="<?php echo esc_attr( wp_json_encode( $data ) ); ?>" role="img" tabindex="0" aria-label="<?php /* translators: %s: chart title */ echo esc_attr( sprintf( __( '%s. Use the left and right arrow keys to read each point. The same figures are in the table on this page.', 'nexora-commerce-toolkit' ), $title ) ); ?>"></div>
			<noscript><p><?php esc_html_e( 'The chart needs JavaScript. The figures are in the table below.', 'nexora-commerce-toolkit' ); ?></p></noscript>
		</section>
		<?php
	}

	/**
	 * Table of named amounts with a bar for each.
	 *
	 * @param string $title Title.
	 * @param array  $rows  Each: label, orders, sales.
	 * @param string $first Heading of the first column.
	 */
	private static function split_table( $title, $rows, $first ) {
		$top = 0.0;
		foreach ( $rows as $row ) {
			$top = max( $top, (float) $row[2] );
		}
		?>
		<section class="nx-an-card">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p class="nx-an-empty"><?php esc_html_e( 'No sales in this period.', 'nexora-commerce-toolkit' ); ?></p>
			<?php else : ?>
				<div class="nx-an-scroll" tabindex="0"><table class="nx-an-table">
					<thead><tr><th scope="col"><?php echo esc_html( $first ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Orders', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sales', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row[0] ); ?><span class="nx-an-meter" aria-hidden="true"><i style="width:<?php echo esc_attr( $top > 0 ? round( max( 0, (float) $row[2] ) / $top * 100, 1 ) : 0 ); ?>%"></i></span></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row[1] ) ); ?></td>
							<td class="num"><?php echo esc_html( Nexora_Toolkit_Analytics_Data::money( $row[2] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Payment and fulfilment rows.
	 *
	 * @param array $summary Summary.
	 * @return array{0:array,1:array}
	 */
	private static function split_rows( $summary ) {
		$payments = array();
		foreach ( $summary['payments'] as $name => $group ) {
			$payments[] = array( $name, $group['orders'], $group['sales'] );
		}
		$types = array();
		foreach ( $summary['fulfilment'] as $type => $group ) {
			$types[] = array( Nexora_Toolkit_Analytics_Data::fulfilment_label( $type ), $group['orders'], $group['sales'] );
		}
		return array( $payments, $types );
	}

	/**
	 * Link to a product's editor, when allowed.
	 *
	 * @param int    $id   Product.
	 * @param string $name Name.
	 * @return string Markup.
	 */
	private static function product_link( $id, $name ) {
		$url = $id && current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id ) : '';
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
	}

	/* ------------------------------------------------------------------ screen */

	/**
	 * The screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		$tabs = self::tabs();
		$tab  = self::arg( 'tab' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'sales';
		?>
		<div class="wrap nx-an">
			<h1><?php esc_html_e( 'Analytics', 'nexora-commerce-toolkit' ); ?></h1>
			<nav class="nx-an-tabs" aria-label="<?php esc_attr_e( 'Analytics sections', 'nexora-commerce-toolkit' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $key ) ); ?>"<?php echo $key === $tab ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'daily' === $tab ) {
				self::tab_daily();
			} else {
				$range = self::current_range();
				self::filters( $tab, $range );
				if ( 'products' === $tab ) {
					self::tab_products( $range );
				} elseif ( 'reviews' === $tab ) {
					self::tab_reviews( $range );
				} else {
					self::tab_sales( $range );
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Tab address that keeps the chosen dates.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_url( $tab ) {
		$args = array( 'tab' => $tab );
		foreach ( array( 'range', 'from', 'to' ) as $key ) {
			if ( '' !== self::arg( $key ) && 'daily' !== $tab ) {
				$args[ $key ] = self::arg( $key );
			}
		}
		return self::url( $args );
	}

	/**
	 * Date filter, download and print.
	 *
	 * @param string $tab   Tab.
	 * @param array  $range Range.
	 */
	private static function filters( $tab, $range ) {
		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'nexora_analytics_export',
					'tab'    => $tab,
					'range'  => $range['preset'],
					'from'   => $range['from'],
					'to'     => $range['to'],
				),
				admin_url( 'admin-post.php' )
			),
			'nexora_analytics_export'
		);
		?>
		<form class="nx-an-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<label><span><?php esc_html_e( 'Period', 'nexora-commerce-toolkit' ); ?></span>
				<select name="range" data-nx-range>
					<?php foreach ( Nexora_Toolkit_Analytics_Data::presets() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $range['preset'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<span class="nx-an-custom" data-nx-custom>
				<label><span><?php esc_html_e( 'From', 'nexora-commerce-toolkit' ); ?></span> <input type="date" name="from" value="<?php echo esc_attr( $range['from'] ); ?>"></label>
				<label><span><?php esc_html_e( 'To', 'nexora-commerce-toolkit' ); ?></span> <input type="date" name="to" value="<?php echo esc_attr( $range['to'] ); ?>"></label>
			</span>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'nexora-commerce-toolkit' ); ?></button>
			<span class="nx-an-filters__end">
				<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Download CSV', 'nexora-commerce-toolkit' ); ?></a>
				<button type="button" class="button" data-nx-print><?php esc_html_e( 'Print', 'nexora-commerce-toolkit' ); ?></button>
			</span>
		</form>
		<p class="nx-an-period">
			<strong><?php echo esc_html( self::range_label( $range['start'], $range['end'] ) ); ?></strong>
			<?php /* translators: %s: earlier date range */ ?>
			<span><?php echo esc_html( sprintf( __( 'compared with %s', 'nexora-commerce-toolkit' ), self::range_label( $range['prev_start'], $range['prev_end'] ) ) ); ?></span>
		</p>
		<?php
	}

	/**
	 * Sales tab.
	 *
	 * @param array $range Range.
	 */
	private static function tab_sales( $range ) {
		$m       = array( 'Nexora_Toolkit_Analytics_Data', 'money' );
		$now     = Nexora_Toolkit_Analytics_Data::summary( $range['start'], $range['end'], $range['unit'] );
		$before  = Nexora_Toolkit_Analytics_Data::summary( $range['prev_start'], $range['prev_end'], $range['unit'] );
		$with    = __( 'previous period', 'nexora-commerce-toolkit' );
		self::tiles(
			array(
				array( __( 'Total sales', 'nexora-commerce-toolkit' ), $m( $now['sales'] ), self::change( $now['sales'], $before['sales'], $with ) ),
				array( __( 'Net sales', 'nexora-commerce-toolkit' ), $m( $now['net'] ), self::change( $now['net'], $before['net'], $with ) ),
				array( __( 'Orders', 'nexora-commerce-toolkit' ), number_format_i18n( $now['orders'] ), self::change( $now['orders'], $before['orders'], $with ) ),
				array( __( 'Average order', 'nexora-commerce-toolkit' ), $m( $now['average'] ), self::change( $now['average'], $before['average'], $with ) ),
				array( __( 'Items sold', 'nexora-commerce-toolkit' ), number_format_i18n( $now['items'] ), self::change( $now['items'], $before['items'], $with ) ),
				array( __( 'Customers', 'nexora-commerce-toolkit' ), number_format_i18n( $now['customers'] ), self::change( $now['customers'], $before['customers'], $with ) ),
				array( __( 'Discounts', 'nexora-commerce-toolkit' ), $m( $now['discount'] ), self::change( $now['discount'], $before['discount'], $with ) ),
				array( __( 'Refunds', 'nexora-commerce-toolkit' ), $m( $now['refunds'] ), self::change( $now['refunds'], $before['refunds'], $with ) ),
			)
		);
		if ( $now['unpaid'] ) {
			/* translators: 1: number of orders, 2: amount */
			echo '<p class="nx-an-note">' . esc_html( sprintf( _n( '%1$s order worth %2$s is pending payment and is not counted above.', '%1$s orders worth %2$s are pending payment and are not counted above.', $now['unpaid'], 'nexora-commerce-toolkit' ), number_format_i18n( $now['unpaid'] ), $m( $now['unpaid_sum'] ) ) ) . '</p>';
		}
		self::chart( $now, $before, $range['unit'], __( 'Total sales over time', 'nexora-commerce-toolkit' ), __( 'This period', 'nexora-commerce-toolkit' ), __( 'Previous period', 'nexora-commerce-toolkit' ) );
		list( $payments, $types ) = self::split_rows( $now );
		$statuses                 = array();
		foreach ( $now['statuses'] as $status => $count ) {
			$statuses[] = array( Nexora_Toolkit_Analytics_Data::status_label( $status ), $count );
		}
		$labels = self::point_labels( $now['edges'], $range['unit'] );
		?>
		<div class="nx-an-grid">
			<?php
			self::split_table( __( 'By payment method', 'nexora-commerce-toolkit' ), $payments, __( 'Payment', 'nexora-commerce-toolkit' ) );
			self::split_table( __( 'Pickup and delivery', 'nexora-commerce-toolkit' ), $types, __( 'Method', 'nexora-commerce-toolkit' ) );
			?>
		</div>
		<div class="nx-an-grid nx-an-grid--wide">
			<section class="nx-an-card">
				<h2><?php esc_html_e( 'Sales by period', 'nexora-commerce-toolkit' ); ?></h2>
				<div class="nx-an-scroll nx-an-scroll--tall" tabindex="0"><table class="nx-an-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Period', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Orders', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Total sales', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $labels as $i => $label ) : ?>
						<tr><td><?php echo esc_html( $label ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $now['counts'][ $i ] ) ); ?></td><td class="num"><?php echo esc_html( $m( $now['series'][ $i ] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</section>
			<section class="nx-an-card">
				<h2><?php esc_html_e( 'All orders by status', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( $statuses ) : ?>
					<table class="nx-an-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Orders', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $statuses as $row ) : ?>
							<tr><td><?php echo esc_html( $row[0] ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row[1] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="nx-an-empty"><?php esc_html_e( 'No orders in this period.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<details class="nx-an-help">
			<summary><?php esc_html_e( 'How these figures work', 'nexora-commerce-toolkit' ); ?></summary>
			<ul>
				<li><?php esc_html_e( 'Sales count every order except pending payment, cancelled, failed and refunded. Cash on delivery and bank transfer orders count before the money arrives.', 'nexora-commerce-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Total sales include delivery fees and tax. Net sales leave them out. Both are after discounts and refunds.', 'nexora-commerce-toolkit' ); ?></li>
				<li><?php esc_html_e( 'Orders and refunds count on the date the order was placed, in your site\'s timezone.', 'nexora-commerce-toolkit' ); ?></li>
			</ul>
		</details>
		<?php
	}

	/**
	 * Products tab.
	 *
	 * @param array $range Range.
	 */
	private static function tab_products( $range ) {
		$m      = array( 'Nexora_Toolkit_Analytics_Data', 'money' );
		$now    = Nexora_Toolkit_Analytics_Data::summary( $range['start'], $range['end'], $range['unit'] );
		$before = Nexora_Toolkit_Analytics_Data::summary( $range['prev_start'], $range['prev_end'], $range['unit'] );
		$with   = __( 'previous period', 'nexora-commerce-toolkit' );
		$sort   = in_array( self::arg( 'sort' ), array( 'units', 'orders' ), true ) ? self::arg( 'sort' ) : 'sales';
		$rows   = array_values( $now['products'] );
		usort(
			$rows,
			static function ( $a, $b ) use ( $sort ) {
				return $b[ $sort ] <=> $a[ $sort ];
			}
		);
		$top   = $now['products'] ? reset( $now['products'] ) : null;
		$idle  = Nexora_Toolkit_Analytics_Data::not_selling( $now['products'] );
		$stock = Nexora_Toolkit_Analytics_Data::stock_alerts();
		self::tiles(
			array(
				array( __( 'Items sold', 'nexora-commerce-toolkit' ), number_format_i18n( $now['items'] ), self::change( $now['items'], $before['items'], $with ) ),
				array( __( 'Different products sold', 'nexora-commerce-toolkit' ), number_format_i18n( count( $now['products'] ) ), self::change( count( $now['products'] ), count( $before['products'] ), $with ) ),
				array( __( 'Top seller', 'nexora-commerce-toolkit' ), $top ? $top['name'] : __( 'None yet', 'nexora-commerce-toolkit' ), $top ? $m( $top['sales'] ) : '' ),
				array( __( 'Products with no sales', 'nexora-commerce-toolkit' ), number_format_i18n( $idle['count'] ), __( 'Published, none sold in this period', 'nexora-commerce-toolkit' ) ),
			)
		);
		$sort_link = static function ( $key, $label ) use ( $sort, $range ) {
			$args = array(
				'tab'   => 'products',
				'range' => $range['preset'],
				'from'  => $range['from'],
				'to'    => $range['to'],
				'sort'  => $key,
			);
			$mark = $sort === $key ? ' ▼' : '';
			return '<a href="' . esc_url( self::url( $args ) ) . '"' . ( $sort === $key ? ' aria-current="true"' : '' ) . '>' . esc_html( $label . $mark ) . '</a>';
		};
		$top_sales = $rows ? max( array_column( $rows, 'sales' ) ) : 0;
		$links     = array(
			'sales'  => $sort_link( 'sales', __( 'Sales', 'nexora-commerce-toolkit' ) ),
			'units'  => $sort_link( 'units', __( 'Sold', 'nexora-commerce-toolkit' ) ),
			'orders' => $sort_link( 'orders', __( 'Orders', 'nexora-commerce-toolkit' ) ),
		);
		?>
		<section class="nx-an-card">
			<h2><?php esc_html_e( 'Products sold', 'nexora-commerce-toolkit' ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p class="nx-an-empty"><?php esc_html_e( 'No sales in this period.', 'nexora-commerce-toolkit' ); ?></p>
			<?php else : ?>
				<div class="nx-an-scroll" tabindex="0"><table class="nx-an-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Product', 'nexora-commerce-toolkit' ); ?></th>
						<th scope="col" class="num"><?php echo $links['units']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></th>
						<th scope="col" class="num"><?php echo $links['orders']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></th>
						<th scope="col" class="num"><?php echo $links['sales']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Rating', 'nexora-commerce-toolkit' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'In stock', 'nexora-commerce-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( array_slice( $rows, 0, 50 ) as $row ) :
						$rating = $row['id'] ? (float) get_post_meta( $row['id'], '_wc_average_rating', true ) : 0;
						$qty    = $row['id'] && 'yes' === get_post_meta( $row['id'], '_manage_stock', true ) ? number_format_i18n( (int) get_post_meta( $row['id'], '_stock', true ) ) : '–';
						?>
						<tr>
							<td><?php echo self::product_link( $row['id'], $row['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?><span class="nx-an-meter" aria-hidden="true"><i style="width:<?php echo esc_attr( $top_sales > 0 ? round( $row['sales'] / $top_sales * 100, 1 ) : 0 ); ?>%"></i></span></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['units'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $m( $row['sales'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $rating ? number_format_i18n( $rating, 1 ) . ' ★' : '–' ); ?></td>
							<td class="num"><?php echo esc_html( $qty ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<p class="nx-an-note"><?php esc_html_e( 'Product sales are before order-wide discounts, and packages count as one product.', 'nexora-commerce-toolkit' ); ?></p>
			<?php endif; ?>
		</section>
		<div class="nx-an-grid">
			<section class="nx-an-card">
				<h2><?php esc_html_e( 'By category', 'nexora-commerce-toolkit' ); ?></h2>
				<?php
				$cats = Nexora_Toolkit_Analytics_Data::categories( $now['products'] );
				if ( $cats ) :
					$cat_top = max( array_column( $cats, 'sales' ) );
					?>
					<table class="nx-an-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Category', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sold', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sales', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $cats, 0, 15 ) as $cat ) : ?>
							<tr><td><?php echo esc_html( $cat['name'] ); ?><span class="nx-an-meter" aria-hidden="true"><i style="width:<?php echo esc_attr( $cat_top > 0 ? round( $cat['sales'] / $cat_top * 100, 1 ) : 0 ); ?>%"></i></span></td><td class="num"><?php echo esc_html( number_format_i18n( $cat['units'] ) ); ?></td><td class="num"><?php echo esc_html( $m( $cat['sales'] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="nx-an-note"><?php esc_html_e( 'A product in two categories counts in both.', 'nexora-commerce-toolkit' ); ?></p>
				<?php else : ?>
					<p class="nx-an-empty"><?php esc_html_e( 'No sales in this period.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
			<?php self::stock_card( $stock ); ?>
		</div>
		<section class="nx-an-card">
			<h2><?php esc_html_e( 'Products with no sales', 'nexora-commerce-toolkit' ); ?></h2>
			<?php if ( $idle['items'] ) : ?>
				<ul class="nx-an-list">
					<?php foreach ( $idle['items'] as $post ) : ?>
						<li><?php echo self::product_link( $post->ID, $post->post_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $idle['count'] > count( $idle['items'] ) ) : ?>
					<?php /* translators: 1: shown, 2: total */ ?>
					<p class="nx-an-note"><?php echo esc_html( sprintf( __( 'Showing %1$s of %2$s, A to Z.', 'nexora-commerce-toolkit' ), number_format_i18n( count( $idle['items'] ) ), number_format_i18n( $idle['count'] ) ) ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p class="nx-an-empty"><?php esc_html_e( 'Every published product sold at least once in this period.', 'nexora-commerce-toolkit' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Low stock card.
	 *
	 * @param array $stock From stock_alerts().
	 */
	private static function stock_card( $stock ) {
		?>
		<section class="nx-an-card">
			<h2><?php esc_html_e( 'Low and sold-out stock', 'nexora-commerce-toolkit' ); ?></h2>
			<?php if ( $stock['rows'] ) : ?>
				<table class="nx-an-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Product', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'In stock', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $stock['rows'] as $line ) : ?>
						<tr>
							<td><?php echo self::product_link( $line->post_parent ? (int) $line->post_parent : (int) $line->ID, $line->post_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?></td>
							<td class="num"><?php echo esc_html( 'outofstock' === $line->status ? __( 'Sold out', 'nexora-commerce-toolkit' ) : number_format_i18n( (int) $line->stock ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php /* translators: %s: stock level */ ?>
				<p class="nx-an-note"><?php echo esc_html( sprintf( __( 'Low means %s or fewer left. This is today\'s stock, whatever the dates above.', 'nexora-commerce-toolkit' ), number_format_i18n( $stock['threshold'] ) ) ); ?></p>
			<?php else : ?>
				<p class="nx-an-empty"><?php esc_html_e( 'Nothing is low or sold out.', 'nexora-commerce-toolkit' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Reviews tab.
	 *
	 * @param array $range Range.
	 */
	private static function tab_reviews( $range ) {
		$now     = Nexora_Toolkit_Analytics_Data::reviews( $range['start'], $range['end'] );
		$before  = Nexora_Toolkit_Analytics_Data::reviews( $range['prev_start'], $range['prev_end'] );
		$waiting = Nexora_Toolkit_Analytics_Data::reviews_waiting();
		$with    = __( 'previous period', 'nexora-commerce-toolkit' );
		$low     = $now['stars'][1] + $now['stars'][2];
		self::tiles(
			array(
				array( __( 'New reviews', 'nexora-commerce-toolkit' ), number_format_i18n( $now['total'] ), self::change( $now['total'], $before['total'], $with ) ),
				array( __( 'Average rating', 'nexora-commerce-toolkit' ), $now['rated'] ? number_format_i18n( $now['average'], 2 ) . ' ★' : '–', $before['rated'] ? self::change( $now['average'], $before['average'], $with ) : __( 'Approved reviews only', 'nexora-commerce-toolkit' ) ),
				array( __( 'Waiting for approval', 'nexora-commerce-toolkit' ), number_format_i18n( $waiting ), __( 'All dates', 'nexora-commerce-toolkit' ) ),
				array( __( 'Rated 1 or 2 stars', 'nexora-commerce-toolkit' ), number_format_i18n( $low ), __( 'Worth a closer look', 'nexora-commerce-toolkit' ) ),
			)
		);
		$products = array_values( $now['products'] );
		foreach ( $products as &$row ) {
			$row['avg']  = $row['sum'] / $row['count'];
			$row['name'] = get_the_title( $row['id'] );
		}
		unset( $row );
		usort(
			$products,
			static function ( $a, $b ) {
				return $b['avg'] <=> $a['avg'] ?: $b['count'] <=> $a['count'];
			}
		);
		$show = in_array( self::arg( 'show' ), array( 'waiting', 'low' ), true ) ? self::arg( 'show' ) : 'all';
		$list = array_filter(
			$now['list'],
			static function ( $entry ) use ( $show ) {
				if ( 'waiting' === $show ) {
					return '0' === (string) $entry['review']->comment_approved;
				}
				return 'low' !== $show || ( $entry['rating'] && $entry['rating'] <= 2 );
			}
		);
		$filters = array(
			'all'     => __( 'All', 'nexora-commerce-toolkit' ),
			'waiting' => __( 'Waiting for approval', 'nexora-commerce-toolkit' ),
			'low'     => __( '1 or 2 stars', 'nexora-commerce-toolkit' ),
		);
		?>
		<div class="nx-an-grid">
			<section class="nx-an-card">
				<h2><?php esc_html_e( 'Ratings', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( $now['rated'] ) : ?>
					<table class="nx-an-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Stars', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Reviews', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $now['stars'] as $star => $count ) : ?>
							<tr><td><?php echo esc_html( $star . ' ★' ); ?><span class="nx-an-meter" aria-hidden="true"><i style="width:<?php echo esc_attr( round( $count / max( $now['stars'] ) * 100, 1 ) ); ?>%"></i></span></td><td class="num"><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="nx-an-empty"><?php esc_html_e( 'No approved ratings in this period.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
			<section class="nx-an-card">
				<h2><?php esc_html_e( 'Products by rating', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( $products ) : ?>
					<div class="nx-an-scroll nx-an-scroll--tall" tabindex="0"><table class="nx-an-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Product', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Reviews', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Average', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $products as $row ) : ?>
							<tr><td><?php echo self::product_link( $row['id'], $row['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['count'] ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['avg'], 1 ) . ' ★' ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php else : ?>
					<p class="nx-an-empty"><?php esc_html_e( 'No approved ratings in this period.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<section class="nx-an-card">
			<div class="nx-an-card__head">
				<h2><?php esc_html_e( 'Latest reviews', 'nexora-commerce-toolkit' ); ?></h2>
				<ul class="nx-an-pills">
					<?php
					foreach ( $filters as $key => $label ) :
						$args = array(
							'tab'   => 'reviews',
							'range' => $range['preset'],
							'from'  => $range['from'],
							'to'    => $range['to'],
							'show'  => $key,
						);
						?>
						<li><a href="<?php echo esc_url( self::url( $args ) ); ?>"<?php echo $key === $show ? ' class="is-current" aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php if ( ! $list ) : ?>
				<p class="nx-an-empty"><?php esc_html_e( 'No reviews to show.', 'nexora-commerce-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="nx-an-reviews">
					<?php
					foreach ( array_slice( $list, 0, 50 ) as $entry ) :
						$review   = $entry['review'];
						$id       = (int) $review->comment_ID;
						$pending  = '0' === (string) $review->comment_approved;
						$can_edit = current_user_can( 'edit_comment', $id );
						?>
						<li class="nx-an-review">
							<p class="nx-an-review__head">
								<strong><?php echo esc_html( $entry['rating'] ? $entry['rating'] . ' ★' : __( 'No rating', 'nexora-commerce-toolkit' ) ); ?></strong>
								<?php echo self::product_link( (int) $review->comment_post_ID, get_the_title( (int) $review->comment_post_ID ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?>
								<?php if ( $pending ) : ?><span class="nx-an-tag"><?php esc_html_e( 'Waiting for approval', 'nexora-commerce-toolkit' ); ?></span><?php endif; ?>
							</p>
							<p class="nx-an-review__text"><?php echo esc_html( wp_trim_words( $review->comment_content, 45 ) ); ?></p>
							<p class="nx-an-review__meta">
								<?php echo esc_html( $review->comment_author . ' · ' . wp_date( 'M j, Y g:i a', (int) strtotime( $review->comment_date_gmt . ' UTC' ) ) ); ?>
								<?php if ( $can_edit && $pending ) : ?>
									· <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'comment.php?action=approvecomment&c=' . $id ), 'approve-comment_' . $id ) ); ?>"><?php esc_html_e( 'Approve', 'nexora-commerce-toolkit' ); ?></a>
								<?php endif; ?>
								<?php if ( $can_edit ) : ?>
									· <a href="<?php echo esc_url( admin_url( 'comment.php?action=editcomment&c=' . $id ) ); ?>"><?php esc_html_e( 'Edit or reply', 'nexora-commerce-toolkit' ); ?></a>
								<?php endif; ?>
							</p>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Daily report tab.
	 */
	private static function tab_daily() {
		$m      = array( 'Nexora_Toolkit_Analytics_Data', 'money' );
		$day    = self::current_day();
		$today  = current_datetime()->setTime( 0, 0 );
		$report = Nexora_Toolkit_Analytics_Report::build( $day );
		$s      = $report['summary'];
		$b      = $report['before'];
		$w      = $report['last_week'];
		$cfg    = Nexora_Toolkit_Analytics_Report::settings();
		$msg    = self::arg( 'nx_msg' );
		$yest   = __( 'the day before', 'nexora-commerce-toolkit' );
		$week   = __( 'same day last week', 'nexora-commerce-toolkit' );
		$both   = static function ( $key ) use ( $s, $b, $w, $yest, $week ) {
			return array( self::change( $s[ $key ], $b[ $key ], $yest ), self::change( $s[ $key ], $w[ $key ], $week ) );
		};
		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'nexora_analytics_export',
					'tab'    => 'daily',
					'date'   => $day->format( 'Y-m-d' ),
				),
				admin_url( 'admin-post.php' )
			),
			'nexora_analytics_export'
		);
		$notices = array(
			'saved'       => array( 'success', __( 'Daily report settings saved.', 'nexora-commerce-toolkit' ) ),
			'sent'        => array( 'success', __( 'Report sent.', 'nexora-commerce-toolkit' ) ),
			'failed'      => array( 'error', $cfg['last_error'] ? $cfg['last_error'] : __( 'The report could not be sent.', 'nexora-commerce-toolkit' ) ),
			'norecipient' => array( 'error', __( 'Add at least one valid email address to switch on the daily email.', 'nexora-commerce-toolkit' ) ),
		);
		if ( isset( $notices[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $notices[ $msg ][0] ) . ' inline"><p>' . esc_html( $notices[ $msg ][1] ) . '</p></div>';
		}
		$day_url = static function ( $date ) {
			return self::url(
				array(
					'tab'  => 'daily',
					'date' => $date->format( 'Y-m-d' ),
				)
			);
		};
		?>
		<form class="nx-an-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="daily">
			<a class="button" href="<?php echo esc_url( $day_url( $day->modify( '-1 day' ) ) ); ?>"><?php esc_html_e( 'Previous day', 'nexora-commerce-toolkit' ); ?></a>
			<label><span class="screen-reader-text"><?php esc_html_e( 'Report date', 'nexora-commerce-toolkit' ); ?></span><input type="date" name="date" value="<?php echo esc_attr( $day->format( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( $today->format( 'Y-m-d' ) ); ?>"></label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Show', 'nexora-commerce-toolkit' ); ?></button>
			<?php if ( $day < $today ) : ?>
				<a class="button" href="<?php echo esc_url( $day_url( $day->modify( '+1 day' ) ) ); ?>"><?php esc_html_e( 'Next day', 'nexora-commerce-toolkit' ); ?></a>
			<?php endif; ?>
			<span class="nx-an-filters__end">
				<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Download CSV', 'nexora-commerce-toolkit' ); ?></a>
				<button type="button" class="button" data-nx-print><?php esc_html_e( 'Print', 'nexora-commerce-toolkit' ); ?></button>
			</span>
		</form>
		<p class="nx-an-period">
			<strong><?php echo esc_html( wp_date( 'l, ' . get_option( 'date_format' ), $day->getTimestamp() ) ); ?></strong>
			<?php if ( $day->format( 'Y-m-d' ) === $today->format( 'Y-m-d' ) ) : ?>
				<span><?php esc_html_e( 'so far today', 'nexora-commerce-toolkit' ); ?></span>
			<?php endif; ?>
		</p>
		<?php
		self::tiles(
			array(
				array_merge( array( __( 'Total sales', 'nexora-commerce-toolkit' ), $m( $s['sales'] ) ), $both( 'sales' ) ),
				array_merge( array( __( 'Orders', 'nexora-commerce-toolkit' ), number_format_i18n( $s['orders'] ) ), $both( 'orders' ) ),
				array_merge( array( __( 'Average order', 'nexora-commerce-toolkit' ), $m( $s['average'] ) ), $both( 'average' ) ),
				array_merge( array( __( 'Items sold', 'nexora-commerce-toolkit' ), number_format_i18n( $s['items'] ) ), $both( 'items' ) ),
				array( __( 'Discounts', 'nexora-commerce-toolkit' ), $m( $s['discount'] ) ),
				array( __( 'Refunds', 'nexora-commerce-toolkit' ), $m( $s['refunds'] ) ),
				array( __( 'Cancelled orders', 'nexora-commerce-toolkit' ), number_format_i18n( $s['cancelled'] ) ),
				array( __( 'New reviews', 'nexora-commerce-toolkit' ), number_format_i18n( $report['reviews']['total'] ), sprintf( /* translators: %s: reviews */ __( '%s waiting for approval', 'nexora-commerce-toolkit' ), number_format_i18n( $report['waiting'] ) ) ),
			)
		);
		if ( null !== $report['scheduled'] ) {
			/* translators: 1: number of orders, 2: date */
			echo '<p class="nx-an-note">' . esc_html( sprintf( _n( '%1$s order is scheduled for pickup or rider collection on %2$s.', '%1$s orders are scheduled for pickup or rider collection on %2$s.', $report['scheduled'], 'nexora-commerce-toolkit' ), number_format_i18n( $report['scheduled'] ), wp_date( get_option( 'date_format' ), $day->modify( '+1 day' )->getTimestamp() ) ) ) . '</p>';
		}
		self::chart( $s, $b, 'hour', __( 'Sales by hour', 'nexora-commerce-toolkit' ), __( 'This day', 'nexora-commerce-toolkit' ), __( 'The day before', 'nexora-commerce-toolkit' ) );
		list( $payments, $types ) = self::split_rows( $s );
		?>
		<div class="nx-an-grid">
			<section class="nx-an-card">
				<h2><?php esc_html_e( 'Top products', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( $s['products'] ) : ?>
					<table class="nx-an-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Product', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sold', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Sales', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $s['products'], 0, 10 ) as $row ) : ?>
							<tr><td><?php echo self::product_link( $row['id'], $row['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the method. ?></td><td class="num"><?php echo esc_html( number_format_i18n( $row['units'] ) ); ?></td><td class="num"><?php echo esc_html( $m( $row['sales'] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="nx-an-empty"><?php esc_html_e( 'No sales on this day.', 'nexora-commerce-toolkit' ); ?></p>
				<?php endif; ?>
			</section>
			<?php self::stock_card( $report['stock'] ); ?>
			<?php self::split_table( __( 'By payment method', 'nexora-commerce-toolkit' ), $payments, __( 'Payment', 'nexora-commerce-toolkit' ) ); ?>
			<?php self::split_table( __( 'Pickup and delivery', 'nexora-commerce-toolkit' ), $types, __( 'Method', 'nexora-commerce-toolkit' ) ); ?>
		</div>
		<?php
		self::recent_days( $today, $day_url );
		self::email_settings( $cfg, $day );
	}

	/**
	 * The last two weeks, each linking to its report.
	 *
	 * @param DateTimeImmutable $today   Today.
	 * @param callable          $day_url Address of a day.
	 */
	private static function recent_days( $today, $day_url ) {
		$start = $today->modify( '-13 days' );
		$now   = Nexora_Toolkit_Analytics_Data::summary( $start, $today->modify( '+1 day' ), 'day' );
		?>
		<section class="nx-an-card nx-an-noprint">
			<h2><?php esc_html_e( 'Last 14 days', 'nexora-commerce-toolkit' ); ?></h2>
			<div class="nx-an-scroll nx-an-scroll--tall" tabindex="0"><table class="nx-an-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Day', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Orders', 'nexora-commerce-toolkit' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Total sales', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( array_reverse( array_slice( $now['edges'], 0, -1, true ), true ) as $i => $at ) :
					$date = $start->setTimestamp( $at );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $day_url( $date ) ); ?>"><?php echo esc_html( wp_date( 'D, M j', $at ) ); ?></a></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $now['counts'][ $i ] ) ); ?></td>
						<td class="num"><?php echo esc_html( Nexora_Toolkit_Analytics_Data::money( $now['series'][ $i ] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
		<?php
	}

	/**
	 * Email settings.
	 *
	 * @param array             $cfg Settings.
	 * @param DateTimeImmutable $day Day on screen.
	 */
	private static function email_settings( $cfg, $day ) {
		$next = wp_next_scheduled( Nexora_Toolkit_Analytics_Report::HOOK );
		?>
		<section class="nx-an-card nx-an-noprint">
			<h2><?php esc_html_e( 'Email the report every day', 'nexora-commerce-toolkit' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nx-an-form">
				<?php wp_nonce_field( 'nexora_report_settings' ); ?>
				<input type="hidden" name="action" value="nexora_report_settings">
				<label class="nx-an-check"><input type="checkbox" name="enabled" value="1" <?php checked( $cfg['enabled'] ); ?>> <?php esc_html_e( 'Send yesterday\'s report by email each morning', 'nexora-commerce-toolkit' ); ?></label>
				<label><span><?php esc_html_e( 'Send to', 'nexora-commerce-toolkit' ); ?></span><input type="text" class="regular-text" name="recipients" value="<?php echo esc_attr( $cfg['recipients'] ); ?>" aria-describedby="nx-an-to-help"></label>
				<p class="nx-an-note" id="nx-an-to-help"><?php esc_html_e( 'Up to 10 addresses, separated by commas. The email has totals and product names, with no customer details.', 'nexora-commerce-toolkit' ); ?></p>
				<label><span><?php esc_html_e( 'Send at', 'nexora-commerce-toolkit' ); ?></span>
					<select name="hour">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<option value="<?php echo esc_attr( $h ); ?>" <?php selected( (int) $cfg['hour'], $h ); ?>><?php echo esc_html( wp_date( 'g:00 a', current_datetime()->setTime( $h, 0 )->getTimestamp() ) ); ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'nexora-commerce-toolkit' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nx-an-form">
				<?php wp_nonce_field( 'nexora_report_send' ); ?>
				<input type="hidden" name="action" value="nexora_report_send">
				<input type="hidden" name="date" value="<?php echo esc_attr( $day->format( 'Y-m-d' ) ); ?>">
				<?php /* translators: %s: date */ ?>
				<p><button type="submit" class="button"><?php echo esc_html( sprintf( __( 'Email the report for %s now', 'nexora-commerce-toolkit' ), wp_date( 'M j', $day->getTimestamp() ) ) ); ?></button></p>
			</form>
			<ul class="nx-an-note nx-an-status">
				<?php if ( $cfg['last_at'] ) : ?>
					<?php /* translators: %s: date and time */ ?>
					<li><?php echo esc_html( sprintf( __( 'Last sent: %s', 'nexora-commerce-toolkit' ), wp_date( 'M j, Y g:i a', (int) $cfg['last_at'] ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( $cfg['enabled'] && $next ) : ?>
					<?php /* translators: %s: date and time */ ?>
					<li><?php echo esc_html( sprintf( __( 'Next send: %s', 'nexora-commerce-toolkit' ), wp_date( 'M j, Y g:i a', $next ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( $cfg['last_error'] ) : ?>
					<?php /* translators: %s: error message */ ?>
					<li><strong><?php echo esc_html( sprintf( __( 'Last problem: %s', 'nexora-commerce-toolkit' ), $cfg['last_error'] ) ); ?></strong></li>
				<?php endif; ?>
				<li><?php esc_html_e( 'WordPress sends this on the first visit after the set time, so a quiet site can send it late.', 'nexora-commerce-toolkit' ); ?></li>
			</ul>
		</section>
		<?php
	}

	/* --------------------------------------------------------------------- csv */

	/**
	 * Download the figures on a tab. Cells starting with a formula character are quoted so spreadsheets do not run them.
	 */
	public static function export() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_analytics_export' );
		$tab = self::arg( 'tab' );
		if ( 'daily' === $tab ) {
			$day  = self::current_day();
			$name = 'daily-report-' . $day->format( 'Y-m-d' );
			$rows = self::csv_daily( $day );
		} else {
			$range = self::current_range();
			$name  = ( 'products' === $tab || 'reviews' === $tab ? $tab : 'sales' ) . '-' . $range['from'] . '-to-' . $range['to'];
			$rows  = 'products' === $tab ? self::csv_products( $range ) : ( 'reviews' === $tab ? self::csv_reviews( $range ) : self::csv_sales( $range ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- lets Excel read accents correctly.
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					static function ( $value ) {
						return is_string( $value ) && preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
					},
					$row
				)
			);
		}
		exit;
	}

	/**
	 * Sales rows.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	private static function csv_sales( $range ) {
		$now  = Nexora_Toolkit_Analytics_Data::summary( $range['start'], $range['end'], $range['unit'] );
		$rows = array( array( 'period_start', 'orders', 'total_sales' ) );
		$fmt  = 'hour' === $range['unit'] ? 'Y-m-d H:i' : 'Y-m-d';
		foreach ( array_slice( $now['edges'], 0, -1 ) as $i => $at ) {
			$rows[] = array( wp_date( $fmt, $at ), $now['counts'][ $i ], round( $now['series'][ $i ], 2 ) );
		}
		$rows[] = array();
		$rows[] = array( 'total_sales', round( $now['sales'], 2 ) );
		$rows[] = array( 'net_sales', round( $now['net'], 2 ) );
		$rows[] = array( 'product_sales_before_discounts', round( $now['gross'], 2 ) );
		$rows[] = array( 'discounts', round( $now['discount'], 2 ) );
		$rows[] = array( 'delivery_fees', round( $now['shipping'], 2 ) );
		$rows[] = array( 'tax', round( $now['tax'], 2 ) );
		$rows[] = array( 'refunds', round( $now['refunds'], 2 ) );
		$rows[] = array( 'orders', $now['orders'] );
		$rows[] = array( 'average_order', round( $now['average'], 2 ) );
		$rows[] = array( 'items_sold', $now['items'] );
		$rows[] = array( 'customers', $now['customers'] );
		return $rows;
	}

	/**
	 * Product rows.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	private static function csv_products( $range ) {
		$now  = Nexora_Toolkit_Analytics_Data::summary( $range['start'], $range['end'], $range['unit'] );
		$rows = array( array( 'product_id', 'product', 'units_sold', 'orders', 'sales_before_order_discounts' ) );
		foreach ( $now['products'] as $row ) {
			$rows[] = array( $row['id'], $row['name'], $row['units'], $row['orders'], round( $row['sales'], 2 ) );
		}
		return $rows;
	}

	/**
	 * Review rows. Email addresses and IP addresses stay out of the file.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	private static function csv_reviews( $range ) {
		$now  = Nexora_Toolkit_Analytics_Data::reviews( $range['start'], $range['end'] );
		$rows = array( array( 'date_utc', 'product', 'rating', 'approved', 'name', 'review' ) );
		foreach ( $now['list'] as $entry ) {
			$review = $entry['review'];
			$rows[] = array( $review->comment_date_gmt, get_the_title( (int) $review->comment_post_ID ), $entry['rating'], '1' === (string) $review->comment_approved ? 'yes' : 'no', $review->comment_author, $review->comment_content );
		}
		return $rows;
	}

	/**
	 * Daily report rows.
	 *
	 * @param DateTimeImmutable $day Day.
	 * @return array
	 */
	private static function csv_daily( $day ) {
		$report = Nexora_Toolkit_Analytics_Report::build( $day );
		$s      = $report['summary'];
		$rows   = array(
			array( 'date', $day->format( 'Y-m-d' ) ),
			array( 'total_sales', round( $s['sales'], 2 ) ),
			array( 'net_sales', round( $s['net'], 2 ) ),
			array( 'orders', $s['orders'] ),
			array( 'average_order', round( $s['average'], 2 ) ),
			array( 'items_sold', $s['items'] ),
			array( 'discounts', round( $s['discount'], 2 ) ),
			array( 'delivery_fees', round( $s['shipping'], 2 ) ),
			array( 'refunds', round( $s['refunds'], 2 ) ),
			array( 'cancelled_orders', $s['cancelled'] ),
			array( 'new_reviews', $report['reviews']['total'] ),
			array( 'total_sales_day_before', round( $report['before']['sales'], 2 ) ),
			array( 'total_sales_same_day_last_week', round( $report['last_week']['sales'], 2 ) ),
			array(),
			array( 'product', 'units_sold', 'sales_before_order_discounts' ),
		);
		foreach ( $s['products'] as $row ) {
			$rows[] = array( $row['name'], $row['units'], round( $row['sales'], 2 ) );
		}
		return $rows;
	}
}
