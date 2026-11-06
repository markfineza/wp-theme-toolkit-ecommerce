<?php
/**
 * Freshtastic Food Hub bootstrap.
 *
 * Child themes: copy any function below into your child theme's functions.php
 * only if it is wrapped in function_exists(); prefer the hooks and filters
 * documented in docs/CUSTOMIZATION.md.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXORA_VERSION', '2.6.0' );
define( 'NEXORA_DIR', get_template_directory() );
define( 'NEXORA_URI', get_template_directory_uri() );

/**
 * Whether WooCommerce is active and loaded.
 *
 * @return bool
 */
function nexora_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Whether the toolkit's built-in store is running instead of WooCommerce.
 *
 * @return bool
 */
function nexora_is_native_store() {
	return class_exists( 'Nexora_Store' ) && Nexora_Store::active();
}

/**
 * Whether any store engine is running.
 *
 * @return bool
 */
function nexora_has_store() {
	return nexora_is_woocommerce_active() || nexora_is_native_store();
}

/**
 * Product from the running engine.
 *
 * @param int $id Product.
 * @return WC_Product|Nexora_Store_Product|false|null
 */
function nexora_get_product( $id ) {
	return nexora_is_woocommerce_active() ? wc_get_product( $id ) : ( nexora_is_native_store() ? Nexora_Store_Product::get( $id ) : null );
}

/**
 * Price markup from the running engine.
 *
 * @param float $amount Amount.
 * @param array $args   wc_price() args.
 * @return string
 */
function nexora_price( $amount, $args = array() ) {
	return nexora_is_woocommerce_active() ? wc_price( $amount, $args ) : Nexora_Store::price( $amount );
}

/**
 * Account page URL.
 *
 * @param string $endpoint Endpoint.
 * @return string
 */
function nexora_account_url( $endpoint = '' ) {
	if ( nexora_is_woocommerce_active() ) {
		return $endpoint ? wc_get_account_endpoint_url( $endpoint ) : wc_get_page_permalink( 'myaccount' );
	}
	return nexora_is_native_store() ? Nexora_Store::url( 'account', $endpoint ) : home_url( '/' );
}

/**
 * Cart page URL.
 *
 * @return string
 */
function nexora_cart_url() {
	if ( nexora_is_woocommerce_active() ) {
		return wc_get_cart_url();
	}
	return nexora_is_native_store() ? Nexora_Store::url( 'cart' ) : home_url( '/' );
}

/**
 * Whether this is the cart or checkout page.
 *
 * @return bool
 */
function nexora_is_cart_or_checkout() {
	if ( nexora_is_woocommerce_active() ) {
		return is_cart() || is_checkout();
	}
	return nexora_is_native_store() && ( Nexora_Store::is_page( 'cart' ) || Nexora_Store::is_page( 'checkout' ) );
}

require NEXORA_DIR . '/inc/setup.php';
require NEXORA_DIR . '/inc/customizer.php';
require NEXORA_DIR . '/inc/assets.php';
require NEXORA_DIR . '/inc/template-tags.php';
require NEXORA_DIR . '/inc/brand.php';
require NEXORA_DIR . '/inc/integrations.php';
require NEXORA_DIR . '/inc/rest.php';

if ( nexora_has_store() ) {
	require NEXORA_DIR . '/inc/shop-common.php';
	require NEXORA_DIR . '/inc/market.php';
}
if ( nexora_is_native_store() ) {
	require NEXORA_DIR . '/inc/native-store.php';
}
if ( nexora_is_woocommerce_active() ) {
	require NEXORA_DIR . '/inc/woocommerce.php';
	require NEXORA_DIR . '/inc/shop-filters.php';
	require NEXORA_DIR . '/inc/checkout.php';
	require NEXORA_DIR . '/inc/account.php';
}
