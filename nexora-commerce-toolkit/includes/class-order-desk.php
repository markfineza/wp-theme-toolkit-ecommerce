<?php
/**
 * Order Desk: a modern, enterprise-style skin for the WooCommerce order screens, plus a focused
 * admin for staff who only handle orders.
 *
 * - Order screens (HPOS and legacy): KPI strip, fulfilment column and filters, "Ready for pickup"
 *   actions, order progress bar and a fulfilment box with rescheduling.
 * - "Order Desk" role: staff see only Orders, Quotations and their profile, land on the order list
 *   after login, and get a branded admin.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order Desk module.
 */
class Nexora_Toolkit_Order_Desk {

	const ROLE = 'nexora_order_desk';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );

		if ( ! is_admin() ) {
			add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
			add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'wc_login_redirect' ), 20, 2 );
			return;
		}

		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'all_admin_notices', array( __CLASS__, 'kpi_strip' ), 1 );

		// List table: column, filters, actions.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filters' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters_legacy' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_query' ) );
		add_filter( 'request', array( __CLASS__, 'filter_request_legacy' ) );
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ) );

		// Edit screen.
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 30 );
		add_action( 'order_edit_form_top', array( __CLASS__, 'progress' ) );
		add_action( 'edit_form_top', array( __CLASS__, 'progress_legacy' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_box' ), 50, 2 );

		// KPI cache.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'flush' ) );

		// Focused admin for desk staff.
		add_action( 'admin_menu', array( __CLASS__, 'desk_menu' ), 9999 );
		add_action( 'admin_init', array( __CLASS__, 'desk_redirect' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'desk_admin_bar' ), 999 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'desk_footer' ), 99 );
		add_filter( 'update_footer', array( __CLASS__, 'desk_version' ), 99 );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Setting shortcut.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function opt( $key ) {
		return Nexora_Toolkit_Settings::get( $key );
	}

	/**
	 * Capabilities of the Order Desk role.
	 *
	 * @return array
	 */
	public static function role_caps() {
		return array(
			'read'                       => true,
			'view_admin_dashboard'       => true,
			'edit_shop_orders'           => true,
			'edit_others_shop_orders'    => true,
			'edit_published_shop_orders' => true,
			'edit_private_shop_orders'   => true,
			'read_shop_order'            => true,
			'read_private_shop_orders'   => true,
			'publish_shop_orders'        => true,
		);
	}

	/**
	 * Create the role if missing.
	 */
	public static function ensure_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Order Desk', 'nexora-commerce-toolkit' ), self::role_caps() );
		}
	}

	/**
	 * Whether a user works only with orders (no store management or content rights).
	 *
	 * @param WP_User|int|null $user User.
	 * @return bool
	 */
	public static function is_desk_user( $user = null ) {
		$user = $user ? ( $user instanceof WP_User ? $user : get_userdata( $user ) ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! self::opt( 'order_desk' ) ) {
			return false;
		}
		return user_can( $user, 'edit_shop_orders' ) && ! user_can( $user, 'manage_woocommerce' ) && ! user_can( $user, 'edit_posts' );
	}

	/**
	 * HPOS in use?
	 *
	 * @return bool
	 */
	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Order list URL.
	 *
	 * @param array $args Query args (status uses the wc- prefix).
	 * @return string
	 */
	public static function orders_url( $args = array() ) {
		if ( self::hpos() ) {
			return add_query_arg( $args, admin_url( 'admin.php?page=wc-orders' ) );
		}
		if ( isset( $args['status'] ) ) {
			$args['post_status'] = $args['status'];
			unset( $args['status'] );
		}
		return add_query_arg( $args, admin_url( 'edit.php?post_type=shop_order' ) );
	}

	/**
	 * Current screen kind: list|edit|''.
	 *
	 * @return string
	 */
	private static function screen_kind() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return '';
		}
		if ( 'woocommerce_page_wc-orders' === $screen->id ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'edit', 'new' ), true ) ? 'edit' : 'list';
		}
		$map = array(
			'edit-shop_order'  => 'list',
			'shop_order'       => 'edit',
			'edit-nexora_rfq'  => 'other',
			'nexora_rfq'       => 'other',
		);
		return isset( $map[ $screen->id ] ) ? $map[ $screen->id ] : '';
	}

	/* ---------------------------------------------------------------------
	 * Skin
	 * ------------------------------------------------------------------ */

	/**
	 * Body classes.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$kind = self::screen_kind();
		if ( $kind && self::opt( 'orders_modern' ) ) {
			$classes .= ' nx-orders nx-orders--' . $kind;
		}
		if ( self::is_desk_user() ) {
			$classes .= ' nx-desk';
		}
		return $classes;
	}

	/**
	 * Admin CSS/JS.
	 */
	public static function assets() {
		$kind = self::screen_kind();
		if ( ! self::is_desk_user() && ! ( $kind && self::opt( 'orders_modern' ) ) ) {
			return;
		}
		wp_enqueue_style( 'nexora-order-desk', NEXORA_TOOLKIT_URL . 'assets/css/admin-orders.css', array(), NEXORA_TOOLKIT_VERSION );
		wp_enqueue_script( 'nexora-order-desk', NEXORA_TOOLKIT_URL . 'assets/js/admin-orders.js', array(), NEXORA_TOOLKIT_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * KPI strip
	 * ------------------------------------------------------------------ */

	/**
	 * Clear cached KPIs.
	 */
	public static function flush() {
		delete_transient( 'nexora_order_kpis' );
	}

	/**
	 * Numbers for the KPI strip (cached two minutes).
	 *
	 * @return array
	 */
	public static function kpis() {
		$cached = get_transient( 'nexora_order_kpis' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$today = current_datetime()->setTime( 0, 0 );
		$paid  = wc_get_is_paid_statuses();

		$orders = wc_get_orders(
			array(
				'limit'        => 500,
				'status'       => $paid,
				'date_created' => '>=' . $today->getTimestamp(),
				'type'         => 'shop_order',
			)
		);
		$sales  = 0.0;
		foreach ( $orders as $order ) {
			$sales += (float) $order->get_total() - (float) $order->get_total_refunded();
		}

		$due = wc_get_orders(
			array(
				'limit'      => 500,
				'return'     => 'ids',
				'status'     => array( 'processing', Nexora_Toolkit_Fulfilment::STATUS ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_nexora_fulfilment_date',
						'value' => $today->format( 'Y-m-d' ),
					),
				),
			)
		);

		$kpis = array(
			'processing' => (int) wc_orders_count( 'processing' ),
			'ready'      => (int) wc_orders_count( Nexora_Toolkit_Fulfilment::STATUS ),
			'on-hold'    => (int) wc_orders_count( 'on-hold' ),
			'pending'    => (int) wc_orders_count( 'pending' ),
			'today'      => count( $orders ),
			'sales'      => $sales,
			'due'        => count( $due ),
		);
		set_transient( 'nexora_order_kpis', $kpis, 2 * MINUTE_IN_SECONDS );
		return $kpis;
	}

	/**
	 * KPI strip above the order list (moved under the page title by admin-orders.js).
	 */
	public static function kpi_strip() {
		if ( 'list' !== self::screen_kind() || ! self::opt( 'orders_modern' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$k     = self::kpis();
		$user  = wp_get_current_user();
		$hour  = (int) current_datetime()->format( 'G' );
		$greet = $hour < 12 ? __( 'Good morning', 'nexora-commerce-toolkit' ) : ( $hour < 18 ? __( 'Good afternoon', 'nexora-commerce-toolkit' ) : __( 'Good evening', 'nexora-commerce-toolkit' ) );
		$tiles = array(
			array( __( 'To pack', 'nexora-commerce-toolkit' ), number_format_i18n( $k['processing'] ), __( 'Processing', 'nexora-commerce-toolkit' ), self::orders_url( array( 'status' => 'wc-processing' ) ), 'is-primary' ),
			array( __( 'Ready for pickup', 'nexora-commerce-toolkit' ), number_format_i18n( $k['ready'] ), __( 'Waiting for customer or rider', 'nexora-commerce-toolkit' ), self::orders_url( array( 'status' => 'wc-' . Nexora_Toolkit_Fulfilment::STATUS ) ), 'is-ready' ),
			array( __( 'Scheduled today', 'nexora-commerce-toolkit' ), number_format_i18n( $k['due'] ), __( 'Pickups and rider collections', 'nexora-commerce-toolkit' ), self::orders_url( array( 'nx_when' => 'today' ) ), 'is-due' ),
			array( __( 'On hold', 'nexora-commerce-toolkit' ), number_format_i18n( $k['on-hold'] ), __( 'Awaiting payment check', 'nexora-commerce-toolkit' ), self::orders_url( array( 'status' => 'wc-on-hold' ) ), 'is-warn' ),
			array( __( 'Pending payment', 'nexora-commerce-toolkit' ), number_format_i18n( $k['pending'] ), __( 'Not paid yet', 'nexora-commerce-toolkit' ), self::orders_url( array( 'status' => 'wc-pending' ) ), '' ),
		);
		if ( current_user_can( 'view_woocommerce_reports' ) || current_user_can( 'manage_woocommerce' ) ) {
			/* translators: %s: number of orders */
			$tiles[] = array( __( 'Sales today', 'nexora-commerce-toolkit' ), wp_strip_all_tags( wc_price( $k['sales'] ) ), sprintf( _n( '%s paid order', '%s paid orders', $k['today'], 'nexora-commerce-toolkit' ), number_format_i18n( $k['today'] ) ), '', 'is-money' );
		}
		?>
		<section class="nx-kpis" data-nexora-kpis aria-label="<?php esc_attr_e( 'Order summary', 'nexora-commerce-toolkit' ); ?>">
			<header class="nx-kpis__head">
				<?php /* translators: 1: greeting, 2: first name */ ?>
				<p class="nx-kpis__greet"><?php echo esc_html( sprintf( __( '%1$s, %2$s', 'nexora-commerce-toolkit' ), $greet, $user->first_name ? $user->first_name : $user->display_name ) ); ?></p>
				<p class="nx-kpis__date"><?php echo esc_html( wp_date( get_option( 'date_format' ) ) ); ?></p>
			</header>
			<ul class="nx-kpis__grid">
				<?php foreach ( $tiles as $t ) : ?>
					<li class="nx-kpi <?php echo esc_attr( $t[4] ); ?>">
						<?php if ( $t[3] ) : ?><a href="<?php echo esc_url( $t[3] ); ?>"><?php endif; ?>
							<span class="nx-kpi__label"><?php echo esc_html( $t[0] ); ?></span>
							<strong class="nx-kpi__value"><?php echo esc_html( $t[1] ); ?></strong>
							<span class="nx-kpi__hint"><?php echo esc_html( $t[2] ); ?></span>
						<?php if ( $t[3] ) : ?></a><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * List table
	 * ------------------------------------------------------------------ */

	/**
	 * Add the Fulfilment column after Status.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['nx_fulfilment'] = __( 'Fulfilment', 'nexora-commerce-toolkit' );
			}
		}
		return isset( $out['nx_fulfilment'] ) ? $out : $out + array( 'nx_fulfilment' => __( 'Fulfilment', 'nexora-commerce-toolkit' ) );
	}

	/**
	 * Column cell.
	 *
	 * @param string       $column Column.
	 * @param WC_Order|int $order  Order or post ID.
	 */
	public static function column( $column, $order ) {
		if ( 'nx_fulfilment' !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$info = Nexora_Toolkit_Fulfilment::for_order( $order );
		if ( ! $info ) {
			$names = array();
			foreach ( $order->get_shipping_methods() as $item ) {
				$names[] = $item->get_name();
			}
			echo $names ? '<span class="nx-ful nx-ful--other">' . esc_html( implode( ', ', $names ) ) . '</span>' : '<span class="nx-ful nx-ful--none" aria-label="' . esc_attr__( 'No shipping', 'nexora-commerce-toolkit' ) . '">—</span>';
			return;
		}
		$pickup  = 'pickup' === $info['type'];
		$overdue = ! empty( $info['date'] ) && $info['date'] < current_datetime()->format( 'Y-m-d' ) && $order->has_status( array( 'processing', Nexora_Toolkit_Fulfilment::STATUS ) );
		printf(
			'<span class="nx-ful nx-ful--%1$s%2$s"><span class="nx-ful__badge">%3$s</span>%4$s%5$s</span>',
			esc_attr( $info['type'] ),
			$overdue ? ' is-overdue' : '',
			esc_html( $pickup ? __( 'Pickup', 'nexora-commerce-toolkit' ) : __( 'Courier', 'nexora-commerce-toolkit' ) ),
			! empty( $info['date'] ) ? '<span class="nx-ful__when">' . esc_html( Nexora_Toolkit_Fulfilment::when( $info ) ) . ( $overdue ? ' · ' . esc_html__( 'overdue', 'nexora-commerce-toolkit' ) : '' ) . '</span>' : '',
			'<span class="nx-ful__meta">' . esc_html( $pickup ? ( isset( $info['location'] ) ? $info['location'] : '' ) : ( isset( $info['courier'] ) ? $info['courier'] : '' ) ) . '</span>'
		);
	}

	/**
	 * Filter dropdowns.
	 *
	 * @param string $order_type Order type.
	 * @param string $which      top|bottom.
	 */
	public static function filters( $order_type = 'shop_order', $which = 'top' ) {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['nx_fulfilment'] ) ? sanitize_key( wp_unslash( $_GET['nx_fulfilment'] ) ) : '';
		$when = isset( $_GET['nx_when'] ) ? sanitize_key( wp_unslash( $_GET['nx_when'] ) ) : '';
		// phpcs:enable
		?>
		<label class="screen-reader-text" for="nx-filter-fulfilment"><?php esc_html_e( 'Filter by fulfilment', 'nexora-commerce-toolkit' ); ?></label>
		<select name="nx_fulfilment" id="nx-filter-fulfilment">
			<option value=""><?php esc_html_e( 'All fulfilment', 'nexora-commerce-toolkit' ); ?></option>
			<option value="pickup" <?php selected( $type, 'pickup' ); ?>><?php esc_html_e( 'Store pickup', 'nexora-commerce-toolkit' ); ?></option>
			<option value="courier" <?php selected( $type, 'courier' ); ?>><?php esc_html_e( 'Courier (customer books)', 'nexora-commerce-toolkit' ); ?></option>
		</select>
		<label class="screen-reader-text" for="nx-filter-when"><?php esc_html_e( 'Filter by schedule', 'nexora-commerce-toolkit' ); ?></label>
		<select name="nx_when" id="nx-filter-when">
			<option value=""><?php esc_html_e( 'Any schedule', 'nexora-commerce-toolkit' ); ?></option>
			<option value="today" <?php selected( $when, 'today' ); ?>><?php esc_html_e( 'Scheduled today', 'nexora-commerce-toolkit' ); ?></option>
			<option value="tomorrow" <?php selected( $when, 'tomorrow' ); ?>><?php esc_html_e( 'Scheduled tomorrow', 'nexora-commerce-toolkit' ); ?></option>
			<option value="overdue" <?php selected( $when, 'overdue' ); ?>><?php esc_html_e( 'Overdue (not collected)', 'nexora-commerce-toolkit' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Legacy (posts) list filters.
	 *
	 * @param string $post_type Post type.
	 */
	public static function filters_legacy( $post_type ) {
		if ( 'shop_order' === $post_type ) {
			self::filters();
		}
	}

	/**
	 * Meta query for the selected filters.
	 *
	 * @return array{meta:array,status:array}
	 */
	private static function filter_clauses() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['nx_fulfilment'] ) ? sanitize_key( wp_unslash( $_GET['nx_fulfilment'] ) ) : '';
		$when = isset( $_GET['nx_when'] ) ? sanitize_key( wp_unslash( $_GET['nx_when'] ) ) : '';
		// phpcs:enable
		$meta   = array();
		$status = array();
		if ( in_array( $type, array( 'pickup', 'courier' ), true ) ) {
			$meta[] = array(
				'key'   => '_nexora_fulfilment_type',
				'value' => $type,
			);
		}
		$today = current_datetime();
		if ( 'today' === $when || 'tomorrow' === $when ) {
			$meta[]  = array(
				'key'   => '_nexora_fulfilment_date',
				'value' => ( 'today' === $when ? $today : $today->modify( '+1 day' ) )->format( 'Y-m-d' ),
			);
			$status = array( 'processing', Nexora_Toolkit_Fulfilment::STATUS );
		} elseif ( 'overdue' === $when ) {
			$meta[]  = array(
				'key'     => '_nexora_fulfilment_date',
				'value'   => $today->format( 'Y-m-d' ),
				'compare' => '<',
				'type'    => 'DATE',
			);
			$meta[]  = array(
				'key'     => '_nexora_fulfilment_date',
				'value'   => '',
				'compare' => '!=',
			);
			$status = array( 'processing', Nexora_Toolkit_Fulfilment::STATUS );
		}
		return array(
			'meta'   => $meta,
			'status' => $status,
		);
	}

	/**
	 * Apply filters (HPOS).
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function filter_query( $args ) {
		$c = self::filter_clauses();
		if ( $c['meta'] ) {
			$args['meta_query'] = array_merge( isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(), $c['meta'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		if ( $c['status'] && ( empty( $args['status'] ) || 'all' === $args['status'] || array( 'all' ) === (array) $args['status'] ) ) {
			$args['status'] = array_map(
				static function ( $s ) {
					return 'wc-' . $s;
				},
				$c['status']
			);
		}
		return $args;
	}

	/**
	 * Apply filters (legacy posts list).
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function filter_request_legacy( $vars ) {
		global $typenow;
		if ( 'shop_order' !== $typenow || ! is_admin() ) {
			return $vars;
		}
		$c = self::filter_clauses();
		if ( $c['meta'] ) {
			$vars['meta_query'] = $c['meta']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		if ( $c['status'] && empty( $vars['post_status'] ) ) {
			$vars['post_status'] = array_map(
				static function ( $s ) {
					return 'wc-' . $s;
				},
				$c['status']
			);
		}
		return $vars;
	}

	/**
	 * Quick action buttons in the list.
	 *
	 * @param array    $actions Actions.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public static function row_actions( $actions, $order ) {
		$url = static function ( $status ) use ( $order ) {
			return wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=' . $status . '&order_id=' . $order->get_id() ), 'woocommerce-mark-order-status' );
		};
		if ( $order->has_status( 'processing' ) && Nexora_Toolkit_Fulfilment::for_order( $order ) ) {
			$actions = array(
				'nx-ready' => array(
					'url'    => $url( Nexora_Toolkit_Fulfilment::STATUS ),
					'name'   => __( 'Ready for pickup', 'nexora-commerce-toolkit' ),
					'action' => 'nx-ready',
				),
			) + $actions;
		}
		if ( $order->has_status( Nexora_Toolkit_Fulfilment::STATUS ) && ! isset( $actions['complete'] ) ) {
			$actions['complete'] = array(
				'url'    => $url( 'completed' ),
				'name'   => __( 'Collected — complete', 'nexora-commerce-toolkit' ),
				'action' => 'complete',
			);
		}
		return $actions;
	}

	/**
	 * Bulk action.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$out = array();
		foreach ( $actions as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'mark_processing' === $key ) {
				$out[ 'mark_' . Nexora_Toolkit_Fulfilment::STATUS ] = __( 'Change status to ready for pickup', 'nexora-commerce-toolkit' );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Edit screen
	 * ------------------------------------------------------------------ */

	/**
	 * Fulfilment box.
	 */
	public static function meta_boxes() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'nexora-fulfilment', __( 'Fulfilment', 'nexora-commerce-toolkit' ), array( __CLASS__, 'box' ), $screen, 'side', 'high' );
	}

	/**
	 * Box content.
	 *
	 * @param WP_Post|WC_Order $object Post or order.
	 */
	public static function box( $object ) {
		$order = $object instanceof WP_Post ? wc_get_order( $object->ID ) : $object;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$info = Nexora_Toolkit_Fulfilment::for_order( $order );
		if ( ! $info ) {
			echo '<p class="nx-box-empty">' . esc_html__( 'This order does not use courier booking or store pickup.', 'nexora-commerce-toolkit' ) . '</p>';
			return;
		}
		$pickup = 'pickup' === $info['type'];
		wp_nonce_field( 'nexora_fulfilment_box', 'nexora_fulfilment_nonce' );
		?>
		<div class="nx-box">
			<p class="nx-box__type"><span class="nx-ful nx-ful--<?php echo esc_attr( $info['type'] ); ?>"><span class="nx-ful__badge"><?php echo esc_html( $pickup ? __( 'Store pickup', 'nexora-commerce-toolkit' ) : __( 'Courier — customer books', 'nexora-commerce-toolkit' ) ); ?></span></span></p>
			<dl class="nx-box__list">
				<?php if ( ! empty( $info['date'] ) ) : ?>
					<dt><?php echo esc_html( $pickup ? __( 'Pickup time', 'nexora-commerce-toolkit' ) : __( 'Rider pickup', 'nexora-commerce-toolkit' ) ); ?></dt>
					<dd><?php echo esc_html( Nexora_Toolkit_Fulfilment::when( $info ) ); ?></dd>
				<?php endif; ?>
				<?php if ( $pickup && ! empty( $info['location'] ) ) : ?>
					<dt><?php esc_html_e( 'Pickup point', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( $info['location'] ); ?></dd>
				<?php endif; ?>
				<?php if ( ! $pickup && ! empty( $info['courier'] ) ) : ?>
					<dt><?php esc_html_e( 'Courier', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( $info['courier'] ); ?></dd>
				<?php endif; ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					<dt><?php esc_html_e( 'Customer mobile', 'nexora-commerce-toolkit' ); ?></dt><dd><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $order->get_billing_phone() ) ); ?>"><?php echo esc_html( $order->get_billing_phone() ); ?></a></dd>
				<?php endif; ?>
			</dl>
			<details class="nx-box__edit">
				<summary><?php esc_html_e( 'Reschedule', 'nexora-commerce-toolkit' ); ?></summary>
				<p><label for="nx-ful-date"><?php esc_html_e( 'Date', 'nexora-commerce-toolkit' ); ?></label><input type="date" id="nx-ful-date" name="nexora_ful_date" value="<?php echo esc_attr( isset( $info['date'] ) ? $info['date'] : '' ); ?>"></p>
				<p><label for="nx-ful-slot"><?php esc_html_e( 'Time slot (HH:MM-HH:MM)', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="nx-ful-slot" name="nexora_ful_slot" value="<?php echo esc_attr( isset( $info['slot'] ) ? $info['slot'] : '' ); ?>" placeholder="10:00-12:00" pattern="\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}"></p>
				<?php if ( ! $pickup ) : ?>
					<p><label for="nx-ful-courier"><?php esc_html_e( 'Courier', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="nx-ful-courier" name="nexora_ful_courier" value="<?php echo esc_attr( isset( $info['courier'] ) ? $info['courier'] : '' ); ?>" maxlength="60"></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Saved with the order. A note is added to the order history.', 'nexora-commerce-toolkit' ); ?></p>
			</details>
		</div>
		<?php
	}

	/**
	 * Save rescheduling.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order (HPOS) or post.
	 */
	public static function save_box( $order_id, $order = null ) {
		if ( ! isset( $_POST['nexora_fulfilment_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_fulfilment_nonce'] ) ), 'nexora_fulfilment_box' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		$info  = $order ? Nexora_Toolkit_Fulfilment::for_order( $order ) : null;
		if ( ! $info ) {
			return;
		}
		$date    = isset( $_POST['nexora_ful_date'] ) ? sanitize_text_field( wp_unslash( $_POST['nexora_ful_date'] ) ) : '';
		$slot    = isset( $_POST['nexora_ful_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['nexora_ful_slot'] ) ) : '';
		$courier = isset( $_POST['nexora_ful_courier'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['nexora_ful_courier'] ) ), 0, 60 ) : null;

		Nexora_Toolkit_Fulfilment::load_methods();
		$parsed = Nexora_Toolkit_Shipping_Method::parse_slot( $slot );
		$new    = $info;
		$new['date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : ( '' === $date ? '' : $info['date'] );
		$new['slot'] = $parsed ? $parsed['key'] : ( '' === $slot ? '' : $info['slot'] );
		if ( null !== $courier && 'courier' === $info['type'] ) {
			$new['courier'] = $courier;
		}
		if ( $new === $info ) {
			return;
		}
		$order->update_meta_data( Nexora_Toolkit_Fulfilment::META, $new );
		$order->update_meta_data( '_nexora_fulfilment_type', $new['type'] );
		$order->update_meta_data( '_nexora_fulfilment_date', $new['date'] );
		/* translators: %s: new schedule */
		$order->add_order_note( sprintf( __( 'Fulfilment rescheduled to %s.', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Fulfilment::when( $new ) ? Nexora_Toolkit_Fulfilment::when( $new ) : __( 'no set time', 'nexora-commerce-toolkit' ) ), 0, true );
		$order->save();
		self::flush();
	}

	/**
	 * Progress bar: Placed → Paid → Ready → Completed.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function progress( $order ) {
		if ( ! $order instanceof WC_Order || ! self::opt( 'orders_modern' ) || ! $order->get_date_created() ) {
			return;
		}
		$fmt   = static function ( $date ) {
			return $date ? wp_date( 'M j, g:i a', is_numeric( $date ) ? (int) $date : $date->getTimestamp() ) : '';
		};
		$ready = $order->get_meta( '_nexora_ready_at' );
		$steps = array(
			array( __( 'Placed', 'nexora-commerce-toolkit' ), $fmt( $order->get_date_created() ), true ),
			// Cash on pickup is only paid once collected, even though "Ready for pickup" counts as a paid status.
			array( __( 'Paid', 'nexora-commerce-toolkit' ), $fmt( $order->get_date_paid() ), (bool) $order->get_date_paid() || ( $order->is_paid() && 'cod' !== $order->get_payment_method() ) ),
		);
		if ( Nexora_Toolkit_Fulfilment::for_order( $order ) ) {
			$steps[] = array( __( 'Ready for pickup', 'nexora-commerce-toolkit' ), $fmt( $ready ), $order->has_status( array( Nexora_Toolkit_Fulfilment::STATUS, 'completed' ) ) );
		}
		$steps[] = array( __( 'Completed', 'nexora-commerce-toolkit' ), $fmt( $order->get_date_completed() ), $order->has_status( 'completed' ) );
		$stopped = $order->has_status( array( 'cancelled', 'refunded', 'failed' ) );
		?>
		<ol class="nx-progress<?php echo $stopped ? ' is-stopped' : ''; ?>" aria-label="<?php esc_attr_e( 'Order progress', 'nexora-commerce-toolkit' ); ?>">
			<?php foreach ( $steps as $s ) : ?>
				<li class="<?php echo $s[2] ? 'is-done' : ''; ?>">
					<span class="nx-progress__dot" aria-hidden="true"></span>
					<span class="nx-progress__label"><?php echo esc_html( $s[0] ); ?><?php echo $s[2] ? '' : '<span class="screen-reader-text"> ' . esc_html__( '(not yet)', 'nexora-commerce-toolkit' ) . '</span>'; ?></span>
					<?php if ( $s[2] && $s[1] ) : ?><span class="nx-progress__time"><?php echo esc_html( $s[1] ); ?></span><?php endif; ?>
				</li>
			<?php endforeach; ?>
			<?php if ( $stopped ) : ?>
				<li class="is-stopped"><span class="nx-progress__dot" aria-hidden="true"></span><span class="nx-progress__label"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></li>
			<?php endif; ?>
		</ol>
		<?php
	}

	/**
	 * Legacy edit screen.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function progress_legacy( $post ) {
		if ( $post instanceof WP_Post && 'shop_order' === $post->post_type ) {
			self::progress( wc_get_order( $post->ID ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Desk mode
	 * ------------------------------------------------------------------ */

	/**
	 * Keep only Orders, Quotations and Profile.
	 */
	public static function desk_menu() {
		global $menu, $submenu;
		if ( ! self::is_desk_user() ) {
			return;
		}
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], array( 'woocommerce', 'profile.php' ), true ) && 0 !== strpos( $item[2], 'separator' ) ) {
				remove_menu_page( $item[2] );
			}
		}
		if ( isset( $submenu['woocommerce'] ) ) {
			foreach ( $submenu['woocommerce'] as $i => $item ) {
				if ( ! in_array( $item[2], array( 'wc-orders', 'edit.php?post_type=shop_order', 'edit.php?post_type=nexora_rfq' ), true ) ) {
					unset( $submenu['woocommerce'][ $i ] );
				}
			}
		}
		foreach ( (array) $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'woocommerce' === $item[2] ) {
				$menu[ $i ][0] = esc_html( self::desk_name() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$menu[ $i ][6] = 'dashicons-clipboard'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	/**
	 * Desk name from settings.
	 *
	 * @return string
	 */
	public static function desk_name() {
		$name = trim( (string) self::opt( 'order_desk_name' ) );
		return '' !== $name ? $name : __( 'Order Desk', 'nexora-commerce-toolkit' );
	}

	/**
	 * Send desk staff from the Dashboard to Orders.
	 */
	public static function desk_redirect() {
		global $pagenow;
		if ( 'index.php' === $pagenow && ! wp_doing_ajax() && self::is_desk_user() ) {
			wp_safe_redirect( self::orders_url() );
			exit;
		}
	}

	/**
	 * After wp-login.php.
	 *
	 * @param string           $redirect  Redirect.
	 * @param string           $requested Requested.
	 * @param WP_User|WP_Error $user      User.
	 * @return string
	 */
	public static function login_redirect( $redirect, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_desk_user( $user ) && ( ! $requested || admin_url() === $requested || false !== strpos( $requested, 'wp-admin/index.php' ) ) ) {
			return self::orders_url();
		}
		return $redirect;
	}

	/**
	 * After the My Account login form.
	 *
	 * @param string  $redirect Redirect.
	 * @param WP_User $user     User.
	 * @return string
	 */
	public static function wc_login_redirect( $redirect, $user ) {
		return $user instanceof WP_User && self::is_desk_user( $user ) ? self::orders_url() : $redirect;
	}

	/**
	 * Trim the toolbar and add the desk brand.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function desk_admin_bar( $bar ) {
		if ( ! is_admin() || ! self::is_desk_user() ) {
			return;
		}
		foreach ( array( 'wp-logo', 'comments', 'new-content', 'updates', 'customize', 'search' ) as $node ) {
			$bar->remove_node( $node );
		}
		$bar->add_node(
			array(
				'id'    => 'nx-desk',
				'title' => '<span class="nx-desk-brand">' . esc_html( self::desk_name() ) . '</span>',
				'href'  => self::orders_url(),
				'meta'  => array( 'class' => 'nx-desk-node' ),
			)
		);
	}

	/**
	 * Footer text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function desk_footer( $text ) {
		return self::is_desk_user() ? esc_html( self::desk_name() . ' · ' . get_bloginfo( 'name' ) ) : $text;
	}

	/**
	 * Hide the WordPress version for desk staff.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function desk_version( $text ) {
		return self::is_desk_user() ? '' : $text;
	}
}
