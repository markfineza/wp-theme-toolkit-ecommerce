<?php
/**
 * Third-party compatibility: SEO plugins and the Freshtastic Food Hub Toolkit.
 *
 * The theme never outputs Open Graph tags or schema itself: WooCommerce provides product
 * structured data and SEO plugins provide social/canonical metadata. That avoids duplicates.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a known SEO plugin is handling metadata.
 *
 * @return bool
 */
function nexora_seo_plugin_active() {
	$active = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	return (bool) apply_filters( 'nexora_seo_plugin_active', $active );
}

/**
 * Declare Yoast/Rank Math breadcrumb support.
 */
function nexora_seo_supports() {
	add_theme_support( 'yoast-seo-breadcrumbs' );
	add_theme_support( 'rank-math-breadcrumbs' );
}
add_action( 'after_setup_theme', 'nexora_seo_supports' );

/**
 * Minimal meta description when no SEO plugin is installed (disable with the
 * `nexora_output_meta_description` filter).
 */
function nexora_meta_description() {
	if ( nexora_seo_plugin_active() || ! apply_filters( 'nexora_output_meta_description', true ) ) {
		return;
	}

	$description = '';
	if ( is_front_page() ) {
		$description = get_bloginfo( 'description' );
		if ( '' === trim( (string) $description ) || __( 'Just another WordPress site' ) === $description ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- compare with core's default tagline.
			$description = nexora_option( 'hero_text' );
		}
	} elseif ( nexora_is_woocommerce_active() && is_product() ) {
		$product     = wc_get_product( get_queried_object_id() );
		$description = $product ? $product->get_short_description() : '';
	} elseif ( is_singular() ) {
		$description = get_the_excerpt( get_queried_object_id() );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$description = term_description();
	}

	$description = trim( wp_strip_all_tags( (string) $description, true ) );
	if ( '' !== $description ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( wp_html_excerpt( $description, 158, '…' ) ) );
	}
}
add_action( 'wp_head', 'nexora_meta_description', 1 );

/**
 * Admin notice pointing to the companion plugin (dismissible per user).
 */
function nexora_toolkit_notice() {
	if ( defined( 'NEXORA_TOOLKIT_VERSION' ) || ! current_user_can( 'install_plugins' ) || get_user_meta( get_current_user_id(), 'nexora_dismiss_toolkit_notice', true ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'themes', 'plugins' ), true ) ) {
		return;
	}
	$dismiss = wp_nonce_url( add_query_arg( 'nexora_dismiss_toolkit', '1' ), 'nexora_dismiss_toolkit' );
	printf(
		'<div class="notice notice-info"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
		esc_html__( 'Freshtastic Food Hub works best with its companion plugin, Freshtastic Food Hub Toolkit (included in the theme package). It adds the demo importer, wishlist, newsletter providers and analytics events.', 'nexora-commerce' ),
		esc_url( $dismiss ),
		esc_html__( 'Dismiss', 'nexora-commerce' )
	);
}
add_action( 'admin_notices', 'nexora_toolkit_notice' );

/**
 * Handle the toolkit notice dismissal.
 */
function nexora_handle_toolkit_dismiss() {
	if ( ! isset( $_GET['nexora_dismiss_toolkit'] ) || ! current_user_can( 'install_plugins' ) ) {
		return;
	}
	check_admin_referer( 'nexora_dismiss_toolkit' );
	update_user_meta( get_current_user_id(), 'nexora_dismiss_toolkit_notice', 1 );
	wp_safe_redirect( remove_query_arg( array( 'nexora_dismiss_toolkit', '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'nexora_handle_toolkit_dismiss' );
