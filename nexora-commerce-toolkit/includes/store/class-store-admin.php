<?php
/**
 * Store admin: engine switch, dashboard, orders, product editor and settings.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class Nexora_Store_Admin {

	const CAP_STORE  = 'nx_manage_store';
	const CAP_ORDERS = 'nx_manage_orders';

	/* -------------------------------------------------------- engine switch */

	/**
	 * Always available, whichever engine is running.
	 */
	public static function init_switch() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_post_nexora_store_engine', array( __CLASS__, 'handle_engine' ) );
		add_action( 'admin_notices', array( __CLASS__, 'engine_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'after_switch_to_woocommerce' ) );
	}

	/**
	 * Switch box on Settings → Freshtastic Toolkit.
	 */
	public static function render_engine_box() {
		$native = Nexora_Store::active();
		$woo    = class_exists( 'WooCommerce' );
		$url    = static function ( $mode ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=nexora_store_engine&mode=' . $mode ), 'nexora_store_engine' );
		};
		?>
		<h2 id="nexora-engine"><?php esc_html_e( 'Store engine', 'nexora-commerce-toolkit' ); ?></h2>
		<p>
			<?php
			if ( $native ) {
				esc_html_e( 'Current: built-in store. WooCommerce is not needed.', 'nexora-commerce-toolkit' );
			} elseif ( $woo ) {
				esc_html_e( 'Current: WooCommerce.', 'nexora-commerce-toolkit' );
			} else {
				esc_html_e( 'Current: none. WooCommerce is not active and the built-in store is off, so the shop is hidden.', 'nexora-commerce-toolkit' );
			}
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Only one engine runs at a time. Products, categories, coupons and customer accounts carry over when you switch, in either direction. Orders stay with the engine that took them: WooCommerce orders stay in WooCommerce, built-in orders stay in the built-in store, and each is available again when you switch back.', 'nexora-commerce-toolkit' ); ?></p>
		<p>
			<?php if ( ! $native ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $url( 'native' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Switch to the built-in store? WooCommerce will be deactivated (not deleted). WooCommerce payment gateways and add-ons stop working until you switch back.', 'nexora-commerce-toolkit' ) ); ?>');"><?php esc_html_e( 'Use the built-in store', 'nexora-commerce-toolkit' ); ?></a>
			<?php endif; ?>
			<?php if ( ! $woo ) : ?>
				<a class="button" href="<?php echo esc_url( $url( 'woocommerce' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Switch to WooCommerce? It will be activated, or installed first if needed. The built-in store stops, and its orders stay saved for when you switch back.', 'nexora-commerce-toolkit' ) ); ?>');"><?php esc_html_e( 'Use WooCommerce', 'nexora-commerce-toolkit' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Warn when the built-in store was chosen but WooCommerce was activated again.
	 */
	public static function engine_notice() {
		if ( 'native' === Nexora_Store::engine() && class_exists( 'WooCommerce' ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'The built-in store is paused because WooCommerce is active. WooCommerce is running your shop.', 'nexora-commerce-toolkit' ) . '</p></div>';
		}
		$key     = 'nexora_engine_msg_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Handle the switch.
	 */
	public static function handle_engine() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_store_engine' );
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$key  = 'nexora_engine_msg_' . get_current_user_id();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$file = 'woocommerce/woocommerce.php';

		if ( 'native' === $mode ) {
			update_option( Nexora_Store::ENGINE, 'native' );
			update_option( 'nexora_store_db', 0 ); // Re-run the installer: pages, roles and tables.
			if ( is_plugin_active( $file ) ) {
				deactivate_plugins( $file );
			}
			set_transient( $key, __( 'The built-in store is now running. WooCommerce was deactivated, not deleted.', 'nexora-commerce-toolkit' ), 120 );
			wp_safe_redirect( admin_url( 'admin.php?page=nexora-store' ) );
			exit;
		}

		update_option( Nexora_Store::ENGINE, 'woocommerce' );
		update_option( 'nexora_wc_regen', 1 );
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			Nexora_Toolkit_Dependencies::install_all();
		} elseif ( ! is_plugin_active( $file ) ) {
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				set_transient( $key, $result->get_error_message(), 120 );
			}
		}
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'options-general.php?page=nexora-toolkit#nexora-engine' ) );
		exit;
	}

	/**
	 * WooCommerce keeps a product lookup table; rebuild it so products edited in the built-in store appear correctly.
	 */
	public static function after_switch_to_woocommerce() {
		if ( get_option( 'nexora_wc_regen' ) && class_exists( 'WooCommerce' ) ) {
			delete_option( 'nexora_wc_regen' );
			if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
				wc_update_product_lookup_tables();
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients();
			}
		}
	}

	/* ------------------------------------------------------------------ boot */

	/**
	 * Hook up while the built-in store runs.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'sort_menu' ), 9000 );
		add_action( 'admin_menu', array( __CLASS__, 'desk_menu' ), 9999 );
		add_action( 'admin_init', array( __CLASS__, 'desk_redirect' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'desk_admin_bar' ), 999 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_nexora_store_order', array( __CLASS__, 'handle_order' ) );
		add_action( 'admin_post_nexora_store_export', array( __CLASS__, 'export_orders' ) );
		add_action( 'admin_post_nexora_store_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'product_boxes' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_product' ), 20, 2 );
		add_filter( 'manage_product_posts_columns', array( __CLASS__, 'product_columns' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column' ), 10, 2 );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'category_field' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'category_field_edit' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_category' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( __( 'Store', 'nexora-commerce-toolkit' ), __( 'Store', 'nexora-commerce-toolkit' ), self::CAP_ORDERS, 'nexora-store', array( __CLASS__, 'page_dashboard' ), 'dashicons-store', 26 );
		add_submenu_page( 'nexora-store', __( 'Store dashboard', 'nexora-commerce-toolkit' ), __( 'Dashboard', 'nexora-commerce-toolkit' ), self::CAP_ORDERS, 'nexora-store', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'nexora-store', __( 'Orders', 'nexora-commerce-toolkit' ), __( 'Orders', 'nexora-commerce-toolkit' ), self::CAP_ORDERS, 'nexora-store-orders', array( __CLASS__, 'page_orders' ) );
		add_submenu_page( 'nexora-store', __( 'Categories', 'nexora-commerce-toolkit' ), __( 'Categories', 'nexora-commerce-toolkit' ), 'manage_product_terms', 'edit-tags.php?taxonomy=product_cat&post_type=product' );
		add_submenu_page( 'nexora-store', __( 'Store settings', 'nexora-commerce-toolkit' ), __( 'Settings', 'nexora-commerce-toolkit' ), self::CAP_STORE, 'nexora-store-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Put the Store submenu in a sensible order.
	 */
	public static function sort_menu() {
		global $submenu;
		if ( empty( $submenu['nexora-store'] ) ) {
			return;
		}
		$order = array( 'nexora-store', 'nexora-store-orders', 'edit.php?post_type=product', 'post-new.php?post_type=product', 'edit-tags.php?taxonomy=product_cat&post_type=product', 'edit.php?post_type=shop_coupon', 'edit.php?post_type=' . Nexora_Store_Coupons::PROMO, 'edit.php?post_type=nexora_rfq', 'nexora-store-settings' );
		usort(
			$submenu['nexora-store'],
			static function ( $a, $b ) use ( $order ) {
				$x = array_search( $a[2], $order, true );
				$y = array_search( $b[2], $order, true );
				return ( false === $x ? 99 : $x ) <=> ( false === $y ? 99 : $y );
			}
		);
	}

	/**
	 * Admin-only staff with just the orders capability.
	 *
	 * @return bool
	 */
	private static function is_desk() {
		return current_user_can( self::CAP_ORDERS ) && ! current_user_can( self::CAP_STORE ) && Nexora_Toolkit_Settings::get( 'order_desk' );
	}

	/**
	 * Order Desk staff see only Orders, Quotations and their profile.
	 */
	public static function desk_menu() {
		global $menu, $submenu;
		if ( ! self::is_desk() ) {
			return;
		}
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], array( 'nexora-store', 'profile.php' ), true ) && 0 !== strpos( $item[2], 'separator' ) ) {
				remove_menu_page( $item[2] );
			}
		}
		if ( isset( $submenu['nexora-store'] ) ) {
			foreach ( $submenu['nexora-store'] as $i => $item ) {
				if ( ! in_array( $item[2], array( 'nexora-store', 'nexora-store-orders', 'edit.php?post_type=nexora_rfq' ), true ) ) {
					unset( $submenu['nexora-store'][ $i ] );
				}
			}
		}
		$name = trim( (string) Nexora_Toolkit_Settings::get( 'order_desk_name' ) );
		foreach ( (array) $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'nexora-store' === $item[2] ) {
				$menu[ $i ][0] = esc_html( '' !== $name ? $name : __( 'Order Desk', 'nexora-commerce-toolkit' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	/**
	 * Send Order Desk staff from the WordPress dashboard to the orders.
	 */
	public static function desk_redirect() {
		global $pagenow;
		if ( 'index.php' === $pagenow && ! wp_doing_ajax() && self::is_desk() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=nexora-store-orders' ) );
			exit;
		}
	}

	/**
	 * Trim the toolbar for Order Desk staff.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function desk_admin_bar( $bar ) {
		if ( is_admin() && self::is_desk() ) {
			foreach ( array( 'wp-logo', 'comments', 'new-content', 'updates', 'customize', 'search' ) as $node ) {
				$bar->remove_node( $node );
			}
		}
	}

	/**
	 * Body classes for the order skin.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( (string) $screen->id, 'nexora-store' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes .= ' nx-orders nx-orders--' . ( isset( $_GET['order'] ) ? 'edit' : 'list' );
		}
		if ( self::is_desk() ) {
			$classes .= ' nx-desk';
		}
		return $classes;
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$is_prod = $screen && in_array( $screen->post_type, array( 'product', 'shop_coupon', Nexora_Store_Coupons::PROMO ), true );
		$is_cat  = $screen && 'product_cat' === $screen->taxonomy;
		if ( false !== strpos( (string) $hook, 'nexora-store' ) || self::is_desk() ) {
			wp_enqueue_style( 'nexora-order-desk', NEXORA_TOOLKIT_URL . 'assets/css/admin-orders.css', array(), NEXORA_TOOLKIT_VERSION );
			wp_enqueue_script( 'nexora-order-desk', NEXORA_TOOLKIT_URL . 'assets/js/admin-orders.js', array(), NEXORA_TOOLKIT_VERSION, true );
		}
		if ( $is_prod || $is_cat ) {
			wp_enqueue_media();
			wp_enqueue_style( 'nexora-store-admin', NEXORA_TOOLKIT_URL . 'assets/css/store-admin.css', array(), NEXORA_TOOLKIT_VERSION );
			wp_enqueue_script( 'nexora-store-admin', NEXORA_TOOLKIT_URL . 'assets/js/store-admin.js', array(), NEXORA_TOOLKIT_VERSION, true );
		}
	}

	/* -------------------------------------------------------------- dashboard */

	/**
	 * Figures for the dashboard and the orders screen (cached briefly).
	 *
	 * @return array
	 */
	public static function kpis() {
		$cached = get_transient( 'nexora_store_kpis' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$t      = "{$wpdb->prefix}nx_orders";
		$counts = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$t} GROUP BY status" ) as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}
		$start = current_datetime()->setTime( 0, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$today = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM {$t} WHERE created_at >= %s AND status NOT IN ('cancelled','refunded','failed')", $start ) );
		$month = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(total),0) s FROM {$t} WHERE created_at >= %s AND status NOT IN ('cancelled','refunded','failed')", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$due   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE fulfilment_date = %s AND status IN ('processing','nx-ready')", current_datetime()->format( 'Y-m-d' ) ) );
		// phpcs:enable
		$kpis = array(
			'counts'  => $counts,
			'today_n' => (int) $today->n,
			'today_s' => (float) $today->s,
			'month_n' => (int) $month->n,
			'month_s' => (float) $month->s,
			'due'     => $due,
		);
		set_transient( 'nexora_store_kpis', $kpis, 2 * MINUTE_IN_SECONDS );
		return $kpis;
	}

	/**
	 * KPI tiles.
	 */
	private static function kpi_strip() {
		$k     = self::kpis();
		$c     = static function ( $s ) use ( $k ) {
			return isset( $k['counts'][ $s ] ) ? $k['counts'][ $s ] : 0;
		};
		$url   = static function ( $args ) {
			return add_query_arg( $args, admin_url( 'admin.php?page=nexora-store-orders' ) );
		};
		$user  = wp_get_current_user();
		$hour  = (int) current_datetime()->format( 'G' );
		$greet = $hour < 12 ? __( 'Good morning', 'nexora-commerce-toolkit' ) : ( $hour < 18 ? __( 'Good afternoon', 'nexora-commerce-toolkit' ) : __( 'Good evening', 'nexora-commerce-toolkit' ) );
		$tiles = array(
			array( __( 'To pack', 'nexora-commerce-toolkit' ), number_format_i18n( $c( 'processing' ) ), __( 'Processing', 'nexora-commerce-toolkit' ), $url( array( 'status' => 'processing' ) ), 'is-primary' ),
			array( __( 'Ready for pickup', 'nexora-commerce-toolkit' ), number_format_i18n( $c( 'nx-ready' ) ), __( 'Waiting for customer or rider', 'nexora-commerce-toolkit' ), $url( array( 'status' => 'nx-ready' ) ), 'is-ready' ),
			array( __( 'Scheduled today', 'nexora-commerce-toolkit' ), number_format_i18n( $k['due'] ), __( 'Pickups and rider collections', 'nexora-commerce-toolkit' ), $url( array( 'when' => 'today' ) ), 'is-due' ),
			array( __( 'On hold', 'nexora-commerce-toolkit' ), number_format_i18n( $c( 'on-hold' ) ), __( 'Awaiting payment check', 'nexora-commerce-toolkit' ), $url( array( 'status' => 'on-hold' ) ), 'is-warn' ),
		);
		if ( current_user_can( self::CAP_STORE ) ) {
			/* translators: %s: orders */
			$tiles[] = array( __( 'Placed today', 'nexora-commerce-toolkit' ), Nexora_Store::money( $k['today_s'] ), sprintf( _n( '%s order', '%s orders', $k['today_n'], 'nexora-commerce-toolkit' ), number_format_i18n( $k['today_n'] ) ), '', 'is-money' );
			/* translators: %s: orders */
			$tiles[] = array( __( 'Last 30 days', 'nexora-commerce-toolkit' ), Nexora_Store::money( $k['month_s'] ), sprintf( _n( '%s order', '%s orders', $k['month_n'], 'nexora-commerce-toolkit' ), number_format_i18n( $k['month_n'] ) ), '', 'is-money' );
		}
		?>
		<section class="nx-kpis" aria-label="<?php esc_attr_e( 'Order summary', 'nexora-commerce-toolkit' ); ?>">
			<header class="nx-kpis__head">
				<?php /* translators: 1: greeting, 2: name */ ?>
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

	/**
	 * Dashboard page.
	 */
	public static function page_dashboard() {
		global $wpdb;
		$manager = current_user_can( self::CAP_STORE );
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Store', 'nexora-commerce-toolkit' ) . '</h1><hr class="wp-header-end">';
		self::kpi_strip();

		if ( $manager ) {
			$todo = array();
			if ( ! Nexora_Store::get( 'address' ) ) {
				$todo[] = __( 'Add your store address, used for pickup and riders.', 'nexora-commerce-toolkit' );
			}
			if ( Nexora_Store::get( 'bank_enabled' ) && ! Nexora_Store::get( 'bank_details' ) ) {
				$todo[] = __( 'Add your bank details, or turn off Bank transfer.', 'nexora-commerce-toolkit' );
			}
			if ( Nexora_Store::get( 'ewallet_enabled' ) && ! Nexora_Store::get( 'ewallet_details' ) ) {
				$todo[] = __( 'Add your GCash or Maya number, or turn off that payment method.', 'nexora-commerce-toolkit' );
			}
			if ( $todo ) {
				echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Finish setting up your store:', 'nexora-commerce-toolkit' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
				foreach ( $todo as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=nexora-store-settings' ) ) . '">' . esc_html__( 'Open settings', 'nexora-commerce-toolkit' ) . '</a></p></div>';
			}
			$count = wp_count_posts( 'product' );
			if ( empty( $count->publish ) ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'You have no products yet.', 'nexora-commerce-toolkit' ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">' . esc_html__( 'Add a product', 'nexora-commerce-toolkit' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_store_seed' ), 'nexora_store_seed' ) ) . '">' . esc_html__( 'Load demo products', 'nexora-commerce-toolkit' ) . '</a></p></div>';
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$top = $wpdb->get_results( $wpdb->prepare( "SELECT i.name, SUM(i.qty) qty, SUM(i.line_total) total FROM {$wpdb->prefix}nx_order_items i INNER JOIN {$wpdb->prefix}nx_orders o ON o.id = i.order_id WHERE o.created_at >= %s AND o.status NOT IN ('cancelled','refunded','failed') GROUP BY i.name ORDER BY total DESC LIMIT 5", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
			$low = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title, p.post_parent, m.meta_value stock FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock' INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_manage_stock' AND s.meta_value = 'yes' WHERE p.post_type IN ('product','product_variation') AND p.post_status = 'publish' AND m.meta_value + 0 <= %d ORDER BY m.meta_value + 0 ASC LIMIT 8", (int) Nexora_Store::get( 'low_stock' ) ) );
			// phpcs:enable
			echo '<div class="nx-store-admin-grid">';
			echo '<section class="nx-store-admin-card"><h2>' . esc_html__( 'Top products, last 30 days', 'nexora-commerce-toolkit' ) . '</h2>';
			if ( $top ) {
				echo '<table class="widefat striped"><tbody>';
				foreach ( $top as $row ) {
					echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( number_format_i18n( (int) $row->qty ) ) . '</td><td>' . esc_html( Nexora_Store::money( $row->total ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . esc_html__( 'No sales yet.', 'nexora-commerce-toolkit' ) . '</p>';
			}
			echo '</section><section class="nx-store-admin-card"><h2>' . esc_html__( 'Low stock', 'nexora-commerce-toolkit' ) . '</h2>';
			if ( $low ) {
				echo '<table class="widefat striped"><tbody>';
				foreach ( $low as $row ) {
					$id = $row->post_parent ? (int) $row->post_parent : (int) $row->ID;
					echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></td><td>' . esc_html( number_format_i18n( (float) $row->stock ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . esc_html__( 'Nothing is running low.', 'nexora-commerce-toolkit' ) . '</p>';
			}
			echo '</section></div>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=nexora-store-orders' ) ) . '">' . esc_html__( 'Open orders', 'nexora-commerce-toolkit' ) . '</a></p></div>';
	}

	/* ----------------------------------------------------------------- orders */

	/**
	 * Orders list or one order.
	 */
	public static function page_orders() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		if ( $id ) {
			self::page_order( $id );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once NEXORA_TOOLKIT_DIR . 'includes/store/class-store-orders-table.php';
		$table = new Nexora_Store_Orders_Table();
		$table->prepare_items();
		$msg = get_transient( 'nexora_store_msg_' . get_current_user_id() );
		delete_transient( 'nexora_store_msg_' . get_current_user_id() );
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Orders', 'nexora-commerce-toolkit' ) . '</h1>';
		if ( current_user_can( self::CAP_STORE ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_store_export' ), 'nexora_store_export' ) ) . '">' . esc_html__( 'Export CSV', 'nexora-commerce-toolkit' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		if ( $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		self::kpi_strip();
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="nexora-store-orders">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search orders', 'nexora-commerce-toolkit' ), 'nx-order' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * One order.
	 *
	 * @param int $id Order.
	 */
	private static function page_order( $id ) {
		$order = Nexora_Store_Orders::get( $id );
		if ( ! $order ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Order not found', 'nexora-commerce-toolkit' ) . '</h1></div>';
			return;
		}
		$utc = static function ( $value ) {
			return $value ? wp_date( 'M j, g:i a', strtotime( $value . ' UTC' ) ) : '';
		};
		$f       = $order->fulfilment;
		$stopped = in_array( $order->status, array( 'cancelled', 'refunded', 'failed' ), true );
		$steps   = array(
			array( __( 'Placed', 'nexora-commerce-toolkit' ), $utc( $order->created_at ), true ),
			array( __( 'Paid', 'nexora-commerce-toolkit' ), $utc( $order->paid_at ), (bool) $order->paid_at ),
			array( __( 'Ready for pickup', 'nexora-commerce-toolkit' ), $utc( $order->ready_at ), in_array( $order->status, array( 'nx-ready', 'completed' ), true ) ),
			array( __( 'Completed', 'nexora-commerce-toolkit' ), $utc( $order->completed_at ), 'completed' === $order->status ),
		);
		$msg = get_transient( 'nexora_store_msg_' . get_current_user_id() );
		delete_transient( 'nexora_store_msg_' . get_current_user_id() );
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<?php /* translators: %d: order */ ?>
			<h1 class="wp-heading-inline"><?php echo esc_html( sprintf( __( 'Order #%d', 'nexora-commerce-toolkit' ), $order->id ) ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=nexora-store-orders' ) ); ?>"><?php esc_html_e( 'All orders', 'nexora-commerce-toolkit' ); ?></a>
			<hr class="wp-header-end">
			<?php if ( $msg ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<ol class="nx-progress<?php echo $stopped ? ' is-stopped' : ''; ?>" aria-label="<?php esc_attr_e( 'Order progress', 'nexora-commerce-toolkit' ); ?>">
				<?php foreach ( $steps as $s ) : ?>
					<li class="<?php echo $s[2] ? 'is-done' : ''; ?>">
						<span class="nx-progress__dot" aria-hidden="true"></span>
						<span class="nx-progress__label"><?php echo esc_html( $s[0] ); ?><?php echo $s[2] ? '' : '<span class="screen-reader-text"> ' . esc_html__( '(not yet)', 'nexora-commerce-toolkit' ) . '</span>'; ?></span>
						<?php if ( $s[2] && $s[1] ) : ?><span class="nx-progress__time"><?php echo esc_html( $s[1] ); ?></span><?php endif; ?>
					</li>
				<?php endforeach; ?>
				<?php if ( $stopped ) : ?>
					<li class="is-stopped"><span class="nx-progress__dot" aria-hidden="true"></span><span class="nx-progress__label"><?php echo esc_html( Nexora_Store_Orders::status_label( $order->status ) ); ?></span></li>
				<?php endif; ?>
			</ol>
			<div class="nx-store-order-layout">
				<div>
					<section class="nx-store-admin-card">
						<h2><?php esc_html_e( 'Items', 'nexora-commerce-toolkit' ); ?></h2>
						<table class="widefat striped">
							<thead><tr><th><?php esc_html_e( 'Item', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'SKU', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Price', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Qty', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Total', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( Nexora_Store_Orders::items( $order->id ) as $line ) : ?>
									<tr><td><?php echo esc_html( $line->name . ( $line->option_label ? ' — ' . $line->option_label : '' ) ); ?></td><td><?php echo esc_html( $line->sku ); ?></td><td><?php echo esc_html( Nexora_Store::money( $line->price ) ); ?></td><td><?php echo (int) $line->qty; ?></td><td><?php echo esc_html( Nexora_Store::money( $line->line_total ) ); ?></td></tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr><th colspan="4"><?php esc_html_e( 'Subtotal', 'nexora-commerce-toolkit' ); ?></th><td><?php echo esc_html( Nexora_Store::money( $order->subtotal ) ); ?></td></tr>
								<?php foreach ( $order->discounts as $d ) : ?>
									<tr><th colspan="4"><?php echo esc_html( $d['label'] ); ?></th><td>-<?php echo esc_html( Nexora_Store::money( $d['amount'] ) ); ?></td></tr>
								<?php endforeach; ?>
								<tr><th colspan="4"><?php echo esc_html( ! empty( $f['method'] ) ? $f['method'] : __( 'Delivery', 'nexora-commerce-toolkit' ) ); ?></th><td><?php echo esc_html( Nexora_Store::money( $order->shipping_total ) ); ?></td></tr>
								<tr><th colspan="4"><strong><?php esc_html_e( 'Total', 'nexora-commerce-toolkit' ); ?></strong></th><td><strong><?php echo esc_html( Nexora_Store::money( $order->total ) ); ?></strong></td></tr>
							</tfoot>
						</table>
						<?php if ( $order->note ) : ?>
							<p><strong><?php esc_html_e( 'Customer note:', 'nexora-commerce-toolkit' ); ?></strong> <?php echo esc_html( $order->note ); ?></p>
						<?php endif; ?>
					</section>
					<section class="nx-store-admin-card">
						<h2><?php esc_html_e( 'History', 'nexora-commerce-toolkit' ); ?></h2>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="nx-store-note-form">
							<input type="hidden" name="action" value="nexora_store_order"><input type="hidden" name="order" value="<?php echo (int) $order->id; ?>"><input type="hidden" name="do" value="note">
							<?php wp_nonce_field( 'nexora_store_order_' . $order->id ); ?>
							<label class="screen-reader-text" for="nx-note"><?php esc_html_e( 'Add a note', 'nexora-commerce-toolkit' ); ?></label>
							<input type="text" id="nx-note" name="note" class="regular-text" maxlength="500" placeholder="<?php esc_attr_e( 'Add a staff note (customers do not see it)', 'nexora-commerce-toolkit' ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Add note', 'nexora-commerce-toolkit' ); ?></button>
						</form>
						<ul class="nx-store-notes">
							<?php foreach ( Nexora_Store_Orders::notes( $order->id ) as $note ) : ?>
								<li><?php echo esc_html( $note->note ); ?> <small><?php echo esc_html( $note->author . ' · ' . $utc( $note->created_at ) ); ?></small></li>
							<?php endforeach; ?>
						</ul>
					</section>
				</div>
				<div>
					<section class="nx-store-admin-card">
						<h2><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></h2>
						<p><mark class="order-status status-<?php echo esc_attr( $order->status ); ?>"><span><?php echo esc_html( Nexora_Store_Orders::status_label( $order->status ) ); ?></span></mark></p>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<input type="hidden" name="action" value="nexora_store_order"><input type="hidden" name="order" value="<?php echo (int) $order->id; ?>"><input type="hidden" name="do" value="status">
							<?php wp_nonce_field( 'nexora_store_order_' . $order->id ); ?>
							<label for="nx-status"><?php esc_html_e( 'Change to', 'nexora-commerce-toolkit' ); ?></label>
							<select id="nx-status" name="status">
								<?php foreach ( Nexora_Store_Orders::statuses() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order->status, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Update', 'nexora-commerce-toolkit' ); ?></button>
						</form>
						<p class="description"><?php esc_html_e( 'The customer gets an email for Processing, Ready for pickup, Completed, Cancelled and Refunded. Cancelling or refunding puts the stock back. Refunded only records the status; send the money back yourself.', 'nexora-commerce-toolkit' ); ?></p>
					</section>
					<section class="nx-store-admin-card">
						<h2><?php esc_html_e( 'Customer', 'nexora-commerce-toolkit' ); ?></h2>
						<p><?php echo wp_kses( implode( '<br>', array_map( 'esc_html', Nexora_Store_Orders::address_lines( $order ) ) ), array( 'br' => array() ) ); ?></p>
						<p><a href="mailto:<?php echo esc_attr( $order->email ); ?>"><?php echo esc_html( $order->email ); ?></a><br><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $order->phone ) ); ?>"><?php echo esc_html( $order->phone ); ?></a></p>
						<?php /* translators: 1: payment, 2: reference */ ?>
						<p><strong><?php esc_html_e( 'Payment:', 'nexora-commerce-toolkit' ); ?></strong> <?php echo esc_html( $order->payment_title . ( $order->payment_ref ? ' · ' . $order->payment_ref : '' ) ); ?><?php echo $order->paid_at ? ' ✓' : ''; ?></p>
					</section>
					<section class="nx-store-admin-card">
						<h2><?php esc_html_e( 'Fulfilment', 'nexora-commerce-toolkit' ); ?></h2>
						<?php if ( ! empty( $f['type'] ) ) : ?>
							<p><span class="nx-ful nx-ful--<?php echo esc_attr( $f['type'] ); ?>"><span class="nx-ful__badge"><?php echo esc_html( ! empty( $f['method'] ) ? $f['method'] : $f['type'] ); ?></span></span></p>
							<dl class="nx-box__list">
								<?php if ( Nexora_Store_Orders::when( $order ) ) : ?><dt><?php esc_html_e( 'Time', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( Nexora_Store_Orders::when( $order ) ); ?></dd><?php endif; ?>
								<?php if ( ! empty( $f['courier'] ) ) : ?><dt><?php esc_html_e( 'Courier', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( $f['courier'] ); ?></dd><?php endif; ?>
								<?php if ( ! empty( $f['location'] ) ) : ?><dt><?php esc_html_e( 'Pickup point', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( $f['location'] ); ?></dd><?php endif; ?>
							</dl>
							<details class="nx-box__edit">
								<summary><?php esc_html_e( 'Reschedule', 'nexora-commerce-toolkit' ); ?></summary>
								<form method="post" action="<?php echo esc_url( $post_url ); ?>">
									<input type="hidden" name="action" value="nexora_store_order"><input type="hidden" name="order" value="<?php echo (int) $order->id; ?>"><input type="hidden" name="do" value="reschedule">
									<?php wp_nonce_field( 'nexora_store_order_' . $order->id ); ?>
									<p><label for="nx-ful-date"><?php esc_html_e( 'Date', 'nexora-commerce-toolkit' ); ?></label><input type="date" id="nx-ful-date" name="date" value="<?php echo esc_attr( isset( $f['date'] ) ? $f['date'] : '' ); ?>"></p>
									<p><label for="nx-ful-slot"><?php esc_html_e( 'Time slot (HH:MM-HH:MM)', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="nx-ful-slot" name="slot" value="<?php echo esc_attr( isset( $f['slot'] ) ? $f['slot'] : '' ); ?>" placeholder="10:00-12:00"></p>
									<button type="submit" class="button"><?php esc_html_e( 'Save', 'nexora-commerce-toolkit' ); ?></button>
								</form>
							</details>
						<?php endif; ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save an order action.
	 */
	public static function handle_order() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		$id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		if ( ! current_user_can( self::CAP_ORDERS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_store_order_' . $id );
		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$key   = 'nexora_store_msg_' . get_current_user_id();
		$order = Nexora_Store_Orders::get( $id );
		if ( $order ) {
			if ( 'status' === $do ) {
				$result = Nexora_Store_Orders::set_status( $id, isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '' );
				set_transient( $key, is_wp_error( $result ) ? $result->get_error_message() : __( 'Order updated.', 'nexora-commerce-toolkit' ), 60 );
			} elseif ( 'note' === $do ) {
				$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
				if ( '' !== $note ) {
					Nexora_Store_Orders::add_note( $id, mb_substr( $note, 0, 500 ) );
				}
			} elseif ( 'reschedule' === $do ) {
				global $wpdb;
				$date   = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
				$slot   = isset( $_POST['slot'] ) ? Nexora_Toolkit_Schedule::parse_slot( sanitize_text_field( wp_unslash( $_POST['slot'] ) ) ) : null;
				$f      = $order->fulfilment;
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$f['date'] = $date;
				}
				if ( $slot ) {
					$f['slot'] = $slot['key'];
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( "{$wpdb->prefix}nx_orders", array( 'fulfilment' => wp_json_encode( $f ), 'fulfilment_date' => isset( $f['date'] ) && $f['date'] ? $f['date'] : null ), array( 'id' => $id ) );
				/* translators: %s: new time */
				Nexora_Store_Orders::add_note( $id, sprintf( __( 'Rescheduled to %s.', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Schedule::when( isset( $f['date'] ) ? $f['date'] : '', isset( $f['slot'] ) ? $f['slot'] : '' ) ) );
				Nexora_Store_Orders::flush_kpis();
			}
		}
		// phpcs:enable
		wp_safe_redirect( admin_url( 'admin.php?page=nexora-store-orders&order=' . $id ) );
		exit;
	}

	/**
	 * Bulk and row actions from the list.
	 */
	public static function handle_bulk() {
		if ( ! current_user_can( self::CAP_ORDERS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_store_bulk', '_nx_bulk' );
		$ids    = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
		$status = isset( $_REQUEST['to'] ) ? sanitize_key( wp_unslash( $_REQUEST['to'] ) ) : '';
		$done   = 0;
		$fail   = 0;
		foreach ( array_slice( $ids, 0, 100 ) as $id ) {
			$result = Nexora_Store_Orders::set_status( $id, $status );
			if ( is_wp_error( $result ) ) {
				++$fail;
			} else {
				++$done;
			}
		}
		/* translators: 1: updated, 2: failed */
		set_transient( 'nexora_store_msg_' . get_current_user_id(), sprintf( __( '%1$d orders updated, %2$d could not be changed.', 'nexora-commerce-toolkit' ), $done, $fail ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=nexora-store-orders' ) );
		exit;
	}

	/**
	 * Export orders as CSV. Cells starting with a formula character are quoted so spreadsheets do not run them.
	 */
	public static function export_orders() {
		if ( ! current_user_can( self::CAP_STORE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_store_export' );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, created_at, status, email, phone, subtotal, discount_total, shipping_total, total, payment_title, payment_ref, fulfilment_type, fulfilment_date FROM {$wpdb->prefix}nx_orders ORDER BY id DESC LIMIT 20000", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=orders-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'order', 'created_utc', 'status', 'email', 'phone', 'subtotal', 'discount', 'delivery', 'total', 'payment', 'payment_ref', 'fulfilment', 'fulfilment_date' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					static function ( $value ) {
						return preg_match( '/^[=+\-@\t\r]/', (string) $value ) ? "'" . $value : $value;
					},
					$row
				)
			);
		}
		exit;
	}

	/* ----------------------------------------------------------------- products */

	/**
	 * Product editor boxes.
	 */
	public static function product_boxes() {
		add_meta_box( 'nx-product', __( 'Product data', 'nexora-commerce-toolkit' ), array( __CLASS__, 'product_box' ), 'product', 'normal', 'high' );
		add_meta_box( 'nx-product-gallery', __( 'Product gallery', 'nexora-commerce-toolkit' ), array( __CLASS__, 'gallery_box' ), 'product', 'side', 'low' );
		remove_meta_box( 'commentstatusdiv', 'product', 'normal' );
	}

	/**
	 * Main product form.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function product_box( $post ) {
		$product = Nexora_Store_Product::get( $post->ID );
		$m       = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $key, true );
		};
		$type    = $product && $product->is_type( 'variable' ) ? 'variable' : 'simple';
		$manage  = 'yes' === $m( '_manage_stock' ) || '' === $m( '_manage_stock' );
		$date    = static function ( $ts ) {
			return $ts ? wp_date( 'Y-m-d', (int) $ts ) : '';
		};
		$rows    = $product ? $product->get_variations( false ) : array();
		wp_nonce_field( 'nexora_product', 'nexora_product_nonce' );
		?>
		<div class="nx-pd" data-nx-product>
			<fieldset class="nx-pd__type">
				<legend><?php esc_html_e( 'How is it sold?', 'nexora-commerce-toolkit' ); ?></legend>
				<label><input type="radio" name="nx_type" value="simple" <?php checked( $type, 'simple' ); ?>> <?php esc_html_e( 'One price', 'nexora-commerce-toolkit' ); ?></label>
				<label><input type="radio" name="nx_type" value="variable" <?php checked( $type, 'variable' ); ?>> <?php esc_html_e( 'Several options (pack sizes, cuts)', 'nexora-commerce-toolkit' ); ?></label>
			</fieldset>

			<div class="nx-pd__simple">
				<div class="nx-pd__grid">
					<p><label for="nx_regular"><?php esc_html_e( 'Regular price (₱)', 'nexora-commerce-toolkit' ); ?></label><input type="number" min="0" step="0.01" id="nx_regular" name="nx_regular" value="<?php echo esc_attr( $m( '_regular_price' ) ); ?>"></p>
					<p><label for="nx_sale"><?php esc_html_e( 'Sale price (₱)', 'nexora-commerce-toolkit' ); ?></label><input type="number" min="0" step="0.01" id="nx_sale" name="nx_sale" value="<?php echo esc_attr( $m( '_sale_price' ) ); ?>"></p>
					<p><label for="nx_sale_from"><?php esc_html_e( 'Sale starts', 'nexora-commerce-toolkit' ); ?></label><input type="date" id="nx_sale_from" name="nx_sale_from" value="<?php echo esc_attr( $date( $m( '_sale_price_dates_from' ) ) ); ?>"></p>
					<p><label for="nx_sale_to"><?php esc_html_e( 'Sale ends', 'nexora-commerce-toolkit' ); ?></label><input type="date" id="nx_sale_to" name="nx_sale_to" value="<?php echo esc_attr( $date( $m( '_sale_price_dates_to' ) ) ); ?>"></p>
					<p><label for="nx_sku"><?php esc_html_e( 'SKU', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="nx_sku" name="nx_sku" value="<?php echo esc_attr( $m( '_sku' ) ); ?>"></p>
					<p><label for="nx_stock"><?php esc_html_e( 'Stock quantity', 'nexora-commerce-toolkit' ); ?></label><input type="number" min="0" step="1" id="nx_stock" name="nx_stock" value="<?php echo esc_attr( '' !== $m( '_stock' ) ? (string) (int) $m( '_stock' ) : '' ); ?>"></p>
				</div>
				<p><label><input type="checkbox" name="nx_manage" value="1" <?php checked( $manage ); ?>> <?php esc_html_e( 'Track stock (untick for items that never run out)', 'nexora-commerce-toolkit' ); ?></label></p>
				<p class="nx-pd__nostock"><label><input type="checkbox" name="nx_outofstock" value="1" <?php checked( 'outofstock', $m( '_stock_status' ) ); ?>> <?php esc_html_e( 'Out of stock (when stock is not tracked)', 'nexora-commerce-toolkit' ); ?></label></p>
			</div>

			<div class="nx-pd__variable">
				<table class="widefat nx-pd__rows">
					<thead><tr><th><?php esc_html_e( 'Option', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Regular ₱', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Sale ₱', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Stock', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'SKU', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Pack contents', 'nexora-commerce-toolkit' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'nexora-commerce-toolkit' ); ?></span></th></tr></thead>
					<tbody data-nx-rows>
						<?php foreach ( $rows as $i => $row ) : ?>
							<?php self::variation_row( $i, $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<template data-nx-row-template><?php self::variation_row( '__i__', null ); ?></template>
				<p><button type="button" class="button" data-nx-add-row><?php esc_html_e( 'Add option', 'nexora-commerce-toolkit' ); ?></button></p>
			</div>

			<div class="nx-pd__grid">
				<p><label for="nx_unit"><?php esc_html_e( 'Selling unit', 'nexora-commerce-toolkit' ); ?></label><select id="nx_unit" name="nx_unit"><?php foreach ( Nexora_Toolkit_Market::units() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $m( '_nexora_unit' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p>
				<p><label for="nx_origin"><?php esc_html_e( 'Origin', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="nx_origin" name="nx_origin" value="<?php echo esc_attr( $m( '_nexora_origin' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Benguet', 'nexora-commerce-toolkit' ); ?>"></p>
				<p><label for="nx_measure_unit"><?php esc_html_e( 'Compare price per', 'nexora-commerce-toolkit' ); ?></label><select id="nx_measure_unit" name="nx_measure_unit"><option value=""><?php esc_html_e( '— Off —', 'nexora-commerce-toolkit' ); ?></option><option value="kg" <?php selected( $m( '_nexora_measure_unit' ), 'kg' ); ?>>kg</option><option value="L" <?php selected( $m( '_nexora_measure_unit' ), 'L' ); ?>>L</option></select></p>
				<p class="nx-pd__simple"><label for="nx_measure"><?php esc_html_e( 'Pack contents (kg or L)', 'nexora-commerce-toolkit' ); ?></label><input type="number" min="0" step="any" id="nx_measure" name="nx_measure" value="<?php echo esc_attr( $m( '_nexora_measure' ) ); ?>"></p>
			</div>
			<p><label><input type="checkbox" name="nx_featured" value="1" <?php checked( $product && $product->is_featured() ); ?>> <?php esc_html_e( 'Featured on the homepage', 'nexora-commerce-toolkit' ); ?></label></p>
			<p><label><input type="checkbox" name="nx_hidden" value="1" <?php checked( has_term( 'exclude-from-catalog', 'product_visibility', $post ) ); ?>> <?php esc_html_e( 'Hide from the shop and search', 'nexora-commerce-toolkit' ); ?></label></p>
		</div>
		<?php
	}

	/**
	 * One option row.
	 *
	 * @param int|string                $i   Index.
	 * @param Nexora_Store_Product|null $row Variation.
	 */
	private static function variation_row( $i, $row ) {
		$name = static function ( $field ) use ( $i ) {
			return 'nx_rows[' . $i . '][' . $field . ']';
		};
		?>
		<tr>
			<td><input type="hidden" name="<?php echo esc_attr( $name( 'id' ) ); ?>" value="<?php echo esc_attr( $row ? (string) $row->get_id() : '0' ); ?>"><input type="text" aria-label="<?php esc_attr_e( 'Option name', 'nexora-commerce-toolkit' ); ?>" name="<?php echo esc_attr( $name( 'label' ) ); ?>" value="<?php echo esc_attr( $row ? $row->option_label() : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 5 kg', 'nexora-commerce-toolkit' ); ?>"></td>
			<td><input type="number" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Regular price', 'nexora-commerce-toolkit' ); ?>" name="<?php echo esc_attr( $name( 'regular' ) ); ?>" value="<?php echo esc_attr( $row ? $row->get_regular_price() : '' ); ?>"></td>
			<td><input type="number" min="0" step="0.01" aria-label="<?php esc_attr_e( 'Sale price', 'nexora-commerce-toolkit' ); ?>" name="<?php echo esc_attr( $name( 'sale' ) ); ?>" value="<?php echo esc_attr( $row ? (string) $row->get_meta( '_sale_price' ) : '' ); ?>"></td>
			<td><input type="number" min="0" step="1" aria-label="<?php esc_attr_e( 'Stock', 'nexora-commerce-toolkit' ); ?>" name="<?php echo esc_attr( $name( 'stock' ) ); ?>" value="<?php echo esc_attr( $row && $row->managing_stock() ? (string) (int) $row->get_stock_quantity() : '' ); ?>" placeholder="<?php esc_attr_e( 'Not tracked', 'nexora-commerce-toolkit' ); ?>"></td>
			<td><input type="text" aria-label="<?php esc_attr_e( 'SKU', 'nexora-commerce-toolkit' ); ?>" name="<?php echo esc_attr( $name( 'sku' ) ); ?>" value="<?php echo esc_attr( $row ? $row->get_sku() : '' ); ?>"></td>
			<td><input type="number" min="0" step="any" aria-label="<?php esc_attr_e( 'Pack contents', 'nexora-commerce-toolkit' ); ?>" name="<?php echo esc_attr( $name( 'measure' ) ); ?>" value="<?php echo esc_attr( $row ? (string) $row->get_meta( '_nexora_measure' ) : '' ); ?>"></td>
			<td><button type="button" class="button-link-delete" data-nx-remove-row aria-label="<?php esc_attr_e( 'Remove this option', 'nexora-commerce-toolkit' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Gallery picker.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function gallery_box( $post ) {
		$ids = (string) get_post_meta( $post->ID, '_product_image_gallery', true );
		echo '<div class="nx-pd-gallery" data-nx-gallery>';
		foreach ( array_filter( array_map( 'absint', explode( ',', $ids ) ) ) as $id ) {
			echo wp_get_attachment_image( $id, 'thumbnail' );
		}
		echo '</div><input type="hidden" name="nx_gallery" value="' . esc_attr( $ids ) . '" data-nx-gallery-input>';
		echo '<p><button type="button" class="button" data-nx-gallery-pick>' . esc_html__( 'Choose images', 'nexora-commerce-toolkit' ) . '</button></p>';
	}

	/**
	 * Decimal from a posted price, or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function dec( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? '' : (string) max( 0, round( (float) $value, 2 ) );
	}

	/**
	 * Save a product in WooCommerce's format.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_product( $post_id, $post ) {
		if ( ! isset( $_POST['nexora_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_product_nonce'] ) ), 'nexora_product' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		remove_action( 'save_post_product', array( __CLASS__, 'save_product' ), 20 );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- values are cast or sanitized as they are used.
		$type = isset( $_POST['nx_type'] ) && 'variable' === $_POST['nx_type'] ? 'variable' : 'simple';
		wp_set_object_terms( $post_id, $type, 'product_type' );

		update_post_meta( $post_id, '_nexora_unit', isset( $_POST['nx_unit'] ) && array_key_exists( sanitize_key( wp_unslash( $_POST['nx_unit'] ) ), Nexora_Toolkit_Market::units() ) ? sanitize_key( wp_unslash( $_POST['nx_unit'] ) ) : '' );
		update_post_meta( $post_id, '_nexora_origin', isset( $_POST['nx_origin'] ) ? sanitize_text_field( wp_unslash( $_POST['nx_origin'] ) ) : '' );
		$mu = isset( $_POST['nx_measure_unit'] ) ? sanitize_text_field( wp_unslash( $_POST['nx_measure_unit'] ) ) : '';
		update_post_meta( $post_id, '_nexora_measure_unit', in_array( $mu, array( 'kg', 'L' ), true ) ? $mu : '' );
		$gallery = isset( $_POST['nx_gallery'] ) ? implode( ',', array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['nx_gallery'] ) ) ) ) ) ) : '';
		update_post_meta( $post_id, '_product_image_gallery', $gallery );
		if ( '' === (string) get_post_meta( $post_id, 'total_sales', true ) ) {
			update_post_meta( $post_id, 'total_sales', 0 );
		}

		$visibility = array();
		if ( ! empty( $_POST['nx_featured'] ) ) {
			$visibility[] = 'featured';
		}
		if ( ! empty( $_POST['nx_hidden'] ) ) {
			$visibility[] = 'exclude-from-catalog';
			$visibility[] = 'exclude-from-search';
		}

		delete_post_meta( $post_id, '_price' );
		if ( 'simple' === $type ) {
			$regular = self::dec( isset( $_POST['nx_regular'] ) ? wp_unslash( $_POST['nx_regular'] ) : '' );
			$sale    = self::dec( isset( $_POST['nx_sale'] ) ? wp_unslash( $_POST['nx_sale'] ) : '' );
			update_post_meta( $post_id, '_regular_price', $regular );
			update_post_meta( $post_id, '_sale_price', $sale );
			$from = isset( $_POST['nx_sale_from'] ) ? self::day_ts( sanitize_text_field( wp_unslash( $_POST['nx_sale_from'] ) ), false ) : 0;
			$to   = isset( $_POST['nx_sale_to'] ) ? self::day_ts( sanitize_text_field( wp_unslash( $_POST['nx_sale_to'] ) ), true ) : 0;
			update_post_meta( $post_id, '_sale_price_dates_from', $from ? $from : '' );
			update_post_meta( $post_id, '_sale_price_dates_to', $to ? $to : '' );
			add_post_meta( $post_id, '_price', '' !== $sale ? $sale : $regular );
			update_post_meta( $post_id, '_sku', isset( $_POST['nx_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['nx_sku'] ) ) : '' );
			$measure = isset( $_POST['nx_measure'] ) ? (float) wp_unslash( $_POST['nx_measure'] ) : 0;
			update_post_meta( $post_id, '_nexora_measure', $measure > 0 ? (string) $measure : '' );
			$manage = ! empty( $_POST['nx_manage'] );
			update_post_meta( $post_id, '_manage_stock', $manage ? 'yes' : 'no' );
			if ( $manage ) {
				$stock = isset( $_POST['nx_stock'] ) ? absint( $_POST['nx_stock'] ) : 0;
				update_post_meta( $post_id, '_stock', $stock );
				$status = $stock > 0 ? 'instock' : 'outofstock';
			} else {
				delete_post_meta( $post_id, '_stock' );
				$status = ! empty( $_POST['nx_outofstock'] ) ? 'outofstock' : 'instock';
			}
			update_post_meta( $post_id, '_stock_status', $status );
			if ( 'outofstock' === $status ) {
				$visibility[] = 'outofstock';
			}
			// Remove leftovers if this product used to have options.
			foreach ( get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $post_id, 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids' ) ) as $old ) {
				wp_delete_post( $old, true );
			}
		} else {
			$in_stock = self::save_variations( $post_id, isset( $_POST['nx_rows'] ) && is_array( $_POST['nx_rows'] ) ? wp_unslash( $_POST['nx_rows'] ) : array() );
			delete_post_meta( $post_id, '_regular_price' );
			delete_post_meta( $post_id, '_sale_price' );
			update_post_meta( $post_id, '_manage_stock', 'no' );
			update_post_meta( $post_id, '_stock_status', $in_stock ? 'instock' : 'outofstock' );
			if ( ! $in_stock ) {
				$visibility[] = 'outofstock';
			}
		}
		// phpcs:enable
		wp_set_object_terms( $post_id, $visibility, 'product_visibility' );
		Nexora_Toolkit_Market::record( $post_id );
		Nexora_Store_Catalog::flush();
		add_action( 'save_post_product', array( __CLASS__, 'save_product' ), 20, 2 );
		unset( $post );
	}

	/**
	 * Date to the start or end of that day.
	 *
	 * @param string $value Y-m-d.
	 * @param bool   $end   End of day.
	 * @return int
	 */
	private static function day_ts( $value, $end ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return 0;
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Save option rows as product_variation posts, under one "Option" attribute.
	 *
	 * @param int   $post_id Product.
	 * @param array $rows    Posted rows.
	 * @return bool Any option in stock.
	 */
	private static function save_variations( $post_id, $rows ) {
		$existing = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $post_id, 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids' ) );
		$kept     = array();
		$labels   = array();
		$in_stock = false;
		$order    = 0;
		$attrs    = get_post_meta( $post_id, '_product_attributes', true );
		$attrs    = is_array( $attrs ) ? $attrs : array();
		// Keep the product's existing option attribute name, so existing products stay unchanged.
		$attr_key = '';
		foreach ( $attrs as $key => $attr ) {
			if ( ! empty( $attr['is_variation'] ) && empty( $attr['is_taxonomy'] ) ) {
				$attr_key = $key;
				break;
			}
		}
		if ( '' === $attr_key ) {
			$attr_key = 'option';
		}

		foreach ( $rows as $i => $row ) {
			if ( '__i__' === (string) $i || ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( ! $id || ! in_array( $id, array_map( 'intval', $existing ), true ) ) {
				$id = (int) wp_insert_post(
					array(
						'post_type'   => 'product_variation',
						'post_parent' => $post_id,
						'post_status' => 'publish',
						'post_title'  => get_the_title( $post_id ) . ' - ' . $label,
						'menu_order'  => $order,
					)
				);
			} else {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => get_the_title( $post_id ) . ' - ' . $label,
						'menu_order' => $order,
					)
				);
			}
			if ( ! $id ) {
				continue;
			}
			++$order;
			$kept[]   = $id;
			$labels[] = $label;
			update_post_meta( $id, 'attribute_' . $attr_key, $label );
			$regular = self::dec( isset( $row['regular'] ) ? $row['regular'] : '' );
			$sale    = self::dec( isset( $row['sale'] ) ? $row['sale'] : '' );
			update_post_meta( $id, '_regular_price', $regular );
			update_post_meta( $id, '_sale_price', $sale );
			update_post_meta( $id, '_price', '' !== $sale ? $sale : $regular );
			add_post_meta( $post_id, '_price', '' !== $sale ? $sale : $regular );
			update_post_meta( $id, '_sku', isset( $row['sku'] ) ? sanitize_text_field( $row['sku'] ) : '' );
			$measure = isset( $row['measure'] ) ? (float) $row['measure'] : 0;
			update_post_meta( $id, '_nexora_measure', $measure > 0 ? (string) $measure : '' );
			$stock = isset( $row['stock'] ) ? trim( (string) $row['stock'] ) : '';
			if ( '' === $stock ) {
				update_post_meta( $id, '_manage_stock', 'no' );
				delete_post_meta( $id, '_stock' );
				update_post_meta( $id, '_stock_status', 'instock' );
				$in_stock = true;
			} else {
				update_post_meta( $id, '_manage_stock', 'yes' );
				update_post_meta( $id, '_stock', absint( $stock ) );
				update_post_meta( $id, '_stock_status', absint( $stock ) > 0 ? 'instock' : 'outofstock' );
				$in_stock = $in_stock || absint( $stock ) > 0;
			}
		}
		foreach ( array_diff( array_map( 'intval', $existing ), $kept ) as $gone ) {
			wp_delete_post( $gone, true );
		}
		$name               = isset( $attrs[ $attr_key ]['name'] ) ? $attrs[ $attr_key ]['name'] : __( 'Option', 'nexora-commerce-toolkit' );
		$attrs[ $attr_key ] = array(
			'name'         => $name,
			'value'        => implode( ' | ', $labels ),
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 1,
			'is_taxonomy'  => 0,
		);
		update_post_meta( $post_id, '_product_attributes', $attrs );
		return $in_stock;
	}

	/**
	 * Product list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function product_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['nx_price'] = __( 'Price', 'nexora-commerce-toolkit' );
				$out['nx_stock'] = __( 'Stock', 'nexora-commerce-toolkit' );
				$out['nx_sku']   = __( 'SKU', 'nexora-commerce-toolkit' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	/**
	 * Product list cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public static function product_column( $column, $post_id ) {
		$product = Nexora_Store_Product::get( $post_id );
		if ( ! $product ) {
			return;
		}
		if ( 'nx_price' === $column ) {
			echo wp_kses_post( $product->get_price_html() );
		} elseif ( 'nx_sku' === $column ) {
			echo esc_html( $product->get_sku() );
		} elseif ( 'nx_stock' === $column ) {
			if ( ! $product->is_in_stock() ) {
				esc_html_e( 'Out of stock', 'nexora-commerce-toolkit' );
			} elseif ( ! $product->is_type( 'variable' ) && $product->managing_stock() ) {
				echo esc_html( number_format_i18n( (int) $product->get_stock_quantity() ) );
			} else {
				esc_html_e( 'In stock', 'nexora-commerce-toolkit' );
			}
		}
	}

	/**
	 * Category image field (new category).
	 */
	public static function category_field() {
		echo '<div class="form-field"><label>' . esc_html__( 'Image', 'nexora-commerce-toolkit' ) . '</label><div data-nx-cat-image><input type="hidden" name="nx_cat_thumb" value="" data-nx-cat-input><span data-nx-cat-preview></span> <button type="button" class="button" data-nx-cat-pick>' . esc_html__( 'Choose image', 'nexora-commerce-toolkit' ) . '</button></div></div>';
		wp_nonce_field( 'nexora_cat', 'nexora_cat_nonce' );
	}

	/**
	 * Category image field (edit).
	 *
	 * @param WP_Term $term Term.
	 */
	public static function category_field_edit( $term ) {
		$id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Image', 'nexora-commerce-toolkit' ) . '</th><td><div data-nx-cat-image><input type="hidden" name="nx_cat_thumb" value="' . esc_attr( $id ? (string) $id : '' ) . '" data-nx-cat-input><span data-nx-cat-preview>' . ( $id ? wp_get_attachment_image( $id, 'thumbnail' ) : '' ) . '</span> <button type="button" class="button" data-nx-cat-pick>' . esc_html__( 'Choose image', 'nexora-commerce-toolkit' ) . '</button></div>';
		wp_nonce_field( 'nexora_cat', 'nexora_cat_nonce' );
		echo '</td></tr>';
	}

	/**
	 * Save the category image.
	 *
	 * @param int $term_id Term.
	 */
	public static function save_category( $term_id ) {
		if ( isset( $_POST['nexora_cat_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_cat_nonce'] ) ), 'nexora_cat' ) && isset( $_POST['nx_cat_thumb'] ) ) {
			update_term_meta( $term_id, 'thumbnail_id', absint( $_POST['nx_cat_thumb'] ) );
		}
	}

	/* --------------------------------------------------------------- settings */

	/**
	 * Register the option.
	 */
	public static function register_settings() {
		register_setting(
			'nexora_store',
			Nexora_Store::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$out   = Nexora_Store::defaults();
		$bools = array( 'guest_checkout', 'registration', 'reviews', 'pickup_enabled', 'courier_enabled', 'delivery_enabled', 'cod_enabled', 'bank_enabled', 'ewallet_enabled' );
		$nums  = array( 'pickup_fee', 'courier_fee', 'delivery_fee', 'delivery_free_over' );
		$ints  = array( 'decimals', 'low_stock', 'lead_hours', 'days_ahead', 'hold_days' );
		$areas = array( 'address', 'pickup_locations', 'couriers', 'slots', 'bank_details', 'ewallet_details' );
		foreach ( $out as $key => $default ) {
			if ( in_array( $key, $bools, true ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			} elseif ( in_array( $key, $nums, true ) ) {
				$out[ $key ] = isset( $input[ $key ] ) ? max( 0, (float) $input[ $key ] ) : $default;
			} elseif ( in_array( $key, $ints, true ) ) {
				$out[ $key ] = isset( $input[ $key ] ) ? max( 0, (int) $input[ $key ] ) : $default;
			} elseif ( in_array( $key, $areas, true ) ) {
				$out[ $key ] = isset( $input[ $key ] ) ? sanitize_textarea_field( $input[ $key ] ) : $default;
			} elseif ( 'closed_days' === $key ) {
				$out[ $key ] = isset( $input[ $key ] ) ? array_values( array_intersect( array_map( 'strval', (array) $input[ $key ] ), array( '0', '1', '2', '3', '4', '5', '6' ) ) ) : array();
			} elseif ( 'order_email' === $key ) {
				$out[ $key ] = isset( $input[ $key ] ) && is_email( $input[ $key ] ) ? sanitize_email( $input[ $key ] ) : '';
			} elseif ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		$out['currency'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $out['currency'] ), 0, 3 ) );
		$out['decimals'] = min( 4, (int) $out['decimals'] );
		$slots           = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $out['slots'] ) as $line ) {
			$slot = Nexora_Toolkit_Schedule::parse_slot( $line );
			if ( $slot ) {
				$slots[] = $slot['key'];
			}
		}
		$out['slots'] = implode( "\n", array_unique( $slots ) );
		return $out;
	}

	/**
	 * Settings field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $type  text|number|textarea|checkbox.
	 * @param string $help  Help.
	 */
	private static function setting( $key, $label, $type = 'text', $help = '' ) {
		$name  = Nexora_Store::SETTINGS . '[' . $key . ']';
		$id    = 'nx-s-' . $key;
		$value = Nexora_Store::get( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'checkbox' === $type ) {
			printf( '<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ) );
		} elseif ( 'textarea' === $type ) {
			printf( '<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
		} else {
			printf( '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" %5$s>', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), 'number' === $type ? 'min="0" step="any" class="small-text"' : 'class="regular-text"' );
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Settings page.
	 */
	public static function page_settings() {
		global $wp_locale;
		$closed = array_map( 'strval', (array) Nexora_Store::get( 'closed_days' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Store settings', 'nexora-commerce-toolkit' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_store' ); ?>
				<h2><?php esc_html_e( 'Store', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::setting( 'address', __( 'Store address', 'nexora-commerce-toolkit' ), 'textarea', __( 'Riders collect here, and it is the pickup point when none are listed below.', 'nexora-commerce-toolkit' ) );
					self::setting( 'phone', __( 'Store phone', 'nexora-commerce-toolkit' ) );
					self::setting( 'order_email', __( 'Send new orders to', 'nexora-commerce-toolkit' ), 'email', __( 'Leave empty to use the site admin email.', 'nexora-commerce-toolkit' ) );
					self::setting( 'symbol', __( 'Currency symbol', 'nexora-commerce-toolkit' ) );
					self::setting( 'currency', __( 'Currency code', 'nexora-commerce-toolkit' ) );
					self::setting( 'decimals', __( 'Decimals', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'low_stock', __( 'Low stock alert at', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'hold_days', __( 'Auto-cancel unpaid orders after (days)', 'nexora-commerce-toolkit' ), 'number', __( 'Releases the stock. 0 = never.', 'nexora-commerce-toolkit' ) );
					?>
				</table>
				<h2><?php esc_html_e( 'Customers', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::setting( 'guest_checkout', __( 'Guest checkout', 'nexora-commerce-toolkit' ), 'checkbox', __( 'Allow orders without an account.', 'nexora-commerce-toolkit' ) );
					self::setting( 'registration', __( 'Customer registration', 'nexora-commerce-toolkit' ), 'checkbox' );
					self::setting( 'reviews', __( 'Product reviews', 'nexora-commerce-toolkit' ), 'checkbox' );
					?>
				</table>
				<h2><?php esc_html_e( 'Delivery and pickup', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::setting( 'pickup_enabled', __( 'Store pickup', 'nexora-commerce-toolkit' ), 'checkbox' );
					self::setting( 'pickup_title', __( 'Pickup name at checkout', 'nexora-commerce-toolkit' ) );
					self::setting( 'pickup_fee', __( 'Pickup fee', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'pickup_locations', __( 'Pickup points', 'nexora-commerce-toolkit' ), 'textarea', __( 'One per line: Name | Address | Opening hours', 'nexora-commerce-toolkit' ) );
					self::setting( 'courier_enabled', __( 'Courier delivery (customer books)', 'nexora-commerce-toolkit' ), 'checkbox' );
					self::setting( 'courier_title', __( 'Courier name at checkout', 'nexora-commerce-toolkit' ) );
					self::setting( 'courier_fee', __( 'Courier handling fee', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'couriers', __( 'Couriers customers can choose', 'nexora-commerce-toolkit' ), 'textarea', __( 'One per line.', 'nexora-commerce-toolkit' ) );
					self::setting( 'courier_contact', __( 'Contact number for riders', 'nexora-commerce-toolkit' ) );
					self::setting( 'delivery_enabled', __( 'Store delivery', 'nexora-commerce-toolkit' ), 'checkbox', __( 'Your own delivery, at a flat fee.', 'nexora-commerce-toolkit' ) );
					self::setting( 'delivery_title', __( 'Delivery name at checkout', 'nexora-commerce-toolkit' ) );
					self::setting( 'delivery_fee', __( 'Delivery fee', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'delivery_free_over', __( 'Free delivery over', 'nexora-commerce-toolkit' ), 'number', __( '0 = never free.', 'nexora-commerce-toolkit' ) );
					self::setting( 'lead_hours', __( 'Preparation time (hours)', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'days_ahead', __( 'Days customers can book ahead', 'nexora-commerce-toolkit' ), 'number' );
					self::setting( 'slots', __( 'Time slots', 'nexora-commerce-toolkit' ), 'textarea', __( 'One per line, 24-hour time, for example 08:00-10:00.', 'nexora-commerce-toolkit' ) );
					?>
					<tr><th scope="row"><?php esc_html_e( 'Closed on', 'nexora-commerce-toolkit' ); ?></th><td>
						<?php for ( $i = 0; $i < 7; $i++ ) : ?>
							<label style="margin-right:12px;"><input type="checkbox" name="<?php echo esc_attr( Nexora_Store::SETTINGS ); ?>[closed_days][]" value="<?php echo (int) $i; ?>" <?php checked( in_array( (string) $i, $closed, true ) ); ?>> <?php echo esc_html( $wp_locale->get_weekday( $i ) ); ?></label>
						<?php endfor; ?>
					</td></tr>
				</table>
				<h2><?php esc_html_e( 'Payments', 'nexora-commerce-toolkit' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These are manual methods: you confirm the payment yourself, then set the order to Processing. Automatic online card or wallet payments need a payment provider integration added through the nexora_store_gateways filter.', 'nexora-commerce-toolkit' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					self::setting( 'cod_enabled', __( 'Pay at pickup (cash)', 'nexora-commerce-toolkit' ), 'checkbox', __( 'Offered for store pickup only.', 'nexora-commerce-toolkit' ) );
					self::setting( 'cod_title', __( 'Name at checkout', 'nexora-commerce-toolkit' ) );
					self::setting( 'bank_enabled', __( 'Bank transfer', 'nexora-commerce-toolkit' ), 'checkbox' );
					self::setting( 'bank_title', __( 'Name at checkout', 'nexora-commerce-toolkit' ) );
					self::setting( 'bank_details', __( 'Bank details', 'nexora-commerce-toolkit' ), 'textarea', __( 'Shown at checkout, on the order page and in the order email.', 'nexora-commerce-toolkit' ) );
					self::setting( 'ewallet_enabled', __( 'GCash / Maya (send manually)', 'nexora-commerce-toolkit' ), 'checkbox', __( 'The customer sends the money, then enters the reference number at checkout.', 'nexora-commerce-toolkit' ) );
					self::setting( 'ewallet_title', __( 'Name at checkout', 'nexora-commerce-toolkit' ) );
					self::setting( 'ewallet_details', __( 'Account name and number', 'nexora-commerce-toolkit' ), 'textarea' );
					?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
