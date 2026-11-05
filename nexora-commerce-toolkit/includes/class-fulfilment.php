<?php
/**
 * Fulfilment: courier delivery booked by the customer, and scheduled store pickup.
 *
 * - Registers the two shipping methods (includes/shipping/class-shipping-methods.php).
 * - Adds the schedule fields to the classic checkout, validates them against the method's live
 *   schedule, and stores the choice on the order (_nexora_fulfilment).
 * - Adds the "Ready for pickup" order status and its customer email.
 * - Shows the details to customers (thank-you page, My Account, emails) and to staff.
 * - Offers a one-click switch from the block checkout to the classic checkout, with a backup.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fulfilment module.
 */
class Nexora_Toolkit_Fulfilment {

	const COURIER = 'nexora_courier_booking';
	const PICKUP  = 'nexora_store_pickup';
	const META    = '_nexora_fulfilment';
	const STATUS  = 'nx-ready';
	const BACKUP  = '_nexora_block_checkout_backup';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_methods' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_methods' ) );

		// Checkout.
		add_action( 'nexora_checkout_fulfilment', array( __CLASS__, 'render_fields' ) );
		add_action( 'woocommerce_checkout_after_customer_details', array( __CLASS__, 'render_fields_fallback' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragment' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_draft' ) );
		add_filter( 'woocommerce_cart_needs_shipping_address', array( __CLASS__, 'needs_shipping_address' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// Customer-facing details.
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals_rows' ), 10, 2 );
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'customer_card' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_item_meta' ) );

		// "Ready for pickup" status and email.
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( __CLASS__, 'mark_ready_time' ), 10, 2 );
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'email_actions' ) );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ) );

		// Classic checkout switch.
		add_action( 'admin_notices', array( __CLASS__, 'checkout_notice' ) );
		add_action( 'admin_post_nexora_checkout_mode', array( __CLASS__, 'handle_checkout_mode' ) );
	}

	/* ---------------------------------------------------------------------
	 * Shipping methods
	 * ------------------------------------------------------------------ */

	/**
	 * Load the method classes (needs WC_Shipping_Method).
	 */
	public static function load_methods() {
		if ( class_exists( 'WC_Shipping_Method' ) ) {
			require_once NEXORA_TOOLKIT_DIR . 'includes/shipping/class-shipping-methods.php';
		}
	}

	/**
	 * Register the methods.
	 *
	 * @param array $methods Methods.
	 * @return array
	 */
	public static function register_methods( $methods ) {
		$methods[ self::COURIER ] = 'Nexora_Toolkit_Shipping_Courier';
		$methods[ self::PICKUP ]  = 'Nexora_Toolkit_Shipping_Pickup';
		return $methods;
	}

	/**
	 * Store address from WooCommerce → Settings → General, on one line.
	 *
	 * @return string
	 */
	public static function store_address() {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}
		$c = WC()->countries;
		return (string) $c->get_formatted_address(
			array(
				'address_1' => $c->get_base_address(),
				'address_2' => $c->get_base_address_2(),
				'city'      => $c->get_base_city(),
				'state'     => $c->get_base_state(),
				'postcode'  => $c->get_base_postcode(),
				'country'   => $c->get_base_country(),
			),
			', '
		);
	}

	/**
	 * Fulfilment type for a rate/method ID such as "nexora_store_pickup:7".
	 *
	 * @param string $rate_id Rate ID.
	 * @return string courier|pickup|''
	 */
	public static function type_of( $rate_id ) {
		$method = strtok( (string) $rate_id, ':' );
		if ( self::COURIER === $method ) {
			return 'courier';
		}
		return self::PICKUP === $method ? 'pickup' : '';
	}

	/**
	 * The method instance behind a rate ID.
	 *
	 * @param string $rate_id Rate ID.
	 * @return Nexora_Toolkit_Shipping_Method|null
	 */
	public static function method_for( $rate_id ) {
		if ( ! self::type_of( $rate_id ) ) {
			return null;
		}
		$parts = explode( ':', (string) $rate_id );
		if ( empty( $parts[1] ) ) {
			return null;
		}
		self::load_methods();
		$method = WC_Shipping_Zones::get_shipping_method( absint( $parts[1] ) );
		return $method instanceof Nexora_Toolkit_Shipping_Method ? $method : null;
	}

	/**
	 * Chosen rate ID for the first package: from the request being processed, else the session.
	 *
	 * @param array|null $posted Posted checkout data.
	 * @return string
	 */
	public static function chosen_rate( $posted = null ) {
		if ( is_array( $posted ) && ! empty( $posted['shipping_method'] ) ) {
			$methods = (array) $posted['shipping_method'];
			return (string) reset( $methods );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; the checkout handler verifies its nonce.
		if ( isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) {
			$methods = wc_clean( wp_unslash( $_POST['shipping_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return (string) reset( $methods );
		}
		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		return isset( $chosen[0] ) ? (string) $chosen[0] : '';
	}

	/* ---------------------------------------------------------------------
	 * Checkout fields
	 * ------------------------------------------------------------------ */

	/**
	 * Front-end styles for the fields (small; themes may restyle).
	 */
	public static function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_account_page() || is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}
		wp_enqueue_style( 'nexora-fulfilment', NEXORA_TOOLKIT_URL . 'assets/css/fulfilment.css', array(), NEXORA_TOOLKIT_VERSION );
	}

	/**
	 * Values typed so far, so a checkout refresh does not wipe them.
	 *
	 * @param string $post_data Serialized checkout form.
	 */
	public static function remember_draft( $post_data ) {
		parse_str( (string) $post_data, $data );
		$draft = array();
		foreach ( array( 'nexora_schedule', 'nexora_courier', 'nexora_courier_other', 'nexora_pickup_location', 'nexora_courier_ack' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$draft[ $key ] = sanitize_text_field( wp_unslash( $data[ $key ] ) );
			}
		}
		if ( WC()->session ) {
			WC()->session->set( 'nexora_fulfilment_draft', $draft );
		}
	}

	/**
	 * Draft value.
	 *
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	private static function draft( $key, $default = '' ) {
		$draft = WC()->session ? (array) WC()->session->get( 'nexora_fulfilment_draft', array() ) : array();
		return isset( $draft[ $key ] ) ? (string) $draft[ $key ] : $default;
	}

	/**
	 * Print the fields for the chosen method (also used for the AJAX fragment).
	 */
	public static function render_fields() {
		echo self::fields_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
	}

	/**
	 * Themes without their own slot (add_theme_support( 'nexora-checkout' )) get the fields after the customer details.
	 */
	public static function render_fields_fallback() {
		if ( ! current_theme_supports( 'nexora-checkout' ) ) {
			self::render_fields();
		}
	}

	/**
	 * Fragment refresh after the shopper changes delivery method or address.
	 *
	 * @param array $fragments Fragments.
	 * @return array
	 */
	public static function fragment( $fragments ) {
		$fragments['.nexora-fulfilment'] = self::fields_html();
		return $fragments;
	}

	/**
	 * Fields markup.
	 *
	 * @return string
	 */
	public static function fields_html() {
		$rate   = WC()->cart && WC()->cart->needs_shipping() ? self::chosen_rate() : '';
		$type   = self::type_of( $rate );
		$method = $type ? self::method_for( $rate ) : null;
		if ( ! $method ) {
			return '<div class="nexora-fulfilment" data-type="" hidden></div>';
		}

		$schedule = $method->schedule();
		$selected = self::draft( 'nexora_schedule' );
		ob_start();
		?>
		<div class="nexora-fulfilment" data-type="<?php echo esc_attr( $type ); ?>">
			<?php if ( 'courier' === $type ) : ?>
				<?php
				$couriers = $method->couriers();
				$picked   = self::draft( 'nexora_courier', (string) get_user_meta( get_current_user_id(), 'nexora_preferred_courier', true ) );
				?>
				<ol class="nexora-fulfilment__steps">
					<li><?php esc_html_e( 'Place your order. We confirm it and start packing.', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php esc_html_e( 'We email and text you when it is packed and ready for your rider.', 'nexora-commerce-toolkit' ); ?></li>
					<li>
						<?php esc_html_e( 'Book your courier to collect from:', 'nexora-commerce-toolkit' ); ?>
						<strong><?php echo esc_html( $method->pickup_point() ); ?></strong>
						<?php if ( $method->get_option( 'contact_phone' ) ) : ?>
							<?php /* translators: %s: phone number */ ?>
							<span class="nexora-fulfilment__muted"><?php echo esc_html( sprintf( __( 'Rider contact: %s', 'nexora-commerce-toolkit' ), $method->get_option( 'contact_phone' ) ) ); ?></span>
						<?php endif; ?>
					</li>
					<li><?php esc_html_e( 'Your rider gives your order number at the counter. Courier fees are paid by you directly to the courier.', 'nexora-commerce-toolkit' ); ?></li>
				</ol>
				<div class="nexora-fulfilment__grid">
					<p class="form-row form-row-first validate-required" id="nexora_courier_field">
						<label for="nexora_courier"><?php esc_html_e( 'Courier you will book', 'nexora-commerce-toolkit' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'nexora-commerce-toolkit' ); ?>">*</abbr></label>
						<span class="woocommerce-input-wrapper">
							<select name="nexora_courier" id="nexora_courier" class="select" required aria-required="true">
								<option value=""><?php esc_html_e( 'Choose a courier…', 'nexora-commerce-toolkit' ); ?></option>
								<?php foreach ( $couriers as $courier ) : ?>
									<option value="<?php echo esc_attr( $courier ); ?>" <?php selected( $picked, $courier ); ?>><?php echo esc_html( $courier ); ?></option>
								<?php endforeach; ?>
								<option value="other" <?php selected( $picked, 'other' ); ?>><?php esc_html_e( 'Other / not sure yet', 'nexora-commerce-toolkit' ); ?></option>
							</select>
						</span>
					</p>
					<?php self::schedule_select( $schedule, $selected, __( 'Rider pickup time', 'nexora-commerce-toolkit' ), 'form-row-last' ); ?>
				</div>
				<p class="form-row form-row-wide nexora-fulfilment__other" id="nexora_courier_other_field">
					<label for="nexora_courier_other"><?php esc_html_e( 'Courier name (if “Other”)', 'nexora-commerce-toolkit' ); ?>&nbsp;<span class="optional"><?php esc_html_e( '(optional)', 'nexora-commerce-toolkit' ); ?></span></label>
					<span class="woocommerce-input-wrapper"><input type="text" class="input-text" name="nexora_courier_other" id="nexora_courier_other" maxlength="60" value="<?php echo esc_attr( self::draft( 'nexora_courier_other' ) ); ?>"></span>
				</p>
				<p class="form-row form-row-wide validate-required nexora-fulfilment__ack" id="nexora_courier_ack_field">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
						<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="nexora_courier_ack" id="nexora_courier_ack" value="1" <?php checked( '1', self::draft( 'nexora_courier_ack' ) ); ?> required aria-required="true">
						<span><?php esc_html_e( 'I will book and pay for the courier myself. The store does not deliver this order.', 'nexora-commerce-toolkit' ); ?></span>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'nexora-commerce-toolkit' ); ?>">*</abbr>
					</label>
				</p>
			<?php else : ?>
				<?php
				$locations = $method->locations();
				$loc       = (int) self::draft( 'nexora_pickup_location', '0' );
				$loc       = isset( $locations[ $loc ] ) ? $loc : 0;
				?>
				<?php if ( count( $locations ) > 1 ) : ?>
					<fieldset class="nexora-fulfilment__locations">
						<legend><?php esc_html_e( 'Pickup point', 'nexora-commerce-toolkit' ); ?></legend>
						<?php foreach ( $locations as $i => $location ) : ?>
							<label class="nexora-fulfilment__location">
								<input type="radio" name="nexora_pickup_location" value="<?php echo esc_attr( (string) $i ); ?>" <?php checked( $loc, $i ); ?>>
								<span>
									<strong><?php echo esc_html( $location['name'] ); ?></strong>
									<?php if ( $location['address'] ) : ?><span><?php echo esc_html( $location['address'] ); ?></span><?php endif; ?>
									<?php if ( $location['hours'] ) : ?><span class="nexora-fulfilment__muted"><?php echo esc_html( $location['hours'] ); ?></span><?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php else : ?>
					<div class="nexora-fulfilment__place">
						<strong><?php echo esc_html( $locations[0]['name'] ); ?></strong>
						<?php if ( $locations[0]['address'] ) : ?><span><?php echo esc_html( $locations[0]['address'] ); ?></span><?php endif; ?>
						<?php if ( $locations[0]['hours'] ) : ?><span class="nexora-fulfilment__muted"><?php echo esc_html( $locations[0]['hours'] ); ?></span><?php endif; ?>
						<input type="hidden" name="nexora_pickup_location" value="0">
					</div>
				<?php endif; ?>
				<?php self::schedule_select( $schedule, $selected, __( 'Pickup time', 'nexora-commerce-toolkit' ), 'form-row-wide' ); ?>
				<p class="nexora-fulfilment__note"><?php esc_html_e( 'We will email you when your order is packed. Bring your order number; someone else can collect it for you.', 'nexora-commerce-toolkit' ); ?></p>
			<?php endif; ?>
			<?php if ( $method->get_option( 'instructions' ) ) : ?>
				<p class="nexora-fulfilment__note"><?php echo esc_html( $method->get_option( 'instructions' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Date + slot select, grouped by day.
	 *
	 * @param array  $schedule Schedule.
	 * @param string $selected Selected value (Y-m-d|HH:MM-HH:MM).
	 * @param string $label    Label.
	 * @param string $class    Row class.
	 */
	private static function schedule_select( $schedule, $selected, $label, $class ) {
		?>
		<p class="form-row <?php echo esc_attr( $class ); ?> validate-required" id="nexora_schedule_field">
			<label for="nexora_schedule"><?php echo esc_html( $label ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'nexora-commerce-toolkit' ); ?>">*</abbr></label>
			<span class="woocommerce-input-wrapper">
				<?php if ( $schedule ) : ?>
					<select name="nexora_schedule" id="nexora_schedule" class="select" required aria-required="true">
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
					<span class="nexora-fulfilment__muted"><?php esc_html_e( 'No time slots are open right now. Please contact us or choose another option.', 'nexora-commerce-toolkit' ); ?></span>
				<?php endif; ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Pickup orders do not need a shipping address.
	 *
	 * @param bool $needs Needs address.
	 * @return bool
	 */
	public static function needs_shipping_address( $needs ) {
		if ( ! $needs || ! WC()->session ) {
			return $needs;
		}
		return 'pickup' === self::type_of( self::chosen_rate() ) ? false : $needs;
	}

	/**
	 * Validate the posted fields against the method's current schedule.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ) {
		$rate   = self::chosen_rate( $data );
		$type   = self::type_of( $rate );
		$method = $type ? self::method_for( $rate ) : null;
		if ( ! $method ) {
			return;
		}
		$posted = self::posted();

		if ( 'pickup' === $type ) {
			// The billing address is not needed when collecting in person.
			foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode' ) as $field ) {
				$errors->remove( 'billing_' . $field . '_required' );
				$errors->remove( 'billing_' . $field . '_validation' );
			}
			if ( ! isset( $method->locations()[ (int) $posted['nexora_pickup_location'] ] ) ) {
				$errors->add( 'nexora_pickup_location', __( 'Please choose a pickup point.', 'nexora-commerce-toolkit' ), array( 'id' => 'nexora_pickup_location' ) );
			}
		} else {
			$couriers = array_merge( $method->couriers(), array( 'other' ) );
			if ( '' === $posted['nexora_courier'] || ! in_array( $posted['nexora_courier'], $couriers, true ) ) {
				$errors->add( 'nexora_courier', __( 'Please choose the courier you will book.', 'nexora-commerce-toolkit' ), array( 'id' => 'nexora_courier' ) );
			}
			if ( '1' !== $posted['nexora_courier_ack'] ) {
				$errors->add( 'nexora_courier_ack', __( 'Please confirm that you will book the courier yourself.', 'nexora-commerce-toolkit' ), array( 'id' => 'nexora_courier_ack' ) );
			}
		}

		if ( ! self::slot_from_value( $method, $posted['nexora_schedule'] ) ) {
			$errors->add(
				'nexora_schedule',
				'' === $posted['nexora_schedule']
					? ( 'pickup' === $type ? __( 'Please choose a pickup time.', 'nexora-commerce-toolkit' ) : __( 'Please choose when your rider will collect the order.', 'nexora-commerce-toolkit' ) )
					: __( 'That time is no longer available. Please choose another.', 'nexora-commerce-toolkit' ),
				array( 'id' => 'nexora_schedule' )
			);
		}
	}

	/**
	 * Posted field values.
	 *
	 * @return array<string,string>
	 */
	private static function posted() {
		$out = array();
		foreach ( array( 'nexora_schedule', 'nexora_courier', 'nexora_courier_other', 'nexora_pickup_location', 'nexora_courier_ack' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout::process_checkout() verified the nonce.
			$out[ $key ] = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		return $out;
	}

	/**
	 * Split and check "Y-m-d|HH:MM-HH:MM" against the live schedule.
	 *
	 * @param Nexora_Toolkit_Shipping_Method $method Method.
	 * @param string                         $value  Value.
	 * @return array|null [date, slot]
	 */
	private static function slot_from_value( $method, $value ) {
		$parts = explode( '|', (string) $value );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$schedule = $method->schedule();
		return isset( $schedule[ $parts[0] ]['slots'][ $parts[1] ] ) ? $parts : null;
	}

	/**
	 * Store the choice on the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public static function save( $order, $data ) {
		$rate   = self::chosen_rate( $data );
		$type   = self::type_of( $rate );
		$method = $type ? self::method_for( $rate ) : null;
		if ( ! $method ) {
			return;
		}
		$posted = self::posted();
		$slot   = self::slot_from_value( $method, $posted['nexora_schedule'] );
		$info   = array(
			'type'   => $type,
			'method' => $method->title,
			'date'   => $slot ? $slot[0] : '',
			'slot'   => $slot ? $slot[1] : '',
		);

		if ( 'pickup' === $type ) {
			$locations        = $method->locations();
			$location         = isset( $locations[ (int) $posted['nexora_pickup_location'] ] ) ? $locations[ (int) $posted['nexora_pickup_location'] ] : $locations[0];
			$info['location'] = $location['name'];
			$info['address']  = $location['address'];
			$info['hours']    = $location['hours'];
		} else {
			$courier         = 'other' === $posted['nexora_courier'] ? ( $posted['nexora_courier_other'] ? mb_substr( $posted['nexora_courier_other'], 0, 60 ) : __( 'Other courier', 'nexora-commerce-toolkit' ) ) : $posted['nexora_courier'];
			$info['courier'] = $courier;
			$info['address'] = $method->pickup_point();
			$info['contact'] = (string) $method->get_option( 'contact_phone' );
			if ( $order->get_customer_id() && 'other' !== $posted['nexora_courier'] ) {
				update_user_meta( $order->get_customer_id(), 'nexora_preferred_courier', $posted['nexora_courier'] );
			}
		}
		if ( $method->get_option( 'instructions' ) ) {
			$info['instructions'] = (string) $method->get_option( 'instructions' );
		}

		$order->update_meta_data( self::META, $info );
		$order->update_meta_data( '_nexora_fulfilment_type', $type );
		$order->update_meta_data( '_nexora_fulfilment_date', $info['date'] );
		if ( WC()->session ) {
			WC()->session->set( 'nexora_fulfilment_draft', null );
		}
	}

	/* ---------------------------------------------------------------------
	 * Reading and showing the details
	 * ------------------------------------------------------------------ */

	/**
	 * Fulfilment details for an order. Orders placed through the block checkout only have the type.
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	public static function for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$info = $order->get_meta( self::META );
		if ( is_array( $info ) && ! empty( $info['type'] ) ) {
			return $info;
		}
		foreach ( $order->get_shipping_methods() as $item ) {
			$type = self::type_of( $item->get_method_id() );
			if ( $type ) {
				return array(
					'type'   => $type,
					'method' => $item->get_name(),
					'date'   => '',
					'slot'   => '',
				);
			}
		}
		return null;
	}

	/**
	 * "Wed, Oct 28 · 8:00 am – 10:00 am".
	 *
	 * @param array $info Fulfilment info.
	 * @return string
	 */
	public static function when( $info ) {
		if ( empty( $info['date'] ) ) {
			return '';
		}
		self::load_methods();
		$day = class_exists( 'Nexora_Toolkit_Shipping_Method' ) ? Nexora_Toolkit_Shipping_Method::date_label( $info['date'] ) : $info['date'];
		return $info['slot'] ? $day . ' · ' . Nexora_Toolkit_Shipping_Method::slot_label( $info['slot'] ) : $day;
	}

	/**
	 * Add schedule rows under Shipping in order totals (emails, thank-you page, My Account).
	 *
	 * @param array    $rows  Rows.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function totals_rows( $rows, $order ) {
		$info = self::for_order( $order );
		if ( ! $info || empty( $info['date'] ) ) {
			return $rows;
		}
		if ( 'pickup' === $info['type'] ) {
			$extra = array(
				'nexora_pickup' => array(
					'label' => __( 'Pickup:', 'nexora-commerce-toolkit' ),
					'value' => esc_html( self::when( $info ) ) . '<br>' . esc_html( trim( $info['location'] . ( $info['address'] ? ' — ' . $info['address'] : '' ) ) ),
				),
			);
		} else {
			$extra = array(
				'nexora_courier' => array(
					'label' => __( 'Courier:', 'nexora-commerce-toolkit' ),
					/* translators: 1: courier name, 2: pickup time */
					'value' => esc_html( sprintf( __( '%1$s, booked by the customer. Rider pickup %2$s', 'nexora-commerce-toolkit' ), $info['courier'], self::when( $info ) ) ),
				),
			);
		}
		$out = array();
		foreach ( $rows as $key => $row ) {
			$out[ $key ] = $row;
			if ( 'shipping' === $key ) {
				$out += $extra;
			}
		}
		return isset( $out[ key( $extra ) ] ) ? $out : $out + $extra;
	}

	/**
	 * The rate's internal type marker is not shown on the order screen.
	 *
	 * @param array $keys Hidden meta keys.
	 * @return array
	 */
	public static function hidden_item_meta( $keys ) {
		$keys[] = 'nexora_fulfilment';
		return $keys;
	}

	/**
	 * Details card for the customer, with what to do next.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function customer_card( $order ) {
		$info = self::for_order( $order );
		if ( ! $info ) {
			return;
		}
		$ready  = $order->has_status( self::STATUS );
		$done   = $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) );
		$pickup = 'pickup' === $info['type'];
		?>
		<section class="nexora-fulfilment-card<?php echo $ready ? ' is-ready' : ''; ?>" data-type="<?php echo esc_attr( $info['type'] ); ?>">
			<h2 class="nexora-fulfilment-card__title">
				<?php echo esc_html( $pickup ? __( 'Store pickup', 'nexora-commerce-toolkit' ) : __( 'Courier delivery (you book)', 'nexora-commerce-toolkit' ) ); ?>
			</h2>
			<?php if ( ! $done ) : ?>
				<p class="nexora-fulfilment-card__status">
					<?php
					if ( $ready ) {
						echo esc_html( $pickup ? __( 'Your order is packed and ready. Bring your order number when you collect it.', 'nexora-commerce-toolkit' ) : __( 'Your order is packed. Book your rider now and give them your order number.', 'nexora-commerce-toolkit' ) );
					} else {
						echo esc_html( $pickup ? __( 'We are preparing your order. We will email you when it is ready to collect.', 'nexora-commerce-toolkit' ) : __( 'We are preparing your order. We will email you when it is ready, so you can book your rider.', 'nexora-commerce-toolkit' ) );
					}
					?>
				</p>
			<?php endif; ?>
			<dl class="nexora-fulfilment-card__list">
				<?php if ( ! empty( $info['date'] ) ) : ?>
					<div><dt><?php echo esc_html( $pickup ? __( 'Pickup time', 'nexora-commerce-toolkit' ) : __( 'Rider pickup time', 'nexora-commerce-toolkit' ) ); ?></dt><dd><?php echo esc_html( self::when( $info ) ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! $pickup && ! empty( $info['courier'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Courier', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( $info['courier'] ); ?></dd></div>
				<?php endif; ?>
				<?php if ( $pickup && ! empty( $info['location'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Pickup point', 'nexora-commerce-toolkit' ); ?></dt><dd><?php echo esc_html( $info['location'] ); ?><?php echo ! empty( $info['hours'] ) ? '<br><span class="nexora-fulfilment__muted">' . esc_html( $info['hours'] ) . '</span>' : ''; ?></dd></div>
				<?php endif; ?>
				<?php if ( ! empty( $info['address'] ) ) : ?>
					<div><dt><?php echo esc_html( $pickup ? __( 'Address', 'nexora-commerce-toolkit' ) : __( 'Rider collects from', 'nexora-commerce-toolkit' ) ); ?></dt><dd><?php echo esc_html( $info['address'] ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! empty( $info['contact'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Store contact', 'nexora-commerce-toolkit' ); ?></dt><dd><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $info['contact'] ) ); ?>"><?php echo esc_html( $info['contact'] ); ?></a></dd></div>
				<?php endif; ?>
				<div><dt><?php esc_html_e( 'Order number', 'nexora-commerce-toolkit' ); ?></dt><dd><strong><?php echo esc_html( $order->get_order_number() ); ?></strong></dd></div>
			</dl>
			<?php if ( ! empty( $info['instructions'] ) ) : ?>
				<p class="nexora-fulfilment__note"><?php echo esc_html( $info['instructions'] ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * "Ready for pickup" status
	 * ------------------------------------------------------------------ */

	/**
	 * Register the post status (used by the legacy order storage and status counts).
	 */
	public static function register_status() {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Ready for pickup', 'Order status', 'nexora-commerce-toolkit' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Ready for pickup <span class="count">(%s)</span>', 'Ready for pickup <span class="count">(%s)</span>', 'nexora-commerce-toolkit' ),
			)
		);
	}

	/**
	 * Add to the status list, right after Processing.
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function order_statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out[ 'wc-' . self::STATUS ] = _x( 'Ready for pickup', 'Order status', 'nexora-commerce-toolkit' );
			}
		}
		return $out;
	}

	/**
	 * Ready orders are paid orders (reports, downloads, "paid" checks).
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function paid_statuses( $statuses ) {
		$statuses[] = self::STATUS;
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Remember when the order became ready (shown in the admin timeline).
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order.
	 */
	public static function mark_ready_time( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_nexora_ready_at', time() );
			$order->save_meta_data();
		}
	}

	/**
	 * Let WooCommerce queue an email hook for the new status.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function email_actions( $actions ) {
		$actions[] = 'woocommerce_order_status_' . self::STATUS;
		return $actions;
	}

	/**
	 * Register the customer email (WooCommerce → Settings → Emails → Order ready for pickup).
	 *
	 * @param array $emails Emails.
	 * @return array
	 */
	public static function email_classes( $emails ) {
		require_once NEXORA_TOOLKIT_DIR . 'includes/emails/class-email-order-ready.php';
		$emails['Nexora_Toolkit_Email_Order_Ready'] = new Nexora_Toolkit_Email_Order_Ready();
		return $emails;
	}

	/* ---------------------------------------------------------------------
	 * Block checkout → classic checkout
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the checkout page uses the Checkout block.
	 *
	 * @return bool
	 */
	public static function checkout_uses_block() {
		$page = get_post( wc_get_page_id( 'checkout' ) );
		return $page && has_block( 'woocommerce/checkout', $page );
	}

	/**
	 * Switch the checkout page between the block and the classic shortcode.
	 *
	 * @param string $mode classic|block.
	 * @return bool|WP_Error
	 */
	public static function set_checkout_mode( $mode ) {
		$page = get_post( wc_get_page_id( 'checkout' ) );
		if ( ! $page ) {
			return new WP_Error( 'nexora_no_checkout', __( 'No checkout page is set in WooCommerce → Settings → Advanced.', 'nexora-commerce-toolkit' ) );
		}
		if ( 'classic' === $mode ) {
			if ( ! has_block( 'woocommerce/checkout', $page ) ) {
				return true;
			}
			update_post_meta( $page->ID, self::BACKUP, wp_slash( $page->post_content ) );
			$content = "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->";
		} else {
			$content = (string) get_post_meta( $page->ID, self::BACKUP, true );
			if ( '' === $content ) {
				return new WP_Error( 'nexora_no_backup', __( 'There is no saved block checkout to restore. Add the Checkout block to the page in the editor instead.', 'nexora-commerce-toolkit' ) );
			}
		}
		$result = wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Admin notice while the block checkout is active (the schedule fields need the classic checkout).
	 */
	public static function checkout_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || get_option( 'nexora_checkout_notice_dismissed' ) || ! self::checkout_uses_block() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings', 'settings_page_nexora-toolkit', 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			return;
		}
		$url = static function ( $mode ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=nexora_checkout_mode&mode=' . $mode ), 'nexora_checkout_mode' );
		};
		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'Freshtastic checkout is not active yet', 'nexora-commerce-toolkit' ); ?></strong></p>
			<p><?php esc_html_e( 'Your checkout page uses the Checkout block. The Freshtastic checkout layout and the courier and pickup scheduling need the classic checkout. Switching keeps a backup of the current page, so you can switch back from Settings → Freshtastic Toolkit.', 'nexora-commerce-toolkit' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $url( 'classic' ) ); ?>"><?php esc_html_e( 'Use the Freshtastic checkout', 'nexora-commerce-toolkit' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $url( 'dismiss' ) ); ?>"><?php esc_html_e( 'Keep the block checkout', 'nexora-commerce-toolkit' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the switch / restore / dismiss links.
	 */
	public static function handle_checkout_mode() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_checkout_mode' );
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'options-general.php?page=nexora-toolkit' );

		if ( 'dismiss' === $mode ) {
			update_option( 'nexora_checkout_notice_dismissed', 1, false );
			wp_safe_redirect( $back );
			exit;
		}
		$result = self::set_checkout_mode( 'block' === $mode ? 'block' : 'classic' );
		if ( 'block' === $mode && true === $result ) {
			update_option( 'nexora_checkout_notice_dismissed', 1, false );
		}
		set_transient( 'nexora_checkout_mode_result_' . get_current_user_id(), is_wp_error( $result ) ? $result->get_error_message() : ( 'block' === $mode ? __( 'The block checkout was restored.', 'nexora-commerce-toolkit' ) : __( 'The Freshtastic checkout is now active.', 'nexora-commerce-toolkit' ) ), 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=nexora-toolkit#nexora-orders' ) );
		exit;
	}
}
