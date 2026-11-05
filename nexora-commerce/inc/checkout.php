<?php
/**
 * Freshtastic checkout and account forms: step layout, delivery option cards, field order and
 * account navigation. Templates live in woocommerce/checkout and woocommerce/myaccount.
 *
 * Only presentation lives here. Courier and pickup scheduling (fields, validation, saving) come from
 * the Freshtastic Food Hub Toolkit, which prints them in the nexora_checkout_fulfilment slot.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

// Payment gets its own step in form-checkout.php instead of sitting under the order summary.
remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );

/* -------------------------------------------------------------------------
 * Checkout
 * ---------------------------------------------------------------------- */

/**
 * Contact fields first (email, mobile), then the address.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function nexora_checkout_field_order( $fields ) {
	if ( isset( $fields['billing']['billing_email'] ) ) {
		$fields['billing']['billing_email']['priority'] = 22;
		$fields['billing']['billing_email']['class']    = array( 'form-row-first' );
	}
	if ( isset( $fields['order']['order_comments'] ) ) {
		$fields['order']['order_comments']['label']       = __( 'Notes for the store', 'nexora-commerce' );
		$fields['order']['order_comments']['placeholder'] = __( 'For example: choose ripe mangoes, cut the bangus into steaks, call when the order is packed.', 'nexora-commerce' );
	}
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'nexora_checkout_field_order', 20 );

/**
 * Phone is an address field (WooCommerce 8.9+), so its label and position come from the address
 * locale; set them there so the country script keeps them.
 *
 * @param array $fields Default address fields.
 * @return array
 */
function nexora_address_phone_field( $fields ) {
	if ( isset( $fields['phone'] ) ) {
		$fields['phone']['priority']    = 24;
		$fields['phone']['class']       = array( 'form-row-last' );
		$fields['phone']['label']       = __( 'Mobile number', 'nexora-commerce' );
		$fields['phone']['placeholder'] = '09XX XXX XXXX';
	}
	return $fields;
}
add_filter( 'woocommerce_default_address_fields', 'nexora_address_phone_field' );

/**
 * Steps shown at the top of the checkout.
 *
 * @return array<string,string>
 */
function nexora_checkout_steps() {
	$steps = array( 'details' => __( 'Your details', 'nexora-commerce' ) );
	if ( WC()->cart && WC()->cart->needs_shipping() ) {
		$steps['fulfilment'] = __( 'Delivery or pickup', 'nexora-commerce' );
	}
	$steps['payment'] = __( 'Review and pay', 'nexora-commerce' );
	return $steps;
}

/**
 * Numbered card heading for a checkout step.
 *
 * @param string $key   Step key.
 * @param string $title Title.
 * @param string $intro Short help text.
 */
function nexora_checkout_step_head( $key, $title, $intro = '' ) {
	$index = array_search( $key, array_keys( nexora_checkout_steps() ), true );
	printf(
		'<header class="nx-co-card__head"><span class="nx-co-card__num" aria-hidden="true">%1$d</span><div><h2 class="nx-co-card__title" id="nx-step-%2$s-title">%3$s</h2>%4$s</div></header>',
		false === $index ? 0 : $index + 1,
		esc_attr( $key ),
		esc_html( $title ),
		$intro ? '<p class="nx-co-card__intro">' . esc_html( $intro ) . '</p>' : ''
	);
}

/**
 * Price shown on a delivery option card.
 *
 * @param WC_Shipping_Rate $rate Rate.
 * @return string HTML.
 */
function nexora_shipping_rate_price( $rate ) {
	$cost = (float) $rate->get_cost();
	if ( $cost <= 0 ) {
		return '<span class="nx-ship-card__free">' . esc_html__( 'Free', 'nexora-commerce' ) . '</span>';
	}
	if ( WC()->cart->display_prices_including_tax() ) {
		$cost += array_sum( $rate->get_taxes() );
	}
	return wc_price( $cost );
}

/**
 * Icon and description for a delivery option.
 *
 * @param WC_Shipping_Rate $rate Rate.
 * @return array{0:string,1:string}
 */
