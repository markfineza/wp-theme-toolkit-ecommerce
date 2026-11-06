<?php
/**
 * Checkout of the built-in store: customer details, delivery or pickup with a time slot, payment
 * and the order success page. Uses the theme's checkout layout.
 *
 * Online card and wallet payments need a gateway; add one through the `nexora_store_gateways` filter.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checkout.
 */
class Nexora_Store_Checkout {

	/**
	 * Errors from the last submit.
	 *
	 * @var array<string,string>
	 */
	private static $errors = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'process' ), 5 );
	}

	/* --------------------------------------------------------------- options */

	/**
	 * Store address on one line.
	 *
	 * @return string
	 */
	public static function store_address() {
		return trim( (string) Nexora_Store::get( 'address' ) );
	}

	/**
	 * Pickup points.
	 *
	 * @return array<int,array{name:string,address:string,hours:string}>
	 */
	public static function pickup_locations() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) Nexora_Store::get( 'pickup_locations' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$out[] = array(
				'name'    => sanitize_text_field( $parts[0] ),
				'address' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '',
				'hours'   => isset( $parts[2] ) ? sanitize_text_field( $parts[2] ) : '',
			);
		}
		return $out ? $out : array(
			array(
				'name'    => get_bloginfo( 'name' ),
				'address' => self::store_address(),
				'hours'   => '',
			),
		);
	}

	/**
	 * Courier names.
	 *
	 * @return string[]
	 */
	public static function couriers() {
		return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) Nexora_Store::get( 'couriers' ) ) ) ) ) );
	}

	/**
	 * Bookable days and slots.
	 *
	 * @return array
	 */
	public static function schedule() {
		$out = Nexora_Toolkit_Schedule::build( Nexora_Store::get( 'slots' ), Nexora_Store::get( 'lead_hours' ), Nexora_Store::get( 'days_ahead' ), Nexora_Store::get( 'closed_days' ) );
		return (array) apply_filters( 'nexora_store_schedule', $out );
	}

	/**
	 * Payment methods.
	 *
	 * Each: title, desc, types (delivery options it works with, empty = all), status (after ordering),
	 * needs_ref (customer enters a reference). A gateway may add `process`: callable( $order_id ) returning a redirect URL.
	 *
	 * @return array<string,array>
	 */
	public static function gateways() {
		$out = array();
		if ( Nexora_Store::get( 'cod_enabled' ) ) {
			$out['cod'] = array(
				'title'     => (string) Nexora_Store::get( 'cod_title' ),
				'desc'      => __( 'Pay in cash when you collect your order at the store.', 'nexora-commerce-toolkit' ),
				'types'     => array( 'pickup' ),
				'status'    => 'processing',
				'needs_ref' => false,
			);
		}
		if ( Nexora_Store::get( 'bank_enabled' ) ) {
			$out['bank'] = array(
				'title'     => (string) Nexora_Store::get( 'bank_title' ),
				'desc'      => Nexora_Store::get( 'bank_details' ) ? (string) Nexora_Store::get( 'bank_details' ) : __( 'We will send our bank details by email. We prepare your order once the payment is confirmed.', 'nexora-commerce-toolkit' ),
				'types'     => array(),
				'status'    => 'on-hold',
				'needs_ref' => false,
			);
		}
		if ( Nexora_Store::get( 'ewallet_enabled' ) ) {
			$out['ewallet'] = array(
				'title'     => (string) Nexora_Store::get( 'ewallet_title' ),
				'desc'      => (string) Nexora_Store::get( 'ewallet_details' ),
				'types'     => array(),
				'status'    => 'on-hold',
				'needs_ref' => true,
			);
		}
		return (array) apply_filters( 'nexora_store_gateways', $out );
	}

	/**
	 * Order success URL (with the private key, so guests can see their own order).
	 *
	 * @param object $order Order.
	 * @return string
	 */
	public static function received_url( $order ) {
		return add_query_arg( 'key', $order->order_key, Nexora_Store::url( 'checkout', 'order-received/' . (int) $order->id ) );
	}

	/* ---------------------------------------------------------------- submit */

	/**
	 * Posted value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read after process() verifies the nonce, or to refill the form.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Handle the checkout form.
	 */
	public static function process() {
		if ( ! Nexora_Store::is_page( 'checkout' ) || empty( $_POST['nx_checkout'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_nx_checkout'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_nx_checkout'] ) ), 'nexora_checkout' ) ) {
			self::$errors['form'] = __( 'Your session expired. Please review your details and try again.', 'nexora-commerce-toolkit' );
			return;
		}

		global $wpdb;
		$lock = 'nx_co_' . md5( get_current_user_id() . '|' . ( isset( $_COOKIE[ Nexora_Store_Cart::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Nexora_Store_Cart::COOKIE ] ) ) : '' ) );
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$url = self::submit();
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Validate and place the order.
	 *
	 * @return string Redirect URL, or '' with self::$errors set.
	 */
	private static function submit() {
		$e     = array();
		$user  = get_current_user_id();
		$first = self::posted( 'first_name' );
		$last  = self::posted( 'last_name' );
		$email = sanitize_email( self::posted( 'email' ) );
		$phone = self::posted( 'phone' );
		$type  = sanitize_key( self::posted( 'shipping' ) );

		if ( '' === $first ) {
			$e['first_name'] = __( 'Please enter your first name.', 'nexora-commerce-toolkit' );
		}
		if ( '' === $last ) {
			$e['last_name'] = __( 'Please enter your last name.', 'nexora-commerce-toolkit' );
		}
		if ( ! is_email( $email ) ) {
			$e['email'] = __( 'Please enter a valid email address.', 'nexora-commerce-toolkit' );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
			$e['phone'] = __( 'Please enter a valid mobile number, for example 0917 123 4567.', 'nexora-commerce-toolkit' );
		}

		$t = Nexora_Store_Cart::totals( $type, $email );
		if ( ! $t['lines'] ) {
			self::$errors['form'] = __( 'Your cart is empty.', 'nexora-commerce-toolkit' );
			return '';
		}
		foreach ( $t['invalid'] as $code => $message ) {
			/* translators: 1: coupon code, 2: reason */
			$e[ 'coupon_' . $code ] = sprintf( __( 'Coupon %1$s: %2$s Please remove it to continue.', 'nexora-commerce-toolkit' ), $code, $message );
		}
		if ( $t['shipping_key'] !== $type ) {
			$e['shipping'] = __( 'Please choose how you will get your order.', 'nexora-commerce-toolkit' );
		}

		$address = array(
			'first_name' => $first,
			'last_name'  => $last,
			'address_1'  => self::posted( 'address_1' ),
			'address_2'  => self::posted( 'address_2' ),
			'city'       => self::posted( 'city' ),
			'state'      => self::posted( 'state' ),
			'postcode'   => self::posted( 'postcode' ),
			'country'    => 'PH',
		);
		if ( 'pickup' !== $type ) {
			if ( '' === $address['address_1'] ) {
				$e['address_1'] = __( 'Please enter your street address.', 'nexora-commerce-toolkit' );
			}
			if ( '' === $address['city'] ) {
				$e['city'] = __( 'Please enter your city or town.', 'nexora-commerce-toolkit' );
			}
		}

		// Delivery details, checked against the live schedule.
		$parts    = explode( '|', self::posted( 'nx_schedule' ) );
		$schedule = self::schedule();
		$date     = '';
		$slot     = '';
		if ( 2 === count( $parts ) && isset( $schedule[ $parts[0] ]['slots'][ $parts[1] ] ) ) {
			list( $date, $slot ) = $parts;
		} elseif ( '' === self::posted( 'nx_schedule' ) ) {
			$e['nx_schedule'] = __( 'Please choose a day and time.', 'nexora-commerce-toolkit' );
		} else {
			$e['nx_schedule'] = __( 'That time is no longer available. Please choose another.', 'nexora-commerce-toolkit' );
		}
		$fulfilment = array(
			'type'   => $type,
			'method' => $t['shipping_title'],
			'date'   => $date,
			'slot'   => $slot,
		);
		if ( 'pickup' === $type ) {
			$locations = self::pickup_locations();
			$index     = (int) self::posted( 'nx_location' );
			if ( ! isset( $locations[ $index ] ) ) {
				$e['nx_location'] = __( 'Please choose a pickup point.', 'nexora-commerce-toolkit' );
			} else {
				$fulfilment['location'] = $locations[ $index ]['name'];
				$fulfilment['address']  = $locations[ $index ]['address'];
				$fulfilment['hours']    = $locations[ $index ]['hours'];
			}
		} elseif ( 'courier' === $type ) {
			$courier = self::posted( 'nx_courier' );
			$allowed = array_merge( self::couriers(), array( 'other' ) );
			if ( ! in_array( $courier, $allowed, true ) ) {
				$e['nx_courier'] = __( 'Please choose the courier you will book.', 'nexora-commerce-toolkit' );
			}
			if ( '1' !== self::posted( 'nx_courier_ack' ) ) {
				$e['nx_courier_ack'] = __( 'Please confirm that you will book the courier yourself.', 'nexora-commerce-toolkit' );
			}
			$fulfilment['courier'] = 'other' === $courier ? ( self::posted( 'nx_courier_other' ) ? mb_substr( self::posted( 'nx_courier_other' ), 0, 60 ) : __( 'Other courier', 'nexora-commerce-toolkit' ) ) : $courier;
			$fulfilment['address'] = self::store_address();
			$fulfilment['contact'] = (string) Nexora_Store::get( 'courier_contact' );
		}

		// Payment.
		$gateways = self::gateways();
		$pay      = sanitize_key( self::posted( 'payment_method' ) );
		$ref      = mb_substr( self::posted( 'payment_ref' ), 0, 100 );
		if ( ! isset( $gateways[ $pay ] ) || ( $gateways[ $pay ]['types'] && ! in_array( $type, $gateways[ $pay ]['types'], true ) ) ) {
			$e['payment_method'] = __( 'Please choose a payment method available for your delivery option.', 'nexora-commerce-toolkit' );
		} elseif ( $gateways[ $pay ]['needs_ref'] && '' === $ref ) {
			$e['payment_ref'] = __( 'Please enter your payment reference number.', 'nexora-commerce-toolkit' );
		}

		// Guests.
		$create = false;
		if ( ! $user ) {
			$create = '1' === self::posted( 'createaccount' );
			if ( ! Nexora_Store::get( 'guest_checkout' ) && ! $create ) {
				$e['createaccount'] = __( 'Please create an account or log in to place your order.', 'nexora-commerce-toolkit' );
			}
			if ( $create ) {
				if ( email_exists( $email ) ) {
					$e['email'] = __( 'An account with this email already exists. Please log in first.', 'nexora-commerce-toolkit' );
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce verified in process(); passwords are not sanitized.
				if ( strlen( isset( $_POST['account_password'] ) ? (string) $_POST['account_password'] : '' ) < 8 ) {
					$e['account_password'] = __( 'Please choose a password of at least 8 characters.', 'nexora-commerce-toolkit' );
				}
			}
		}

		// Throttle repeat submissions per visitor address.
		$rate_key = 'nx_co_rate_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$tries    = (int) get_transient( $rate_key );
		if ( $tries >= 30 ) {
			$e['form'] = __( 'Too many attempts. Please wait a few minutes and try again.', 'nexora-commerce-toolkit' );
		}
		set_transient( $rate_key, $tries + 1, 10 * MINUTE_IN_SECONDS );

		if ( $e ) {
			self::$errors = $e;
			return '';
		}

		if ( $create ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
			$new = Nexora_Store_Account::create_customer( $email, (string) wp_unslash( $_POST['account_password'] ), $first, $last, $phone );
			if ( is_wp_error( $new ) ) {
				self::$errors['email'] = $new->get_error_message();
				return '';
			}
			$user = (int) $new;
			wp_set_current_user( $user );
			wp_set_auth_cookie( $user, true );
			do_action( 'wp_login', get_userdata( $user )->user_login, get_userdata( $user ) );
			$t = Nexora_Store_Cart::totals( $type, $email );
		}

		$id = Nexora_Store_Orders::create(
			$t,
			array(
				'user_id'    => $user,
				'email'      => $email,
				'phone'      => $phone,
				'address'    => $address,
				'type'       => $type,
				'date'       => $date,
				'fulfilment' => $fulfilment,
				'note'       => sanitize_textarea_field( wp_unslash( isset( $_POST['order_comments'] ) ? (string) $_POST['order_comments'] : '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
				'payment'    => array(
					'id'    => $pay,
					'title' => $gateways[ $pay ]['title'],
					'ref'   => $ref,
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			self::$errors['form'] = $id->get_error_message();
			return '';
		}

		if ( $user ) {
			foreach ( $address as $key => $value ) {
				if ( '' !== $value ) {
					update_user_meta( $user, 'billing_' . $key, $value );
				}
			}
			update_user_meta( $user, 'billing_phone', $phone );
			update_user_meta( $user, 'billing_email', $email );
			if ( ! empty( $fulfilment['courier'] ) ) {
				update_user_meta( $user, 'nexora_preferred_courier', self::posted( 'nx_courier' ) );
			}
		}
		Nexora_Store_Cart::clear();

		$status = (float) $t['total'] <= 0 ? 'processing' : $gateways[ $pay ]['status'];
		Nexora_Store_Orders::mail_new_order( $id );
		if ( 'pending' !== $status ) {
			self::silently_set_initial_status( $id, $status );
		}
		do_action( 'nexora_store_order_placed', $id );

		$order = Nexora_Store_Orders::get( $id );
		if ( isset( $gateways[ $pay ]['process'] ) && is_callable( $gateways[ $pay ]['process'] ) ) {
			$redirect = call_user_func( $gateways[ $pay ]['process'], $id );
			if ( $redirect ) {
				return (string) $redirect;
			}
		}
		return self::received_url( $order );
	}

	/**
	 * Set the first status without a second email (the customer has just had "Order received").
	 *
	 * @param int    $id     Order.
	 * @param string $status Status.
	 */
	private static function silently_set_initial_status( $id, $status ) {
		global $wpdb;
		$fields = array( 'status' => $status );
		$order  = Nexora_Store_Orders::get( $id );
		if ( $order && 'processing' === $status && 'cod' !== $order->payment_method ) {
			$fields['paid_at'] = gmdate( 'Y-m-d H:i:s' ); // Nothing left to pay: a free order.
		}
		$wpdb->update( "{$wpdb->prefix}nx_orders", $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Nexora_Store_Orders::flush_kpis();
	}

	/* ---------------------------------------------------------------- output */

	/**
	 * Value to refill a field with: posted, else from the account.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function value( $key ) {
		if ( isset( $_POST['nx_checkout'] ) && isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return self::posted( $key );
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return '';
		}
		if ( 'email' === $key ) {
			return (string) $user->user_email;
		}
		$saved = (string) get_user_meta( $user->ID, 'billing_' . $key, true );
		if ( '' === $saved && in_array( $key, array( 'first_name', 'last_name' ), true ) ) {
			return (string) $user->$key;
		}
		return $saved;
	}

	/**
	 * Text field row.
	 *
	 * @param string $key      Field.
	 * @param string $label    Label.
	 * @param string $class    Row class.
	 * @param bool   $required Required.
	 * @param array  $attrs    type, autocomplete, placeholder.
	 */
	private static function field( $key, $label, $class = 'form-row-wide', $required = true, $attrs = array() ) {
		$error = isset( self::$errors[ $key ] );
		printf(
			'<p class="form-row %1$s%2$s" id="%3$s_field"><label for="%3$s">%4$s%5$s</label><span class="woocommerce-input-wrapper"><input type="%6$s" class="input-text" name="%3$s" id="%3$s" value="%7$s" autocomplete="%8$s" placeholder="%9$s"%10$s%11$s></span></p>',
			esc_attr( $class ),
			$error ? ' woocommerce-invalid' : '',
			esc_attr( $key ),
			esc_html( $label ),
			$required ? '&nbsp;<abbr class="required" title="' . esc_attr__( 'required', 'nexora-commerce-toolkit' ) . '">*</abbr>' : '&nbsp;<span class="optional">' . esc_html__( '(optional)', 'nexora-commerce-toolkit' ) . '</span>',
			esc_attr( isset( $attrs['type'] ) ? $attrs['type'] : 'text' ),
			esc_attr( self::value( $key ) ),
			esc_attr( isset( $attrs['autocomplete'] ) ? $attrs['autocomplete'] : 'on' ),
			esc_attr( isset( $attrs['placeholder'] ) ? $attrs['placeholder'] : '' ),
			$required ? ' aria-required="true"' : '',
			$error ? ' aria-invalid="true"' : ''
		);
	}

	/**
	 * Numbered step heading.
	 *
	 * @param int    $n     Number.
	 * @param string $key   Key.
	 * @param string $title Title.
	 * @param string $intro Intro.
	 */
	private static function step_head( $n, $key, $title, $intro ) {
		printf(
			'<header class="nx-co-card__head"><span class="nx-co-card__num" aria-hidden="true">%1$d</span><div><h2 class="nx-co-card__title" id="nx-step-%2$s-title">%3$s</h2><p class="nx-co-card__intro">%4$s</p></div></header>',
			(int) $n,
			esc_attr( $key ),
			esc_html( $title ),
			esc_html( $intro )
		);
	}

	/**
	 * Order summary table (also returned by the totals endpoint).
	 *
	 * @param array $t Totals.
	 * @return string
	 */
	public static function summary_html( $t ) {
		$out = '<table class="shop_table woocommerce-checkout-review-order-table nx-co-review"><thead class="screen-reader-text"><tr><th>' . esc_html__( 'Product', 'nexora-commerce-toolkit' ) . '</th><th>' . esc_html__( 'Subtotal', 'nexora-commerce-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( $t['lines'] as $line ) {
			$out .= '<tr class="cart_item"><td class="product-name"><div class="nx-co-item"><span class="nx-co-item__thumb">' . $line['item']->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) . '<span class="nx-co-item__badge" aria-hidden="true">' . (int) $line['qty'] . '</span></span><span class="nx-co-item__text"><span class="nx-co-item__name">' . esc_html( $line['product']->get_name() ) . '</span>' . ( $line['option'] ? '<span class="nx-store-option">' . esc_html( $line['option'] ) . '</span>' : '' ) . '<strong class="product-quantity">&times;&nbsp;' . (int) $line['qty'] . '</strong></span></div></td><td class="product-total">' . Nexora_Store::price( $line['total'] ) . '</td></tr>';
		}
		$out .= '</tbody><tfoot><tr class="cart-subtotal"><th>' . esc_html__( 'Subtotal', 'nexora-commerce-toolkit' ) . '</th><td>' . Nexora_Store::price( $t['subtotal'] ) . '</td></tr>';
		foreach ( $t['discounts'] as $d ) {
			$remove = $d['code'] ? ' <button type="button" class="nx-store-link" data-nx-remove-coupon="' . esc_attr( $d['code'] ) . '">' . esc_html__( 'Remove', 'nexora-commerce-toolkit' ) . '</button>' : '';
			$out   .= '<tr class="cart-discount"><th>' . esc_html( $d['label'] ) . $remove . '</th><td>-' . Nexora_Store::price( $d['amount'] ) . '</td></tr>';
		}
		foreach ( $t['invalid'] as $code => $message ) {
			$out .= '<tr class="cart-discount is-invalid"><th colspan="2">' . esc_html( $code . ': ' . $message ) . ' <button type="button" class="nx-store-link" data-nx-remove-coupon="' . esc_attr( $code ) . '">' . esc_html__( 'Remove', 'nexora-commerce-toolkit' ) . '</button></th></tr>';
		}
		$out .= '<tr class="woocommerce-shipping-totals shipping"><th>' . esc_html( $t['shipping_title'] ? $t['shipping_title'] : __( 'Delivery', 'nexora-commerce-toolkit' ) ) . '</th><td>' . ( $t['shipping_cost'] > 0 ? Nexora_Store::price( $t['shipping_cost'] ) : '<span class="nx-ship-card__free">' . esc_html__( 'Free', 'nexora-commerce-toolkit' ) . '</span>' ) . '</td></tr>';
		$out .= '<tr class="order-total"><th>' . esc_html__( 'Total', 'nexora-commerce-toolkit' ) . '</th><td>' . Nexora_Store::price( $t['total'] ) . '</td></tr>';
		return $out . '</tfoot></table>';
	}

	/**
	 * Checkout page or order success page.
	 *
	 * @return string
	 */
	public static function render() {
		$received = get_query_var( 'order-received', false );
		if ( false !== $received && '' !== $received ) {
			return self::render_received( (int) $received );
		}
		$options = Nexora_Store_Cart::shipping_options();
		$type    = sanitize_key( self::posted( 'shipping' ) );
		$type    = isset( $options[ $type ] ) ? $type : ( $options ? (string) key( $options ) : '' );
		$t       = Nexora_Store_Cart::totals( $type, sanitize_email( self::value( 'email' ) ) );
		$notices = Nexora_Store_Cart::notices_html();
		if ( ! $t['lines'] ) {
			return '<div class="woocommerce">' . $notices . '<div class="nx-empty"><h2>' . esc_html__( 'Your cart is empty', 'nexora-commerce-toolkit' ) . '</h2><a class="nx-btn nx-btn--primary" href="' . esc_url( Nexora_Store::shop_url() ) . '">' . esc_html__( 'Browse the market', 'nexora-commerce-toolkit' ) . '</a></div></div>';
		}
		$logged_in = is_user_logged_in();
		// Recalculate options with the real subtotal (free delivery thresholds).
		$options = Nexora_Store_Cart::shipping_options( $t['subtotal'] );
		$steps   = array(
			'details'    => __( 'Your details', 'nexora-commerce-toolkit' ),
			'fulfilment' => __( 'Delivery or pickup', 'nexora-commerce-toolkit' ),
			'payment'    => __( 'Review and pay', 'nexora-commerce-toolkit' ),
		);
		ob_start();
		echo '<div class="woocommerce">';
		echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		if ( self::$errors ) {
			echo '<ul class="woocommerce-error" role="alert" tabindex="-1" data-nx-errors>';
			foreach ( self::$errors as $message ) {
				echo '<li>' . esc_html( $message ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! $logged_in ) {
			echo '<p class="nx-store-co-login">' . esc_html__( 'Already have an account?', 'nexora-commerce-toolkit' ) . ' <a href="' . esc_url( add_query_arg( 'redirect_to', rawurlencode( Nexora_Store::url( 'checkout' ) ), Nexora_Store::url( 'account' ) ) ) . '">' . esc_html__( 'Log in', 'nexora-commerce-toolkit' ) . '</a></p>';
		}
		?>
		<form method="post" name="checkout" class="checkout woocommerce-checkout nx-co" action="<?php echo esc_url( Nexora_Store::url( 'checkout' ) ); ?>" novalidate aria-label="<?php esc_attr_e( 'Checkout', 'nexora-commerce-toolkit' ); ?>" data-nx-checkout>
			<input type="hidden" name="nx_checkout" value="1">
			<?php wp_nonce_field( 'nexora_checkout', '_nx_checkout' ); ?>
			<div class="nx-co__main">
				<nav class="nx-co-steps" aria-label="<?php esc_attr_e( 'Checkout steps', 'nexora-commerce-toolkit' ); ?>">
					<ol>
						<?php $n = 0; foreach ( $steps as $key => $label ) : ?>
							<li><a href="#nx-step-<?php echo esc_attr( $key ); ?>" data-nexora-step-link="<?php echo esc_attr( $key ); ?>"><span class="nx-co-steps__num"><?php echo (int) ++$n; ?></span><span class="nx-co-steps__label"><?php echo esc_html( $label ); ?></span></a></li>
						<?php endforeach; ?>
					</ol>
				</nav>

				<section class="nx-co-card" id="nx-step-details" aria-labelledby="nx-step-details-title" data-nexora-step="details">
					<?php self::step_head( 1, 'details', __( 'Your details', 'nexora-commerce-toolkit' ), __( 'We send order updates to this email and mobile number.', 'nexora-commerce-toolkit' ) ); ?>
					<div class="woocommerce-billing-fields__field-wrapper">
						<?php
						self::field( 'first_name', __( 'First name', 'nexora-commerce-toolkit' ), 'form-row-first', true, array( 'autocomplete' => 'given-name' ) );
						self::field( 'last_name', __( 'Last name', 'nexora-commerce-toolkit' ), 'form-row-last', true, array( 'autocomplete' => 'family-name' ) );
						self::field( 'email', __( 'Email address', 'nexora-commerce-toolkit' ), 'form-row-first', true, array( 'type' => 'email', 'autocomplete' => 'email' ) );
						self::field( 'phone', __( 'Mobile number', 'nexora-commerce-toolkit' ), 'form-row-last', true, array( 'type' => 'tel', 'autocomplete' => 'tel', 'placeholder' => '09XX XXX XXXX' ) );
						?>
						<div class="nx-store-address">
							<?php
							self::field( 'address_1', __( 'Street address', 'nexora-commerce-toolkit' ), 'form-row-wide', true, array( 'autocomplete' => 'address-line1', 'placeholder' => __( 'House number and street', 'nexora-commerce-toolkit' ) ) );
							self::field( 'address_2', __( 'Barangay, unit or building', 'nexora-commerce-toolkit' ), 'form-row-wide', false, array( 'autocomplete' => 'address-line2' ) );
							self::field( 'city', __( 'City or town', 'nexora-commerce-toolkit' ), 'form-row-first', true, array( 'autocomplete' => 'address-level2' ) );
							self::field( 'state', __( 'Province', 'nexora-commerce-toolkit' ), 'form-row-last', false, array( 'autocomplete' => 'address-level1' ) );
							self::field( 'postcode', __( 'Postcode', 'nexora-commerce-toolkit' ), 'form-row-first', false, array( 'autocomplete' => 'postal-code' ) );
							?>
						</div>
					</div>
					<?php if ( ! $logged_in && Nexora_Store::get( 'registration' ) ) : ?>
						<div class="woocommerce-account-fields">
							<p class="form-row form-row-wide create-account">
								<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
									<input type="checkbox" class="woocommerce-form__input-checkbox" name="createaccount" id="createaccount" value="1" <?php checked( '1', self::posted( 'createaccount' ) ); ?>>
									<span><?php esc_html_e( 'Create an account for faster checkout and order tracking', 'nexora-commerce-toolkit' ); ?></span>
								</label>
							</p>
							<p class="form-row form-row-wide nx-store-account-pass">
								<label for="account_password"><?php esc_html_e( 'Choose a password (8 characters or more)', 'nexora-commerce-toolkit' ); ?></label>
								<input type="password" class="input-text" name="account_password" id="account_password" autocomplete="new-password">
							</p>
						</div>
					<?php endif; ?>
				</section>

				<section class="nx-co-card" id="nx-step-fulfilment" aria-labelledby="nx-step-fulfilment-title" data-nexora-step="fulfilment">
					<?php self::step_head( 2, 'fulfilment', __( 'Delivery or pickup', 'nexora-commerce-toolkit' ), __( 'Choose how you will get your order, then pick a time.', 'nexora-commerce-toolkit' ) ); ?>
					<?php self::render_fulfilment( $options, $type ); ?>
				</section>

				<section class="nx-co-card" id="nx-step-payment" aria-labelledby="nx-step-payment-title" data-nexora-step="payment">
					<?php self::step_head( 3, 'payment', __( 'Review and pay', 'nexora-commerce-toolkit' ), __( 'Add a note for the store if you need to, then choose how to pay.', 'nexora-commerce-toolkit' ) ); ?>
					<div class="woocommerce-additional-fields">
						<p class="form-row form-row-wide">
							<label for="order_comments"><?php esc_html_e( 'Notes for the store', 'nexora-commerce-toolkit' ); ?>&nbsp;<span class="optional"><?php esc_html_e( '(optional)', 'nexora-commerce-toolkit' ); ?></span></label>
							<textarea class="input-text" name="order_comments" id="order_comments" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'For example: choose ripe mangoes, cut the bangus into steaks, call when the order is packed.', 'nexora-commerce-toolkit' ); ?>"><?php echo esc_textarea( self::posted( 'order_comments' ) ); ?></textarea>
						</p>
					</div>
					<?php self::render_payment( $type ); ?>
				</section>
			</div>

			<aside class="nx-co__aside">
				<details class="nx-co-summary" open data-nexora-summary>
					<summary class="nx-co-summary__toggle">
						<span><?php echo Nexora_Store::icon( 'bag', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Order summary', 'nexora-commerce-toolkit' ); ?></span>
						<span class="nx-co-summary__total" data-nexora-summary-total><?php echo esc_html( Nexora_Store::money( $t['total'] ) ); ?></span>
					</summary>
					<h2 id="order_review_heading" class="nx-co-summary__title">
						<?php esc_html_e( 'Order summary', 'nexora-commerce-toolkit' ); ?>
						<?php /* translators: %d: items */ ?>
						<span><?php echo esc_html( sprintf( _n( '%d item', '%d items', Nexora_Store_Cart::count(), 'nexora-commerce-toolkit' ), Nexora_Store_Cart::count() ) ); ?></span>
					</h2>
					<div id="order_review" class="woocommerce-checkout-review-order" data-nx-summary aria-live="polite">
						<?php echo self::summary_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
					</div>
					<div class="nx-store-coupon nx-store-coupon--checkout" data-nx-coupon>
						<label for="nx_coupon_code"><?php esc_html_e( 'Have a coupon?', 'nexora-commerce-toolkit' ); ?></label>
						<span class="nx-store-coupon__row">
							<input type="text" id="nx_coupon_code" class="input-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Coupon code', 'nexora-commerce-toolkit' ); ?>">
							<button type="button" class="button" data-nx-apply-coupon><?php esc_html_e( 'Apply', 'nexora-commerce-toolkit' ); ?></button>
						</span>
						<p class="nx-store-coupon__msg" role="status" data-nx-coupon-msg></p>
					</div>
					<ul class="nx-co-trust">
						<li><?php echo Nexora_Store::icon( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Secure checkout', 'nexora-commerce-toolkit' ); ?></li>
						<li><?php echo Nexora_Store::icon( 'leaf', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Packed fresh on your schedule', 'nexora-commerce-toolkit' ); ?></li>
					</ul>
				</details>
			</aside>
		</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Delivery cards and their fields.
	 *
	 * @param array  $options Delivery options.
	 * @param string $type    Selected.
	 */
	private static function render_fulfilment( $options, $type ) {
		if ( ! $options ) {
			echo '<p class="nx-ship-options__empty">' . esc_html__( 'No delivery options are available right now. Please contact us.', 'nexora-commerce-toolkit' ) . '</p>';
			return;
		}
		echo '<fieldset class="nx-ship-cards"><legend class="screen-reader-text">' . esc_html__( 'Delivery or pickup', 'nexora-commerce-toolkit' ) . '</legend>';
		foreach ( $options as $key => $option ) {
			$id = 'shipping_' . $key;
			printf(
				'<div class="nx-ship-card"><input type="radio" class="nx-ship-card__input" name="shipping" id="%1$s" value="%2$s"%3$s><label for="%1$s" class="nx-ship-card__label"><span class="nx-ship-card__icon" aria-hidden="true">%4$s</span><span class="nx-ship-card__body"><span class="nx-ship-card__name">%5$s</span><span class="nx-ship-card__desc">%6$s</span></span><span class="nx-ship-card__price">%7$s</span></label></div>',
				esc_attr( $id ),
				esc_attr( $key ),
				checked( $key, $type, false ),
				Nexora_Store::icon( $option['icon'], 24 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $option['title'] ),
				esc_html( $option['desc'] ),
				$option['cost'] > 0 ? Nexora_Store::price( $option['cost'] ) : '<span class="nx-ship-card__free">' . esc_html__( 'Free', 'nexora-commerce-toolkit' ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</fieldset>';

		$schedule  = self::schedule();
		$selected  = self::posted( 'nx_schedule' );
		$locations = self::pickup_locations();
		$location  = (int) self::posted( 'nx_location' );
		$couriers  = self::couriers();
		$picked    = self::posted( 'nx_courier' );
		if ( '' === $picked ) {
			$picked = (string) get_user_meta( get_current_user_id(), 'nexora_preferred_courier', true );
		}
		?>
		<div class="nexora-fulfilment nx-store-fulfil">
			<?php if ( isset( $options['pickup'] ) ) : ?>
				<div class="nx-store-panel" data-panel="pickup">
					<?php if ( count( $locations ) > 1 ) : ?>
						<fieldset class="nexora-fulfilment__locations">
							<legend><?php esc_html_e( 'Pickup point', 'nexora-commerce-toolkit' ); ?></legend>
							<?php foreach ( $locations as $i => $l ) : ?>
								<label class="nexora-fulfilment__location">
									<input type="radio" name="nx_location" value="<?php echo esc_attr( (string) $i ); ?>" <?php checked( $location, $i ); ?>>
									<span>
										<strong><?php echo esc_html( $l['name'] ); ?></strong>
										<?php if ( $l['address'] ) : ?><span><?php echo esc_html( $l['address'] ); ?></span><?php endif; ?>
										<?php if ( $l['hours'] ) : ?><span class="nexora-fulfilment__muted"><?php echo esc_html( $l['hours'] ); ?></span><?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php else : ?>
						<div class="nexora-fulfilment__place">
							<strong><?php echo esc_html( $locations[0]['name'] ); ?></strong>
							<?php if ( $locations[0]['address'] ) : ?><span><?php echo esc_html( $locations[0]['address'] ); ?></span><?php endif; ?>
							<?php if ( $locations[0]['hours'] ) : ?><span class="nexora-fulfilment__muted"><?php echo esc_html( $locations[0]['hours'] ); ?></span><?php endif; ?>
							<input type="hidden" name="nx_location" value="0">
						</div>
					<?php endif; ?>
					<p class="nexora-fulfilment__note"><?php esc_html_e( 'We will email you when your order is packed. Bring your order number; someone else can collect it for you.', 'nexora-commerce-toolkit' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $options['courier'] ) ) : ?>
				<div class="nx-store-panel" data-panel="courier">
					<ol class="nexora-fulfilment__steps">
						<li><?php esc_html_e( 'Place your order. We confirm it and start packing.', 'nexora-commerce-toolkit' ); ?></li>
						<li><?php esc_html_e( 'We email you when it is packed and ready for your rider.', 'nexora-commerce-toolkit' ); ?></li>
						<li>
							<span>
								<?php esc_html_e( 'Book your courier to collect from:', 'nexora-commerce-toolkit' ); ?>
								<strong><?php echo esc_html( self::store_address() ); ?></strong>
								<?php if ( Nexora_Store::get( 'courier_contact' ) ) : ?>
									<?php /* translators: %s: phone */ ?>
									<span class="nexora-fulfilment__muted"><?php echo esc_html( sprintf( __( 'Rider contact: %s', 'nexora-commerce-toolkit' ), Nexora_Store::get( 'courier_contact' ) ) ); ?></span>
								<?php endif; ?>
							</span>
						</li>
						<li><?php esc_html_e( 'Your rider gives your order number at the counter. Courier fees are paid by you directly to the courier.', 'nexora-commerce-toolkit' ); ?></li>
					</ol>
					<p class="form-row form-row-wide<?php echo isset( self::$errors['nx_courier'] ) ? ' woocommerce-invalid' : ''; ?>">
						<label for="nx_courier"><?php esc_html_e( 'Courier you will book', 'nexora-commerce-toolkit' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'nexora-commerce-toolkit' ); ?>">*</abbr></label>
						<select name="nx_courier" id="nx_courier" class="select">
							<option value=""><?php esc_html_e( 'Choose a courier…', 'nexora-commerce-toolkit' ); ?></option>
							<?php foreach ( $couriers as $c ) : ?>
								<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $picked, $c ); ?>><?php echo esc_html( $c ); ?></option>
							<?php endforeach; ?>
							<option value="other" <?php selected( $picked, 'other' ); ?>><?php esc_html_e( 'Other / not sure yet', 'nexora-commerce-toolkit' ); ?></option>
						</select>
					</p>
					<p class="form-row form-row-wide nexora-fulfilment__other">
						<label for="nx_courier_other"><?php esc_html_e( 'Courier name (if “Other”)', 'nexora-commerce-toolkit' ); ?>&nbsp;<span class="optional"><?php esc_html_e( '(optional)', 'nexora-commerce-toolkit' ); ?></span></label>
						<input type="text" class="input-text" name="nx_courier_other" id="nx_courier_other" maxlength="60" value="<?php echo esc_attr( self::posted( 'nx_courier_other' ) ); ?>">
					</p>
					<p class="form-row form-row-wide nexora-fulfilment__ack">
						<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
							<input type="checkbox" name="nx_courier_ack" id="nx_courier_ack" value="1" <?php checked( '1', self::posted( 'nx_courier_ack' ) ); ?>>
							<span><?php esc_html_e( 'I will book and pay for the courier myself. The store does not deliver this order.', 'nexora-commerce-toolkit' ); ?></span>
						</label>
					</p>
				</div>
			<?php endif; ?>
			<p class="form-row form-row-wide<?php echo isset( self::$errors['nx_schedule'] ) ? ' woocommerce-invalid' : ''; ?>" id="nx_schedule_field">
				<label for="nx_schedule">
					<?php esc_html_e( 'Day and time', 'nexora-commerce-toolkit' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'nexora-commerce-toolkit' ); ?>">*</abbr>
				</label>
				<?php if ( $schedule ) : ?>
					<select name="nx_schedule" id="nx_schedule" class="select" aria-required="true">
						<option value=""><?php esc_html_e( 'Choose a day and time…', 'nexora-commerce-toolkit' ); ?></option>
						<?php foreach ( $schedule as $day => $info ) : ?>
							<optgroup label="<?php echo esc_attr( $info['label'] ); ?>">
								<?php foreach ( $info['slots'] as $slot => $slot_label ) : ?>
									<?php $value = $day . '|' . $slot; ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $info['label'] . ' · ' . $slot_label ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<span class="nexora-fulfilment__muted"><?php esc_html_e( 'No time slots are open right now. Please contact us.', 'nexora-commerce-toolkit' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Payment methods and the place-order button.
	 *
	 * @param string $type Selected delivery option.
	 */
	private static function render_payment( $type ) {
		$gateways = self::gateways();
		$selected = sanitize_key( self::posted( 'payment_method' ) );
		if ( ! isset( $gateways[ $selected ] ) || ( $gateways[ $selected ]['types'] && ! in_array( $type, $gateways[ $selected ]['types'], true ) ) ) {
			$selected = '';
			foreach ( $gateways as $id => $g ) {
				if ( ! $g['types'] || in_array( $type, $g['types'], true ) ) {
					$selected = $id;
					break;
				}
			}
		}
		?>
		<div id="payment" class="woocommerce-checkout-payment">
			<?php if ( ! $gateways ) : ?>
				<p class="nx-ship-options__empty"><?php esc_html_e( 'No payment methods are available right now. Please contact us.', 'nexora-commerce-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="wc_payment_methods payment_methods methods">
					<?php foreach ( $gateways as $id => $g ) : ?>
						<li class="wc_payment_method payment_method_<?php echo esc_attr( $id ); ?>" data-pay-types="<?php echo esc_attr( implode( ' ', $g['types'] ) ); ?>">
							<input type="radio" class="input-radio" name="payment_method" id="payment_method_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $selected, $id ); ?>>
							<label for="payment_method_<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $g['title'] ); ?></label>
							<div class="payment_box">
								<?php if ( $g['desc'] ) : ?><p><?php echo nl2br( esc_html( $g['desc'] ) ); ?></p><?php endif; ?>
								<?php if ( $g['needs_ref'] ) : ?>
									<p class="form-row form-row-wide<?php echo isset( self::$errors['payment_ref'] ) ? ' woocommerce-invalid' : ''; ?>">
										<label for="payment_ref"><?php esc_html_e( 'Payment reference number', 'nexora-commerce-toolkit' ); ?></label>
										<input type="text" class="input-text" name="payment_ref" id="payment_ref" maxlength="100" value="<?php echo esc_attr( self::posted( 'payment_ref' ) ); ?>">
									</p>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="form-row place-order">
					<?php if ( function_exists( 'get_privacy_policy_url' ) && get_privacy_policy_url() ) : ?>
						<?php /* translators: 1: opening link, 2: closing link */ ?>
						<p class="woocommerce-privacy-policy-text"><?php printf( esc_html__( 'Your personal data will be used to process your order and for other purposes described in our %1$sprivacy policy%2$s.', 'nexora-commerce-toolkit' ), '<a href="' . esc_url( get_privacy_policy_url() ) . '">', '</a>' ); ?></p>
					<?php endif; ?>
					<button type="submit" class="button alt" id="place_order"><?php esc_html_e( 'Place order', 'nexora-commerce-toolkit' ); ?></button>
					<p class="nx-secure-note"><?php echo Nexora_Store::icon( 'shield', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'We never store card details on this site.', 'nexora-commerce-toolkit' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Order success page. Only the order's owner, or someone holding its private link, can see it.
	 *
	 * @param int $id Order.
	 * @return string
	 */
	public static function render_received( $id ) {
		$order = Nexora_Store_Orders::get( $id );
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$owner = $order && $order->user_id && (int) $order->user_id === get_current_user_id();
		if ( ! $order || ( ! $owner && ! hash_equals( (string) $order->order_key, $key ) ) ) {
			return '<div class="woocommerce"><div class="nx-empty"><h2>' . esc_html__( 'We could not find that order', 'nexora-commerce-toolkit' ) . '</h2><p>' . esc_html__( 'Please use the link in your order email.', 'nexora-commerce-toolkit' ) . '</p></div></div>';
		}
		$first = isset( $order->address['first_name'] ) ? $order->address['first_name'] : '';
		ob_start();
		?>
		<div class="woocommerce">
			<div class="woocommerce-order nx-ty">
				<header class="nx-ty__hero">
					<span class="nx-ty__check" aria-hidden="true"><?php echo Nexora_Store::icon( 'check', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<?php /* translators: %s: first name */ ?>
						<h2 class="nx-ty__title"><?php echo esc_html( $first ? sprintf( __( 'Thank you, %s!', 'nexora-commerce-toolkit' ), $first ) : __( 'Thank you!', 'nexora-commerce-toolkit' ) ); ?></h2>
						<p class="woocommerce-notice"><?php esc_html_e( 'Your order has been received. We sent the details to your email.', 'nexora-commerce-toolkit' ); ?></p>
					</div>
				</header>
				<ul class="woocommerce-order-overview order_details nx-ty__facts">
					<li><?php esc_html_e( 'Order number:', 'nexora-commerce-toolkit' ); ?> <strong><?php echo (int) $order->id; ?></strong></li>
					<li><?php esc_html_e( 'Date:', 'nexora-commerce-toolkit' ); ?> <strong><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $order->created_at . ' UTC' ) ) ); ?></strong></li>
					<li><?php esc_html_e( 'Total:', 'nexora-commerce-toolkit' ); ?> <strong><?php echo Nexora_Store::price( $order->total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></li>
					<li><?php esc_html_e( 'Payment method:', 'nexora-commerce-toolkit' ); ?> <strong><?php echo esc_html( $order->payment_title ); ?></strong></li>
				</ul>
				<?php if ( 'bank' === $order->payment_method && Nexora_Store::get( 'bank_details' ) && ! $order->paid_at ) : ?>
					<section class="nexora-fulfilment-card"><h2 class="nexora-fulfilment-card__title"><?php esc_html_e( 'How to pay', 'nexora-commerce-toolkit' ); ?></h2><p><?php echo nl2br( esc_html( (string) Nexora_Store::get( 'bank_details' ) ) ); ?></p></section>
				<?php endif; ?>
				<?php echo Nexora_Store_Orders::fulfilment_card( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
				<section class="woocommerce-order-details">
					<h2 class="woocommerce-order-details__title"><?php esc_html_e( 'Order details', 'nexora-commerce-toolkit' ); ?></h2>
					<?php echo Nexora_Store_Orders::details_table( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
				</section>
				<p class="nx-ty__actions">
					<a class="nx-btn nx-btn--ghost" href="<?php echo esc_url( Nexora_Store::shop_url() ); ?>"><?php esc_html_e( 'Continue shopping', 'nexora-commerce-toolkit' ); ?></a>
					<?php if ( $owner ) : ?>
						<a class="nx-btn nx-btn--primary" href="<?php echo esc_url( Nexora_Store::url( 'account', 'view-order/' . (int) $order->id ) ); ?>"><?php esc_html_e( 'Track this order', 'nexora-commerce-toolkit' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
