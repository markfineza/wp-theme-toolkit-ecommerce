<?php
/**
 * Consent-aware analytics and conversion events: GA4 (gtag), Google Tag Manager (dataLayer),
 * Google Ads purchase conversions and Meta Pixel.
 *
 * Duplicate protection:
 *  - Channels already handled by a detected plugin are skipped (unless "Send events anyway").
 *  - AJAX add-to-cart events are sent client-side only; form/Store API adds are queued server-side.
 *  - Purchase is sent once per order (order meta flag), using the real order total and currency.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracking module.
 */
class Nexora_Toolkit_Tracking {

	const SESSION_KEY = 'nexora_pending_events';

	/**
	 * Events collected while rendering this request.
	 *
	 * @var array
	 */
	private static $events = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_action( 'wp_body_open', array( __CLASS__, 'gtm_noscript' ) );
		add_action( 'wp', array( __CLASS__, 'collect_page_events' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'purchase' ), 5 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'queue_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'queue_remove_from_cart' ), 10, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_filter( 'woocommerce_loop_add_to_cart_args', array( __CLASS__, 'button_data' ), 20, 2 );
	}

	/**
	 * Known plugins that already send these events, keyed by plugin name => channels handled.
	 *
	 * @return array<string,string[]>
	 */
	public static function detected() {
		$found = array();
		if ( defined( 'WC_GLA_VERSION' ) ) {
			$found['Google for WooCommerce'] = array( 'ga4', 'ads' );
		}
		if ( class_exists( 'WC_Google_Analytics_Integration' ) || defined( 'WC_GOOGLE_ANALYTICS_INTEGRATION_VERSION' ) ) {
			$found['Google Analytics for WooCommerce'] = array( 'ga4' );
		}
		if ( class_exists( 'WC_Facebookcommerce' ) ) {
			$found['Meta for WooCommerce'] = array( 'pixel' );
		}
		if ( defined( 'GTM4WP_VERSION' ) ) {
			$found['GTM4WP'] = array( 'gtm' );
		}
		if ( defined( 'PYS_FREE_VERSION' ) || defined( 'PYS_VERSION' ) ) {
			$found['PixelYourSite'] = array( 'pixel', 'ga4' );
		}
		if ( defined( 'GOOGLESITEKIT_VERSION' ) ) {
			$found['Site Kit by Google'] = array( 'ga4' );
		}
		return apply_filters( 'nexora_tracking_detected_plugins', $found );
	}

	/**
	 * Names of detected plugins (for the settings screen).
	 *
	 * @return string[]
	 */
	public static function detected_plugins() {
		return array_keys( self::detected() );
	}

	/**
	 * Enabled channels with their IDs.
	 *
	 * @return array
	 */
	public static function channels() {
		$taken = array();
		if ( ! Nexora_Toolkit_Settings::get( 'tracking_force' ) ) {
			foreach ( self::detected() as $channels ) {
				$taken = array_merge( $taken, $channels );
			}
		}
		$c = array(
			'gtm'      => Nexora_Toolkit_Settings::get( 'gtm_id' ),
			'ga4'      => Nexora_Toolkit_Settings::get( 'ga4_id' ),
			'ads'      => Nexora_Toolkit_Settings::get( 'google_ads_id' ),
			'adsLabel' => Nexora_Toolkit_Settings::get( 'google_ads_label' ),
			'pixel'    => Nexora_Toolkit_Settings::get( 'meta_pixel_id' ),
		);
		foreach ( array( 'gtm', 'ga4', 'ads', 'pixel' ) as $ch ) {
			if ( in_array( $ch, $taken, true ) ) {
				$c[ $ch ] = '';
			}
		}
		return apply_filters( 'nexora_tracking_channels', $c );
	}

	/**
	 * Whether any channel is active.
	 *
	 * @return bool
	 */
	private static function active() {
		$c = self::channels();
		return (bool) ( $c['gtm'] || $c['ga4'] || $c['ads'] || $c['pixel'] );
	}

	/**
	 * Skip tracking for logged-in shop managers so test orders do not pollute data.
	 *
	 * @return bool
	 */
	private static function skip_user() {
		return (bool) apply_filters( 'nexora_tracking_skip_user', current_user_can( 'manage_woocommerce' ) );
	}