function nexora_shipping_rate_meta( $rate ) {
	$method = $rate->get_method_id();
	$desc   = '';
	if ( class_exists( 'Nexora_Toolkit_Fulfilment' ) ) {
		$instance = Nexora_Toolkit_Fulfilment::method_for( $rate->get_id() );
		$desc     = $instance ? $instance->summary() : '';
	}
	switch ( $method ) {
		case 'nexora_courier_booking':
			return array( 'bike', $desc );
		case 'nexora_store_pickup':
		case 'local_pickup':
		case 'pickup_location':
			return array( 'store', $desc ? $desc : __( 'Collect your order at the store.', 'nexora-commerce' ) );
		case 'free_shipping':
			return array( 'truck', __( 'Delivered to your address at no charge.', 'nexora-commerce' ) );
		default:
			return array( 'truck', __( 'Delivered to your address.', 'nexora-commerce' ) );
	}
}

/**
 * Delivery option cards. They post the same shipping_method[] inputs WooCommerce expects,
 * so the classic checkout script recalculates totals as usual.
 *
 * @return string
 */
function nexora_checkout_shipping_options_html() {
	ob_start();
	echo '<div class="nx-ship-options">';
	$packages = WC()->shipping()->get_packages();
	$multiple = count( $packages ) > 1;
	foreach ( $packages as $i => $package ) {
		$rates  = isset( $package['rates'] ) ? $package['rates'] : array();
		$chosen = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';
		if ( ! $rates ) {
			echo '<p class="nx-ship-options__empty">' . wp_kses_post( apply_filters( 'woocommerce_no_shipping_available_html', __( 'There are no delivery options for this address yet. Check your address, or contact us and we will help.', 'nexora-commerce' ) ) ) . '</p>';
			continue;
		}
		echo '<fieldset class="nx-ship-cards">';
		/* translators: %d: package number */
		echo '<legend class="' . ( $multiple ? 'nx-ship-cards__legend' : 'screen-reader-text' ) . '">' . esc_html( $multiple ? sprintf( __( 'Package %d', 'nexora-commerce' ), $i + 1 ) : __( 'Delivery or pickup', 'nexora-commerce' ) ) . '</legend>';
		$single = 1 === count( $rates );
		foreach ( $rates as $rate ) {
			$id         = 'shipping_method_' . $i . '_' . sanitize_title( $rate->get_id() );
			list( $icon, $desc ) = nexora_shipping_rate_meta( $rate );
			$is_checked = $single || $rate->get_id() === $chosen;
			echo '<div class="nx-ship-card' . ( $is_checked ? ' is-selected' : '' ) . '">';
			if ( $single ) {
				printf( '<input type="hidden" name="shipping_method[%1$d]" data-index="%1$d" id="%2$s" value="%3$s" class="shipping_method">', (int) $i, esc_attr( $id ), esc_attr( $rate->get_id() ) );
			} else {
				printf( '<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="%2$s" value="%3$s" class="shipping_method nx-ship-card__input" %4$s>', (int) $i, esc_attr( $id ), esc_attr( $rate->get_id() ), checked( $is_checked, true, false ) );
			}
			printf(
				'<label for="%1$s" class="nx-ship-card__label"><span class="nx-ship-card__icon" aria-hidden="true">%2$s</span><span class="nx-ship-card__body"><span class="nx-ship-card__name">%3$s</span>%4$s</span><span class="nx-ship-card__price">%5$s</span></label>',
				esc_attr( $id ),
				nexora_icon( $icon, 24 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $rate->get_label() ),
				$desc ? '<span class="nx-ship-card__desc">' . esc_html( $desc ) . '</span>' : '',
				wp_kses_post( nexora_shipping_rate_price( $rate ) )
			);
			do_action( 'woocommerce_after_shipping_rate', $rate, $i );
			echo '</div>';
		}
		echo '</fieldset>';
	}
	echo '</div>';
	return (string) ob_get_clean();
}

/**
 * Refresh the option cards with the rest of the checkout.
 *
 * @param array $fragments Fragments.
 * @return array
 */
function nexora_checkout_fragments( $fragments ) {
	$fragments['.nx-ship-options'] = WC()->cart && WC()->cart->needs_shipping() ? nexora_checkout_shipping_options_html() : '<div class="nx-ship-options"></div>';
	return $fragments;
}
add_filter( 'woocommerce_update_order_review_fragments', 'nexora_checkout_fragments' );

