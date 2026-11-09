<?php
/**
 * Coupons (customer enters a code; stored as shop_coupon in WooCommerce's format) and promotions
 * (applied automatically while they run).
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coupons and promotions.
 */
class Nexora_Store_Coupons {

	const PROMO = 'nx_promotion';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_shop_coupon', array( __CLASS__, 'save_coupon' ), 10, 2 );
		add_action( 'save_post_' . self::PROMO, array( __CLASS__, 'save_promo' ), 10, 2 );
		add_filter( 'manage_shop_coupon_posts_columns', array( __CLASS__, 'coupon_columns' ) );
		add_action( 'manage_shop_coupon_posts_custom_column', array( __CLASS__, 'coupon_column' ), 10, 2 );
		add_filter( 'manage_' . self::PROMO . '_posts_columns', array( __CLASS__, 'promo_columns' ) );
		add_action( 'manage_' . self::PROMO . '_posts_custom_column', array( __CLASS__, 'promo_column' ), 10, 2 );
	}

	/**
	 * Register post types.
	 */
	public static function register() {
		register_post_type(
			'shop_coupon',
			array(
				'labels'          => array(
					'name'          => __( 'Coupons', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Coupon', 'nexora-commerce-toolkit' ),
					'add_new'       => __( 'Add coupon', 'nexora-commerce-toolkit' ),
					'add_new_item'  => __( 'Add coupon', 'nexora-commerce-toolkit' ),
					'edit_item'     => __( 'Edit coupon', 'nexora-commerce-toolkit' ),
					'not_found'     => __( 'No coupons yet', 'nexora-commerce-toolkit' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'nexora-store',
				'capability_type' => 'shop_coupon',
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
		register_post_type(
			self::PROMO,
			array(
				'labels'          => array(
					'name'          => __( 'Promotions', 'nexora-commerce-toolkit' ),
					'singular_name' => __( 'Promotion', 'nexora-commerce-toolkit' ),
					'add_new'       => __( 'Add promotion', 'nexora-commerce-toolkit' ),
					'add_new_item'  => __( 'Add promotion', 'nexora-commerce-toolkit' ),
					'edit_item'     => __( 'Edit promotion', 'nexora-commerce-toolkit' ),
					'not_found'     => __( 'No promotions yet', 'nexora-commerce-toolkit' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'nexora-store',
				'capability_type' => 'shop_coupon',
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/* ------------------------------------------------------------ evaluation */

	/**
	 * Find a published coupon by code (case-insensitive).
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function find( $code ) {
		global $wpdb;
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status = 'publish' AND LOWER(post_title) = LOWER(%s) ORDER BY ID DESC LIMIT 1", $code ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $id ) {
			return null;
		}
		$m = static function ( $key ) use ( $id ) {
			return get_post_meta( $id, $key, true );
		};
		$ids  = static function ( $value ) {
			return array_values( array_filter( array_map( 'absint', is_array( $value ) ? $value : explode( ',', (string) $value ) ) ) );
		};
		$free = Nexora_Toolkit_Rewards::free_item( $id );
		return array(
			'id'             => $id,
			'code'           => get_the_title( $id ),
			'type'           => $m( 'discount_type' ) ? (string) $m( 'discount_type' ) : 'fixed_cart',
			'amount'         => (float) $m( 'coupon_amount' ),
			'expires'        => (int) $m( 'date_expires' ),
			'min'            => (float) $m( 'minimum_amount' ),
			'max'            => (float) $m( 'maximum_amount' ),
			'limit'          => (int) $m( 'usage_limit' ),
			'limit_user'     => $free || Nexora_Toolkit_Rewards::owner( $id ) ? 1 : (int) $m( 'usage_limit_per_user' ),
			'count'          => (int) $m( 'usage_count' ),
			'free_shipping'  => 'yes' === $m( 'free_shipping' ),
			'individual'     => 'yes' === $m( 'individual_use' ),
			'exclude_sale'   => 'yes' === $m( 'exclude_sale_items' ),
			'products'       => $ids( $m( 'product_ids' ) ),
			'categories'     => $ids( $m( 'product_categories' ) ),
			'emails'         => array_values( array_filter( array_map( 'strval', (array) $m( 'customer_email' ) ) ) ),
			'free_item'      => $free,
		);
	}

	/**
	 * Whether a cart line qualifies for a coupon or promotion.
	 *
	 * @param array $line       Line.
	 * @param array $products   Allowed products (empty = all).
	 * @param array $categories Allowed categories (empty = all).
	 * @param bool  $no_sale    Exclude items on sale.
	 * @return bool
	 */
	private static function line_eligible( $line, $products, $categories, $no_sale ) {
		if ( $no_sale && $line['item']->is_on_sale() ) {
			return false;
		}
		$id = $line['product']->get_id();
		if ( $products && ! in_array( $id, $products, true ) && ! in_array( $line['item']->get_id(), $products, true ) ) {
			return false;
		}
		if ( $categories ) {
			$terms = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				foreach ( array_merge( array( $term ), get_ancestors( $term, 'product_cat' ) ) as $candidate ) {
					if ( in_array( (int) $candidate, $categories, true ) ) {
						return true;
					}
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Check a coupon against the cart and customer.
	 *
	 * @param array  $coupon Coupon.
	 * @param array  $lines  Cart lines.
	 * @param int    $user   User.
	 * @param string $email  Email.
	 * @return true|WP_Error
	 */
	public static function validate( $coupon, $lines, $user = 0, $email = '' ) {
		$fail = static function ( $message ) {
			return new WP_Error( 'nexora_coupon', $message );
		};
		if ( $coupon['expires'] && time() > $coupon['expires'] ) {
			return $fail( __( 'This coupon has expired.', 'nexora-commerce-toolkit' ) );
		}
		$rule = Nexora_Toolkit_Rewards::check(
			$coupon['id'],
			$user,
			array_map(
				static function ( $line ) {
					return array(
						'product'   => $line['product']->get_id(),
						'variation' => $line['item']->get_parent_id() ? $line['item']->get_id() : 0,
						'qty'       => (int) $line['qty'],
					);
				},
				array_values( $lines )
			)
		);
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		if ( $coupon['emails'] && ! self::email_allowed( $coupon['emails'], $user, $email ) ) {
			return $fail( __( 'This coupon is not available for your account.', 'nexora-commerce-toolkit' ) );
		}
		if ( $coupon['limit'] && $coupon['count'] >= $coupon['limit'] ) {
			return $fail( __( 'This coupon has reached its usage limit.', 'nexora-commerce-toolkit' ) );
		}
		if ( $coupon['limit_user'] ) {
			$used = array_map( 'strval', (array) get_post_meta( $coupon['id'], '_used_by' ) );
			$me   = array_filter( array( $user ? (string) $user : '', $email ? strtolower( $email ) : '' ) );
			$n    = 0;
			foreach ( $used as $who ) {
				$n += in_array( strtolower( $who ), $me, true ) ? 1 : 0;
			}
			if ( $n >= $coupon['limit_user'] ) {
				return $fail( __( 'You have already used this coupon the maximum number of times.', 'nexora-commerce-toolkit' ) );
			}
		}
		$subtotal = 0.0;
		$eligible = 0.0;
		foreach ( $lines as $line ) {
			$subtotal += $line['total'];
			if ( self::line_eligible( $line, $coupon['products'], $coupon['categories'], $coupon['exclude_sale'] ) ) {
				$eligible += $line['total'];
			}
		}
		if ( $coupon['min'] > 0 && $subtotal < $coupon['min'] ) {
			/* translators: %s: amount */
			return $fail( sprintf( __( 'This coupon needs a minimum order of %s.', 'nexora-commerce-toolkit' ), Nexora_Store::money( $coupon['min'] ) ) );
		}
		if ( $coupon['max'] > 0 && $subtotal > $coupon['max'] ) {
			/* translators: %s: amount */
			return $fail( sprintf( __( 'This coupon is for orders up to %s.', 'nexora-commerce-toolkit' ), Nexora_Store::money( $coupon['max'] ) ) );
		}
		if ( $eligible <= 0 && ! $coupon['free_shipping'] && ! $coupon['free_item'] ) {
			return $fail( __( 'This coupon does not apply to the items in your cart.', 'nexora-commerce-toolkit' ) );
		}
		return true;
	}

	/**
	 * Whether the customer matches a coupon's allowed emails. Checked again at checkout, once a guest has
	 * given an email address. Like WooCommerce, "*" works as a wildcard.
	 *
	 * @param string[] $allowed Allowed emails.
	 * @param int      $user    User.
	 * @param string   $email   Email entered at checkout.
	 * @return bool
	 */
	public static function email_allowed( $allowed, $user, $email ) {
		$mine = array( $email );
		if ( $user ) {
			$account = get_userdata( $user );
			$mine[]  = $account ? $account->user_email : '';
			$mine[]  = (string) get_user_meta( $user, 'billing_email', true );
		}
		$mine = array_filter( $mine );
		if ( ! $mine ) {
			return true;
		}
		foreach ( $allowed as $pattern ) {
			$regex = '/^' . str_replace( '\\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
			foreach ( $mine as $address ) {
				if ( preg_match( $regex, $address ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * What a discount row shows: the amount, or a word when the reward is not money off.
	 *
	 * @param array $discount Discount row.
	 * @param bool  $plain    Plain text instead of HTML.
	 * @return string
	 */
	public static function discount_value( $discount, $plain = false ) {
		if ( (float) $discount['amount'] > 0 ) {
			return '-' . ( $plain ? Nexora_Store::money( $discount['amount'] ) : Nexora_Store::price( $discount['amount'] ) );
		}
		$word = ! empty( $discount['free_item'] ) ? __( 'Free item', 'nexora-commerce-toolkit' ) : __( 'Applied', 'nexora-commerce-toolkit' );
		return $plain ? $word : esc_html( $word );
	}

	/**
	 * Promotions running now.
	 *
	 * @return array[]
	 */
	public static function active_promotions() {
		$out = array();
		$now = time();
		foreach ( get_posts( array( 'post_type' => self::PROMO, 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC' ) ) as $post ) {
			$start = (int) get_post_meta( $post->ID, '_nx_start', true );
			$end   = (int) get_post_meta( $post->ID, '_nx_end', true );
			if ( ( $start && $now < $start ) || ( $end && $now > $end ) ) {
				continue;
			}
			$out[] = array(
				'id'     => $post->ID,
				'label'  => get_the_title( $post ),
				'type'   => (string) get_post_meta( $post->ID, '_nx_type', true ),
				'amount' => (float) get_post_meta( $post->ID, '_nx_amount', true ),
				'min'    => (float) get_post_meta( $post->ID, '_nx_min', true ),
				'cats'   => array_values( array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_nx_cats', true ) ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Work out discounts: the single best promotion, then each valid coupon, never below zero.
	 *
	 * @param array    $lines Cart lines.
	 * @param string[] $codes Coupon codes.
	 * @param int      $user  User.
	 * @param string   $email Email.
	 * @return array{discounts:array,gifts:array,total:float,free_shipping:bool,invalid:array}
	 */
	public static function calculate( $lines, $codes, $user = 0, $email = '' ) {
		$subtotal = 0.0;
		foreach ( $lines as $line ) {
			$subtotal += $line['total'];
		}
		$remaining = $subtotal;
		$discounts = array();
		$free      = false;
		$invalid   = array();
		$gifts     = array();

		$best = null;
		foreach ( self::active_promotions() as $promo ) {
			if ( $promo['min'] > 0 && $subtotal < $promo['min'] ) {
				continue;
			}
			if ( 'free_shipping' === $promo['type'] ) {
				$free = true;
				continue;
			}
			$base = 0.0;
			foreach ( $lines as $line ) {
				if ( self::line_eligible( $line, array(), $promo['cats'], false ) ) {
					$base += $line['total'];
				}
			}
			$amount = 'percent' === $promo['type'] ? $base * min( 100, $promo['amount'] ) / 100 : min( $base, $promo['amount'] );
			if ( $amount > 0 && ( ! $best || $amount > $best['amount'] ) ) {
				$best = array( 'label' => $promo['label'], 'amount' => $amount, 'code' => '', 'promo' => $promo['id'] );
			}
		}
		if ( $best ) {
			$best['amount'] = Nexora_Store::round( min( $remaining, $best['amount'] ) );
			$remaining     -= $best['amount'];
			$discounts[]    = $best;
		}

		foreach ( $codes as $code ) {
			$coupon = self::find( $code );
			$check  = $coupon ? self::validate( $coupon, $lines, $user, $email ) : new WP_Error( 'nexora_coupon', __( 'This coupon is no longer valid.', 'nexora-commerce-toolkit' ) );
			if ( is_wp_error( $check ) ) {
				$invalid[ $code ] = $check->get_error_message();
				continue;
			}
			$free   = $free || $coupon['free_shipping'];
			$amount = 0.0;
			if ( $coupon['free_item'] ) {
				$gifts[ $coupon['id'] ] = $coupon['free_item'];
			}
			if ( 'fixed_cart' === $coupon['type'] ) {
				$base = 0.0;
				foreach ( $lines as $line ) {
					if ( self::line_eligible( $line, $coupon['products'], $coupon['categories'], $coupon['exclude_sale'] ) ) {
						$base += $line['total'];
					}
				}
				$amount = min( $base, $coupon['amount'] );
			} else {
				foreach ( $lines as $line ) {
					if ( ! self::line_eligible( $line, $coupon['products'], $coupon['categories'], $coupon['exclude_sale'] ) ) {
						continue;
					}
					$amount += 'percent' === $coupon['type']
						? $line['total'] * min( 100, $coupon['amount'] ) / 100
						: min( $line['price'], $coupon['amount'] ) * $line['qty'];
				}
			}
			$amount = Nexora_Store::round( min( $remaining, $amount ) );
			$remaining -= $amount;
			$discounts[] = array(
				/* translators: %s: coupon code */
				'label'     => sprintf( __( 'Coupon: %s', 'nexora-commerce-toolkit' ), $coupon['code'] ),
				'amount'    => $amount,
				'code'      => $coupon['code'],
				'id'        => $coupon['id'],
				'free_item' => (bool) $coupon['free_item'],
			);
		}
		return array(
			'discounts'     => $discounts,
			'gifts'         => $gifts,
			'total'         => Nexora_Store::round( $subtotal - $remaining ),
			'free_shipping' => $free,
			'invalid'       => $invalid,
		);
	}

	/**
	 * Count a coupon's use when an order is placed. The update only succeeds below the limit, so two
	 * customers cannot take the last use at the same moment.
	 *
	 * @param string $code  Code.
	 * @param string $who   User ID or email.
	 * @return bool
	 */
	public static function use_coupon( $code, $who ) {
		global $wpdb;
		$coupon = self::find( $code );
		if ( ! $coupon ) {
			return false;
		}
		if ( '' === (string) get_post_meta( $coupon['id'], 'usage_count', true ) ) {
			update_post_meta( $coupon['id'], 'usage_count', 0 );
		}
		if ( $coupon['limit_user'] ) {
			// Runs inside the order transaction: the row lock is held until commit, so one customer cannot
			// spend a one-time coupon twice from two tabs or devices. Locking reads see the latest data.
			$wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'usage_count' FOR UPDATE", $coupon['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_used_by' AND meta_value = %s FOR UPDATE", $coupon['id'], $who ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( count( $rows ) >= $coupon['limit_user'] ) {
				return false;
			}
		}
		if ( $coupon['limit'] ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'usage_count' AND meta_value + 0 < %d", $coupon['id'], $coupon['limit'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $done ) {
				return false;
			}
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'usage_count'", $coupon['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		add_post_meta( $coupon['id'], '_used_by', $who );
		wp_cache_delete( $coupon['id'], 'post_meta' );
		return true;
	}

	/**
	 * Give back a coupon use (cancelled order).
	 *
	 * @param string $code Code.
	 * @param string $who  User ID or email.
	 */
	public static function release_coupon( $code, $who ) {
		global $wpdb;
		$coupon = self::find( $code );
		if ( $coupon ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(meta_value - 1, 0) WHERE post_id = %d AND meta_key = 'usage_count'", $coupon['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_post_meta( $coupon['id'], '_used_by', $who );
			wp_cache_delete( $coupon['id'], 'post_meta' );
		}
	}

	/* ----------------------------------------------------------------- admin */

	/**
	 * Meta boxes.
	 */
	public static function boxes() {
		add_meta_box( 'nx-coupon', __( 'Coupon details', 'nexora-commerce-toolkit' ), array( __CLASS__, 'coupon_box' ), 'shop_coupon', 'normal', 'high' );
		add_meta_box( 'nx-promo', __( 'Promotion details', 'nexora-commerce-toolkit' ), array( __CLASS__, 'promo_box' ), self::PROMO, 'normal', 'high' );
	}

	/**
	 * Category checklist.
	 *
	 * @param string $name     Field name.
	 * @param int[]  $selected Selected IDs.
	 */
	private static function category_select( $name, $selected ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		echo '<select multiple size="6" class="nx-admin-multi" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '[]">';
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $term->term_id, in_array( (int) $term->term_id, $selected, true ) ? ' selected' : '', esc_html( $term->name ) );
		}
		echo '</select>';
	}

	/**
	 * Timestamp to a date input value.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	private static function date_value( $ts ) {
		return $ts ? wp_date( 'Y-m-d', (int) $ts ) : '';
	}

	/**
	 * Posted date to a timestamp at the start or end of that day in the store's time zone.
	 *
	 * @param string $key   Field.
	 * @param bool   $end   End of day.
	 * @return int
	 */
	private static function posted_date( $key, $end = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce.
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return 0;
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	/**
	 * Coupon form.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function coupon_box( $post ) {
		$m    = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$type = $m( 'discount_type' ) ? $m( 'discount_type' ) : 'percent';
		wp_nonce_field( 'nexora_coupon', 'nexora_coupon_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'The title above is the code customers type at checkout, for example FRESH10.', 'nexora-commerce-toolkit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="discount_type"><?php esc_html_e( 'Discount type', 'nexora-commerce-toolkit' ); ?></label></th><td>
				<select id="discount_type" name="discount_type">
					<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percent off', 'nexora-commerce-toolkit' ); ?></option>
					<option value="fixed_cart" <?php selected( $type, 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed amount off the cart', 'nexora-commerce-toolkit' ); ?></option>
					<option value="fixed_product" <?php selected( $type, 'fixed_product' ); ?>><?php esc_html_e( 'Fixed amount off each item', 'nexora-commerce-toolkit' ); ?></option>
				</select></td></tr>
			<tr><th><label for="coupon_amount"><?php esc_html_e( 'Amount', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="coupon_amount" name="coupon_amount" value="<?php echo esc_attr( (string) $m( 'coupon_amount' ) ); ?>"></td></tr>
			<tr><th><label for="date_expires"><?php esc_html_e( 'Expires on', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="date" id="date_expires" name="date_expires" value="<?php echo esc_attr( self::date_value( (int) $m( 'date_expires' ) ) ); ?>"><p class="description"><?php esc_html_e( 'Valid through the end of this day. Empty = no expiry.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
			<tr><th><label for="minimum_amount"><?php esc_html_e( 'Minimum order', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="minimum_amount" name="minimum_amount" value="<?php echo esc_attr( (string) $m( 'minimum_amount' ) ); ?>"></td></tr>
			<tr><th><label for="maximum_amount"><?php esc_html_e( 'Maximum order', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="maximum_amount" name="maximum_amount" value="<?php echo esc_attr( (string) $m( 'maximum_amount' ) ); ?>"></td></tr>
			<tr><th><label for="usage_limit"><?php esc_html_e( 'Total uses allowed', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="1" id="usage_limit" name="usage_limit" value="<?php echo esc_attr( (string) $m( 'usage_limit' ) ); ?>">
				<?php /* translators: %d: uses */ ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Used %d times so far. Empty = unlimited.', 'nexora-commerce-toolkit' ), (int) $m( 'usage_count' ) ) ); ?></p></td></tr>
			<tr><th><label for="usage_limit_per_user"><?php esc_html_e( 'Uses per customer', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="1" id="usage_limit_per_user" name="usage_limit_per_user" value="<?php echo esc_attr( (string) $m( 'usage_limit_per_user' ) ); ?>"></td></tr>
			<tr><th><label for="customer_email"><?php esc_html_e( 'Only these customers', 'nexora-commerce-toolkit' ); ?></label></th><td><textarea id="customer_email" name="customer_email" rows="2" class="large-text"><?php echo esc_textarea( implode( ', ', array_map( 'strval', (array) $m( 'customer_email' ) ) ) ); ?></textarea><p class="description"><?php esc_html_e( 'Email addresses separated by commas. Empty = any customer.', 'nexora-commerce-toolkit' ); ?></p></td></tr>
			<tr><th><label for="product_categories"><?php esc_html_e( 'Only these categories', 'nexora-commerce-toolkit' ); ?></label></th><td><?php self::category_select( 'product_categories', array_map( 'absint', (array) $m( 'product_categories' ) ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Options', 'nexora-commerce-toolkit' ); ?></th><td>
				<label><input type="checkbox" name="free_shipping" value="yes" <?php checked( 'yes', $m( 'free_shipping' ) ); ?>> <?php esc_html_e( 'Also removes the delivery or pickup fee', 'nexora-commerce-toolkit' ); ?></label><br>
				<label><input type="checkbox" name="individual_use" value="yes" <?php checked( 'yes', $m( 'individual_use' ) ); ?>> <?php esc_html_e( 'Cannot be combined with other coupons', 'nexora-commerce-toolkit' ); ?></label><br>
				<label><input type="checkbox" name="exclude_sale_items" value="yes" <?php checked( 'yes', $m( 'exclude_sale_items' ) ); ?>> <?php esc_html_e( 'Does not apply to items already on sale', 'nexora-commerce-toolkit' ); ?></label>
			</td></tr>
		</table>
		<?php
	}

	/**
	 * Save a coupon.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_coupon( $post_id, $post ) {
		if ( ! isset( $_POST['nexora_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_coupon_nonce'] ) ), 'nexora_coupon' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$type = isset( $_POST['discount_type'] ) ? sanitize_key( wp_unslash( $_POST['discount_type'] ) ) : 'percent';
		update_post_meta( $post_id, 'discount_type', in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ? $type : 'percent' );
		foreach ( array( 'coupon_amount', 'minimum_amount', 'maximum_amount' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? max( 0, (float) wp_unslash( $_POST[ $key ] ) ) : 0;
			update_post_meta( $post_id, $key, $value ? (string) $value : '' );
		}
		foreach ( array( 'usage_limit', 'usage_limit_per_user' ) as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0 );
		}
		update_post_meta( $post_id, 'date_expires', self::posted_date( 'date_expires', true ) ?: '' ); // phpcs:ignore Universal.Operators.DisallowShortTernary
		foreach ( array( 'free_shipping', 'individual_use', 'exclude_sale_items' ) as $key ) {
			update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? 'yes' : 'no' );
		}
		$emails = isset( $_POST['customer_email'] ) ? preg_split( '/[\s,;]+/', strtolower( sanitize_textarea_field( wp_unslash( $_POST['customer_email'] ) ) ) ) : array();
		update_post_meta( $post_id, 'customer_email', array_values( array_filter( (array) $emails ) ) );
		update_post_meta( $post_id, 'product_categories', isset( $_POST['product_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['product_categories'] ) ) : array() );
		if ( '' === (string) get_post_meta( $post_id, 'usage_count', true ) ) {
			update_post_meta( $post_id, 'usage_count', 0 );
		}
		unset( $post );
	}

	/**
	 * Promotion form.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function promo_box( $post ) {
		$m    = static function ( $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};
		$type = $m( '_nx_type' ) ? $m( '_nx_type' ) : 'percent';
		wp_nonce_field( 'nexora_promo', 'nexora_promo_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Promotions apply automatically, with no code. The title above is what customers see, for example “Market Day: 5% off”. If several promotions qualify, the customer gets the biggest one.', 'nexora-commerce-toolkit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="nx_type"><?php esc_html_e( 'Discount type', 'nexora-commerce-toolkit' ); ?></label></th><td>
				<select id="nx_type" name="nx_type">
					<option value="percent" <?php selected( $type, 'percent' ); ?>><?php esc_html_e( 'Percent off', 'nexora-commerce-toolkit' ); ?></option>
					<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount off', 'nexora-commerce-toolkit' ); ?></option>
					<option value="free_shipping" <?php selected( $type, 'free_shipping' ); ?>><?php esc_html_e( 'No delivery or pickup fee', 'nexora-commerce-toolkit' ); ?></option>
				</select></td></tr>
			<tr><th><label for="nx_amount"><?php esc_html_e( 'Amount', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="nx_amount" name="nx_amount" value="<?php echo esc_attr( (string) $m( '_nx_amount' ) ); ?>"></td></tr>
			<tr><th><label for="nx_min"><?php esc_html_e( 'Minimum order', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="number" min="0" step="0.01" id="nx_min" name="nx_min" value="<?php echo esc_attr( (string) $m( '_nx_min' ) ); ?>"></td></tr>
			<tr><th><label for="nx_start"><?php esc_html_e( 'Starts on', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="date" id="nx_start" name="nx_start" value="<?php echo esc_attr( self::date_value( (int) $m( '_nx_start' ) ) ); ?>"></td></tr>
			<tr><th><label for="nx_end"><?php esc_html_e( 'Ends on', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="date" id="nx_end" name="nx_end" value="<?php echo esc_attr( self::date_value( (int) $m( '_nx_end' ) ) ); ?>"></td></tr>
			<tr><th><label for="nx_cats"><?php esc_html_e( 'Only these categories', 'nexora-commerce-toolkit' ); ?></label></th><td><?php self::category_select( 'nx_cats', array_map( 'absint', (array) $m( '_nx_cats' ) ) ); ?></td></tr>
		</table>
		<?php
	}

	/**
	 * Save a promotion.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_promo( $post_id, $post ) {
		if ( ! isset( $_POST['nexora_promo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nexora_promo_nonce'] ) ), 'nexora_promo' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$type = isset( $_POST['nx_type'] ) ? sanitize_key( wp_unslash( $_POST['nx_type'] ) ) : 'percent';
		update_post_meta( $post_id, '_nx_type', in_array( $type, array( 'percent', 'fixed', 'free_shipping' ), true ) ? $type : 'percent' );
		update_post_meta( $post_id, '_nx_amount', isset( $_POST['nx_amount'] ) ? max( 0, (float) wp_unslash( $_POST['nx_amount'] ) ) : 0 );
		update_post_meta( $post_id, '_nx_min', isset( $_POST['nx_min'] ) ? max( 0, (float) wp_unslash( $_POST['nx_min'] ) ) : 0 );
		update_post_meta( $post_id, '_nx_start', self::posted_date( 'nx_start' ) );
		update_post_meta( $post_id, '_nx_end', self::posted_date( 'nx_end', true ) );
		update_post_meta( $post_id, '_nx_cats', isset( $_POST['nx_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['nx_cats'] ) ) : array() );
		unset( $post );
	}

	/**
	 * Coupon list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function coupon_columns( $columns ) {
		return array(
			'cb'        => $columns['cb'],
			'title'     => __( 'Code', 'nexora-commerce-toolkit' ),
			'nx_amount' => __( 'Discount', 'nexora-commerce-toolkit' ),
			'nx_used'   => __( 'Used', 'nexora-commerce-toolkit' ),
			'nx_expiry' => __( 'Expires', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Coupon list cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public static function coupon_column( $column, $post_id ) {
		$m = static function ( $key ) use ( $post_id ) {
			return get_post_meta( $post_id, $key, true );
		};
		if ( 'nx_amount' === $column && Nexora_Toolkit_Rewards::free_item( $post_id ) ) {
			esc_html_e( 'Free item', 'nexora-commerce-toolkit' );
		} elseif ( 'nx_amount' === $column ) {
			echo esc_html( 'percent' === $m( 'discount_type' ) ? (float) $m( 'coupon_amount' ) . '%' : Nexora_Store::money( (float) $m( 'coupon_amount' ) ) );
		} elseif ( 'nx_used' === $column ) {
			echo esc_html( (int) $m( 'usage_count' ) . ( $m( 'usage_limit' ) ? ' / ' . (int) $m( 'usage_limit' ) : '' ) );
		} elseif ( 'nx_expiry' === $column ) {
			echo esc_html( $m( 'date_expires' ) ? wp_date( get_option( 'date_format' ), (int) $m( 'date_expires' ) ) : '—' );
		}
	}

	/**
	 * Promotion list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function promo_columns( $columns ) {
		return array(
			'cb'        => $columns['cb'],
			'title'     => __( 'Promotion', 'nexora-commerce-toolkit' ),
			'nx_amount' => __( 'Discount', 'nexora-commerce-toolkit' ),
			'nx_window' => __( 'Runs', 'nexora-commerce-toolkit' ),
			'nx_state'  => __( 'Status', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Promotion list cell.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public static function promo_column( $column, $post_id ) {
		$type  = get_post_meta( $post_id, '_nx_type', true );
		$start = (int) get_post_meta( $post_id, '_nx_start', true );
		$end   = (int) get_post_meta( $post_id, '_nx_end', true );
		$fmt   = get_option( 'date_format' );
		if ( 'nx_amount' === $column ) {
			$amount = (float) get_post_meta( $post_id, '_nx_amount', true );
			echo esc_html( 'free_shipping' === $type ? __( 'No fee', 'nexora-commerce-toolkit' ) : ( 'percent' === $type ? $amount . '%' : Nexora_Store::money( $amount ) ) );
		} elseif ( 'nx_window' === $column ) {
			echo esc_html( ( $start ? wp_date( $fmt, $start ) : '…' ) . ' – ' . ( $end ? wp_date( $fmt, $end ) : '…' ) );
		} elseif ( 'nx_state' === $column ) {
			$now = time();
			if ( 'publish' !== get_post_status( $post_id ) ) {
				esc_html_e( 'Draft', 'nexora-commerce-toolkit' );
			} elseif ( $start && $now < $start ) {
				esc_html_e( 'Scheduled', 'nexora-commerce-toolkit' );
			} elseif ( $end && $now > $end ) {
				esc_html_e( 'Ended', 'nexora-commerce-toolkit' );
			} else {
				esc_html_e( 'Running', 'nexora-commerce-toolkit' );
			}
		}
	}
}
