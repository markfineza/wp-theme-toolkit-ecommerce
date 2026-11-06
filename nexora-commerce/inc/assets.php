<?php
/**
 * Front-end styles and scripts.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current request is a WooCommerce-rendered page that needs the shop stylesheet.
 *
 * @return bool
 */
function nexora_is_shop_context() {
	if ( nexora_is_native_store() ) {
		return Nexora_Store::is_store_request() || is_page( 'wishlist' );
	}
	if ( ! nexora_is_woocommerce_active() ) {
		return false;
	}
	return is_woocommerce() || is_cart() || is_checkout() || is_account_page() || ( function_exists( 'has_block' ) && ( has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ) ) );
}

/**
 * Enqueue front-end assets.
 */
function nexora_enqueue_assets() {
	$css_ver = NEXORA_VERSION . '.' . filemtime( NEXORA_DIR . '/assets/css/main.css' );
	$js_ver  = NEXORA_VERSION . '.' . filemtime( NEXORA_DIR . '/assets/js/main.js' );

	wp_enqueue_style( 'nexora-main', NEXORA_URI . '/assets/css/main.css', array(), $css_ver );
	wp_add_inline_style( 'nexora-main', nexora_brand_css() );
	wp_enqueue_style( 'nexora-market', NEXORA_URI . '/assets/css/market.css', array( 'nexora-main' ), NEXORA_VERSION . '.' . filemtime( NEXORA_DIR . '/assets/css/market.css' ) );

	if ( nexora_is_shop_context() ) {
		wp_enqueue_style( 'nexora-woocommerce', NEXORA_URI . '/assets/css/woocommerce.css', array( 'nexora-main' ), NEXORA_VERSION . '.' . filemtime( NEXORA_DIR . '/assets/css/woocommerce.css' ) );
		if ( nexora_is_native_store() ? ( Nexora_Store::is_page( 'checkout' ) || Nexora_Store::is_page( 'account' ) ) : ( is_checkout() || is_account_page() ) ) {
			wp_enqueue_style( 'nexora-forms', NEXORA_URI . '/assets/css/forms.css', array( 'nexora-woocommerce' ), NEXORA_VERSION . '.' . filemtime( NEXORA_DIR . '/assets/css/forms.css' ) );
		}
	}

	$deps = array();
	if ( nexora_is_woocommerce_active() ) {
		// Keeps the header count and mini-cart drawer in sync, including on cached pages.
		$deps[] = 'wc-cart-fragments';
	}

	wp_enqueue_script( 'nexora-main', NEXORA_URI . '/assets/js/main.js', $deps, $js_ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'nexora-main', 'nexoraData', nexora_script_data() );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'nexora_enqueue_assets' );

/**
 * Data passed to main.js. Intentionally contains no secrets or per-user data,
 * so it is safe on fully cached pages.
 *
 * @return array
 */
function nexora_script_data() {
	$data = array(
		'restUrl'     => esc_url_raw( rest_url( 'nexora/v1/' ) ),
		'homeUrl'     => esc_url_raw( home_url( '/' ) ),
		'woocommerce' => nexora_is_woocommerce_active(),
		'stickyAtc'   => (bool) nexora_option( 'sticky_atc' ),
		'i18n'        => array(
			'searching'     => __( 'Searching…', 'nexora-commerce' ),
			'noResults'     => __( 'No products found. Try a different search.', 'nexora-commerce' ),
			'viewAll'       => __( 'View all results', 'nexora-commerce' ),
			'resultsCount'  => /* translators: %d: number of results */ __( '%d products found', 'nexora-commerce' ),
			'added'         => __( 'Added to cart', 'nexora-commerce' ),
			'addError'      => __( 'Sorry, that item could not be added. Please try again.', 'nexora-commerce' ),
			'loading'       => __( 'Loading…', 'nexora-commerce' ),
			'filterError'   => __( 'Filters could not be applied. Reloading the page…', 'nexora-commerce' ),
			'countdownDays' => __( 'd', 'nexora-commerce' ),
			'freeShipping'  => __( 'You have unlocked free shipping!', 'nexora-commerce' ),
			'marketOpen'    => __( 'Market open', 'nexora-commerce' ),
			'marketClosed'  => __( 'Market closed', 'nexora-commerce' ),
			'opens'         => __( 'opens', 'nexora-commerce' ),
			'closes'        => __( 'closes', 'nexora-commerce' ),
			/* translators: %s: amount remaining */
			'freeShipLeft'  => __( 'Add %s more for free shipping', 'nexora-commerce' ),
		),
	);

	if ( nexora_is_woocommerce_active() ) {
		$data['shopUrl']        = esc_url_raw( wc_get_page_permalink( 'shop' ) );
		$data['cartUrl']        = esc_url_raw( wc_get_cart_url() );
		$data['freeShipping']   = nexora_store_offers_free_shipping() ? (float) nexora_option( 'free_shipping_threshold' ) : 0;
		$data['currency']       = array(
			'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'position'  => get_option( 'woocommerce_currency_pos', 'left' ),
			'decimals'  => wc_get_price_decimals(),
			'decimal'   => wc_get_price_decimal_separator(),
			'thousand'  => wc_get_price_thousand_separator(),
		);
	}

	if ( nexora_is_native_store() ) {
		$data['shopUrl']      = esc_url_raw( Nexora_Store::shop_url() );
		$data['cartUrl']      = esc_url_raw( Nexora_Store::url( 'cart' ) );
		$data['freeShipping'] = 0;
	}

	return apply_filters( 'nexora_script_data', $data );
}

/**
 * Preload the primary font files.
 */
function nexora_preload_fonts() {
	if ( ! nexora_option( 'load_local_fonts' ) ) {
		return;
	}
	$fonts = array_unique( array( nexora_option( 'font_body' ), nexora_option( 'font_heading' ) ) );
	foreach ( $fonts as $font ) {
		if ( in_array( $font, array( 'inter', 'manrope' ), true ) ) {
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( NEXORA_URI . '/assets/fonts/' . $font . '-variable.woff2' ) );
		}
	}
}
add_action( 'wp_head', 'nexora_preload_fonts', 2 );

/**
 * Preload the homepage hero image (it is the LCP element, so it is never lazy-loaded).
 */
function nexora_preload_hero() {
	if ( ! is_front_page() || ! in_array( 'hero', nexora_enabled_home_sections(), true ) ) {
		return;
	}
	$id = (int) nexora_option( 'hero_image' );
	if ( $id ) {
		$src    = wp_get_attachment_image_url( $id, 'full' );
		$srcset = wp_get_attachment_image_srcset( $id, 'full' );
		if ( $src ) {
			printf( '<link rel="preload" as="image" href="%1$s" imagesrcset="%2$s" imagesizes="(min-width: 1024px) 55vw, 100vw" fetchpriority="high">' . "\n", esc_url( $src ), esc_attr( (string) $srcset ) );
		}
	}
}
add_action( 'wp_head', 'nexora_preload_hero', 3 );

/**
 * Block editor: brand variables and fonts so the editor matches the front end.
 */
function nexora_editor_assets() {
	wp_add_inline_style( 'wp-block-library', nexora_brand_css() );
}
add_action( 'enqueue_block_editor_assets', 'nexora_editor_assets' );