	/**
	 * Head: verification meta, consent defaults and tag loaders.
	 */
	public static function head() {
		$gsc = Nexora_Toolkit_Settings::get( 'gsc_verification' );
		if ( $gsc ) {
			printf( '<meta name="google-site-verification" content="%s">' . "\n", esc_attr( $gsc ) );
		}
		if ( ! self::active() || self::skip_user() ) {
			return;
		}
		$c       = self::channels();
		$consent = Nexora_Toolkit_Settings::get( 'consent_mode' );
		$gtag_id = $c['ga4'] ? $c['ga4'] : $c['ads'];

		echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
		if ( 'none' !== $consent ) {
			echo "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});\n";
		}
		echo "</script>\n";

		if ( $c['gtm'] ) {
			printf(
				"<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','%s');</script>\n",
				esc_js( $c['gtm'] )
			);
		}
		if ( $gtag_id ) {
			printf( '<script async src="%s"></script>' . "\n", esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $gtag_id ) ) );
			echo "<script>gtag('js',new Date());";
			if ( $c['ga4'] ) {
				printf( "gtag('config','%s');", esc_js( $c['ga4'] ) );
			}
			if ( $c['ads'] ) {
				printf( "gtag('config','%s');", esc_js( $c['ads'] ) );
			}
			echo "</script>\n";
		}
		if ( $c['pixel'] ) {
			echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');";
			if ( 'none' !== $consent ) {
				echo "fbq('consent','revoke');";
			}
			printf( "fbq('init','%s');fbq('track','PageView');</script>\n", esc_js( $c['pixel'] ) );
		}
	}

	/**
	 * GTM noscript fallback.
	 */
	public static function gtm_noscript() {
		$c = self::channels();
		if ( $c['gtm'] && ! self::skip_user() ) {
			printf( '<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden" title="Google Tag Manager"></iframe></noscript>', esc_url( 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $c['gtm'] ) ) );
		}
	}

	/**
	 * GA4-style item array for a product.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $quantity Quantity.
	 * @param float|null $price    Unit price override (e.g. from the order).
	 * @return array
	 */
	public static function item( $product, $quantity = 1, $price = null ) {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$cats   = wc_get_product_terms( $parent ? $parent->get_id() : $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$item   = array(
			'item_id'       => $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
			'item_name'     => $parent ? $parent->get_name() : $product->get_name(),
			'price'         => round( (float) ( null === $price ? wc_get_price_to_display( $product ) : $price ), wc_get_price_decimals() ),
			'quantity'      => (int) $quantity,
			'item_category' => $cats ? $cats[0] : '',
		);
		if ( $product->is_type( 'variation' ) ) {
			$item['item_variant'] = wc_get_formatted_variation( $product, true, false );
		}
		return $item;
	}

	/**
	 * Events known when the page renders: view_item, search, begin_checkout, plus queued events.
	 */
	public static function collect_page_events() {
		if ( is_admin() || ! self::active() || self::skip_user() ) {
			return;
		}
		$currency = get_woocommerce_currency();

		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product ) {
				$item           = self::item( $product );
				self::$events[] = array( 'name' => 'view_item', 'params' => array( 'currency' => $currency, 'value' => $item['price'], 'items' => array( $item ) ) );
			}
		}
		if ( is_search() && get_search_query() ) {
			self::$events[] = array( 'name' => 'search', 'params' => array( 'search_term' => get_search_query() ) );
		}
		if ( is_checkout() && ! is_order_received_page() && WC()->cart && ! WC()->cart->is_empty() ) {
			$items = array();
			foreach ( WC()->cart->get_cart() as $line ) {
				$items[] = self::item( $line['data'], $line['quantity'] );
			}
			self::$events[] = array(
				'name'   => 'begin_checkout',
				'params' => array( 'currency' => $currency, 'value' => round( (float) WC()->cart->get_total( 'edit' ), wc_get_price_decimals() ), 'items' => $items ),
			);
		}
		if ( WC()->session ) {
			$queued = (array) WC()->session->get( self::SESSION_KEY, array() );
			if ( $queued ) {
				self::$events = array_merge( self::$events, $queued );
				WC()->session->set( self::SESSION_KEY, array() );
			}
		}
	}

	/**
	 * Whether this request is a WooCommerce AJAX add-to-cart (handled client-side instead).
	 *
	 * @return bool
	 */
	private static function is_wc_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_REQUEST['wc-ajax'] ) || wp_doing_ajax();
	}

	/**
	 * Queue an event for the next page render.
	 *
	 * @param array $event Event.
	 */
	private static function queue( $event ) {
		if ( ! WC()->session || ! self::active() || self::skip_user() ) {
			return;
		}
		$queued   = (array) WC()->session->get( self::SESSION_KEY, array() );
		$queued[] = $event;
		WC()->session->set( self::SESSION_KEY, array_slice( $queued, -10 ) );
	}

	/**
	 * Non-AJAX add to cart (single product form, Store API).
	 *
	 * @param string $key          Cart item key.
	 * @param int    $product_id   Product.
	 * @param int    $quantity     Qty.
	 * @param int    $variation_id Variation.
	 */
	public static function queue_add_to_cart( $key, $product_id, $quantity, $variation_id = 0 ) {
		if ( self::is_wc_ajax() ) {
			return;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( $product ) {
			$item = self::item( $product, $quantity );
			self::queue( array( 'name' => 'add_to_cart', 'params' => array( 'currency' => get_woocommerce_currency(), 'value' => $item['price'] * $item['quantity'], 'items' => array( $item ) ) ) );
		}
	}

	/**
	 * Remove from cart (classic cart page). Mini-cart AJAX removals are sent client-side.
	 *
	 * @param string  $key  Cart item key.
	 * @param WC_Cart $cart Cart.
	 */
	public static function queue_remove_from_cart( $key, $cart ) {
		if ( self::is_wc_ajax() || ! isset( $cart->removed_cart_contents[ $key ] ) ) {
			return;
		}
		$line    = $cart->removed_cart_contents[ $key ];
		$product = wc_get_product( ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'] );
		if ( $product ) {
			$item = self::item( $product, $line['quantity'] );
			self::queue( array( 'name' => 'remove_from_cart', 'params' => array( 'currency' => get_woocommerce_currency(), 'value' => $item['price'] * $item['quantity'], 'items' => array( $item ) ) ) );
		}
	}

	/**
	 * Purchase event, once per order, from the actual order totals.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_nexora_tracked' ) || $order->has_status( 'failed' ) || ! self::active() || self::skip_user() ) {
			return;
		}
		$items = array();
		foreach ( $order->get_items() as $line ) {
			$product = $line->get_product();
			if ( $product ) {
				$qty     = max( 1, (int) $line->get_quantity() );
				$items[] = self::item( $product, $qty, (float) $order->get_line_subtotal( $line, $order->get_prices_include_tax() ) / $qty );
			}
		}
		self::$events[] = array(
			'name'   => 'purchase',
			'params' => array(
				'transaction_id' => (string) $order->get_order_number(),
				'currency'       => $order->get_currency(),
				'value'          => round( (float) $order->get_total(), wc_get_price_decimals() ),
				'tax'            => round( (float) $order->get_total_tax(), wc_get_price_decimals() ),
				'shipping'       => round( (float) $order->get_shipping_total(), wc_get_price_decimals() ),
				'coupon'         => implode( ',', $order->get_coupon_codes() ),
				'items'          => $items,
			),
		);
		$order->update_meta_data( '_nexora_tracked', gmdate( 'c' ) );
		$order->save_meta_data();
	}

	/**
	 * SKU/category data on loop buttons for client-side add_to_cart events.
	 *
	 * @param array      $args    Args.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function button_data( $args, $product ) {
		if ( self::active() ) {
			$item                                   = self::item( $product );
			$args['attributes']['data-nexora-item'] = wp_json_encode( $item );
		}
		return $args;
	}

	/**
	 * Footer: config + events, then the dispatcher script.
	 */
	public static function footer() {
		if ( ! self::active() || self::skip_user() ) {
			return;
		}
		$c      = self::channels();
		$config = array(
			'channels' => array(
				'gtm'      => (bool) $c['gtm'],
				'gtag'     => (bool) ( $c['ga4'] || $c['ads'] ),
				'ads'      => $c['ads'] && $c['adsLabel'] ? $c['ads'] . '/' . $c['adsLabel'] : '',
				'pixel'    => (bool) $c['pixel'],
			),
			'consent'  => Nexora_Toolkit_Settings::get( 'consent_mode' ),
			'currency' => get_woocommerce_currency(),
			'events'   => self::$events,
		);
		printf( "<script>window.nexoraTracking=%s;</script>\n", wp_json_encode( $config ) );
		wp_print_script_tag( array( 'src' => esc_url( NEXORA_TOOLKIT_URL . 'assets/js/tracking.js?ver=' . NEXORA_TOOLKIT_VERSION ), 'defer' => true ) );
	}
}
