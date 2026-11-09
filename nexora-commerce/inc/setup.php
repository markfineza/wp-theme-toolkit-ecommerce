<?php
/**
 * Theme setup: supports, menus, image sizes, widget areas and notices.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register theme supports and navigation menus.
 */
function nexora_setup() {
	load_theme_textdomain( 'nexora-commerce', NEXORA_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 64,
			'width'       => 220,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);

	add_editor_style( 'assets/css/editor.css' );

	// The toolkit renders courier / pickup fields in this theme's checkout slot.
	add_theme_support( 'nexora-checkout' );

	add_theme_support(
		'woocommerce',
		array(
			'thumbnail_image_width' => 600,
			'single_image_width'    => 900,
			'product_grid'          => array(
				'default_rows'    => 4,
				'min_rows'        => 1,
				'default_columns' => 4,
				'min_columns'     => 2,
				'max_columns'     => 5,
			),
		)
	);
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	add_image_size( 'nexora-card', 600, 600, true );
	add_image_size( 'nexora-wide', 1600, 900, true );
	add_image_size( 'nexora-post', 800, 500, true );

	register_nav_menus(
		array(
			'primary'   => __( 'Primary navigation', 'nexora-commerce' ),
			'mobile'    => __( 'Mobile drawer (falls back to Primary)', 'nexora-commerce' ),
			'footer-1'  => __( 'Footer: Shop', 'nexora-commerce' ),
			'footer-2'  => __( 'Footer: Customer service', 'nexora-commerce' ),
			'footer-3'  => __( 'Footer: Company', 'nexora-commerce' ),
			'legal'     => __( 'Footer: Legal links', 'nexora-commerce' ),
		)
	);
}
add_action( 'after_setup_theme', 'nexora_setup' );

/**
 * Content width for embeds.
 */
function nexora_content_width() {
	$GLOBALS['content_width'] = apply_filters( 'nexora_content_width', 820 );
}
add_action( 'after_setup_theme', 'nexora_content_width', 0 );

/**
 * Widget areas.
 */
function nexora_widgets_init() {
	$shared = array(
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	);

	register_sidebar(
		array_merge(
			$shared,
			array(
				'name'        => __( 'Blog sidebar', 'nexora-commerce' ),
				'id'          => 'sidebar-blog',
				'description' => __( 'Shown beside blog posts and archives.', 'nexora-commerce' ),
			)
		)
	);
	register_sidebar(
		array_merge(
			$shared,
			array(
				'name'        => __( 'Shop filters (extra)', 'nexora-commerce' ),
				'id'          => 'sidebar-shop',
				'description' => __( 'Optional widgets shown below the built-in shop filters.', 'nexora-commerce' ),
			)
		)
	);
	register_sidebar(
		array_merge(
			$shared,
			array(
				'name'          => __( 'Footer extra', 'nexora-commerce' ),
				'id'            => 'footer-extra',
				'description'   => __( 'Optional widgets shown in a row above the footer bottom bar.', 'nexora-commerce' ),
				'before_title'  => '<h2 class="widget-title footer-title">',
			)
		)
	);
}
add_action( 'widgets_init', 'nexora_widgets_init' );

/**
 * Admin notice when WooCommerce is not active.
 */
function nexora_missing_woocommerce_notice() {
	if ( nexora_has_store() || ! current_user_can( 'install_plugins' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && in_array( $screen->id, array( 'update', 'plugin-install' ), true ) ) {
		return;
	}
	$toolkit = class_exists( 'Nexora_Store' );
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
		esc_html__( 'Freshtastic Food Hub:', 'nexora-commerce' ),
		esc_html__( 'No store is running. The blog, pages and navigation still work, but shop sections, cart and product templates are hidden. The Freshtastic Food Hub Toolkit plugin has a built-in store, so WooCommerce is optional.', 'nexora-commerce' ),
		esc_url( $toolkit ? admin_url( 'options-general.php?page=nexora-toolkit#nexora-engine' ) : admin_url( 'plugins.php' ) ),
		$toolkit ? esc_html__( 'Turn on the built-in store', 'nexora-commerce' ) : esc_html__( 'Activate the Freshtastic Food Hub Toolkit', 'nexora-commerce' )
	);
}
add_action( 'admin_notices', 'nexora_missing_woocommerce_notice' );

/**
 * Body classes for header behaviour and context.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function nexora_body_classes( $classes ) {
	if ( nexora_option( 'header_sticky' ) ) {
		$classes[] = 'has-sticky-header';
	}
	if ( nexora_announcement_is_visible() ) {
		$classes[] = 'has-announcement';
	}
	if ( ! nexora_has_store() ) {
		$classes[] = 'no-woocommerce';
	}
	$classes[] = 'card-style-' . sanitize_html_class( nexora_option( 'card_style' ) );
	return $classes;
}
add_filter( 'body_class', 'nexora_body_classes' );

/**
 * Optionally create WebP sub-sizes for uploaded JPEGs when the server supports it.
 *
 * @param array $formats Map of mime => output mime.
 * @return array
 */
function nexora_webp_output_format( $formats ) {
	if ( nexora_option( 'webp_subsizes' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		$formats['image/jpeg'] = 'image/webp';
	}
	return $formats;
}
add_filter( 'image_editor_output_format', 'nexora_webp_output_format' );

/**
 * Add a pingback header for single posts.
 */
function nexora_pingback_header() {
	if ( is_singular() && pings_open() ) {
		printf( '<link rel="pingback" href="%s">', esc_url( get_bloginfo( 'pingback_url' ) ) );
	}
}
add_action( 'wp_head', 'nexora_pingback_header' );

/**
 * The footer Legal menu always offers the Privacy Policy, even if the menu was built without it.
 *
 * @param string   $items Menu items HTML.
 * @param stdClass $args  Menu arguments.
 * @return string
 */
function nexora_legal_menu_privacy( $items, $args ) {
	$url = get_privacy_policy_url();
	if ( 'legal' === $args->theme_location && $url && false === strpos( $items, $url ) ) {
		$items .= '<li class="menu-item">' . get_the_privacy_policy_link() . '</li>';
	}
	return $items;
}
add_filter( 'wp_nav_menu_items', 'nexora_legal_menu_privacy', 10, 2 );
