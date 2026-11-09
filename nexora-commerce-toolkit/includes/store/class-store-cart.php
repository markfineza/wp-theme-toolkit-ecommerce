<?php
/**
 * Cart of the built-in store. Stored on the server against a random cookie token (guests) or the user
 * (customers). Prices are always read fresh from the catalogue; nothing price-related comes from the browser.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart.
 */
class Nexora_Store_Cart {

	const COOKIE       = 'nx_cart';
	const COUNT_COOKIE = 'nx_cart_n';
	const MAX_QTY      = 9999;

	/**
	 * Loaded contents.
	 *
	 * @var array|null
	 */
	private static $data = null;

	/**
	 * Row ID of the loaded cart.
	 *
	 * @var int
	 */
	private static $row = 0;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'handle_requests' ), 20 );
		add_action( 'wp_login', array( __CLASS__, 'merge_on_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'forget' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* --------------------------------------------------------------- storage */

	/**
	 * Logged-in user, also on REST requests (which need no nonce here: the cart is identified by its
	 * own cookies, and writes are limited to same-origin requests in rest_guard()).
	 *
	 * @return int
	 */
	private static function user_id() {
		$id = get_current_user_id();
		return $id ? $id : (int) wp_validate_auth_cookie( '', 'logged_in' );
	}

	/**
	 * Hash of the visitor's cart token, or ''.
	 *
	 * @return string
	 */
	private static function token_hash() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return 64 === strlen( $token ) ? hash( 'sha256', $token ) : '';
	}

	/**
	 * Load the current cart.
	 *
	 * @return array
	 */
	private static function &data() {
		if ( null === self::$data ) {
			global $wpdb;
			self::$data = array( 'items' => array(), 'coupons' => array(), 'notices' => array() );
			$user       = self::user_id();
			$hash       = self::token_hash();
			$row        = null;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			if ( $user ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, contents FROM {$wpdb->prefix}nx_carts WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user ) );
			} elseif ( $hash ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, contents FROM {$wpdb->prefix}nx_carts WHERE token = %s AND user_id = 0 LIMIT 1", $hash ) );
			}
			// phpcs:enable
			if ( $row ) {
				self::$row  = (int) $row->id;
				self::$data = wp_parse_args( (array) json_decode( $row->contents, true ), self::$data );
			}
		}
		return self::$data;
	}

	/**
	 * Save the cart and refresh its cookies.
	 */
	private static function save() {
		global $wpdb;
		$data = self::data();
		$user = self::user_id();
		$hash = self::token_hash();
		if ( ! $user && ! $hash ) {
			$token = bin2hex( random_bytes( 32 ) );
			self::cookie( self::COOKIE, $token, true );
			$_COOKIE[ self::COOKIE ] = $token;
			$hash                    = hash( 'sha256', $token );
		}
		$fields = array(
			'token'      => $user ? '' : $hash,
			'user_id'    => $user,
			'contents'   => wp_json_encode( $data ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( self::$row ) {
			$wpdb->update( "{$wpdb->prefix}nx_carts", $fields, array( 'id' => self::$row ) );
		} else {
			$wpdb->insert( "{$wpdb->prefix}nx_carts", $fields );
			self::$row = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		self::cookie( self::COUNT_COOKIE, (string) self::count(), false );
	}

	/**
	 * Set a cookie.
	 *
	 * @param string $name      Name.
	 * @param string $value     Value.
	 * @param bool   $http_only Hide from scripts.
	 */
	private static function cookie( $name, $value, $http_only ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => '' === $value ? time() - DAY_IN_SECONDS : time() + 30 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => $http_only,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Move a guest cart into the customer's cart on login.
	 *
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function merge_on_login( $login, $user ) {
		global $wpdb;
		$hash = self::token_hash();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$guest = $hash ? $wpdb->get_row( $wpdb->prepare( "SELECT id, contents FROM {$wpdb->prefix}nx_carts WHERE token = %s AND user_id = 0 LIMIT 1", $hash ) ) : null;
		$mine  = $wpdb->get_row( $wpdb->prepare( "SELECT id, contents FROM {$wpdb->prefix}nx_carts WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user->ID ) );
		if ( $guest ) {
			$g = (array) json_decode( $guest->contents, true );
			if ( $mine ) {
				$m             = (array) json_decode( $mine->contents, true );
				$m['items']    = (array) ( isset( $g['items'] ) ? $g['items'] : array() ) + (array) ( isset( $m['items'] ) ? $m['items'] : array() );
				$m['coupons']  = array_values( array_unique( array_merge( (array) ( isset( $m['coupons'] ) ? $m['coupons'] : array() ), (array) ( isset( $g['coupons'] ) ? $g['coupons'] : array() ) ) ) );
				$wpdb->update( "{$wpdb->prefix}nx_carts", array( 'contents' => wp_json_encode( $m ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $mine->id ) );
				$wpdb->delete( "{$wpdb->prefix}nx_carts", array( 'id' => $guest->id ) );
			} else {
				$wpdb->update( "{$wpdb->prefix}nx_carts", array( 'user_id' => $user->ID, 'token' => '' ), array( 'id' => $guest->id ) );
			}
		}
		// phpcs:enable
		self::$data = null;
		self::$row  = 0;
		self::cookie( self::COOKIE, '', true );
		unset( $_COOKIE[ self::COOKIE ] );
		wp_set_current_user( $user->ID );
		self::cookie( self::COUNT_COOKIE, (string) self::count(), false );
	}

	/**
	 * Clear cart cookies on logout (the customer's cart stays on the account).
	 */
	public static function forget() {
		self::cookie( self::COOKIE, '', true );
		self::cookie( self::COUNT_COOKIE, '', false );
		self::$data = null;
		self::$row  = 0;
	}

	/* ------------------------------------------------------------ contents */

	/**
	 * Add a product.
	 *
	 * @param int $product_id   Product.
	 * @param int $variation_id Variation.
	 * @param int $qty          Quantity.
	 * @return true|WP_Error
	 */
	public static function add( $product_id, $variation_id = 0, $qty = 1 ) {
		$product = Nexora_Store_Product::get( $product_id );
		if ( ! $product || $product->get_parent_id() ) {
			return new WP_Error( 'nexora_cart', __( 'That product is not available.', 'nexora-commerce-toolkit' ) );
		}
		if ( $product->is_type( 'variable' ) ) {
			$variation = $variation_id ? Nexora_Store_Product::get( $variation_id ) : null;
			if ( ! $variation || $variation->get_parent_id() !== $product->get_id() ) {
				return new WP_Error( 'nexora_cart', __( 'Please choose an option first.', 'nexora-commerce-toolkit' ) );
			}
			$item = $variation;
		} else {
			$variation_id = 0;
			$item         = $product;
		}
		if ( ! $item->is_purchasable() || ! $item->is_in_stock() ) {
			/* translators: %s: product */
			return new WP_Error( 'nexora_cart', sprintf( __( '%s cannot be bought right now.', 'nexora-commerce-toolkit' ), $product->get_name() ) );
		}
		$qty  = max( 1, min( self::MAX_QTY, (int) $qty ) );
		$key  = $product->get_id() . '_' . (int) $variation_id;
		$data = &self::data();
		$have = isset( $data['items'][ $key ] ) ? (int) $data['items'][ $key ]['q'] : 0;
		$max  = $item->max_qty();
		if ( null !== $max && $have + $qty > $max ) {
			if ( $have >= $max ) {
				/* translators: 1: quantity, 2: product */
				return new WP_Error( 'nexora_cart', sprintf( __( 'Only %1$d of %2$s are available, and they are already in your cart.', 'nexora-commerce-toolkit' ), $max, $product->get_name() ) );
			}
			$qty = $max - $have;
		}
		$data['items'][ $key ] = array(
			'p' => $product->get_id(),
			'v' => (int) $variation_id,
			'q' => $have + $qty,
		);
		self::save();
		return true;
	}

	/**
	 * Set a line's quantity (0 removes it).
	 *
	 * @param string $key Line key.
	 * @param int    $qty Quantity.
	 */
	public static function set_qty( $key, $qty ) {
		$data = &self::data();
		if ( ! isset( $data['items'][ $key ] ) ) {
			return;
		}
		if ( (int) $qty <= 0 ) {
			unset( $data['items'][ $key ] );
		} else {
			$data['items'][ $key ]['q'] = min( self::MAX_QTY, (int) $qty );
		}
		self::save();
	}

	/**
	 * Empty the cart after an order.
	 */
	public static function clear() {
		$data            = &self::data();
		$data['items']   = array();
		$data['coupons'] = array();
		self::save();
	}

	/**
	 * Applied coupon codes.
	 *
	 * @return string[]
	 */
	public static function coupon_codes() {
		return array_values( (array) self::data()['coupons'] );
	}

	/**
	 * Apply a coupon.
	 *
	 * @param string $code Code.
	 * @return true|WP_Error
	 */
	public static function apply_coupon( $code ) {
		$coupon = Nexora_Store_Coupons::find( $code );
		if ( ! $coupon ) {
			/* translators: %s: code */
			return new WP_Error( 'nexora_coupon', sprintf( __( 'Coupon “%s” does not exist.', 'nexora-commerce-toolkit' ), sanitize_text_field( $code ) ) );
		}
		$data = &self::data();
		if ( in_array( $coupon['code'], $data['coupons'], true ) ) {
			return new WP_Error( 'nexora_coupon', __( 'That coupon is already applied.', 'nexora-commerce-toolkit' ) );
		}
		$check = Nexora_Store_Coupons::validate( $coupon, self::lines(), self::user_id() );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		foreach ( $data['coupons'] as $other ) {
			$applied = Nexora_Store_Coupons::find( $other );
			if ( $applied && $applied['individual'] ) {
				return new WP_Error( 'nexora_coupon', __( 'A coupon already applied cannot be combined with others.', 'nexora-commerce-toolkit' ) );
			}
		}
		if ( $coupon['individual'] ) {
			$data['coupons'] = array();
		}
		$data['coupons'][] = $coupon['code'];
		self::save();
		return true;
	}

	/**
	 * Remove a coupon.
	 *
	 * @param string $code Code.
	 */
	public static function remove_coupon( $code ) {
		$data            = &self::data();
		$data['coupons'] = array_values( array_diff( $data['coupons'], array( $code ) ) );
		self::save();
	}

	/**
	 * Number of items.
	 *
	 * @return int
	 */
	public static function count() {
		$total = 0;
		foreach ( (array) self::data()['items'] as $item ) {
			$total += (int) $item['q'];
		}
		return $total;
	}

	/**
	 * Cart lines with live prices. Items that can no longer be bought are removed or reduced, with a notice.
	 *
	 * @return array[]
	 */
	public static function lines() {
		$data    = &self::data();
		$lines   = array();
		$changed = false;
		foreach ( $data['items'] as $key => $row ) {
			$product = Nexora_Store_Product::get( $row['p'] );
			$item    = $product && ! empty( $row['v'] ) ? Nexora_Store_Product::get( $row['v'] ) : $product;
			if ( ! $product || ! $item || ! $item->is_purchasable() || ! $item->is_in_stock() ) {
				unset( $data['items'][ $key ] );
				$data['notices'][] = array( 'error', __( 'An item in your cart is no longer available and was removed.', 'nexora-commerce-toolkit' ) );
				$changed           = true;
				continue;
			}
			$qty = (int) $row['q'];
			$max = $item->max_qty();
			if ( null !== $max && $qty > $max ) {
				$qty                         = $max;
				$data['items'][ $key ]['q'] = $qty;
				/* translators: 1: product, 2: quantity */
				$data['notices'][] = array( 'error', sprintf( __( 'Only %2$d of %1$s are available, so your cart was updated.', 'nexora-commerce-toolkit' ), $product->get_name(), $qty ) );
				$changed           = true;
			}
			$price         = (float) $item->get_price();
			$lines[ $key ] = array(
				'key'       => $key,
				'product'   => $product,
				'item'      => $item,
				'qty'       => $qty,
				'price'     => $price,
				'total'     => Nexora_Store::round( $price * $qty ),
				'option'    => ! empty( $row['v'] ) ? $item->option_label() : Nexora_Toolkit_Packages::summary( Nexora_Toolkit_Packages::contents( $item->get_id() ) ),
			);
		}
		if ( $changed ) {
			self::save();
		}
		return $lines;
	}

	/**
	 * Delivery options available for a subtotal.
	 *
	 * @param float $subtotal Subtotal.
	 * @return array<string,array{title:string,cost:float,desc:string,icon:string}>
	 */
	public static function shipping_options( $subtotal = 0.0 ) {
		$options = array();
		if ( Nexora_Store::get( 'pickup_enabled' ) ) {
			$locations         = Nexora_Store_Checkout::pickup_locations();
			$options['pickup'] = array(
				'title' => (string) Nexora_Store::get( 'pickup_title' ),
				'cost'  => (float) Nexora_Store::get( 'pickup_fee' ),
				'icon'  => 'store',
				'desc'  => 1 === count( $locations ) && $locations[0]['address']
					/* translators: %s: address */
					? sprintf( __( 'Collect it yourself at %s.', 'nexora-commerce-toolkit' ), $locations[0]['address'] )
					: __( 'Collect it yourself at one of our pickup points.', 'nexora-commerce-toolkit' ),
			);
		}
		if ( Nexora_Store::get( 'courier_enabled' ) ) {
			$options['courier'] = array(
				'title' => (string) Nexora_Store::get( 'courier_title' ),
				'cost'  => (float) Nexora_Store::get( 'courier_fee' ),
				'icon'  => 'bike',
				'desc'  => __( 'Book Lalamove, Grab or any courier yourself. We pack the order and hand it to your rider at the time you pick.', 'nexora-commerce-toolkit' ),
			);
		}
		if ( Nexora_Store::get( 'delivery_enabled' ) ) {
			$free                = (float) Nexora_Store::get( 'delivery_free_over' );
			$options['delivery'] = array(
				'title' => (string) Nexora_Store::get( 'delivery_title' ),
				'cost'  => $free > 0 && $subtotal >= $free ? 0.0 : (float) Nexora_Store::get( 'delivery_fee' ),
				'icon'  => 'truck',
				'desc'  => $free > 0
					/* translators: %s: amount */
					? sprintf( __( 'Delivered to your address. Free over %s.', 'nexora-commerce-toolkit' ), Nexora_Store::money( $free ) )
					: __( 'Delivered to your address.', 'nexora-commerce-toolkit' ),
			);
		}
		return (array) apply_filters( 'nexora_store_shipping_options', $options, $subtotal );
	}

	/**
	 * Totals.
	 *
	 * @param string $shipping Delivery option key.
	 * @param string $email    Customer email, for per-customer coupon limits.
	 * @return array
	 */
	public static function totals( $shipping = '', $email = '' ) {
		$lines    = self::lines();
		$subtotal = 0.0;
		foreach ( $lines as $line ) {
			$subtotal += $line['total'];
		}
		$subtotal = Nexora_Store::round( $subtotal );
		$calc     = Nexora_Store_Coupons::calculate( $lines, self::coupon_codes(), self::user_id(), $email );
		$lines   += self::gift_lines( $calc['gifts'] );
		$options  = self::shipping_options( $subtotal );
		if ( ! isset( $options[ $shipping ] ) ) {
			$shipping = $options ? (string) key( $options ) : '';
		}
		$ship_cost = $shipping ? (float) $options[ $shipping ]['cost'] : 0.0;
		if ( $calc['free_shipping'] ) {
			$ship_cost = 0.0;
		}
		return array(
			'lines'          => $lines,
			'subtotal'       => $subtotal,
			'discounts'      => $calc['discounts'],
			'discount_total' => $calc['total'],
			'invalid'        => $calc['invalid'],
			'shipping_key'   => $shipping,
			'shipping_cost'  => Nexora_Store::round( $ship_cost ),
			'shipping_title' => $shipping ? $options[ $shipping ]['title'] : '',
			'total'          => Nexora_Store::round( max( 0, $subtotal - $calc['total'] ) + $ship_cost ),
		);
	}

	/**
	 * Free items earned by coupons, as zero-priced lines. They are worked out each time from the applied
	 * coupons and never stored in the cart, so nothing can be kept once the coupon stops applying.
	 *
	 * @param array $gifts Free-item settings keyed by coupon ID.
	 * @return array[]
	 */
	private static function gift_lines( $gifts ) {
		$lines = array();
		foreach ( $gifts as $coupon_id => $free ) {
			$item    = Nexora_Store_Product::get( $free['item'] );
			$product = $item && $item->get_parent_id() ? Nexora_Store_Product::get( $item->get_parent_id() ) : $item;
			if ( ! $item || ! $product ) {
				continue;
			}
			$option                        = $item->get_parent_id() ? $item->option_label() : '';
			$lines[ 'gift_' . $coupon_id ] = array(
				'key'     => 'gift_' . $coupon_id,
				'product' => $product,
				'item'    => $item,
				'qty'     => (int) $free['qty'],
				'price'   => 0.0,
				'total'   => 0.0,
				'option'  => ( $option ? $option . ' · ' : '' ) . __( 'Free item', 'nexora-commerce-toolkit' ),
				'gift'    => true,
			);
		}
		return $lines;
	}

	/* --------------------------------------------------------------- notices */

	/**
	 * Queue a notice for the next page.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	public static function notice( $type, $message ) {
		$data              = &self::data();
		$data['notices'][] = array( $type, $message );
		self::save();
	}

	/**
	 * Print and clear notices.
	 *
	 * @return string
	 */
	public static function notices_html() {
		$data = &self::data();
		if ( empty( $data['notices'] ) ) {
			return '';
		}
		$out = '<div class="woocommerce-notices-wrapper">';
		foreach ( $data['notices'] as $notice ) {
			$class = 'error' === $notice[0] ? 'woocommerce-error' : 'woocommerce-message';
			$out  .= '<div class="' . $class . '" role="' . ( 'error' === $notice[0] ? 'alert' : 'status' ) . '">' . esc_html( $notice[1] ) . '</div>';
		}
		$data['notices'] = array();
		self::save();
		return $out . '</div>';
	}

	/* -------------------------------------------------------------- requests */

	/**
	 * ?add-to-cart= links and cart page forms.
	 */
	public static function handle_requests() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_REQUEST['add-to-cart'] ) ) {
			$result = self::add( absint( $_REQUEST['add-to-cart'] ), isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0, isset( $_REQUEST['quantity'] ) ? absint( $_REQUEST['quantity'] ) : 1 );
			if ( is_wp_error( $result ) ) {
				self::notice( 'error', $result->get_error_message() );
				$product = Nexora_Store_Product::get( absint( $_REQUEST['add-to-cart'] ) );
				wp_safe_redirect( $product ? $product->get_permalink() : Nexora_Store::shop_url() );
			} else {
				self::notice( 'success', __( 'Added to your cart.', 'nexora-commerce-toolkit' ) );
				wp_safe_redirect( ! empty( $_REQUEST['buy_now'] ) ? Nexora_Store::url( 'checkout' ) : Nexora_Store::url( 'cart' ) );
			}
			exit;
		}
		// phpcs:enable
		if ( ! isset( $_REQUEST['nx_cart_action'] ) ) {
			return;
		}
		$nonce = isset( $_REQUEST['_nx_cart'] ) ? sanitize_key( wp_unslash( $_REQUEST['_nx_cart'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'nexora_cart' ) ) {
			self::notice( 'error', __( 'Your session expired. Please try again.', 'nexora-commerce-toolkit' ) );
			wp_safe_redirect( Nexora_Store::url( 'cart' ) );
			exit;
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['nx_cart_action'] ) );
		$back   = 'checkout' === ( isset( $_REQUEST['back'] ) ? sanitize_key( wp_unslash( $_REQUEST['back'] ) ) : '' ) ? Nexora_Store::url( 'checkout' ) : Nexora_Store::url( 'cart' );
		switch ( $action ) {
			case 'update':
				$quantities = isset( $_POST['qty'] ) && is_array( $_POST['qty'] ) ? array_map( 'absint', wp_unslash( $_POST['qty'] ) ) : array();
				foreach ( $quantities as $key => $qty ) {
					self::set_qty( sanitize_text_field( (string) $key ), $qty );
				}
				self::lines();
				self::notice( 'success', __( 'Cart updated.', 'nexora-commerce-toolkit' ) );
				break;
			case 'remove':
				self::set_qty( isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '', 0 );
				self::notice( 'success', __( 'Item removed.', 'nexora-commerce-toolkit' ) );
				break;
			case 'coupon':
				$result = self::apply_coupon( isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '' );
				self::notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Coupon applied.', 'nexora-commerce-toolkit' ) );
				break;
			case 'remove_coupon':
				self::remove_coupon( isset( $_REQUEST['code'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['code'] ) ) : '' );
				self::notice( 'success', __( 'Coupon removed.', 'nexora-commerce-toolkit' ) );
				break;
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Hidden nonce link for an action.
	 *
	 * @param string $action Action.
	 * @param array  $args   Extra args.
	 * @return string
	 */
	public static function action_url( $action, $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'nx_cart_action' => $action,
					'_nx_cart'       => wp_create_nonce( 'nexora_cart' ),
				),
				$args
			),
			Nexora_Store::url( 'cart' )
		);
	}

	/* ------------------------------------------------------------------ REST */

	/**
	 * REST routes for the mini cart, add-to-cart buttons and checkout totals.
	 */
	public static function routes() {
		$open = '__return_true';
		register_rest_route( 'nexora/v1', '/store/cart', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_cart' ), 'permission_callback' => $open ) );
		register_rest_route( 'nexora/v1', '/store/cart/add', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_add' ), 'permission_callback' => array( __CLASS__, 'rest_guard' ) ) );
		register_rest_route( 'nexora/v1', '/store/cart/remove', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_remove' ), 'permission_callback' => array( __CLASS__, 'rest_guard' ) ) );
		register_rest_route( 'nexora/v1', '/store/totals', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_totals' ), 'permission_callback' => array( __CLASS__, 'rest_guard' ) ) );
		register_rest_route( 'nexora/v1', '/store/coupon', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_coupon' ), 'permission_callback' => array( __CLASS__, 'rest_guard' ) ) );
	}

	/**
	 * Writes only from this site. The cart cookies are SameSite=Lax, which covers modern browsers;
	 * the Origin check covers the rest.
	 *
	 * @return bool|WP_Error
	 */
	public static function rest_guard() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ), PHP_URL_HOST ) : '';
		if ( $origin && strtolower( $origin ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return new WP_Error( 'nexora_origin', __( 'Request not allowed.', 'nexora-commerce-toolkit' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Personal response.
	 *
	 * @param array $data Data.
	 * @param int   $code Status.
	 * @return WP_REST_Response
	 */
	private static function respond( $data, $code = 200 ) {
		$response = new WP_REST_Response( $data, $code );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Current cart summary.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_cart() {
		return self::respond( array( 'count' => self::count(), 'html' => self::mini_cart() ) );
	}

	/**
	 * Add to cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_add( $request ) {
		$result = self::add( absint( $request['product_id'] ), absint( $request['variation_id'] ), max( 1, absint( $request['quantity'] ) ) );
		if ( is_wp_error( $result ) ) {
			return self::respond( array( 'ok' => false, 'message' => $result->get_error_message() ), 400 );
		}
		return self::respond( array( 'ok' => true, 'count' => self::count(), 'html' => self::mini_cart() ) );
	}

	/**
	 * Remove a line from the mini cart.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_remove( $request ) {
		self::set_qty( sanitize_text_field( (string) $request['key'] ), 0 );
		return self::respond( array( 'ok' => true, 'count' => self::count(), 'html' => self::mini_cart() ) );
	}

	/**
	 * Order summary for the chosen delivery option.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_totals( $request ) {
		$t = self::totals( sanitize_key( (string) $request['shipping'] ), sanitize_email( (string) $request['email'] ) );
		return self::respond( array( 'summary' => Nexora_Store_Checkout::summary_html( $t ), 'total' => Nexora_Store::money( $t['total'] ) ) );
	}

	/**
	 * Apply or remove a coupon at checkout without reloading the form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_coupon( $request ) {
		$code = sanitize_text_field( (string) $request['code'] );
		if ( $request['remove'] ) {
			self::remove_coupon( $code );
			$result = true;
		} else {
			$result = self::apply_coupon( $code );
		}
		$t = self::totals( sanitize_key( (string) $request['shipping'] ), sanitize_email( (string) $request['email'] ) );
		return self::respond(
			array(
				'ok'      => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : ( $request['remove'] ? __( 'Coupon removed.', 'nexora-commerce-toolkit' ) : __( 'Coupon applied.', 'nexora-commerce-toolkit' ) ),
				'summary' => Nexora_Store_Checkout::summary_html( $t ),
				'total'   => Nexora_Store::money( $t['total'] ),
			)
		);
	}

	/* ---------------------------------------------------------------- output */

	/**
	 * Mini cart for the theme's drawer.
	 *
	 * @return string
	 */
	public static function mini_cart() {
		$t = self::totals();
		if ( ! $t['lines'] ) {
			return '<p class="woocommerce-mini-cart__empty-message">' . esc_html__( 'Your cart is empty.', 'nexora-commerce-toolkit' ) . '</p>';
		}
		$out = '<ul class="woocommerce-mini-cart cart_list product_list_widget">';
		foreach ( $t['lines'] as $line ) {
			if ( ! empty( $line['gift'] ) ) {
				$out .= sprintf(
					'<li class="woocommerce-mini-cart-item mini_cart_item"><a href="%1$s">%2$s%3$s</a><span class="quantity">%4$d &times; %5$s</span></li>',
					esc_url( $line['product']->get_permalink() ),
					$line['item']->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ),
					esc_html( $line['product']->get_name() . ' — ' . $line['option'] ),
					(int) $line['qty'],
					esc_html__( 'Free', 'nexora-commerce-toolkit' )
				);
				continue;
			}
			$out .= sprintf(
				'<li class="woocommerce-mini-cart-item mini_cart_item"><button type="button" class="remove remove_from_cart_button" data-nx-remove="%1$s" aria-label="%2$s">&times;</button><a href="%3$s">%4$s%5$s</a><span class="quantity">%6$d &times; %7$s</span></li>',
				esc_attr( $line['key'] ),
				/* translators: %s: product */
				esc_attr( sprintf( __( 'Remove %s', 'nexora-commerce-toolkit' ), $line['product']->get_name() ) ),
				esc_url( $line['product']->get_permalink() ),
				$line['item']->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ),
				esc_html( $line['product']->get_name() . ( $line['option'] ? ' — ' . $line['option'] : '' ) ),
				(int) $line['qty'],
				Nexora_Store::price( $line['price'] )
			);
		}
		$out .= '</ul>';
		$out .= '<p class="woocommerce-mini-cart__total total"><strong>' . esc_html__( 'Subtotal', 'nexora-commerce-toolkit' ) . ':</strong> ' . Nexora_Store::price( $t['subtotal'] ) . '</p>';
		$out .= '<p class="woocommerce-mini-cart__buttons buttons"><a class="button wc-forward" href="' . esc_url( Nexora_Store::url( 'cart' ) ) . '">' . esc_html__( 'View cart', 'nexora-commerce-toolkit' ) . '</a> <a class="button checkout wc-forward" href="' . esc_url( Nexora_Store::url( 'checkout' ) ) . '">' . esc_html__( 'Checkout', 'nexora-commerce-toolkit' ) . '</a></p>';
		return $out;
	}

	/**
	 * Cart page.
	 *
	 * @return string
	 */
	public static function render() {
		$t       = self::totals();
		$notices = self::notices_html();
		ob_start();
		echo '<div class="woocommerce nx-store-cart">';
		echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		if ( $t['lines'] ) {
			echo Nexora_Toolkit_Rewards::reward_card( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		}
		if ( ! $t['lines'] ) {
			echo '<div class="nx-empty"><h2>' . esc_html__( 'Your cart is empty', 'nexora-commerce-toolkit' ) . '</h2><p>' . esc_html__( 'Fresh meat, fish, vegetables and more are waiting.', 'nexora-commerce-toolkit' ) . '</p><a class="nx-btn nx-btn--primary" href="' . esc_url( Nexora_Store::shop_url() ) . '">' . esc_html__( 'Browse the market', 'nexora-commerce-toolkit' ) . '</a></div></div>';
			return (string) ob_get_clean();
		}
		?>
		<form method="post" action="<?php echo esc_url( Nexora_Store::url( 'cart' ) ); ?>" class="woocommerce-cart-form" data-nx-cart-form>
			<input type="hidden" name="nx_cart_action" value="update">
			<?php wp_nonce_field( 'nexora_cart', '_nx_cart' ); ?>
			<table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents">
				<thead>
					<tr>
						<th class="product-thumbnail"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'nexora-commerce-toolkit' ); ?></span></th>
						<th class="product-name"><?php esc_html_e( 'Product', 'nexora-commerce-toolkit' ); ?></th>
						<th class="product-price"><?php esc_html_e( 'Price', 'nexora-commerce-toolkit' ); ?></th>
						<th class="product-quantity"><?php esc_html_e( 'Quantity', 'nexora-commerce-toolkit' ); ?></th>
						<th class="product-subtotal"><?php esc_html_e( 'Subtotal', 'nexora-commerce-toolkit' ); ?></th>
						<th class="product-remove"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'nexora-commerce-toolkit' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $t['lines'] as $line ) : ?>
						<?php $max = $line['item']->max_qty(); ?>
						<tr class="woocommerce-cart-form__cart-item cart_item">
							<td class="product-thumbnail"><a href="<?php echo esc_url( $line['product']->get_permalink() ); ?>" tabindex="-1" aria-hidden="true"><?php echo $line['item']->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></td>
							<td class="product-name" data-title="<?php esc_attr_e( 'Product', 'nexora-commerce-toolkit' ); ?>">
								<a href="<?php echo esc_url( $line['product']->get_permalink() ); ?>"><?php echo esc_html( $line['product']->get_name() ); ?></a>
								<?php if ( $line['option'] ) : ?><span class="nx-store-option"><?php echo esc_html( $line['option'] ); ?></span><?php endif; ?>
							</td>
							<?php if ( ! empty( $line['gift'] ) ) : ?>
								<td class="product-price" data-title="<?php esc_attr_e( 'Price', 'nexora-commerce-toolkit' ); ?>"><?php esc_html_e( 'Free', 'nexora-commerce-toolkit' ); ?></td>
								<td class="product-quantity" data-title="<?php esc_attr_e( 'Quantity', 'nexora-commerce-toolkit' ); ?>"><?php echo (int) $line['qty']; ?></td>
								<td class="product-subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'nexora-commerce-toolkit' ); ?>"><?php esc_html_e( 'Free', 'nexora-commerce-toolkit' ); ?></td>
								<td class="product-remove"></td>
								</tr>
								<?php continue; ?>
							<?php endif; ?>
							<td class="product-price" data-title="<?php esc_attr_e( 'Price', 'nexora-commerce-toolkit' ); ?>"><?php echo Nexora_Store::price( $line['price'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="product-quantity" data-title="<?php esc_attr_e( 'Quantity', 'nexora-commerce-toolkit' ); ?>">
								<div class="quantity">
									<?php /* translators: %s: product */ ?>
									<label class="screen-reader-text" for="qty-<?php echo esc_attr( $line['key'] ); ?>"><?php echo esc_html( sprintf( __( '%s quantity', 'nexora-commerce-toolkit' ), $line['product']->get_name() ) ); ?></label>
									<input type="number" class="input-text qty text" id="qty-<?php echo esc_attr( $line['key'] ); ?>" name="qty[<?php echo esc_attr( $line['key'] ); ?>]" value="<?php echo esc_attr( (string) $line['qty'] ); ?>" min="0" <?php echo null !== $max ? 'max="' . esc_attr( (string) $max ) . '"' : ''; ?> step="1" inputmode="numeric">
								</div>
							</td>
							<td class="product-subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'nexora-commerce-toolkit' ); ?>"><?php echo Nexora_Store::price( $line['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<?php /* translators: %s: product */ ?>
							<td class="product-remove"><a class="remove" href="<?php echo esc_url( self::action_url( 'remove', array( 'key' => $line['key'] ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s from cart', 'nexora-commerce-toolkit' ), $line['product']->get_name() ) ); ?>">&times;</a></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td colspan="6" class="actions">
							<button type="submit" class="button" data-nx-update><?php esc_html_e( 'Update cart', 'nexora-commerce-toolkit' ); ?></button>
						</td>
					</tr>
				</tbody>
			</table>
		</form>
		<div class="cart-collaterals">
			<form method="post" action="<?php echo esc_url( Nexora_Store::url( 'cart' ) ); ?>" class="nx-store-coupon">
				<input type="hidden" name="nx_cart_action" value="coupon">
				<?php wp_nonce_field( 'nexora_cart', '_nx_cart' ); ?>
				<label for="coupon_code"><?php esc_html_e( 'Have a coupon?', 'nexora-commerce-toolkit' ); ?></label>
				<span class="nx-store-coupon__row">
					<input type="text" name="coupon_code" id="coupon_code" class="input-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Coupon code', 'nexora-commerce-toolkit' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Apply', 'nexora-commerce-toolkit' ); ?></button>
				</span>
			</form>
			<div class="cart_totals">
				<h2><?php esc_html_e( 'Cart totals', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="shop_table shop_table_responsive">
					<tr class="cart-subtotal"><th><?php esc_html_e( 'Subtotal', 'nexora-commerce-toolkit' ); ?></th><td data-title="<?php esc_attr_e( 'Subtotal', 'nexora-commerce-toolkit' ); ?>"><?php echo Nexora_Store::price( $t['subtotal'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<?php foreach ( $t['discounts'] as $d ) : ?>
						<tr class="cart-discount">
							<th><?php echo esc_html( $d['label'] ); ?></th>
							<td data-title="<?php echo esc_attr( $d['label'] ); ?>">
								<?php echo Nexora_Store_Coupons::discount_value( $d ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( $d['code'] ) : ?>
									<a class="nx-store-coupon__remove" href="<?php echo esc_url( self::action_url( 'remove_coupon', array( 'code' => $d['code'] ) ) ); ?>"><?php esc_html_e( 'Remove', 'nexora-commerce-toolkit' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( $t['invalid'] as $code => $message ) : ?>
						<tr class="cart-discount is-invalid"><th colspan="2"><?php echo esc_html( $code . ': ' . $message ); ?> <a class="nx-store-coupon__remove" href="<?php echo esc_url( self::action_url( 'remove_coupon', array( 'code' => $code ) ) ); ?>"><?php esc_html_e( 'Remove', 'nexora-commerce-toolkit' ); ?></a></th></tr>
					<?php endforeach; ?>
					<tr class="order-total"><th><?php esc_html_e( 'Total before delivery', 'nexora-commerce-toolkit' ); ?></th><td data-title="<?php esc_attr_e( 'Total', 'nexora-commerce-toolkit' ); ?>"><strong><?php echo Nexora_Store::price( max( 0, $t['subtotal'] - $t['discount_total'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></td></tr>
				</table>
				<div class="wc-proceed-to-checkout"><a class="checkout-button button alt wc-forward" href="<?php echo esc_url( Nexora_Store::url( 'checkout' ) ); ?>"><?php esc_html_e( 'Proceed to checkout', 'nexora-commerce-toolkit' ); ?></a></div>
			</div>
		</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
