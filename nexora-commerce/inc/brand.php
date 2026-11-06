<?php
/**
 * Freshtastic Food Hub brand: bundled logo, browser icons, staff login page, email header and
 * the move from the old default colours to the logo's navy and orange.
 *
 * A logo set in Customize → Site Identity, or a Site Icon, always takes priority over the bundled files.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of a bundled brand file.
 *
 * @param string $file File name in assets/images/brand.
 * @return string
 */
function nexora_brand_asset( $file ) {
	return NEXORA_URI . '/assets/images/brand/' . $file;
}

/**
 * Browser and home-screen icons, unless a Site Icon is set.
 */
function nexora_brand_icons() {
	if ( has_site_icon() ) {
		return;
	}
	printf( '<link rel="icon" href="%s" sizes="32x32">' . "\n", esc_url( nexora_brand_asset( 'favicon-32.png' ) ) );
	printf( '<link rel="icon" href="%s" sizes="512x512">' . "\n", esc_url( nexora_brand_asset( 'site-icon.png' ) ) );
	printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( nexora_brand_asset( 'apple-touch-icon.png' ) ) );
}
add_action( 'wp_head', 'nexora_brand_icons', 3 );
add_action( 'admin_head', 'nexora_brand_icons', 3 );
add_action( 'login_head', 'nexora_brand_icons', 3 );

/**
 * Logo and colours on wp-login.php, which staff use.
 */
function nexora_brand_login_page() {
	$logo = has_custom_logo() ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : nexora_brand_asset( 'logo-192.webp' );
	printf(
		'<style>body.login{background:%3$s}#login h1 a{background:url(%1$s) center/contain no-repeat;width:150px;height:144px}body.login .button-primary{background:%2$s;border-color:%2$s}body.login .button-primary:hover,body.login .button-primary:focus{background:%2$s;border-color:%2$s;filter:brightness(1.2)}</style>',
		esc_url( $logo ),
		esc_attr( nexora_option( 'color_primary' ) ),
		esc_attr( nexora_option( 'color_bg' ) )
	);
}
add_action( 'login_enqueue_scripts', 'nexora_brand_login_page' );
add_filter(
	'login_headerurl',
	static function () {
		return home_url( '/' );
	}
);
add_filter(
	'login_headertext',
	static function () {
		return get_bloginfo( 'name' );
	}
);

/**
 * Logo in WooCommerce emails while no header image is set.
 *
 * @param mixed $value Option value.
 * @return mixed
 */
function nexora_brand_email_image( $value ) {
	return '' === (string) $value ? nexora_brand_asset( 'logo-192.png' ) : $value;
}
add_filter( 'option_woocommerce_email_header_image', 'nexora_brand_email_image' );
add_filter( 'default_option_woocommerce_email_header_image', 'nexora_brand_email_image' );

/**
 * Navy email accent while WooCommerce's stock purple is still in place.
 *
 * @param mixed $value Option value.
 * @return mixed
 */
function nexora_brand_email_color( $value ) {
	return in_array( strtolower( (string) $value ), array( '', '#720eec', '#7f54b3' ), true ) ? nexora_option( 'color_primary' ) : $value;
}
add_filter( 'option_woocommerce_email_base_color', 'nexora_brand_email_color' );
add_filter( 'default_option_woocommerce_email_base_color', 'nexora_brand_email_color' );

/**
 * Colours saved in the Customizer while they were the old defaults follow the new brand.
 * Any other colour was chosen on purpose and is kept. Runs once.
 */
function nexora_brand_migrate_colors() {
	if ( get_theme_mod( 'nexora_brand_colors_v2' ) ) {
		return;
	}
	$old = array(
		'nexora_color_primary'   => '#15803d',
		'nexora_color_secondary' => '#f59e0b',
		'nexora_color_text'      => '#0b1220',
		'nexora_color_bg'        => '#f6f7f3',
		'nexora_color_dark'      => '#0b1220',
	);
	foreach ( $old as $mod => $value ) {
		if ( strtolower( (string) get_theme_mod( $mod, '' ) ) === $value ) {
			remove_theme_mod( $mod );
		}
	}
	set_theme_mod( 'nexora_brand_colors_v2', 1 );
}
add_action( 'init', 'nexora_brand_migrate_colors' );

/**
 * Logo in the built-in store's emails.
 *
 * @return string
 */
function nexora_brand_store_email_logo() {
	return nexora_brand_asset( 'logo-192.png' );
}
add_filter( 'nexora_store_email_logo', 'nexora_brand_store_email_logo' );