/**
 * Order notes (moved out of the shipping template into the last step).
 *
 * @param WC_Checkout $checkout Checkout.
 */
function nexora_checkout_order_notes( $checkout ) {
	echo '<div class="woocommerce-additional-fields">';
	do_action( 'woocommerce_before_order_notes', $checkout );
	if ( apply_filters( 'woocommerce_enable_order_notes_field', 'yes' === get_option( 'woocommerce_enable_order_comments', 'yes' ) ) ) {
		echo '<div class="woocommerce-additional-fields__field-wrapper">';
		foreach ( $checkout->get_checkout_fields( 'order' ) as $key => $field ) {
			woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
		}
		echo '</div>';
	}
	do_action( 'woocommerce_after_order_notes', $checkout );
	echo '</div>';
}

/**
 * Chosen delivery option for the order summary.
 */
function nexora_review_shipping_rows() {
	foreach ( WC()->shipping()->get_packages() as $i => $package ) {
		$chosen = isset( WC()->session->chosen_shipping_methods[ $i ] ) ? WC()->session->chosen_shipping_methods[ $i ] : '';
		$rate   = $chosen && isset( $package['rates'][ $chosen ] ) ? $package['rates'][ $chosen ] : null;
		printf(
			'<tr class="woocommerce-shipping-totals shipping"><th>%1$s</th><td>%2$s</td></tr>',
			esc_html( $rate ? $rate->get_label() : __( 'Delivery', 'nexora-commerce' ) ),
			$rate ? wp_kses_post( nexora_shipping_rate_price( $rate ) ) : '<span class="nx-muted">' . esc_html__( 'Choose in step 2', 'nexora-commerce' ) . '</span>'
		);
	}
}

/**
 * Whether any shipping zone offers WooCommerce Free Shipping (the progress bar is
 * misleading otherwise, for example with pickup and customer-booked couriers only).
 *
 * @return bool
 */
function nexora_store_offers_free_shipping() {
	static $offers = null;
	if ( null !== $offers ) {
		return $offers;
	}
	$key    = 'nexora_free_ship_' . WC_Cache_Helper::get_transient_version( 'shipping' );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $offers = (bool) $cached; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
	}
	$offers = false;
	$zones  = WC_Shipping_Zones::get_zones();
	$zones[] = array( 'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods() );
	foreach ( $zones as $zone ) {
		foreach ( (array) $zone['shipping_methods'] as $method ) {
			if ( 'free_shipping' === $method->id && 'yes' === $method->enabled ) {
				$offers = true;
				break 2;
			}
		}
	}
	set_transient( $key, $offers ? 1 : 0, DAY_IN_SECONDS );
	return $offers;
}

/* -------------------------------------------------------------------------
 * Account
 * ---------------------------------------------------------------------- */

/**
 * Initials for the avatar badge.
 *
 * @param WP_User $user User.
 * @return string
 */
function nexora_user_initials( $user ) {
	$name  = trim( $user->first_name . ' ' . $user->last_name );
	$name  = '' !== $name ? $name : $user->display_name;
	$parts = preg_split( '/\s+/', $name );
	$out   = '';
	foreach ( array_slice( $parts, 0, 2 ) as $part ) {
		$out .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
	}
	return $out ? $out : '?';
}

/**
 * Icon per account endpoint.
 *
 * @param string $endpoint Endpoint.
 * @return string
 */
function nexora_account_icon( $endpoint ) {
	$map = array(
		'dashboard'       => 'grid',
		'orders'          => 'bag',
		'downloads'       => 'download',
		'edit-address'    => 'pin',
		'payment-methods' => 'card',
		'edit-account'    => 'user',
		'wishlist'        => 'heart',
		'quotations'      => 'clipboard',
		'customer-logout' => 'logout',
	);
	return isset( $map[ $endpoint ] ) ? $map[ $endpoint ] : 'chevron-right';
}

/**
 * Body class for the reworked forms.
 *
 * @param array $classes Classes.
 * @return array
 */
function nexora_forms_body_class( $classes ) {
	if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && ! has_block( 'woocommerce/checkout' ) ) {
		$classes[] = 'nx-checkout-page';
	}
	return $classes;
}
add_filter( 'body_class', 'nexora_forms_body_class' );
