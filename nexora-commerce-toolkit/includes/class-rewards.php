<?php
/**
 * Rewards that work with either store engine:
 *
 * - Free-item coupons: a normal shop_coupon with extra meta. The customer must be signed in, must meet the
 *   conditions set on the coupon, and can use it once.
 * - First customers promotion: the first N customers who register each get a personal, single-use coupon
 *   locked to their account.
 *
 * Coupons keep WooCommerce's format, so they survive switching engines. Usage counting, expiry and giving
 * a use back on cancellation are handled by whichever engine is running.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewards.
 */
class Nexora_Toolkit_Rewards {

	const OPTION    = 'nexora_first_customers';
	const TAKEN     = 'nexora_first_customers_taken';
	const USER_META = '_nx_first_customer';
	const PAGE      = 'nexora-first-customers';

	/**
	 * Guards the WooCommerce cart sync against re-entry.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_shop_coupon', array( __CLASS__, 'coupon_box_register' ) );
		// Runs after WooCommerce's own coupon save (save_post priority 1), so its form cannot undo the rules below.
		add_action( 'save_post', array( __CLASS__, 'save_coupon' ), 99, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'coupon_notice' ) );
		add_filter( 'manage_shop_coupon_posts_columns', array( __CLASS__, 'coupon_columns' ), 99 );
		add_action( 'manage_shop_coupon_posts_custom_column', array( __CLASS__, 'coupon_column' ), 10, 2 );

		add_action( 'user_register', array( __CLASS__, 'on_register' ), 20 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_nexora_first_customers_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_nexora_first_customers_reset', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_apply' ), 25 );

		if ( class_exists( 'WooCommerce' ) ) {
			self::init_woocommerce();
		}
	}

	/**
	 * Is WooCommerce the running engine?
	 *
	 * @return bool
	 */
	private static function is_wc() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Money as plain text.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		if ( self::is_wc() ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return Nexora_Store::money( $amount );
	}

	/**
	 * Load a product or variation with the running engine.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	private static function product( $id ) {
		$product = self::is_wc() ? wc_get_product( $id ) : Nexora_Store_Product::get( $id );
		return $product ? $product : null;
	}

	/* --------------------------------------------------------- free-item rules */

	/**
	 * Free-item settings of a coupon.
	 *
	 * @param int $coupon_id Coupon.
	 * @return array|null
	 */
	public static function free_item( $coupon_id ) {
		$item = (int) get_post_meta( $coupon_id, '_nx_free_item', true );
		if ( ! $item ) {
			return null;
		}
		$ids = static function ( $key ) use ( $coupon_id ) {
			return array_values( array_filter( array_map( 'absint', (array) get_post_meta( $coupon_id, $key, true ) ) ) );
		};
		return array(
			'item'        => $item,
			'qty'         => max( 1, (int) get_post_meta( $coupon_id, '_nx_free_qty', true ) ),
			'min_qty'     => (int) get_post_meta( $coupon_id, '_nx_min_qty', true ),
			'products'    => $ids( '_nx_req_products' ),
			'categories'  => $ids( '_nx_req_cats' ),
			'first_order' => 'yes' === get_post_meta( $coupon_id, '_nx_first_order', true ),
		);
	}

	/**
	 * The account a personal coupon belongs to (0 = not personal).
	 *
	 * @param int $coupon_id Coupon.
	 * @return int
	 */
	public static function owner( $coupon_id ) {
		return (int) get_post_meta( $coupon_id, '_nx_owner_user', true );
	}

