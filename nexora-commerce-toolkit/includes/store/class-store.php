<?php
/**
 * Built-in store: a complete shop that runs without the WooCommerce plugin.
 *
 * Only one engine runs at a time. A site that is not running WooCommerce when the toolkit first loads
 * starts on the built-in store; switch under Settings → Freshtastic Toolkit. With WooCommerce active it stays
 * completely dormant and the WooCommerce integration works as before.
 *
 * The catalogue uses WooCommerce's data format (product, product_variation, product_cat, shop_coupon
 * and their meta), so products, categories, coupons and customers survive switching in either direction.
 * Orders and carts live in their own tables.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap, settings, formatting and URLs.
 */
class Nexora_Store {

	const ENGINE   = 'nexora_commerce_engine';
	const SETTINGS = 'nexora_store_settings';
	const PAGES    = 'nexora_store_pages';
	const DB_V     = 4;

	/**
	 * Whether the built-in store is running on this request.
	 *
	 * @return bool
	 */
	public static function active() {
		return 'native' === self::engine() && ! class_exists( 'WooCommerce' );
	}

	/**
	 * Chosen engine. define( 'NEXORA_STORE_ENGINE', 'native' ) in wp-config.php starts a new site on the
	 * built-in store, so WooCommerce is never installed.
	 *
	 * @return string native|woocommerce
	 */
	public static function engine() {
		if ( defined( 'NEXORA_STORE_ENGINE' ) ) {
			return (string) NEXORA_STORE_ENGINE;
		}
		$engine = get_option( self::ENGINE, '' );
		if ( 'native' !== $engine && 'woocommerce' !== $engine ) {
			// No choice yet: keep a site that already runs WooCommerce on it, otherwise start on the
			// built-in store so neither WooCommerce nor the demo importer asks for it. Saved so the
			// choice does not flip later when WooCommerce is deactivated.
			$engine = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'native';
			update_option( self::ENGINE, $engine );
		}
		return $engine;
	}

