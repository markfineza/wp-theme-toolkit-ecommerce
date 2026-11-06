<?php
/**
 * Lightweight wishlist.
 *
 * Guests: stored in the browser (localStorage) — nothing personal is sent to the server.
 * Logged-in customers: stored in user meta and synced through the REST API with the core
 * `wp_rest` nonce; a guest list is merged into the account after login.
 *
 * Display: [nexora_wishlist] shortcode (used on the "Wishlist" page and the My Account tab).
 * Rendering uses WooCommerce's own [products] shortcode, so it works with any theme.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wishlist module.
 */
class Nexora_Toolkit_Wishlist {

	const META = '_nexora_wishlist';
	const MAX  = 100;

	/**
	 * Hook up.
	 */
	public static function init() {
		if ( ! Nexora_Toolkit_Settings::get( 'wishlist_enabled' ) ) {
			return;
		}
		add_filter( 'nexora_wishlist_enabled', '__return_true' );
		add_filter( 'nexora_wishlist_url', array( __CLASS__, 'url' ) );
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_wishlist_endpoint', array( __CLASS__, 'account_tab' ) );
		add_shortcode( 'nexora_wishlist', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Wishlist page URL: a page containing the shortcode (slug "wishlist") or the account tab.
	 *
	 * @param string $url Default URL.
	 * @return string
	 */
	public static function url( $url ) {
		$page = get_page_by_path( 'wishlist' );
		return $page && 'publish' === $page->post_status ? get_permalink( $page ) : $url;
	}

	/**
	 * My Account endpoint.
	 */
	public static function endpoint() {
		add_rewrite_endpoint( 'wishlist', EP_PAGES );
	}

	/**
	 * Register the query var with WooCommerce.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars['wishlist'] = 'wishlist';
		return $vars;
	}

	/**
	 * Account menu item (before Logout).
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu_item( $items ) {
		$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();
		unset( $items['customer-logout'] );
		$items['wishlist'] = __( 'Wishlist', 'nexora-commerce-toolkit' );
		return $items + $logout;
	}

	/**
	 * Account tab content.
	 */
	public static function account_tab() {
		echo self::shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Stored IDs for a user (only visible, existing products).
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function get_ids( $user_id ) {
		$ids = array_map( 'absint', (array) get_user_meta( $user_id, self::META, true ) );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Render product IDs through WooCommerce's [products] shortcode.
	 *
	 * @param int[] $ids IDs.
	 * @return string
	 */
	public static function render_products( $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', $ids ), static function ( $id ) {
			$p = Nexora_Toolkit_Market::product( $id );
			return $p && $p->is_visible();
		} ) );
		if ( ! $ids ) {
			return '<div class="nx-empty"><h2>' . esc_html__( 'Your wishlist is empty', 'nexora-commerce-toolkit' ) . '</h2><p>' . esc_html__( 'Tap the heart on any product to save it for later.', 'nexora-commerce-toolkit' ) . '</p><p><a class="button" href="' . esc_url( ( Nexora_Store::active() ? Nexora_Store::shop_url() : wc_get_page_permalink( 'shop' ) ) ) . '">' . esc_html__( 'Browse products', 'nexora-commerce-toolkit' ) . '</a></p></div>';
		}
		if ( Nexora_Store::active() ) {
			ob_start();
			Nexora_Store_Catalog::grid( $ids, 4 );
			return '<div class="woocommerce">' . ob_get_clean() . '</div>';
		}
		return do_shortcode( '[products ids="' . esc_attr( implode( ',', $ids ) ) . '" orderby="post__in" columns="4" limit="' . count( $ids ) . '"]' );
	}

	/**
	 * Shortcode output. Guests get a container filled client-side from localStorage.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( is_user_logged_in() ) {
			return '<div class="nx-wishlist-page" data-nexora-wishlist-page data-server="1">' . self::render_products( self::get_ids( get_current_user_id() ) ) . '</div>';
		}
		return '<div class="nx-wishlist-page" data-nexora-wishlist-page><div class="nx-skeleton-grid" aria-hidden="true"><div class="nx-skeleton"></div><div class="nx-skeleton"></div><div class="nx-skeleton"></div><div class="nx-skeleton"></div></div><noscript>' . esc_html__( 'Enable JavaScript or sign in to see your wishlist.', 'nexora-commerce-toolkit' ) . '</noscript></div>';
	}

	/**
	 * REST routes.
	 */
	public static function routes() {
		register_rest_route(
			'nexora/v1',
			'/wishlist',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static function () {
						return rest_ensure_response( array( 'ids' => self::get_ids( get_current_user_id() ) ) );
					},
					'permission_callback' => 'is_user_logged_in',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => 'is_user_logged_in',
					'args'                => array(
						'ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'default' => array() ),
						'mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'merge' ), 'default' => 'replace' ),
					),
				),
			)
		);
		register_rest_route(
			'nexora/v1',
			'/wishlist/render',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function ( $request ) {
					$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) ), 0, self::MAX );
					return rest_ensure_response( array( 'html' => self::render_products( $ids ) ) );
				},
				'permission_callback' => '__return_true',
				'args'                => array( 'ids' => array( 'type' => 'string', 'default' => '' ) ),
			)
		);
	}

	/**
	 * Save the logged-in user's list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_save( $request ) {
		$user = get_current_user_id();
		$ids  = array_filter( array_map( 'absint', (array) $request['ids'] ) );
		if ( 'merge' === $request['mode'] ) {
			$ids = array_merge( self::get_ids( $user ), $ids );
		}
		$ids = array_values( array_unique( array_filter( $ids, static function ( $id ) {
			return 'product' === get_post_type( $id );
		} ) ) );
		$ids = array_slice( $ids, 0, self::MAX );
		update_user_meta( $user, self::META, $ids );
		return rest_ensure_response( array( 'ids' => $ids ) );
	}

	/**
	 * Front-end script.
	 */
	public static function assets() {
		wp_enqueue_script( 'nexora-wishlist', NEXORA_TOOLKIT_URL . 'assets/js/wishlist.js', array(), NEXORA_TOOLKIT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		$data = array(
			'rest'     => esc_url_raw( rest_url( 'nexora/v1/' ) ),
			'loggedIn' => is_user_logged_in(),
		);
		if ( is_user_logged_in() ) {
			// Logged-in pages are not page-cached by standard WooCommerce cache setups, so a nonce is safe here.
			$data['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		wp_localize_script( 'nexora-wishlist', 'nexoraWishlist', $data );
	}
}