	/**
	 * Whether a product is in, or under, one of the given categories.
	 *
	 * @param int   $product_id Product.
	 * @param int[] $categories Categories.
	 * @return bool
	 */
	private static function in_categories( $product_id, $categories ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( array_intersect( array_merge( array( (int) $term ), array_map( 'intval', get_ancestors( $term, 'product_cat' ) ) ), $categories ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Has this customer already placed an order that was not cancelled?
	 *
	 * @param int $user User.
	 * @return bool
	 */
	private static function has_ordered( $user ) {
		if ( self::is_wc() ) {
			$statuses = array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-checkout-draft' ) );
			return (bool) wc_get_orders( array( 'customer_id' => $user, 'limit' => 1, 'status' => $statuses, 'return' => 'ids' ) );
		}
		return (bool) Nexora_Store_Orders::for_user( $user, 1, 0, array_merge( Nexora_Store_Orders::active_statuses(), array( 'completed' ) ) );
	}

	/**
	 * Check the rules this module adds to a coupon. The engines still check expiry, usage limits and minimum spend.
	 *
	 * @param int   $coupon_id Coupon.
	 * @param int   $user      Signed-in customer (0 = guest).
	 * @param array $rows      Paid cart lines: product, variation, qty.
	 * @return true|WP_Error
	 */
	public static function check( $coupon_id, $user, $rows ) {
		$fail  = static function ( $message ) {
			return new WP_Error( 'nexora_coupon', $message );
		};
		$owner = self::owner( $coupon_id );
		if ( $owner && ! $user ) {
			return $fail( __( 'Please sign in to use this coupon.', 'nexora-commerce-toolkit' ) );
		}
		if ( $owner && $owner !== (int) $user ) {
			return $fail( __( 'This coupon belongs to another customer.', 'nexora-commerce-toolkit' ) );
		}
		$free = self::free_item( $coupon_id );
		if ( ! $free ) {
			return true;
		}
		if ( ! $user ) {
			return $fail( __( 'Please sign in to use this coupon. Each customer can use it once.', 'nexora-commerce-toolkit' ) );
		}
		if ( ! $rows ) {
			return $fail( __( 'Add something to your cart first, then apply this coupon.', 'nexora-commerce-toolkit' ) );
		}

		$restricted = $free['products'] || $free['categories'];
		$qualifying = 0;
		$in_cart    = 0;
		foreach ( $rows as $row ) {
			$line_item = $row['variation'] ? $row['variation'] : $row['product'];
			if ( $line_item === $free['item'] ) {
				$in_cart += (int) $row['qty'];
			}
			if ( ! $restricted
				|| in_array( $row['product'], $free['products'], true )
				|| ( $row['variation'] && in_array( $row['variation'], $free['products'], true ) )
				|| ( $free['categories'] && self::in_categories( $row['product'], $free['categories'] ) ) ) {
				$qualifying += (int) $row['qty'];
			}
		}
		if ( $restricted && ! $qualifying ) {
			/* translators: %s: products and categories */
			return $fail( sprintf( __( 'This coupon needs one of these in your cart: %s.', 'nexora-commerce-toolkit' ), self::requirement_names( $free ) ) );
		}
		if ( $free['min_qty'] && $qualifying < $free['min_qty'] ) {
			return $fail(
				$restricted
					/* translators: 1: quantity, 2: products and categories */
					? sprintf( __( 'This coupon needs at least %1$d items from: %2$s.', 'nexora-commerce-toolkit' ), $free['min_qty'], self::requirement_names( $free ) )
					/* translators: %d: quantity */
					: sprintf( __( 'This coupon needs at least %d items in your cart.', 'nexora-commerce-toolkit' ), $free['min_qty'] )
			);
		}
		if ( $free['first_order'] && self::has_ordered( $user ) ) {
			return $fail( __( 'This coupon is for your first order only.', 'nexora-commerce-toolkit' ) );
		}

		$item = self::product( $free['item'] );
		if ( ! $item || ! $item->is_purchasable() || ! $item->is_in_stock() ) {
			return $fail( __( 'The free item for this coupon is not available right now.', 'nexora-commerce-toolkit' ) );
		}
		$enough = self::is_wc() ? $item->has_enough_stock( $free['qty'] + $in_cart ) : ( null === $item->max_qty() || $item->max_qty() >= $free['qty'] + $in_cart );
		if ( ! $enough ) {
			return $fail( __( 'The free item for this coupon is not available right now.', 'nexora-commerce-toolkit' ) );
		}
		return true;
	}

	/**
	 * Names of the required products and categories.
	 *
	 * @param array $free Settings.
	 * @return string
	 */
	private static function requirement_names( $free ) {
		$names = array();
		foreach ( $free['products'] as $id ) {
			$names[] = get_the_title( $id );
		}
		foreach ( $free['categories'] as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * Name of a free item, with its option.
	 *
	 * @param int $id Product or variation.
	 * @return string
	 */
	public static function item_name( $id ) {
		$parent = (int) wp_get_post_parent_id( $id );
		if ( 'product_variation' !== get_post_type( $id ) || ! $parent ) {
			return get_the_title( $id );
		}
		$values = array();
		foreach ( get_post_meta( $id ) as $key => $value ) {
			if ( 0 === strpos( $key, 'attribute_' ) && '' !== (string) $value[0] ) {
				$values[] = $value[0];
			}
		}
		return get_the_title( $parent ) . ( $values ? ' — ' . implode( ' / ', $values ) : '' );
	}

	/* --------------------------------------------------------- coupon screen */

	/**
	 * Add the free-item box to the coupon screen.
	 */
	public static function coupon_box_register() {
		add_meta_box( 'nx-free-item', __( 'Free item', 'nexora-commerce-toolkit' ), array( __CLASS__, 'coupon_box' ), 'shop_coupon', 'normal', 'default' );
		add_meta_box( 'nx-coupon-home', __( 'Homepage', 'nexora-commerce-toolkit' ), array( __CLASS__, 'home_box' ), 'shop_coupon', 'side', 'default' );
	}

	/**
	 * Products customers can receive for free, with each pack size listed separately.
	 *
	 * @param int $selected Selected ID.
	 */
	private static function item_options( $selected ) {
		foreach ( self::all_products() as $product ) {
			$children = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $product->ID, 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC', 'fields' => 'ids' ) );
			foreach ( $children ? $children : array( $product->ID ) as $id ) {
				printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $id, selected( (int) $selected, (int) $id, false ), esc_html( self::item_name( $id ) ) );
			}
		}
	}

	/**
	 * Published products.
	 *
	 * @return WP_Post[]
	 */
	private static function all_products() {
		return get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC' ) );
	}

	/**
	 * Category multi-select.
	 *
	 * @param string $name     Field.
	 * @param int[]  $selected Selected.
	 */
	private static function category_select( $name, $selected ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		echo '<select multiple size="5" class="nx-reward-multi" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '[]">';
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $term->term_id, in_array( (int) $term->term_id, $selected, true ) ? ' selected' : '', esc_html( $term->name ) );
		}
		echo '</select>';
	}

	/**
	 * The free-item form.
	 *
	 * @param WP_Post $post Coupon.
	 */
	public static function coupon_box( $post ) {
		$free = self::free_item( $post->ID );
		$m    = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$req  = array_map( 'absint', (array) $m( '_nx_req_products' ) );
		wp_nonce_field( 'nexora_free_item', 'nexora_free_item_nonce' );
		?>
		<p><label><input type="checkbox" name="nx_free_enabled" value="1" data-nx-toggle="nx-free-fields" <?php checked( (bool) $free ); ?>> <strong><?php esc_html_e( 'This coupon gives a free item', 'nexora-commerce-toolkit' ); ?></strong></label></p>
		<p class="description"><?php esc_html_e( 'The customer must be signed in and can use this coupon once. The free item is added to the cart when the coupon is applied, and removed if the conditions stop being met. No other discount is given, so any amount set on this coupon is cleared. A cancelled order gives the use back.', 'nexora-commerce-toolkit' ); ?></p>
		<table class="form-table" role="presentation" id="nx-free-fields">
			<tr><th><label for="nx_free_item"><?php esc_html_e( 'Free item', 'nexora-commerce-toolkit' ); ?></label></th><td>
				<select id="nx_free_item" name="nx_free_item" class="nx-reward-select"><option value="0"><?php esc_html_e( 'Choose a product…', 'nexora-commerce-toolkit' ); ?></option><?php self::item_options( $free ? $free['item'] : 0 ); ?></select>
			</td></tr>
			<tr><th><label for="nx_free_qty"><?php esc_html_e( 'How many free', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="1" step="1" id="nx_free_qty" name="nx_free_qty" value="<?php echo esc_attr( (string) ( $free ? $free['qty'] : 1 ) ); ?>"></td></tr>
			<tr><th colspan="2"><?php esc_html_e( 'Conditions the customer must meet', 'nexora-commerce-toolkit' ); ?><p class="description"><?php esc_html_e( 'Leave a condition empty to skip it. Minimum order, expiry, total uses and allowed customers are set in the coupon’s other fields.', 'nexora-commerce-toolkit' ); ?></p></th></tr>
			<tr><th><label for="nx_min_qty"><?php esc_html_e( 'Minimum items bought', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="1" id="nx_min_qty" name="nx_min_qty" value="<?php echo esc_attr( (string) ( $free && $free['min_qty'] ? $free['min_qty'] : '' ) ); ?>"><p class="description"><?php esc_html_e( 'Counts only the products or categories below, when any are chosen. The free item itself never counts.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
			<tr><th><label for="nx_req_products"><?php esc_html_e( 'Must buy one of these products', 'nexora-commerce-toolkit' ); ?></label></th><td>
				<select multiple size="5" class="nx-reward-multi" id="nx_req_products" name="nx_req_products[]">
					<?php foreach ( self::all_products() as $product ) : ?>
						<option value="<?php echo (int) $product->ID; ?>" <?php echo in_array( (int) $product->ID, $req, true ) ? 'selected' : ''; ?>><?php echo esc_html( get_the_title( $product ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><th><label for="nx_req_cats"><?php esc_html_e( 'Or from these categories', 'nexora-commerce-toolkit' ); ?></label></th><td><?php self::category_select( 'nx_req_cats', array_map( 'absint', (array) $m( '_nx_req_cats' ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'First order', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="nx_first_order" value="yes" <?php checked( 'yes', $m( '_nx_first_order' ) ); ?>> <?php esc_html_e( 'Only for customers who have not ordered before', 'nexora-commerce-toolkit' ); ?></label></td></tr>
		</table>
		<?php
	}

	/**
	 * Opt in to the homepage coupons band. Off by default, so private codes are never published.
	 *
	 * @param WP_Post $post Coupon.
	 */
	public static function home_box( $post ) {
		?>
		<p><label><input type="checkbox" name="nx_show_home" value="yes" <?php checked( 'yes', get_post_meta( $post->ID, '_nx_show_home', true ) ); ?>> <?php esc_html_e( 'Show this code on the homepage', 'nexora-commerce-toolkit' ); ?></label></p>
		<p class="description"><?php esc_html_e( 'Anyone visiting the site can then see it. It disappears by itself once it expires or reaches its usage limit.', 'nexora-commerce-toolkit' ); ?></p>
		<?php
	}

	/**
	 * Coupons chosen for the homepage that can still be used. Personal and email-restricted coupons never appear.
	 *
	 * @param int $limit How many.
	 * @return array[] code, title, terms.
	 */
	public static function public_coupons( $limit = 6 ) {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 30, 'fields' => 'ids', 'meta_key' => '_nx_show_home', 'meta_value' => 'yes', 'orderby' => 'date', 'order' => 'DESC' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$out = array();
		foreach ( $ids as $id ) {
			$expires = (int) get_post_meta( $id, 'date_expires', true );
			$limited = (int) get_post_meta( $id, 'usage_limit', true );
			if ( self::owner( $id ) || array_filter( (array) get_post_meta( $id, 'customer_email', true ) ) || ( $expires && time() > $expires ) || ( $limited && (int) get_post_meta( $id, 'usage_count', true ) >= $limited ) ) {
				continue;
			}
			$terms = array();
			$min   = (float) get_post_meta( $id, 'minimum_amount', true );
			if ( $min > 0 ) {
				/* translators: %s: amount */
				$terms[] = sprintf( __( 'Minimum order %s.', 'nexora-commerce-toolkit' ), self::money( $min ) );
			}
			if ( array_filter( (array) get_post_meta( $id, 'product_categories', true ) ) ) {
				$terms[] = __( 'On selected categories.', 'nexora-commerce-toolkit' );
			}
			if ( self::free_item( $id ) ) {
				$terms[] = __( 'Sign in to use. Conditions apply.', 'nexora-commerce-toolkit' );
			}
			if ( $expires ) {
				/* translators: %s: date */
				$terms[] = sprintf( __( 'Use by %s.', 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ), $expires ) );
			}
			$out[] = array(
				'code'  => get_the_title( $id ),
				'title' => self::reward_text( $id ),
				'terms' => implode( ' ', $terms ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Save the free-item form.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_coupon( $post_id, $post ) {
		if ( 'shop_coupon' !== $post->post_type || ! isset( $_POST['nexora_free_item_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_free_item_nonce'] ) ), 'nexora_free_item' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_nx_show_home', isset( $_POST['nx_show_home'] ) ? 'yes' : 'no' );
		$item = isset( $_POST['nx_free_item'] ) ? absint( $_POST['nx_free_item'] ) : 0;
		$on   = ! empty( $_POST['nx_free_enabled'] );
		if ( $on && ! $item ) {
			set_transient( 'nx_coupon_notice_' . get_current_user_id(), __( 'The free item was not turned on because no product was chosen.', 'nexora-commerce-toolkit' ), 60 );
			$on = false;
		}
		if ( ! $on ) {
			delete_post_meta( $post_id, '_nx_free_item' );
			return;
		}
		update_post_meta( $post_id, '_nx_free_item', $item );
		update_post_meta( $post_id, '_nx_free_qty', isset( $_POST['nx_free_qty'] ) ? max( 1, absint( $_POST['nx_free_qty'] ) ) : 1 );
		update_post_meta( $post_id, '_nx_min_qty', isset( $_POST['nx_min_qty'] ) ? absint( $_POST['nx_min_qty'] ) : 0 );
		update_post_meta( $post_id, '_nx_req_products', isset( $_POST['nx_req_products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['nx_req_products'] ) ) : array() );
		update_post_meta( $post_id, '_nx_req_cats', isset( $_POST['nx_req_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['nx_req_cats'] ) ) : array() );
		update_post_meta( $post_id, '_nx_first_order', isset( $_POST['nx_first_order'] ) ? 'yes' : 'no' );
		// A free item is the whole reward, and one use per customer is not optional.
		update_post_meta( $post_id, 'discount_type', 'fixed_cart' );
		update_post_meta( $post_id, 'coupon_amount', '0' );
		update_post_meta( $post_id, 'usage_limit_per_user', 1 );
	}

	/**
	 * Show a problem from the last coupon save.
	 */
	public static function coupon_notice() {
		$key     = 'nx_coupon_notice_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Add a reward column to the coupon list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function coupon_columns( $columns ) {
		$columns['nx_reward'] = __( 'Reward', 'nexora-commerce-toolkit' );
		return $columns;
	}

	/**
	 * Reward cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Coupon.
	 */
	public static function coupon_column( $column, $post_id ) {
		if ( 'nx_reward' !== $column ) {
			return;
		}
		$lines = array();
		$free  = self::free_item( $post_id );
		if ( $free ) {
			/* translators: 1: item, 2: quantity */
			$lines[] = sprintf( __( 'Free: %1$s × %2$d', 'nexora-commerce-toolkit' ), self::item_name( $free['item'] ), $free['qty'] );
		}
		$owner = self::owner( $post_id ) ? get_userdata( self::owner( $post_id ) ) : null;
		if ( $owner ) {
			/* translators: %s: customer */
			$lines[] = sprintf( __( 'For %s only', 'nexora-commerce-toolkit' ), $owner->display_name );
		}
		echo esc_html( $lines ? implode( ' · ', $lines ) : '—' );
	}

	/* ------------------------------------------------------- first customers */

	/**
	 * Promotion settings.
	 *
	 * @return array
	 */
	public static function settings() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'enabled'    => 0,
				'title'      => __( 'First customers reward', 'nexora-commerce-toolkit' ),
				'limit'      => 100,
				'start'      => 0,
				'end'        => 0,
				'type'       => 'percent',
				'amount'     => 10,
				'min'        => 0,
				'cats'       => array(),
				'free_item'  => 0,
				'free_qty'   => 1,
				'valid_days' => 30,
				'banner'     => 1,
			)
		);
	}

	/**
	 * Spots given out so far.
	 *
	 * @return int
	 */
	public static function taken() {
		return (int) get_option( self::TAKEN, 0 );
	}

	/**
	 * Is the promotion accepting new customers right now?
	 *
	 * @return bool
	 */
	public static function is_open() {
		$s   = self::settings();
		$now = time();
		return $s['enabled'] && ( ! $s['start'] || $now >= $s['start'] ) && ( ! $s['end'] || $now <= $s['end'] ) && self::taken() < (int) $s['limit'];
	}

	/**
	 * Take the next spot. The update only succeeds below the limit, so simultaneous registrations cannot
	 * exceed it.
	 *
	 * @param int $limit Limit.
	 * @return int Spot number, or 0 if none are left.
	 */
	private static function take_seat( $limit ) {
		global $wpdb;
		add_option( self::TAKEN, 0, '', false );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s AND option_value + 0 < %d", self::TAKEN, $limit ) );
		$seat = $done ? (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ) : 0;
		// phpcs:enable
		wp_cache_delete( self::TAKEN, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return $seat;
	}

	/**
	 * Give a new customer a spot and their personal coupon. Accounts made by staff or by import tools do not count.
	 *
	 * @param int $user_id New user.
	 */
	public static function on_register( $user_id ) {
		if ( ! self::is_open() || current_user_can( 'create_users' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( 'customer', (array) $user->roles, true ) || get_user_meta( $user_id, self::USER_META, true ) ) {
			return;
		}
		$s    = self::settings();
		$seat = self::take_seat( (int) $s['limit'] );
		if ( ! $seat ) {
			return;
		}
		$coupon = self::create_coupon( $s, $user_id, $seat );
		if ( ! $coupon ) {
			return;
		}
		update_user_meta( $user_id, self::USER_META, array( 'seat' => $seat, 'coupon' => $coupon, 'at' => time() ) );
		self::mail_reward( $user, $coupon, $seat );
	}

	/**
	 * Create a customer's personal coupon.
	 *
	 * @param array $s    Settings.
	 * @param int   $user User.
	 * @param int   $seat Spot.
	 * @return int Coupon ID, or 0.
	 */
	private static function create_coupon( $s, $user, $seat ) {
		$code = 'WELCOME-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
		$id   = wp_insert_post(
			array(
				'post_type'    => 'shop_coupon',
				'post_status'  => 'publish',
				'post_title'   => $code,
				/* translators: %d: spot number */
				'post_excerpt' => sprintf( __( 'First customers promotion, spot %d', 'nexora-commerce-toolkit' ), $seat ),
				'post_author'  => $user,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		$type = (string) $s['type'];
		$meta = array(
			'discount_type'        => 'percent' === $type ? 'percent' : 'fixed_cart',
			'coupon_amount'        => in_array( $type, array( 'percent', 'fixed' ), true ) ? (string) (float) $s['amount'] : '0',
			'minimum_amount'       => $s['min'] ? (string) (float) $s['min'] : '',
			'usage_limit'          => 1,
			'usage_limit_per_user' => 1,
			'usage_count'          => 0,
			'free_shipping'        => 'free_shipping' === $type ? 'yes' : 'no',
			'individual_use'       => 'no',
			'exclude_sale_items'   => 'no',
			'date_expires'         => $s['valid_days'] ? time() + (int) $s['valid_days'] * DAY_IN_SECONDS : '',
			'_nx_owner_user'       => $user,
		);
		if ( 'free_item' === $type ) {
			$meta['_nx_free_item']   = (int) $s['free_item'];
			$meta['_nx_free_qty']    = max( 1, (int) $s['free_qty'] );
			$meta['_nx_req_cats']    = array_map( 'absint', (array) $s['cats'] );
			$meta['_nx_first_order'] = 'no';
		} else {
			$meta['product_categories'] = array_map( 'absint', (array) $s['cats'] );
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return (int) $id;
	}

	/**
	 * Remove a deleted customer's personal coupon.
	 *
	 * @param int $user_id User.
	 */
	public static function on_user_deleted( $user_id ) {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => 20, 'fields' => 'ids', 'meta_key' => '_nx_owner_user', 'meta_value' => (int) $user_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Reward in words, for example "10% off" or "Free: Tilapia × 1".
	 *
	 * @param int $coupon Coupon.
	 * @return string
	 */
	public static function reward_text( $coupon ) {
		$free = self::free_item( $coupon );
		if ( $free ) {
			/* translators: 1: item, 2: quantity */
			return sprintf( __( 'Free: %1$s × %2$d', 'nexora-commerce-toolkit' ), self::item_name( $free['item'] ), $free['qty'] );
		}
		if ( 'yes' === get_post_meta( $coupon, 'free_shipping', true ) ) {
			return __( 'No delivery or pickup fee', 'nexora-commerce-toolkit' );
		}
		$amount = (float) get_post_meta( $coupon, 'coupon_amount', true );
		if ( 'percent' === get_post_meta( $coupon, 'discount_type', true ) ) {
			/* translators: %s: percent */
			return sprintf( __( '%s%% off your order', 'nexora-commerce-toolkit' ), $amount );
		}
		if ( 'fixed_product' === get_post_meta( $coupon, 'discount_type', true ) ) {
			/* translators: %s: amount */
			return sprintf( __( '%s off each item', 'nexora-commerce-toolkit' ), self::money( $amount ) );
		}
		/* translators: %s: amount */
		return sprintf( __( '%s off your order', 'nexora-commerce-toolkit' ), self::money( $amount ) );
	}

	/**
	 * Same as reward_text() from the settings, before any coupon exists.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function settings_reward_text( $s ) {
		switch ( $s['type'] ) {
			case 'free_item':
				/* translators: 1: item, 2: quantity */
				return sprintf( __( 'Free: %1$s × %2$d', 'nexora-commerce-toolkit' ), self::item_name( (int) $s['free_item'] ), max( 1, (int) $s['free_qty'] ) );
			case 'free_shipping':
				return __( 'No delivery or pickup fee', 'nexora-commerce-toolkit' );
			case 'fixed':
				/* translators: %s: amount */
				return sprintf( __( '%s off your order', 'nexora-commerce-toolkit' ), self::money( (float) $s['amount'] ) );
		}
		/* translators: %s: percent */
		return sprintf( __( '%s%% off your order', 'nexora-commerce-toolkit' ), (float) $s['amount'] );
	}

	/**
	 * Terms of a coupon in words.
	 *
	 * @param int $coupon Coupon.
	 * @return string
	 */
	private static function terms_text( $coupon ) {
		$parts = array();
		$min   = (float) get_post_meta( $coupon, 'minimum_amount', true );
		if ( $min > 0 ) {
			/* translators: %s: amount */
			$parts[] = sprintf( __( 'Minimum order %s.', 'nexora-commerce-toolkit' ), self::money( $min ) );
		}
		$expires = (int) get_post_meta( $coupon, 'date_expires', true );
		if ( $expires ) {
			/* translators: %s: date */
			$parts[] = sprintf( __( 'Use by %s.', 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ), $expires ) );
		}
		$parts[] = __( 'One use, on your account only.', 'nexora-commerce-toolkit' );
		return implode( ' ', $parts );
	}

	/**
	 * Email the customer their reward.
	 *
	 * @param WP_User $user   Customer.
	 * @param int     $coupon Coupon.
	 * @param int     $seat   Spot.
	 */
	private static function mail_reward( $user, $coupon, $seat ) {
		$s    = self::settings();
		$url  = self::is_wc() ? wc_get_page_permalink( 'myaccount' ) : Nexora_Store::url( 'account' );
		$m    = Nexora_Toolkit_Emails::compose(
			'reward',
			array(
				'first_name'   => $user->first_name,
				'reward_title' => (string) $s['title'],
				'spot'         => $seat,
			)
		);
		if ( ! $m ) {
			return;
		}
		$body = $m['message']
			. '<p style="font-size:18px;font-weight:bold;">' . esc_html( self::reward_text( $coupon ) ) . '</p>'
			. '<p>' . esc_html__( 'Your code', 'nexora-commerce-toolkit' ) . ': <strong>' . esc_html( get_the_title( $coupon ) ) . '</strong></p>'
			. '<p>' . esc_html( self::terms_text( $coupon ) ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Go to your account', 'nexora-commerce-toolkit' ) . '</a></p>';
		Nexora_Store_Orders::send_mail( $user->user_email, $m['subject'], $m['heading'], $body );
	}

	/* -------------------------------------------------------- customer output */

	/**
	 * Is this code already on the customer's cart?
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	private static function in_cart( $code ) {
		if ( self::is_wc() ) {
			return WC()->cart && WC()->cart->has_discount( $code );
		}
		return in_array( strtolower( $code ), array_map( 'strtolower', Nexora_Store_Cart::coupon_codes() ), true );
	}

	/**
	 * The signed-in customer's unused reward, as a card.
	 *
	 * @param bool $can_apply Offer a button that applies it to the cart.
	 * @return string
	 */
	public static function reward_card( $can_apply = false ) {
		$user = get_current_user_id();
		$info = $user ? get_user_meta( $user, self::USER_META, true ) : array();
		if ( empty( $info['coupon'] ) || 'publish' !== get_post_status( (int) $info['coupon'] ) ) {
			return '';
		}
		$coupon  = (int) $info['coupon'];
		$expires = (int) get_post_meta( $coupon, 'date_expires', true );
		if ( (int) get_post_meta( $coupon, 'usage_count', true ) > 0 || ( $expires && time() > $expires ) ) {
			return '';
		}
		$code = get_the_title( $coupon );
		if ( $can_apply && self::in_cart( $code ) ) {
			return '';
		}
		$s = self::settings();
		if ( $can_apply ) {
			$action = '<a class="button nx-reward-card__btn" href="' . esc_url( wp_nonce_url( add_query_arg( 'nx_reward_apply', 1 ), 'nexora_reward_apply', '_nx_reward' ) ) . '">' . esc_html__( 'Use on this order', 'nexora-commerce-toolkit' ) . '</a>';
		} else {
			$shop   = self::is_wc() ? wc_get_page_permalink( 'shop' ) : Nexora_Store::shop_url();
			$action = '<a class="button nx-reward-card__btn" href="' . esc_url( $shop ) . '">' . esc_html__( 'Shop the market', 'nexora-commerce-toolkit' ) . '</a>';
		}
		return '<section class="nx-reward-card" aria-label="' . esc_attr( (string) $s['title'] ) . '"><div class="nx-reward-card__text">'
			. '<p class="nx-reward-card__eyebrow">' . esc_html( (string) $s['title'] ) . '</p>'
			. '<p class="nx-reward-card__title">' . esc_html( self::reward_text( $coupon ) ) . '</p>'
			. '<p class="nx-reward-card__terms">' . esc_html( self::terms_text( $coupon ) ) . '</p>'
			. '<p class="nx-reward-card__code">' . esc_html__( 'Your code', 'nexora-commerce-toolkit' ) . ': <code>' . esc_html( $code ) . '</code></p>'
			. '</div>' . $action . '</section>';
	}

	/**
	 * Registration form banner while spots are left.
	 *
	 * @return string
	 */
	public static function signup_banner() {
		$s = self::settings();
		if ( ! $s['banner'] || ! self::is_open() ) {
			return '';
		}
		$left = max( 0, (int) $s['limit'] - self::taken() );
		return '<p class="nx-reward-banner"><strong>' . esc_html( (string) $s['title'] ) . ':</strong> '
			/* translators: 1: number of customers, 2: reward */
			. esc_html( sprintf( __( 'the first %1$d customers to register get: %2$s.', 'nexora-commerce-toolkit' ), (int) $s['limit'], self::settings_reward_text( $s ) ) ) . ' '
			/* translators: %d: spots */
			. '<span class="nx-reward-banner__left">' . esc_html( sprintf( _n( '%d spot left', '%d spots left', $left, 'nexora-commerce-toolkit' ), $left ) ) . '</span></p>';
	}

	/**
	 * "Use on this order" button.
	 */
	public static function handle_apply() {
		if ( is_admin() || empty( $_GET['nx_reward_apply'] ) || ! isset( $_GET['_nx_reward'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_nx_reward'] ) ), 'nexora_reward_apply' ) ) {
			return;
		}
		$info = get_user_meta( get_current_user_id(), self::USER_META, true );
		if ( ! empty( $info['coupon'] ) ) {
			$code = get_the_title( (int) $info['coupon'] );
			if ( self::is_wc() ) {
				if ( WC()->cart ) {
					WC()->cart->apply_coupon( $code );
				}
			} else {
				$result = Nexora_Store_Cart::apply_coupon( $code );
				Nexora_Store_Cart::notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Coupon applied.', 'nexora-commerce-toolkit' ) );
			}
		}
		wp_safe_redirect( remove_query_arg( array( 'nx_reward_apply', '_nx_reward' ) ) );
		exit;
	}

	/**
	 * Front-end styles.
	 */
	public static function assets() {
		$relevant = self::is_wc() ? ( is_cart() || is_checkout() || is_account_page() ) : Nexora_Store::is_store_request();
		if ( $relevant ) {
			wp_enqueue_style( 'nexora-rewards', NEXORA_TOOLKIT_URL . 'assets/css/rewards.css', array(), NEXORA_TOOLKIT_VERSION );
		}
	}

	/* ----------------------------------------------------------- admin screen */

	/**
	 * Capability for the promotion screen.
	 *
	 * @return string
	 */
	private static function cap() {
		return self::is_wc() ? 'manage_woocommerce' : Nexora_Store_Admin::CAP_STORE;
	}

	/**
	 * Add the promotion screen to whichever store menu exists.
	 */
	public static function menu() {
		add_submenu_page( self::is_wc() ? 'woocommerce' : 'nexora-store', __( 'First customers promotion', 'nexora-commerce-toolkit' ), __( 'First customers', 'nexora-commerce-toolkit' ), self::cap(), self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Admin styles and field toggles.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( false === strpos( (string) $hook, self::PAGE ) && ! ( $screen && 'shop_coupon' === $screen->post_type && 'post' === $screen->base ) ) {
			return;
		}
		wp_enqueue_style( 'nexora-rewards-admin', NEXORA_TOOLKIT_URL . 'assets/css/rewards-admin.css', array(), NEXORA_TOOLKIT_VERSION );
		wp_enqueue_script( 'nexora-rewards-admin', NEXORA_TOOLKIT_URL . 'assets/js/rewards-admin.js', array(), NEXORA_TOOLKIT_VERSION, true );
	}

	/**
	 * Posted date to the start or end of that day in the store's time zone.
	 *
	 * @param string $value Date.
	 * @param bool   $end   End of day.
	 * @return int
	 */
	private static function parse_date( $value, $end ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return 0;
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Save the promotion.
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nexora-commerce-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'nexora_first_customers' );
		$text = static function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		};
		$type = sanitize_key( $text( 'type' ) );
		$s    = array(
			'enabled'    => $text( 'enabled' ) ? 1 : 0,
			'title'      => $text( 'title' ) ? $text( 'title' ) : self::settings()['title'],
			'limit'      => max( 1, absint( $text( 'limit' ) ) ),
			'start'      => self::parse_date( $text( 'start' ), false ),
			'end'        => self::parse_date( $text( 'end' ), true ),
			'type'       => in_array( $type, array( 'percent', 'fixed', 'free_shipping', 'free_item' ), true ) ? $type : 'percent',
			'amount'     => max( 0, (float) $text( 'amount' ) ),
			'min'        => max( 0, (float) $text( 'min' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			'cats'       => isset( $_POST['cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['cats'] ) ) : array(),
			'free_item'  => absint( $text( 'free_item' ) ),
			'free_qty'   => max( 1, absint( $text( 'free_qty' ) ) ),
			'valid_days' => absint( $text( 'valid_days' ) ),
			'banner'     => $text( 'banner' ) ? 1 : 0,
		);
		if ( 'percent' === $s['type'] ) {
			$s['amount'] = min( 100, $s['amount'] );
		}
		$error = '';
		if ( 'free_item' === $s['type'] && ! $s['free_item'] ) {
			$error = 'item';
		} elseif ( in_array( $s['type'], array( 'percent', 'fixed' ), true ) && $s['amount'] <= 0 ) {
			$error = 'amount';
		} elseif ( $s['start'] && $s['end'] && $s['end'] < $s['start'] ) {
			$error = 'dates';
		}
		if ( $error ) {
			$s['enabled'] = 0;
		}
		update_option( self::OPTION, $s, false );
		wp_safe_redirect( add_query_arg( $error ? array( 'page' => self::PAGE, 'nx_error' => $error ) : array( 'page' => self::PAGE, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Start counting from zero again. Rewards already given stay valid.
	 */
	public static function handle_reset() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nexora-commerce-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'nexora_first_customers_reset' );
		update_option( self::TAKEN, 0, false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'reset' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Promotion screen.
	 */
	public static function page() {
		$s     = self::settings();
		$taken = self::taken();
		$date  = static function ( $ts ) {
			return $ts ? wp_date( 'Y-m-d', (int) $ts ) : '';
		};
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- messages only.
		$error = isset( $_GET['nx_error'] ) ? sanitize_key( wp_unslash( $_GET['nx_error'] ) ) : '';
		$saved = ! empty( $_GET['updated'] );
		$reset = ! empty( $_GET['reset'] );
		// phpcs:enable
		$errors = array(
			'item'   => __( 'Choose the free item. The promotion was saved but turned off.', 'nexora-commerce-toolkit' ),
			'amount' => __( 'Enter a discount amount above zero. The promotion was saved but turned off.', 'nexora-commerce-toolkit' ),
			'dates'  => __( 'The end date is before the start date. The promotion was saved but turned off.', 'nexora-commerce-toolkit' ),
		);
		if ( ! $s['enabled'] ) {
			$state = __( 'Off', 'nexora-commerce-toolkit' );
		} elseif ( $taken >= (int) $s['limit'] ) {
			$state = __( 'All spots taken', 'nexora-commerce-toolkit' );
		} elseif ( $s['start'] && time() < $s['start'] ) {
			$state = __( 'Scheduled', 'nexora-commerce-toolkit' );
		} elseif ( $s['end'] && time() > $s['end'] ) {
			$state = __( 'Ended', 'nexora-commerce-toolkit' );
		} else {
			$state = __( 'Running', 'nexora-commerce-toolkit' );
		}
		?>
		<div class="wrap nx-reward-admin">
			<h1><?php esc_html_e( 'First customers promotion', 'nexora-commerce-toolkit' ); ?></h1>
			<?php if ( $error && isset( $errors[ $error ] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( $errors[ $error ] ); ?></p></div><?php endif; ?>
			<?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Promotion saved.', 'nexora-commerce-toolkit' ); ?></p></div><?php endif; ?>
			<?php if ( $reset ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The count was reset. Rewards already given stay valid.', 'nexora-commerce-toolkit' ); ?></p></div><?php endif; ?>
			<p class="nx-reward-admin__status">
				<strong><?php echo esc_html( $state ); ?></strong>
				<?php /* translators: 1: spots taken, 2: limit */ ?>
				<span><?php echo esc_html( sprintf( __( '%1$d of %2$d spots taken', 'nexora-commerce-toolkit' ), $taken, (int) $s['limit'] ) ); ?></span>
			</p>
			<p class="description"><?php esc_html_e( 'Each customer who registers while spots are left gets a personal coupon by email and on their account page. It works only on their account and only once. Spots are counted when the customer registers, whether or not they use the reward. Accounts created by staff or import tools do not count.', 'nexora-commerce-toolkit' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nexora_first_customers_save">
				<?php wp_nonce_field( 'nexora_first_customers' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( (bool) $s['enabled'] ); ?>> <?php esc_html_e( 'Run this promotion', 'nexora-commerce-toolkit' ); ?></label></td></tr>
					<tr><th><label for="nx_title"><?php esc_html_e( 'Name customers see', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="text" class="regular-text" id="nx_title" name="title" value="<?php echo esc_attr( (string) $s['title'] ); ?>"></td></tr>
					<tr><th><label for="nx_limit"><?php esc_html_e( 'Number of customers', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="1" step="1" id="nx_limit" name="limit" value="<?php echo esc_attr( (string) $s['limit'] ); ?>"></td></tr>
					<tr><th><label for="nx_start"><?php esc_html_e( 'Starts on', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="date" id="nx_start" name="start" value="<?php echo esc_attr( $date( $s['start'] ) ); ?>"><p class="description"><?php esc_html_e( 'Empty = as soon as it is turned on.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
					<tr><th><label for="nx_end"><?php esc_html_e( 'Ends on', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="date" id="nx_end" name="end" value="<?php echo esc_attr( $date( $s['end'] ) ); ?>"><p class="description"><?php esc_html_e( 'Empty = until all spots are taken.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
					<tr><th><label for="nx_reward_type"><?php esc_html_e( 'Reward', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<select id="nx_reward_type" name="type" data-nx-reward-type>
							<option value="percent" <?php selected( $s['type'], 'percent' ); ?>><?php esc_html_e( 'Percent off', 'nexora-commerce-toolkit' ); ?></option>
							<option value="fixed" <?php selected( $s['type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed amount off', 'nexora-commerce-toolkit' ); ?></option>
							<option value="free_shipping" <?php selected( $s['type'], 'free_shipping' ); ?>><?php esc_html_e( 'No delivery or pickup fee', 'nexora-commerce-toolkit' ); ?></option>
							<option value="free_item" <?php selected( $s['type'], 'free_item' ); ?>><?php esc_html_e( 'Free item', 'nexora-commerce-toolkit' ); ?></option>
						</select></td></tr>
					<tr data-nx-reward-for="percent fixed"><th><label for="nx_amount"><?php esc_html_e( 'Amount', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="nx_amount" name="amount" value="<?php echo esc_attr( (string) $s['amount'] ); ?>"></td></tr>
					<tr data-nx-reward-for="free_item"><th><label for="nx_free_item"><?php esc_html_e( 'Free item', 'nexora-commerce-toolkit' ); ?></label></th><td><select id="nx_free_item" name="free_item" class="nx-reward-select"><option value="0"><?php esc_html_e( 'Choose a product…', 'nexora-commerce-toolkit' ); ?></option><?php self::item_options( (int) $s['free_item'] ); ?></select></td></tr>
					<tr data-nx-reward-for="free_item"><th><label for="nx_free_qty"><?php esc_html_e( 'How many free', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="1" step="1" id="nx_free_qty" name="free_qty" value="<?php echo esc_attr( (string) $s['free_qty'] ); ?>"></td></tr>
					<tr><th><label for="nx_min"><?php esc_html_e( 'Minimum order', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="nx_min" name="min" value="<?php echo esc_attr( $s['min'] ? (string) $s['min'] : '' ); ?>"></td></tr>
					<tr><th><label for="cats"><?php esc_html_e( 'Only for these categories', 'nexora-commerce-toolkit' ); ?></label></th><td><?php self::category_select( 'cats', array_map( 'absint', (array) $s['cats'] ) ); ?><p class="description"><?php esc_html_e( 'Empty = all products.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
					<tr><th><label for="nx_days"><?php esc_html_e( 'Days to use it', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="1" id="nx_days" name="valid_days" value="<?php echo esc_attr( (string) $s['valid_days'] ); ?>"><p class="description"><?php esc_html_e( 'Counted from the day the customer registers. 0 = no expiry.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Registration page', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="banner" value="1" <?php checked( (bool) $s['banner'] ); ?>> <?php esc_html_e( 'Show the offer and the spots left on the registration form', 'nexora-commerce-toolkit' ); ?></label></td></tr>
				</table>
				<p class="description"><?php esc_html_e( 'Changes apply to customers who register from now on. Coupons already given keep their original terms.', 'nexora-commerce-toolkit' ); ?></p>
				<?php submit_button( __( 'Save promotion', 'nexora-commerce-toolkit' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Customers with a spot', 'nexora-commerce-toolkit' ); ?></h2>
			<?php self::render_claims(); ?>

			<?php if ( $taken ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nx-reward-admin__reset">
					<input type="hidden" name="action" value="nexora_first_customers_reset">
					<?php wp_nonce_field( 'nexora_first_customers_reset' ); ?>
					<button type="submit" class="button" data-nx-confirm="<?php esc_attr_e( 'Start counting from zero? Rewards already given stay valid.', 'nexora-commerce-toolkit' ); ?>"><?php esc_html_e( 'Start a new round (reset the count)', 'nexora-commerce-toolkit' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Table of customers who hold a spot.
	 */
	private static function render_claims() {
		$users = get_users( array( 'meta_key' => self::USER_META, 'number' => 200, 'orderby' => 'ID', 'order' => 'DESC' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $users ) {
			echo '<p>' . esc_html__( 'Nobody yet.', 'nexora-commerce-toolkit' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Spot', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Customer', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Code', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Reward', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Status', 'nexora-commerce-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			$info    = (array) get_user_meta( $user->ID, self::USER_META, true );
			$coupon  = isset( $info['coupon'] ) ? (int) $info['coupon'] : 0;
			$expires = $coupon ? (int) get_post_meta( $coupon, 'date_expires', true ) : 0;
			if ( ! $coupon || 'publish' !== get_post_status( $coupon ) ) {
				$status = __( 'Coupon removed', 'nexora-commerce-toolkit' );
			} elseif ( (int) get_post_meta( $coupon, 'usage_count', true ) > 0 ) {
				$status = __( 'Used', 'nexora-commerce-toolkit' );
			} elseif ( $expires && time() > $expires ) {
				$status = __( 'Expired', 'nexora-commerce-toolkit' );
			} else {
				$status = __( 'Not used yet', 'nexora-commerce-toolkit' );
			}
			printf(
				'<tr><td>%1$d</td><td>%2$s<br><small>%3$s</small></td><td><code>%4$s</code></td><td>%5$s</td><td>%6$s</td></tr>',
				isset( $info['seat'] ) ? (int) $info['seat'] : 0,
				esc_html( $user->display_name ),
				esc_html( $user->user_email ),
				esc_html( $coupon ? get_the_title( $coupon ) : '—' ),
				esc_html( $coupon ? self::reward_text( $coupon ) : '—' ),
				esc_html( $status )
			);
		}
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------ WooCommerce */

	/**
	 * WooCommerce hooks.
	 */
	private static function init_woocommerce() {
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'wc_is_valid' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'wc_sync' ), 20 );
		add_filter( 'woocommerce_coupon_discount_amount_html', array( __CLASS__, 'wc_amount_html' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'wc_item_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( __CLASS__, 'wc_remove_link' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'wc_item_name' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'wc_item_price' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'wc_item_price' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'wc_order_item' ), 10, 3 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'wc_free_delivery' ), 20 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'wc_print_apply_card' ), 20 ); // After the notices, which span the cart grid.
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'wc_print_apply_card' ), 5 );
		// Cart and checkout blocks skip the classic hooks above.
		add_filter( 'render_block_woocommerce/cart', array( __CLASS__, 'wc_block_card' ) );
		add_filter( 'render_block_woocommerce/checkout', array( __CLASS__, 'wc_block_card' ) );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'wc_block_quantity' ), 10, 3 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'wc_print_card' ) );
		add_action( 'woocommerce_register_form_start', array( __CLASS__, 'wc_print_banner' ) );
	}

	/**
	 * Paid lines of the WooCommerce cart in the shape check() expects.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array
	 */
	private static function wc_rows( $cart ) {
		$rows = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['nx_gift'] ) ) {
				$rows[] = array( 'product' => (int) $item['product_id'], 'variation' => (int) $item['variation_id'], 'qty' => (int) $item['quantity'] );
			}
		}
		return $rows;
	}

	/**
	 * Apply the extra rules when WooCommerce validates a coupon on the cart.
	 *
	 * @param bool         $valid     Valid so far.
	 * @param WC_Coupon    $coupon    Coupon.
	 * @param WC_Discounts $discounts Discounts.
	 * @return bool
	 * @throws Exception WooCommerce shows the message to the customer.
	 */
	public static function wc_is_valid( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! ( $discounts->get_object() instanceof WC_Cart ) ) {
			return $valid;
		}
		$check = self::check( $coupon->get_id(), get_current_user_id(), self::wc_rows( $discounts->get_object() ) );
		if ( is_wp_error( $check ) ) {
			throw new Exception( esc_html( $check->get_error_message() ) );
		}
		return $valid;
	}

	/**
	 * Keep free items in step with the applied coupons, and make them free.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function wc_sync( $cart ) {
		if ( ! self::$syncing ) {
			self::$syncing = true;
			$wanted        = array();
			$rows          = self::wc_rows( $cart );
			foreach ( $cart->get_applied_coupons() as $code ) {
				$coupon = new WC_Coupon( $code );
				$free   = $coupon->get_id() ? self::free_item( $coupon->get_id() ) : null;
				if ( $free && true === self::check( $coupon->get_id(), get_current_user_id(), $rows ) ) {
					$wanted[ $coupon->get_id() ] = $free;
				}
			}
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( empty( $item['nx_gift'] ) ) {
					continue;
				}
				$id   = (int) $item['nx_gift'];
				$line = $item['variation_id'] ? (int) $item['variation_id'] : (int) $item['product_id'];
				if ( isset( $wanted[ $id ] ) && $wanted[ $id ]['item'] === $line ) {
					$cart->cart_contents[ $key ]['quantity'] = $wanted[ $id ]['qty'];
					unset( $wanted[ $id ] );
				} else {
					$cart->remove_cart_item( $key );
				}
			}
			foreach ( $wanted as $coupon_id => $free ) {
				$product = wc_get_product( $free['item'] );
				if ( $product ) {
					$parent = $product->get_parent_id();
					$cart->add_to_cart( $parent ? $parent : $product->get_id(), $free['qty'], $parent ? $product->get_id() : 0, $parent ? $product->get_variation_attributes() : array(), array( 'nx_gift' => $coupon_id ) );
				}
			}
			self::$syncing = false;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['nx_gift'] ) ) {
				$item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Coupon row on the cart and checkout.
	 *
	 * @param string    $html   HTML.
	 * @param WC_Coupon $coupon Coupon.
	 * @return string
	 */
	public static function wc_amount_html( $html, $coupon ) {
		return self::free_item( $coupon->get_id() ) ? esc_html__( 'Free item', 'nexora-commerce-toolkit' ) : $html;
	}

	/**
	 * Free items have a fixed quantity.
	 *
	 * @param string $html Quantity field.
	 * @param string $key  Cart key.
	 * @param array  $item Cart item.
	 * @return string
	 */
	public static function wc_item_quantity( $html, $key, $item ) {
		return empty( $item['nx_gift'] ) ? $html : (string) (int) $item['quantity'];
	}

	/**
	 * Free items leave the cart when their coupon is removed.
	 *
	 * @param string $html Link.
	 * @param string $key  Cart key.
	 * @return string
	 */
	public static function wc_remove_link( $html, $key ) {
		$item = WC()->cart ? WC()->cart->get_cart_item( $key ) : array();
		return empty( $item['nx_gift'] ) ? $html : '';
	}

	/**
	 * Label the free item.
	 *
	 * @param string $name Name.
	 * @param array  $item Cart item.
	 * @return string
	 */
	public static function wc_item_name( $name, $item ) {
		return empty( $item['nx_gift'] ) ? $name : $name . ' <span class="nx-gift-tag">' . esc_html__( 'Free item', 'nexora-commerce-toolkit' ) . '</span>';
	}

	/**
	 * Show "Free" instead of a zero price.
	 *
	 * @param string $html Price.
	 * @param array  $item Cart item.
	 * @return string
	 */
	public static function wc_item_price( $html, $item ) {
		return empty( $item['nx_gift'] ) ? $html : esc_html__( 'Free', 'nexora-commerce-toolkit' );
	}

	/**
	 * Mark the free item on the order.
	 *
	 * @param WC_Order_Item_Product $line Order line.
	 * @param string                $key  Cart key.
	 * @param array                 $item Cart item.
	 */
	public static function wc_order_item( $line, $key, $item ) {
		if ( ! empty( $item['nx_gift'] ) ) {
			$line->add_meta_data( __( 'Free item', 'nexora-commerce-toolkit' ), get_the_title( (int) $item['nx_gift'] ), true );
		}
	}

	/**
	 * WooCommerce only makes its own Free Shipping method free when a coupon asks for it, so a personal
	 * reward has to clear the fee of the pickup and courier methods itself.
	 *
	 * @param array $rates Rates.
	 * @return array
	 */
	public static function wc_free_delivery( $rates ) {
		if ( ! WC()->cart ) {
			return $rates;
		}
		foreach ( WC()->cart->get_coupons() as $coupon ) {
			if ( self::owner( $coupon->get_id() ) && $coupon->get_free_shipping() ) {
				foreach ( $rates as $rate ) {
					$rate->set_cost( 0 );
					$rate->set_taxes( array() );
				}
				break;
			}
		}
		return $rates;
	}

	/**
	 * Reward card with a button, for the cart and checkout.
	 */
	public static function wc_print_apply_card() {
		echo self::reward_card( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
	}

	/**
	 * Reward card above the cart or checkout block.
	 *
	 * @param string $html Block output.
	 * @return string
	 */
	public static function wc_block_card( $html ) {
		return self::reward_card( true ) . $html;
	}

	/**
	 * Cart block: free items have a fixed quantity.
	 *
	 * @param bool       $editable Editable.
	 * @param WC_Product $product  Product.
	 * @param array      $item     Cart item.
	 * @return bool
	 */
	public static function wc_block_quantity( $editable, $product, $item ) {
		return empty( $item['nx_gift'] ) ? $editable : false;
	}

	/**
	 * Reward card for the account dashboard.
	 */
	public static function wc_print_card() {
		echo self::reward_card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
	}

	/**
	 * Offer on the registration form.
	 */
	public static function wc_print_banner() {
		echo self::signup_banner(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
	}
}