	/**
	 * Hook up. Called once from the toolkit's boot.
	 */
	public static function boot() {
		Nexora_Store_Admin::init_switch();
		if ( ! self::active() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
		Nexora_Store_Catalog::init();
		Nexora_Store_Cart::init();
		Nexora_Store_Coupons::init();
		Nexora_Store_Orders::init();
		Nexora_Store_Checkout::init();
		Nexora_Store_Account::init();
		Nexora_Store_Admin::init();
		Nexora_Store_Importer::init();

		add_action( 'init', array( __CLASS__, 'endpoints' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'the_content', array( __CLASS__, 'page_content' ), 9999 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'get_the_archive_title', array( __CLASS__, 'archive_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
		add_action( 'nexora_store_daily', array( __CLASS__, 'daily' ) );
		add_shortcode( 'nexora_cart', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'nexora_checkout', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'nexora_account', array( __CLASS__, 'shortcode' ) );
		if ( ! wp_next_scheduled( 'nexora_store_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nexora_store_daily' );
		}
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'currency'          => 'PHP',
			'symbol'            => '₱',
			'decimals'          => 2,
			'address'           => '',
			'phone'             => '',
			'order_email'       => '',
			'guest_checkout'    => 1,
			'registration'      => 1,
			'reviews'           => 1,
			'low_stock'         => 5,
			'hold_days'         => 0,
			'pickup_enabled'    => 1,
			'pickup_title'      => 'Store pickup',
			'pickup_fee'        => 0,
			'pickup_locations'  => '',
			'courier_enabled'   => 1,
			'courier_title'     => 'Courier delivery — you book the rider',
			'courier_fee'       => 0,
			'couriers'          => "Lalamove\nGrab Express\nTransportify\nBorzo\nJoyRide\nMove It",
			'courier_contact'   => '',
			'delivery_enabled'  => 0,
			'delivery_title'    => 'Store delivery',
			'delivery_fee'      => 120,
			'delivery_free_over' => 0,
			'lead_hours'        => 2,
			'days_ahead'        => 6,
			'closed_days'       => array(),
			'slots'             => "08:00-10:00\n10:00-12:00\n13:00-15:00\n15:00-17:00",
			'cod_enabled'       => 1,
			'cod_title'         => 'Pay at pickup (cash)',
			'bank_enabled'      => 1,
			'bank_title'        => 'Bank transfer',
			'bank_details'      => '',
			'ewallet_enabled'   => 0,
			'ewallet_title'     => 'GCash / Maya',
			'ewallet_details'   => '',
		);
	}

	/**
	 * Read a setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		static $cache = null;
		if ( null === $cache || doing_action( 'update_option_' . self::SETTINGS ) ) {
			$cache = wp_parse_args( (array) get_option( self::SETTINGS, array() ), self::defaults() );
		}
		return isset( $cache[ $key ] ) ? $cache[ $key ] : null;
	}

	/* ---------------------------------------------------------- formatting */

	/**
	 * Money as plain text, e.g. ₱1,234.00.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		$dec = max( 0, (int) self::get( 'decimals' ) );
		return (float) $amount < 0
			? '-' . self::get( 'symbol' ) . number_format( abs( (float) $amount ), $dec )
			: self::get( 'symbol' ) . number_format( (float) $amount, $dec );
	}

	/**
	 * Money as markup that the theme's price styles recognise.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function price( $amount ) {
		return '<span class="woocommerce-Price-amount amount"><bdi>' . esc_html( self::money( $amount ) ) . '</bdi></span>';
	}

	/**
	 * Round to the store's precision.
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	public static function round( $amount ) {
		return round( (float) $amount, max( 0, (int) self::get( 'decimals' ) ) );
	}

	/* ------------------------------------------------------- pages and URLs */

	/**
	 * Page ID for cart|checkout|account.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public static function page_id( $key ) {
		$pages = (array) get_option( self::PAGES, array() );
		return isset( $pages[ $key ] ) ? (int) $pages[ $key ] : 0;
	}

	/**
	 * Page URL.
	 *
	 * @param string $key      cart|checkout|account.
	 * @param string $endpoint Optional endpoint path.
	 * @return string
	 */
	public static function url( $key, $endpoint = '' ) {
		$id   = self::page_id( $key );
		$base = $id ? get_permalink( $id ) : home_url( '/' );
		return $endpoint ? trailingslashit( $base ) . ltrim( $endpoint, '/' ) . ( get_option( 'permalink_structure' ) ? '/' : '' ) : $base;
	}

	/**
	 * Shop URL.
	 *
	 * @return string
	 */
	public static function shop_url() {
		$url = get_post_type_archive_link( 'product' );
		return $url ? $url : home_url( '/' );
	}

	/**
	 * Whether the current request is one of the store's pages.
	 *
	 * @param string $key cart|checkout|account, or '' for any.
	 * @return bool
	 */
	public static function is_page( $key = '' ) {
		$keys = $key ? array( $key ) : array( 'cart', 'checkout', 'account' );
		foreach ( $keys as $k ) {
			$id = self::page_id( $k );
			if ( $id && is_page( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Any shop, product, cart, checkout or account page (used by maintenance mode and the theme).
	 *
	 * @return bool
	 */
	public static function is_store_request() {
		return is_singular( 'product' ) || is_post_type_archive( 'product' ) || is_tax( array( 'product_cat', 'product_tag' ) ) || self::is_page();
	}

	/**
	 * Register account and order endpoints (same names as WooCommerce, so links keep working after a switch).
	 */
	public static function endpoints() {
		foreach ( array( 'order-received', 'orders', 'view-order', 'edit-address', 'edit-account', 'wishlist', 'quotations', 'customer-logout' ) as $endpoint ) {
			add_rewrite_endpoint( $endpoint, EP_PAGES );
		}
	}

	/**
	 * Flush rewrite rules after a switch.
	 */
	public static function maybe_flush() {
		if ( get_option( 'nexora_store_flush' ) ) {
			delete_option( 'nexora_store_flush' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Replace the cart, checkout and account pages' content while this store runs. The stored page
	 * content is left alone, so WooCommerce's own blocks are still there after switching back.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function page_content( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( self::is_page( 'cart' ) ) {
			return Nexora_Store_Cart::render();
		}
		if ( self::is_page( 'checkout' ) ) {
			return Nexora_Store_Checkout::render();
		}
		if ( self::is_page( 'account' ) ) {
			return Nexora_Store_Account::render();
		}
		return $content;
	}

	/**
	 * Store shortcodes. On the store's own pages page_content() replaces the whole content anyway, so the
	 * shortcode stays empty there: rendering twice would use up one-time notices in the discarded copy.
	 *
	 * @param array|string $atts Attributes.
	 * @param string       $text Enclosed text.
	 * @param string       $tag  Shortcode.
	 * @return string
	 */
	public static function shortcode( $atts, $text, $tag ) {
		$page = substr( $tag, strlen( 'nexora_' ) );
		if ( self::is_page( $page ) && in_the_loop() && is_main_query() ) {
			return '';
		}
		switch ( $page ) {
			case 'cart':
				return Nexora_Store_Cart::render();
			case 'checkout':
				return Nexora_Store_Checkout::render();
		}
		return Nexora_Store_Account::render();
	}

	/**
	 * Body classes shared with the theme's shop styles.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		$classes[] = 'nexora-store';
		if ( self::is_store_request() ) {
			$classes[] = 'woocommerce-page';
		}
		if ( self::is_page( 'cart' ) ) {
			$classes[] = 'woocommerce-cart';
		}
		if ( self::is_page( 'checkout' ) ) {
			$classes[] = 'woocommerce-checkout';
			if ( ! get_query_var( 'order-received', false ) ) {
				$classes[] = 'nx-checkout-page';
			}
		}
		if ( self::is_page( 'account' ) ) {
			$classes[] = 'woocommerce-account';
		}
		return $classes;
	}

	/**
	 * Plain titles for the shop and category pages (used in breadcrumbs).
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function archive_title( $title ) {
		if ( is_post_type_archive( 'product' ) ) {
			return __( 'Shop', 'nexora-commerce-toolkit' );
		}
		return is_tax( array( 'product_cat', 'product_tag' ) ) ? single_term_title( '', false ) : $title;
	}

	/**
	 * Cart, checkout and account pages are personal; never let a page cache keep them.
	 */
	public static function no_cache() {
		if ( self::is_page() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/**
	 * Front-end assets.
	 */
	public static function assets() {
		wp_enqueue_style( 'nexora-fulfilment', NEXORA_TOOLKIT_URL . 'assets/css/fulfilment.css', array(), NEXORA_TOOLKIT_VERSION );
		wp_enqueue_style( 'nexora-store', NEXORA_TOOLKIT_URL . 'assets/css/store.css', array( 'nexora-fulfilment' ), NEXORA_TOOLKIT_VERSION );
		wp_enqueue_script( 'nexora-store', NEXORA_TOOLKIT_URL . 'assets/js/store.js', array(), NEXORA_TOOLKIT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'nexora-store',
			'nexoraStore',
			array(
				'rest'    => esc_url_raw( rest_url( 'nexora/v1/store/' ) ),
				'cartUrl' => esc_url_raw( self::url( 'cart' ) ),
				'i18n'    => array(
					'added'    => __( 'Added to cart', 'nexora-commerce-toolkit' ),
					'viewCart' => __( 'View cart', 'nexora-commerce-toolkit' ),
					'error'    => __( 'Sorry, that item could not be added. Please try again.', 'nexora-commerce-toolkit' ),
					'choose'   => __( 'Please choose an option.', 'nexora-commerce-toolkit' ),
				),
			)
		);
	}

	/**
	 * Theme icon when available.
	 *
	 * @param string $name Icon.
	 * @param int    $size Size.
	 * @return string SVG markup or ''.
	 */
	public static function icon( $name, $size = 20 ) {
		return function_exists( 'nexora_icon' ) ? nexora_icon( $name, $size ) : '';
	}

	/* --------------------------------------------------------------- install */

	/**
	 * Create or update tables, roles and pages when the schema version changes.
	 */
	public static function maybe_install() {
		if ( (int) get_option( 'nexora_store_db' ) >= self::DB_V ) {
			return;
		}
		self::install();
	}

	/**
	 * Install.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}nx_carts (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token char(64) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				contents longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY token (token),
				KEY user_id (user_id),
				KEY updated_at (updated_at)
			) $c;
			CREATE TABLE {$wpdb->prefix}nx_orders (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_key char(32) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				currency char(3) NOT NULL DEFAULT 'PHP',
				subtotal decimal(14,2) NOT NULL DEFAULT 0,
				discount_total decimal(14,2) NOT NULL DEFAULT 0,
				shipping_total decimal(14,2) NOT NULL DEFAULT 0,
				total decimal(14,2) NOT NULL DEFAULT 0,
				discounts longtext NULL,
				payment_method varchar(40) NOT NULL DEFAULT '',
				payment_title varchar(190) NOT NULL DEFAULT '',
				payment_ref varchar(100) NOT NULL DEFAULT '',
				paid_at datetime NULL,
				fulfilment_type varchar(20) NOT NULL DEFAULT '',
				fulfilment_date date NULL,
				fulfilment longtext NULL,
				address longtext NULL,
				note text NULL,
				ready_at datetime NULL,
				completed_at datetime NULL,
				stock_reduced tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY user_id (user_id),
				KEY email (email),
				KEY fulfilment_date (fulfilment_date),
				KEY created_at (created_at)
			) $c;
			CREATE TABLE {$wpdb->prefix}nx_order_items (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(255) NOT NULL DEFAULT '',
				option_label varchar(1000) NOT NULL DEFAULT '',
				contents text NULL,
				sku varchar(100) NOT NULL DEFAULT '',
				qty int(11) NOT NULL DEFAULT 1,
				price decimal(14,2) NOT NULL DEFAULT 0,
				line_total decimal(14,2) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY product_id (product_id)
			) $c;
			CREATE TABLE {$wpdb->prefix}nx_order_notes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				note text NOT NULL,
				author varchar(100) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id)
			) $c;"
		);

		// Same capability names as WooCommerce, so nothing needs changing after a switch.
		$manage = array();
		foreach ( array( 'product', 'shop_coupon', 'shop_order' ) as $type ) {
			foreach ( array( 'edit_%s', 'read_%s', 'delete_%s', 'edit_%ss', 'edit_others_%ss', 'publish_%ss', 'read_private_%ss', 'delete_%ss', 'delete_private_%ss', 'delete_published_%ss', 'delete_others_%ss', 'edit_private_%ss', 'edit_published_%ss' ) as $cap ) {
				$manage[] = sprintf( $cap, $type );
			}
		}
		$manage = array_merge( $manage, array( 'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms', 'nx_manage_store', 'nx_manage_orders', 'view_admin_dashboard' ) );
		if ( ! get_role( 'customer' ) ) {
			add_role( 'customer', __( 'Customer', 'nexora-commerce-toolkit' ), array( 'read' => true ) );
		}
		if ( ! get_role( 'shop_manager' ) ) {
			add_role( 'shop_manager', __( 'Shop manager', 'nexora-commerce-toolkit' ), array( 'read' => true, 'upload_files' => true, 'unfiltered_html' => true, 'list_users' => true ) );
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				foreach ( $manage as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		Nexora_Toolkit_Order_Desk::ensure_role();
		$desk = get_role( Nexora_Toolkit_Order_Desk::ROLE );
		if ( $desk ) {
			$desk->add_cap( 'nx_manage_orders' );
		}

		self::create_pages();
		update_option( 'nexora_store_db', self::DB_V );
		update_option( 'nexora_store_flush', 1 );
	}

	/**
	 * Reuse WooCommerce's pages when they exist, otherwise create the store's pages.
	 */
	private static function create_pages() {
		$pages = (array) get_option( self::PAGES, array() );
		$defs  = array(
			'cart'     => array( 'cart', __( 'Cart', 'nexora-commerce-toolkit' ), 'woocommerce_cart_page_id', '[nexora_cart]' ),
			'checkout' => array( 'checkout', __( 'Checkout', 'nexora-commerce-toolkit' ), 'woocommerce_checkout_page_id', '[nexora_checkout]' ),
			'account'  => array( 'my-account', __( 'My account', 'nexora-commerce-toolkit' ), 'woocommerce_myaccount_page_id', '[nexora_account]' ),
		);
		foreach ( $defs as $key => $def ) {
			if ( ! empty( $pages[ $key ] ) && 'page' === get_post_type( $pages[ $key ] ) && 'publish' === get_post_status( $pages[ $key ] ) ) {
				continue;
			}
			$id = (int) get_option( $def[2] );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				$page = get_page_by_path( $def[0] );
				$id   = $page && 'publish' === $page->post_status ? $page->ID : 0;
			}
			if ( ! $id ) {
				$id = (int) wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_name'    => $def[0],
						'post_title'   => $def[1],
						'post_content' => '<!-- wp:shortcode -->' . $def[3] . '<!-- /wp:shortcode -->',
					)
				);
			}
			$pages[ $key ] = $id;
		}
		update_option( self::PAGES, $pages );

		// Pages the quotation and wishlist features look for.
		$extra = array(
			'request-a-quote' => array( __( 'Request a quote', 'nexora-commerce-toolkit' ), '[nexora_rfq_form]' ),
			'wishlist'        => array( __( 'Wishlist', 'nexora-commerce-toolkit' ), '[nexora_wishlist]' ),
		);
		foreach ( $extra as $slug => $def ) {
			if ( ! get_page_by_path( $slug ) ) {
				wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_name'    => $slug,
						'post_title'   => $def[0],
						'post_content' => '<!-- wp:shortcode -->' . $def[1] . '<!-- /wp:shortcode -->',
					)
				);
			}
		}
	}

	/**
	 * Daily housekeeping: forget abandoned guest carts and release stock from unpaid orders.
	 */
	public static function daily() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}nx_carts WHERE user_id = 0 AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Nexora_Store_Orders::cancel_stale();
	}
}
