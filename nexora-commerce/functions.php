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

define( 'NEXORA_VERSION', '2.2.0' );
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

require NEXORA_DIR . '/inc/setup.php';
require NEXORA_DIR . '/inc/customizer.php';
require NEXORA_DIR . '/inc/assets.php';
require NEXORA_DIR . '/inc/template-tags.php';
require NEXORA_DIR . '/inc/brand.php';
require NEXORA_DIR . '/inc/integrations.php';
require NEXORA_DIR . '/inc/rest.php';

if ( nexora_is_woocommerce_active() ) {
	require NEXORA_DIR . '/inc/woocommerce.php';
	require NEXORA_DIR . '/inc/market.php';
	require NEXORA_DIR . '/inc/shop-filters.php';
	require NEXORA_DIR . '/inc/checkout.php';
	require NEXORA_DIR . '/inc/account.php';
}
