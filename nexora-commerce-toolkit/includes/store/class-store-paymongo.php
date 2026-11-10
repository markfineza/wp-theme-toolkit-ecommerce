<?php
/**
 * PayMongo for the built-in store: GCash, Maya, cards, QR Ph, GrabPay, ShopeePay, BillEase and online
 * banking through PayMongo's hosted Checkout page.
 *
 * Flow: the order is created as "Pending payment", the customer is sent to PayMongo Checkout, and the
 * order becomes "Processing" (paid) when PayMongo confirms the payment, either through the signed
 * webhook or when the customer comes back (the session is always re-read from PayMongo with the
 * secret key, so a forged return link cannot mark an order paid). Unpaid orders are cancelled after
 * the time limit and their stock released. Refunds can be sent from the order screen.
 *
 * Secret keys are write-only in the admin, can be wp-config constants, and only go to api.paymongo.com.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayMongo gateway.
 */
class Nexora_Store_Paymongo {

	const ID      = 'paymongo';
	const OPTION  = 'nexora_paymongo';
	const PAGE    = 'nexora-paymongo';
	const API     = 'https://api.paymongo.com';
	const TIMEOUT = 'nexora_paymongo_timeout';
	const EVENTS  = array( 'checkout_session.payment.paid', 'payment.refunded' );
	const SECRETS = array(
		'test_secret'  => 'NEXORA_PAYMONGO_TEST_SECRET',
		'live_secret'  => 'NEXORA_PAYMONGO_LIVE_SECRET',
		'test_webhook' => 'NEXORA_PAYMONGO_TEST_WEBHOOK_SECRET',
		'live_webhook' => 'NEXORA_PAYMONGO_LIVE_WEBHOOK_SECRET',
	);

	/**
	 * Hooks (built-in store only; WooCommerce shops use PayMongo's own WooCommerce plugin).
	 */
	public static function init() {
		add_filter( 'nexora_store_gateways', array( __CLASS__, 'gateway' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::TIMEOUT, array( __CLASS__, 'timeout' ), 10, 2 );
		add_action( 'nexora_store_received_payment', array( __CLASS__, 'received_card' ) );
		add_filter( 'nexora_store_received_message', array( __CLASS__, 'received_message' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_post_nexora_paymongo_webhook', array( __CLASS__, 'handle_webhook_setup' ) );
			add_action( 'admin_post_nexora_paymongo_refund', array( __CLASS__, 'handle_refund' ) );
			add_action( 'admin_post_nexora_paymongo_check', array( __CLASS__, 'handle_check' ) );
			add_action( 'nexora_store_admin_order_side', array( __CLASS__, 'order_card' ) );
		}
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * Payment methods PayMongo Checkout offers.
	 *
	 * @return array<string,string>
	 */
	public static function methods() {
		return array(
			'gcash'             => __( 'GCash', 'nexora-commerce-toolkit' ),
			'paymaya'           => __( 'Maya', 'nexora-commerce-toolkit' ),
			'card'              => __( 'Credit or debit card', 'nexora-commerce-toolkit' ),
			'qrph'              => __( 'QR Ph (any bank or e-wallet app)', 'nexora-commerce-toolkit' ),
			'grab_pay'          => __( 'GrabPay', 'nexora-commerce-toolkit' ),
			'shopee_pay'        => __( 'ShopeePay', 'nexora-commerce-toolkit' ),
			'billease'          => __( 'BillEase (buy now, pay later)', 'nexora-commerce-toolkit' ),
			'dob'               => __( 'BPI online banking', 'nexora-commerce-toolkit' ),
			'dob_ubp'           => __( 'UnionBank online banking', 'nexora-commerce-toolkit' ),
			'brankas_bdo'       => __( 'BDO online banking', 'nexora-commerce-toolkit' ),
			'brankas_landbank'  => __( 'Landbank online banking', 'nexora-commerce-toolkit' ),
			'brankas_metrobank' => __( 'Metrobank online banking', 'nexora-commerce-toolkit' ),
		);
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => false,
			'mode'           => 'test',
			'test_secret'    => '',
			'live_secret'    => '',
			'test_webhook'   => '',
			'live_webhook'   => '',
			'test_hook_id'   => '',
			'live_hook_id'   => '',
			'title'          => '',
			'methods'        => array( 'gcash', 'paymaya', 'card', 'qrph' ),
			'hold_minutes'   => 60,
			'email_receipt'  => true,
		);
	}

	/**
	 * Settings (secrets resolve wp-config constants first).
	 *
	 * @return array
	 */
	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		foreach ( self::SECRETS as $key => $constant ) {
			if ( defined( $constant ) ) {
				$s[ $key ] = (string) constant( $constant );
			}
		}
		return $s;
	}

	/**
	 * Secret key for a mode.
	 *
	 * @param string|null $mode test|live (default: the selected mode).
	 * @return string
	 */
	private static function secret( $mode = null ) {
		$s    = self::settings();
		$mode = $mode ? $mode : $s['mode'];
		return (string) $s[ 'live' === $mode ? 'live_secret' : 'test_secret' ];
	}

	/**
	 * Ready to take payments.
	 *
	 * @return bool
	 */
	public static function ready() {
		$s = self::settings();
		return $s['enabled'] && '' !== self::secret() && ! empty( $s['methods'] );
	}

	/**
	 * Checkout title.
	 *
	 * @return string
	 */
	public static function title() {
		$t = (string) self::settings()['title'];
		return '' !== $t ? $t : __( 'GCash, Maya, card or QR Ph', 'nexora-commerce-toolkit' );
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting( 'nexora_paymongo', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * Sanitize. Empty secret inputs keep the stored value.
	 *
	 * @param array $input Raw (options.php has already unslashed it).
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$out     = self::defaults();
		$prefix  = array(
			'test_secret'  => 'sk_test_',
			'live_secret'  => 'sk_live_',
			'test_webhook' => 'whsk_',
			'live_webhook' => 'whsk_',
		);
		foreach ( $prefix as $key => $start ) {
			$value = isset( $input[ $key ] ) ? preg_replace( '/\s+/', '', sanitize_text_field( $input[ $key ] ) ) : '';
			if ( '' !== $value && 0 !== strpos( $value, $start ) ) {
				/* translators: 1: field, 2: expected start */
				add_settings_error( self::OPTION, $key, sprintf( __( '%1$s must start with %2$s. It was not saved.', 'nexora-commerce-toolkit' ), $key, $start ) );
				$value = '';
			}
			$out[ $key ] = '' !== $value ? $value : $current[ $key ];
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$out[ $key ] = '';
			}
		}
		$out['test_hook_id']  = $current['test_hook_id'];
		$out['live_hook_id']  = $current['live_hook_id'];
		$out['enabled']       = ! empty( $input['enabled'] );
		$out['email_receipt'] = ! empty( $input['email_receipt'] );
		$out['mode']          = isset( $input['mode'] ) && 'live' === $input['mode'] ? 'live' : 'test';
		$out['title']         = isset( $input['title'] ) ? mb_substr( sanitize_text_field( $input['title'] ), 0, 120 ) : '';
		$out['methods']       = isset( $input['methods'] ) && is_array( $input['methods'] ) ? array_values( array_intersect( array_keys( self::methods() ), array_map( 'sanitize_key', $input['methods'] ) ) ) : array();
		$out['hold_minutes']  = isset( $input['hold_minutes'] ) ? max( 0, min( 2880, absint( $input['hold_minutes'] ) ) ) : 60;
		if ( $out['enabled'] && ! $out['methods'] ) {
			add_settings_error( self::OPTION, 'methods', __( 'Choose at least one payment method.', 'nexora-commerce-toolkit' ) );
		}
		return $out;
	}

	/* ----------------------------------------------------------------- API */

	/**
	 * Call the PayMongo API.
	 *
	 * @param string      $method GET|POST.
	 * @param string      $path   Path, e.g. /v1/checkout_sessions.
	 * @param array|null  $attrs  data.attributes for POST.
	 * @param string|null $mode   test|live.
	 * @return array|WP_Error Decoded "data".
	 */
	public static function api( $method, $path, $attrs = null, $mode = null ) {
		$key = self::secret( $mode );
		if ( '' === $key ) {
			return new WP_Error( 'nexora_paymongo', __( 'No PayMongo secret key is saved for this mode.', 'nexora-commerce-toolkit' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- HTTP Basic auth.
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $attrs ) {
			$args['body'] = wp_json_encode( array( 'data' => array( 'attributes' => $attrs ) ) );
		}
		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error */
			return new WP_Error( 'nexora_paymongo', sprintf( __( 'Could not reach PayMongo: %s', 'nexora-commerce-toolkit' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$detail = '';
			if ( is_array( $body ) && ! empty( $body['errors'][0] ) ) {
				$e      = $body['errors'][0];
				$detail = trim( ( isset( $e['detail'] ) ? $e['detail'] : '' ) . ( isset( $e['code'] ) ? ' (' . $e['code'] . ')' : '' ) );
			}
			/* translators: 1: HTTP status, 2: PayMongo message */
			return new WP_Error( 'nexora_paymongo', sprintf( __( 'PayMongo error %1$d: %2$s', 'nexora-commerce-toolkit' ), $code, '' !== $detail ? wp_strip_all_tags( $detail ) : __( 'unexpected answer', 'nexora-commerce-toolkit' ) ), array( 'status' => $code ) );
		}
		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
	}

	/**
	 * Centavos.
	 *
	 * @param float $amount Pesos.
	 * @return int
	 */
	private static function cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/* ------------------------------------------------------------- checkout */

	/**
	 * Add PayMongo to the checkout's payment methods.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public static function gateway( $gateways ) {
		if ( ! self::ready() ) {
			return $gateways;
		}
		$s      = self::settings();
		$labels = array_intersect_key( self::methods(), array_flip( $s['methods'] ) );
		if ( isset( $labels['card'] ) ) {
			$labels['card'] = __( 'card', 'nexora-commerce-toolkit' );
		}
		/* translators: %s: list of payment methods */
		$desc = sprintf( __( 'Pay securely on PayMongo with %s. You will come back here after paying.', 'nexora-commerce-toolkit' ), implode( ', ', array_map( static function ( $l ) {
			return preg_replace( '/\s*\(.*\)$/', '', $l );
		}, $labels ) ) );
		if ( 'test' === $s['mode'] ) {
			$desc .= ' ' . __( '[Test mode: no real money is charged.]', 'nexora-commerce-toolkit' );
		}
		$gateways[ self::ID ] = array(
			'title'      => self::title(),
			'desc'       => $desc,
			'types'      => array(),
			'status'     => 'pending',
			'needs_ref'  => false,
			'process'    => array( __CLASS__, 'process' ),
			'defer_mail' => true,
		);
		return $gateways;
	}

	/**
	 * Link back to the site for an order.
	 *
	 * @param object $order Order.
	 * @param string $what  return|cancel|pay.
	 * @return string
	 */
	public static function link( $order, $what ) {
		return add_query_arg(
			array(
				'nx_pm'       => $what,
				'nx_pm_order' => (int) $order->id,
				'key'         => $order->order_key,
			),
			home_url( '/' )
		);
	}

	/**
	 * Order placed with PayMongo: create the checkout session and send the customer there.
	 *
	 * @param int $id Order.
	 * @return string Redirect URL.
	 */
	public static function process( $id ) {
		$order = Nexora_Store_Orders::get( $id );
		if ( ! $order ) {
			return '';
		}
		$minutes = (int) self::settings()['hold_minutes'];
		if ( $minutes > 0 && ! wp_next_scheduled( self::TIMEOUT, array( (int) $id, 1 ) ) ) {
			wp_schedule_single_event( time() + $minutes * MINUTE_IN_SECONDS, self::TIMEOUT, array( (int) $id, 1 ) );
		}
		$session = self::create_session( $order );
		if ( is_wp_error( $session ) ) {
			/* translators: %s: error */
			Nexora_Store_Orders::add_note( $id, sprintf( __( 'PayMongo checkout could not be opened: %s', 'nexora-commerce-toolkit' ), $session->get_error_message() ) );
			return add_query_arg( 'nx_pm_state', 'error', Nexora_Store_Checkout::received_url( $order ) );
		}
		return $session['attributes']['checkout_url'];
	}

	/**
	 * Create a checkout session for an order and remember it on the order.
	 *
	 * @param object $order Order.
	 * @return array|WP_Error Session.
	 */
	public static function create_session( $order ) {
		$s     = self::settings();
		$names = array();
		foreach ( Nexora_Store_Orders::items( $order->id ) as $line ) {
			$names[] = $line->name . ( $line->option_label ? ' (' . $line->option_label . ')' : '' ) . ' × ' . (int) $line->qty;
		}
		$a     = $order->address;
		$name  = trim( ( isset( $a['first_name'] ) ? $a['first_name'] : '' ) . ' ' . ( isset( $a['last_name'] ) ? $a['last_name'] : '' ) );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$attrs = array(
			'line_items'           => array(
				array(
					/* translators: 1: order number, 2: site name */
					'name'        => mb_substr( sprintf( __( 'Order #%1$d – %2$s', 'nexora-commerce-toolkit' ), $order->id, $site ), 0, 255 ),
					'description' => mb_substr( implode( ', ', $names ), 0, 255 ),
					'amount'      => self::cents( $order->total ),
					'currency'    => 'PHP',
					'quantity'    => 1,
				),
			),
			'payment_method_types' => array_values( $s['methods'] ),
			'success_url'          => self::link( $order, 'return' ),
			'cancel_url'           => self::link( $order, 'cancel' ),
			/* translators: %d: order number */
			'description'          => sprintf( __( 'Order #%d', 'nexora-commerce-toolkit' ), $order->id ),
			'reference_number'     => (string) $order->id,
			'send_email_receipt'   => (bool) $s['email_receipt'],
			'show_description'     => true,
			'show_line_items'      => true,
			'billing'              => array_filter(
				array(
					'name'  => $name,
					'email' => $order->email,
					'phone' => $order->phone,
				)
			),
			'metadata'             => array(
				'order_id' => (string) $order->id,
				'site'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
		);
		$session = self::api( 'POST', '/v1/checkout_sessions', (array) apply_filters( 'nexora_paymongo_session', $attrs, $order ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['id'] ) || empty( $session['attributes']['checkout_url'] ) ) {
			return new WP_Error( 'nexora_paymongo', __( 'PayMongo did not return a checkout page.', 'nexora-commerce-toolkit' ) );
		}
		self::update_order( $order->id, array( 'payment_ref' => (string) $session['id'] ) );
		return $session;
	}

	/**
	 * Update order columns.
	 *
	 * @param int   $id     Order.
	 * @param array $fields Fields.
	 */
	private static function update_order( $id, $fields ) {
		global $wpdb;
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( "{$wpdb->prefix}nx_orders", $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* --------------------------------------------------------- confirmation */

	/**
	 * Paid payment in a checkout session.
	 *
	 * @param array $session Session.
	 * @return array|null Payment.
	 */
	private static function paid_payment( $session ) {
		$lists = array();
		if ( ! empty( $session['attributes']['payments'] ) ) {
			$lists[] = (array) $session['attributes']['payments'];
		}
		if ( ! empty( $session['attributes']['payment_intent']['attributes']['payments'] ) ) {
			$lists[] = (array) $session['attributes']['payment_intent']['attributes']['payments'];
		}
		foreach ( $lists as $list ) {
			foreach ( $list as $p ) {
				if ( isset( $p['attributes']['status'] ) && 'paid' === $p['attributes']['status'] ) {
					return $p;
				}
			}
		}
		return null;
	}

	/**
	 * Read the order's checkout session from PayMongo and mark the order paid if it is.
	 *
	 * @param object      $order Order.
	 * @param string|null $mode  test|live.
	 * @return string paid|unpaid|error|none
	 */
	public static function sync( $order, $mode = null ) {
		if ( $order->paid_at ) {
			return 'paid';
		}
		if ( 0 !== strpos( (string) $order->payment_ref, 'cs_' ) ) {
			return 'none';
		}
		$session = self::api( 'GET', '/v1/checkout_sessions/' . rawurlencode( $order->payment_ref ), null, $mode );
		if ( is_wp_error( $session ) ) {
			return 'error';
		}
		$payment = self::paid_payment( $session );
		if ( ! $payment ) {
			return 'unpaid';
		}
		self::mark_paid( $order->id, $payment, (string) $session['id'] );
		return 'paid';
	}

	/**
	 * Mark an order paid (once, even when the webhook and the customer's return arrive together).
	 *
	 * @param int    $id      Order.
	 * @param array  $payment PayMongo payment.
	 * @param string $session Session ID it belongs to.
	 */
	private static function mark_paid( $id, $payment, $session ) {
		$lock = 'nx_pm_lock_' . (int) $id;
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			if ( (int) get_option( $lock ) > time() - 60 ) {
				return;
			}
			update_option( $lock, time(), false );
		}
		wp_cache_delete( $lock, 'options' );
		$order = Nexora_Store_Orders::get( $id );
		if ( ! $order || $order->paid_at || (string) $order->payment_ref !== $session ) {
			delete_option( $lock );
			return;
		}
		$attr     = $payment['attributes'];
		$amount   = isset( $attr['amount'] ) ? (int) $attr['amount'] : 0;
		$type     = isset( $attr['source']['type'] ) ? (string) $attr['source']['type'] : '';
		$methods  = self::methods();
		$label    = isset( $methods[ $type ] ) ? preg_replace( '/\s*\(.*\)$/', '', $methods[ $type ] ) : ucwords( str_replace( '_', ' ', $type ) );
		if ( 'card' === $type && ! empty( $attr['source']['last4'] ) ) {
			$label = trim( ( isset( $attr['source']['brand'] ) ? ucfirst( (string) $attr['source']['brand'] ) : __( 'Card', 'nexora-commerce-toolkit' ) ) . ' •••• ' . $attr['source']['last4'] );
		}
		self::update_order(
			$id,
			array(
				'payment_ref'   => mb_substr( (string) $payment['id'], 0, 100 ),
				'payment_title' => mb_substr( self::title() . ( '' !== $label ? ' – ' . $label : '' ), 0, 190 ),
			)
		);
		$expected = self::cents( $order->total );
		if ( $amount < $expected || ( isset( $attr['currency'] ) && 'PHP' !== $attr['currency'] ) ) {
			/* translators: 1: amount paid, 2: order total */
			$note = sprintf( __( 'PayMongo payment of %1$s does not match the order total of %2$s. Check it before preparing the order.', 'nexora-commerce-toolkit' ), Nexora_Store::money( $amount / 100 ), Nexora_Store::money( $order->total ) );
			Nexora_Store_Orders::set_status( $id, 'on-hold', $note );
		} else {
			/* translators: 1: payment method, 2: PayMongo payment ID */
			$note = sprintf( __( 'Paid with %1$s through PayMongo (%2$s).', 'nexora-commerce-toolkit' ), '' !== $label ? $label : 'PayMongo', $payment['id'] );
			$done = Nexora_Store_Orders::set_status( $id, 'processing', $note );
			if ( is_wp_error( $done ) ) {
				// Paid after the order was cancelled and the stock is gone: record the payment for staff.
				self::update_order( $id, array( 'paid_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				/* translators: 1: note, 2: error */
				Nexora_Store_Orders::add_note( $id, sprintf( __( '%1$s The order could not be reopened (%2$s). Refund the customer or arrange the items.', 'nexora-commerce-toolkit' ), $note, $done->get_error_message() ) );
			}
		}
		Nexora_Store_Orders::mail_new_order( $id, false );
		wp_clear_scheduled_hook( self::TIMEOUT, array( (int) $id, 1 ) );
		do_action( 'nexora_paymongo_paid', $id, $payment );
		delete_option( $lock );
	}

	/**
	 * Unpaid after the time limit: check once more, then expire the session and cancel the order.
	 *
	 * @param int $id      Order.
	 * @param int $attempt Attempt.
	 */
	public static function timeout( $id, $attempt = 1 ) {
		$order = Nexora_Store_Orders::get( $id );
		if ( ! $order || self::ID !== $order->payment_method || $order->paid_at || 'pending' !== $order->status ) {
			return;
		}
		$state = self::sync( $order );
		if ( 'paid' === $state ) {
			return;
		}
		if ( 'error' === $state && $attempt < 3 ) {
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::TIMEOUT, array( (int) $id, $attempt + 1 ) );
			return;
		}
		if ( 0 === strpos( (string) $order->payment_ref, 'cs_' ) ) {
			self::api( 'POST', '/v1/checkout_sessions/' . rawurlencode( $order->payment_ref ) . '/expire' );
		}
		Nexora_Store_Orders::set_status( $id, 'cancelled', __( 'Online payment was not completed in time.', 'nexora-commerce-toolkit' ) );
	}

	/* ------------------------------------------------------ customer links */

	/**
	 * Return, cancel and pay-again links.
	 */
	public static function handle_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- links carry the order's private key.
		if ( ! isset( $_GET['nx_pm'], $_GET['nx_pm_order'], $_GET['key'] ) ) {
			return;
		}
		$what  = sanitize_key( wp_unslash( $_GET['nx_pm'] ) );
		$order = Nexora_Store_Orders::get( absint( $_GET['nx_pm_order'] ) );
		$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		// phpcs:enable
		if ( ! $order || ! hash_equals( (string) $order->order_key, $key ) || self::ID !== $order->payment_method ) {
			wp_safe_redirect( Nexora_Store::shop_url() );
			exit;
		}
		nocache_headers();
		$back = Nexora_Store_Checkout::received_url( $order );
		if ( 'return' === $what ) {
			$state = self::sync( $order );
			wp_safe_redirect( add_query_arg( 'nx_pm_state', 'paid' === $state ? 'paid' : 'checking', $back ) );
			exit;
		}
		if ( 'cancel' === $what ) {
			wp_safe_redirect( add_query_arg( 'nx_pm_state', 'cancelled', $back ) );
			exit;
		}
		if ( 'pay' === $what && ! $order->paid_at && 'pending' === $order->status && self::ready() ) {
			if ( 0 === strpos( (string) $order->payment_ref, 'cs_' ) ) {
				$session = self::api( 'GET', '/v1/checkout_sessions/' . rawurlencode( $order->payment_ref ) );
				if ( ! is_wp_error( $session ) ) {
					if ( self::paid_payment( $session ) ) {
						self::sync( $order );
						wp_safe_redirect( add_query_arg( 'nx_pm_state', 'paid', $back ) );
						exit;
					}
					if ( isset( $session['attributes']['status'] ) && 'active' === $session['attributes']['status'] && ! empty( $session['attributes']['checkout_url'] ) ) {
						wp_redirect( esc_url_raw( $session['attributes']['checkout_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- PayMongo's checkout page.
						exit;
					}
				}
			}
			$session = self::create_session( $order );
			if ( ! is_wp_error( $session ) ) {
				wp_redirect( esc_url_raw( $session['attributes']['checkout_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- PayMongo's checkout page.
				exit;
			}
			wp_safe_redirect( add_query_arg( 'nx_pm_state', 'error', $back ) );
			exit;
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Message under "Thank you" while the payment is open.
	 *
	 * @param string $message Message.
	 * @param object $order   Order.
	 * @return string
	 */
	public static function received_message( $message, $order ) {
		if ( self::ID !== $order->payment_method || $order->paid_at ) {
			return $message;
		}
		if ( 'cancelled' === $order->status ) {
			return __( 'This order was cancelled because the payment was not completed.', 'nexora-commerce-toolkit' );
		}
		return __( 'Your order is saved and waiting for payment.', 'nexora-commerce-toolkit' );
	}

	/**
	 * Payment status card on the order received page.
	 *
	 * @param object $order Order.
	 */
	public static function received_card( $order ) {
		if ( self::ID !== $order->payment_method ) {
			return;
		}
		$state = isset( $_GET['nx_pm_state'] ) ? sanitize_key( wp_unslash( $_GET['nx_pm_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<section class="nexora-fulfilment-card nx-pm-card" role="status">';
		if ( $order->paid_at ) {
			echo '<h2 class="nexora-fulfilment-card__title">' . esc_html__( 'Payment received', 'nexora-commerce-toolkit' ) . '</h2>';
			/* translators: %s: payment method */
			echo '<p>' . esc_html( sprintf( __( 'Paid with %s. We are preparing your order.', 'nexora-commerce-toolkit' ), $order->payment_title ) ) . '</p>';
		} elseif ( 'pending' === $order->status ) {
			$messages = array(
				'cancelled' => __( 'You left the payment page before paying. Your order is saved: pay now to confirm it.', 'nexora-commerce-toolkit' ),
				'error'     => __( 'We could not open the payment page. Please try again in a moment.', 'nexora-commerce-toolkit' ),
				'checking'  => __( 'We are waiting for PayMongo to confirm your payment. This usually takes a few seconds: reload this page. If you did not finish paying, pay now.', 'nexora-commerce-toolkit' ),
			);
			echo '<h2 class="nexora-fulfilment-card__title">' . esc_html__( 'Payment not completed yet', 'nexora-commerce-toolkit' ) . '</h2>';
			echo '<p>' . esc_html( isset( $messages[ $state ] ) ? $messages[ $state ] : __( 'Your order is saved. Pay now to confirm it.', 'nexora-commerce-toolkit' ) ) . '</p>';
			$minutes = (int) self::settings()['hold_minutes'];
			if ( $minutes > 0 ) {
				$until = strtotime( $order->created_at . ' UTC' ) + $minutes * MINUTE_IN_SECONDS;
				/* translators: %s: time */
				echo '<p><small>' . esc_html( sprintf( __( 'We keep the items for you until %s. After that the order is cancelled.', 'nexora-commerce-toolkit' ), wp_date( get_option( 'time_format' ), $until ) ) ) . '</small></p>';
			}
			if ( self::ready() ) {
				/* translators: %s: amount */
				echo '<p><a class="nx-btn nx-btn--primary" href="' . esc_url( self::link( $order, 'pay' ) ) . '">' . esc_html( sprintf( __( 'Pay %s now', 'nexora-commerce-toolkit' ), Nexora_Store::money( $order->total ) ) ) . '</a></p>';
			}
		} else {
			echo '<h2 class="nexora-fulfilment-card__title">' . esc_html__( 'Payment', 'nexora-commerce-toolkit' ) . '</h2>';
			echo '<p>' . esc_html( Nexora_Store_Orders::status_label( $order->status ) ) . '</p>';
		}
		echo '</section>';
	}

	/* -------------------------------------------------------------- webhook */

	/**
	 * Webhook address.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'nexora/v1/paymongo-webhook' );
	}

	/**
	 * REST route.
	 */
	public static function routes() {
		register_rest_route(
			'nexora/v1',
			'/paymongo-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Checked by the signature below.
			)
		);
	}

	/**
	 * Verify a Paymongo-Signature header.
	 *
	 * @param string $header Header.
	 * @param string $raw    Raw body.
	 * @param bool   $live   Live mode event.
	 * @return bool
	 */
	public static function verify( $header, $raw, $live ) {
		$secret = (string) self::settings()[ $live ? 'live_webhook' : 'test_webhook' ];
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$parts = array();
		foreach ( explode( ',', $header ) as $piece ) {
			$kv = explode( '=', trim( $piece ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ] = $kv[1];
			}
		}
		$given = $live ? ( isset( $parts['li'] ) ? $parts['li'] : '' ) : ( isset( $parts['te'] ) ? $parts['te'] : '' );
		if ( empty( $parts['t'] ) || '' === $given ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $parts['t'] . '.' . $raw, $secret ), $given );
	}

	/**
	 * Webhook from PayMongo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function webhook( $request ) {
		$raw   = (string) $request->get_body();
		$event = json_decode( $raw, true );
		$live  = ! empty( $event['data']['attributes']['livemode'] );
		if ( ! is_array( $event ) || ! self::verify( (string) $request->get_header( 'paymongo_signature' ), $raw, $live ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 401 );
		}
		$mode     = $live ? 'live' : 'test';
		$type     = isset( $event['data']['attributes']['type'] ) ? (string) $event['data']['attributes']['type'] : '';
		$resource = isset( $event['data']['attributes']['data'] ) ? (array) $event['data']['attributes']['data'] : array();

		if ( 'checkout_session.payment.paid' === $type && ! empty( $resource['attributes']['metadata']['order_id'] ) ) {
			$order = Nexora_Store_Orders::get( absint( $resource['attributes']['metadata']['order_id'] ) );
			if ( $order && self::ID === $order->payment_method && isset( $resource['id'] ) && (string) $order->payment_ref === (string) $resource['id'] ) {
				// Read the session from PayMongo with the secret key instead of trusting the payload.
				self::sync( $order, $mode );
			}
		} elseif ( 'payment.refunded' === $type && ! empty( $resource['id'] ) ) {
			self::record_outside_refunds( $resource );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Note refunds made in the PayMongo dashboard on the order.
	 *
	 * @param array $payment Payment resource.
	 */
	private static function record_outside_refunds( $payment ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nx_orders WHERE payment_method = %s AND payment_ref = %s LIMIT 1", self::ID, (string) $payment['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $id ) {
			return;
		}
		$known = self::refunds( $id );
		$ids   = wp_list_pluck( $known, 'id' );
		foreach ( isset( $payment['attributes']['refunds'] ) ? (array) $payment['attributes']['refunds'] : array() as $r ) {
			if ( empty( $r['id'] ) || in_array( $r['id'], $ids, true ) ) {
				continue;
			}
			$amount  = isset( $r['attributes']['amount'] ) ? (int) $r['attributes']['amount'] : 0;
			$known[] = array( 'id' => (string) $r['id'], 'amount' => $amount, 'status' => isset( $r['attributes']['status'] ) ? (string) $r['attributes']['status'] : '', 'time' => time() );
			/* translators: 1: amount, 2: refund ID */
			Nexora_Store_Orders::add_note( $id, sprintf( __( 'Refund of %1$s made in PayMongo (%2$s).', 'nexora-commerce-toolkit' ), Nexora_Store::money( $amount / 100 ), $r['id'] ) );
		}
		update_option( 'nexora_pm_refunds_' . $id, $known, false );
	}

	/* --------------------------------------------------------------- refunds */

	/**
	 * Refunds of an order.
	 *
	 * @param int $id Order.
	 * @return array[]
	 */
	public static function refunds( $id ) {
		return (array) get_option( 'nexora_pm_refunds_' . (int) $id, array() );
	}

	/**
	 * Refund through PayMongo.
	 *
	 * @param object $order  Order.
	 * @param int    $cents  Amount.
	 * @param string $reason duplicate|fraudulent|requested_by_customer|others.
	 * @param string $notes  Notes.
	 * @return array|WP_Error Refund.
	 */
	public static function refund( $order, $cents, $reason, $notes ) {
		$done = array_sum( wp_list_pluck( self::refunds( $order->id ), 'amount' ) );
		$left = self::cents( $order->total ) - $done;
		if ( 0 !== strpos( (string) $order->payment_ref, 'pay_' ) ) {
			return new WP_Error( 'nexora_paymongo', __( 'This order has no PayMongo payment to refund.', 'nexora-commerce-toolkit' ) );
		}
		if ( $cents < 100 || $cents > $left ) {
			/* translators: %s: amount left */
			return new WP_Error( 'nexora_paymongo', sprintf( __( 'Enter an amount between ₱1.00 and %s.', 'nexora-commerce-toolkit' ), Nexora_Store::money( $left / 100 ) ) );
		}
		$attrs = array(
			'amount'     => $cents,
			'payment_id' => $order->payment_ref,
			'reason'     => in_array( $reason, array( 'duplicate', 'fraudulent', 'requested_by_customer', 'others' ), true ) ? $reason : 'requested_by_customer',
			/* translators: %d: order number */
			'notes'      => mb_substr( trim( sprintf( __( 'Order #%d', 'nexora-commerce-toolkit' ), $order->id ) . ' ' . $notes ), 0, 255 ),
		);
		$refund = self::api( 'POST', '/refunds', $attrs );
		if ( is_wp_error( $refund ) && 404 === (int) ( $refund->get_error_data()['status'] ?? 0 ) ) {
			$refund = self::api( 'POST', '/v1/refunds', $attrs );
		}
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		$list   = self::refunds( $order->id );
		$list[] = array( 'id' => (string) $refund['id'], 'amount' => $cents, 'status' => isset( $refund['attributes']['status'] ) ? (string) $refund['attributes']['status'] : '', 'time' => time() );
		update_option( 'nexora_pm_refunds_' . $order->id, $list, false );
		return $refund;
	}

	/**
	 * Refund form submit.
	 */
	public static function handle_refund() {
		$id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		if ( ! current_user_can( Nexora_Store_Admin::CAP_STORE ) ) {
			wp_die( esc_html__( 'Only store managers can send refunds.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_paymongo_refund_' . $id );
		$order = Nexora_Store_Orders::get( $id );
		if ( $order ) {
			$amount = isset( $_POST['amount'] ) ? (float) str_replace( array( ',', '₱', ' ' ), '', sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : 0;
			$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
			$notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';
			$res    = self::refund( $order, self::cents( $amount ), $reason, $notes );
			if ( is_wp_error( $res ) ) {
				$msg = $res->get_error_message();
			} else {
				/* translators: 1: amount, 2: refund ID, 3: status */
				$msg = sprintf( __( 'Refund of %1$s sent to PayMongo (%2$s, %3$s).', 'nexora-commerce-toolkit' ), Nexora_Store::money( $amount ), $res['id'], isset( $res['attributes']['status'] ) ? $res['attributes']['status'] : '' );
				Nexora_Store_Orders::add_note( $id, $msg . ( '' !== $notes ? ' ' . $notes : '' ), wp_get_current_user()->display_name );
				$left = self::cents( $order->total ) - array_sum( wp_list_pluck( self::refunds( $id ), 'amount' ) );
				if ( $left <= 0 && ! empty( $_POST['mark'] ) && 'refunded' !== $order->status ) {
					Nexora_Store_Orders::set_status( $id, 'refunded', __( 'Refunded in full through PayMongo.', 'nexora-commerce-toolkit' ) );
				}
			}
			set_transient( 'nexora_store_msg_' . get_current_user_id(), $msg, 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=nexora-store-orders&order=' . $id ) );
		exit;
	}

	/**
	 * "Check payment now" submit.
	 */
	public static function handle_check() {
		$id = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		if ( ! current_user_can( Nexora_Store_Admin::CAP_ORDERS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_paymongo_check_' . $id );
		$order = Nexora_Store_Orders::get( $id );
		$state = $order ? self::sync( $order ) : 'none';
		$msgs  = array(
			'paid'   => __( 'PayMongo: paid. The order is marked paid.', 'nexora-commerce-toolkit' ),
			'unpaid' => __( 'PayMongo: not paid yet.', 'nexora-commerce-toolkit' ),
			'error'  => __( 'PayMongo could not be reached. Try again in a moment.', 'nexora-commerce-toolkit' ),
			'none'   => __( 'This order has no PayMongo checkout to check.', 'nexora-commerce-toolkit' ),
		);
		set_transient( 'nexora_store_msg_' . get_current_user_id(), $msgs[ $state ], 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=nexora-store-orders&order=' . $id ) );
		exit;
	}

	/**
	 * PayMongo card in the order screen.
	 *
	 * @param object $order Order.
	 */
	public static function order_card( $order ) {
		if ( self::ID !== $order->payment_method ) {
			return;
		}
		$post    = admin_url( 'admin-post.php' );
		$refunds = self::refunds( $order->id );
		$done    = array_sum( wp_list_pluck( $refunds, 'amount' ) );
		$left    = self::cents( $order->total ) - $done;
		$ref     = (string) $order->payment_ref;
		$mode    = self::settings()['mode'];
		$dash    = 0 === strpos( $ref, 'pay_' ) ? 'https://dashboard.paymongo.com/payments/' . rawurlencode( $ref ) : '';
		?>
		<section class="nx-store-admin-card">
			<h2><?php esc_html_e( 'PayMongo', 'nexora-commerce-toolkit' ); ?></h2>
			<p>
				<?php echo esc_html( $order->paid_at ? __( 'Paid', 'nexora-commerce-toolkit' ) : __( 'Waiting for payment', 'nexora-commerce-toolkit' ) ); ?>
				<?php if ( '' !== $ref ) : ?> · <code><?php echo esc_html( $ref ); ?></code><?php endif; ?>
				<?php if ( $dash ) : ?><br><a href="<?php echo esc_url( $dash ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in PayMongo', 'nexora-commerce-toolkit' ); ?></a><?php endif; ?>
			</p>
			<?php if ( ! $order->paid_at && current_user_can( Nexora_Store_Admin::CAP_ORDERS ) ) : ?>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<input type="hidden" name="action" value="nexora_paymongo_check"><input type="hidden" name="order" value="<?php echo (int) $order->id; ?>">
					<?php wp_nonce_field( 'nexora_paymongo_check_' . $order->id ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Check payment now', 'nexora-commerce-toolkit' ); ?></button>
				</form>
			<?php endif; ?>
			<?php foreach ( $refunds as $r ) : ?>
				<?php /* translators: 1: amount, 2: refund ID */ ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Refunded %1$s · %2$s', 'nexora-commerce-toolkit' ), Nexora_Store::money( $r['amount'] / 100 ), $r['id'] ) ); ?></p>
			<?php endforeach; ?>
			<?php if ( 0 === strpos( $ref, 'pay_' ) && $left >= 100 && current_user_can( Nexora_Store_Admin::CAP_STORE ) ) : ?>
				<details class="nx-box__edit">
					<summary><?php esc_html_e( 'Refund through PayMongo', 'nexora-commerce-toolkit' ); ?></summary>
					<form method="post" action="<?php echo esc_url( $post ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Send this refund to the customer now? This cannot be undone.', 'nexora-commerce-toolkit' ) ) ); ?>)">
						<input type="hidden" name="action" value="nexora_paymongo_refund"><input type="hidden" name="order" value="<?php echo (int) $order->id; ?>">
						<?php wp_nonce_field( 'nexora_paymongo_refund_' . $order->id ); ?>
						<p><label for="nx-pm-amount"><?php esc_html_e( 'Amount (₱)', 'nexora-commerce-toolkit' ); ?></label><input type="number" id="nx-pm-amount" name="amount" min="1" step="0.01" max="<?php echo esc_attr( number_format( $left / 100, 2, '.', '' ) ); ?>" value="<?php echo esc_attr( number_format( $left / 100, 2, '.', '' ) ); ?>"></p>
						<p><label for="nx-pm-reason"><?php esc_html_e( 'Reason', 'nexora-commerce-toolkit' ); ?></label>
							<select id="nx-pm-reason" name="reason">
								<option value="requested_by_customer"><?php esc_html_e( 'Requested by the customer', 'nexora-commerce-toolkit' ); ?></option>
								<option value="duplicate"><?php esc_html_e( 'Duplicate payment', 'nexora-commerce-toolkit' ); ?></option>
								<option value="fraudulent"><?php esc_html_e( 'Fraudulent', 'nexora-commerce-toolkit' ); ?></option>
								<option value="others"><?php esc_html_e( 'Other', 'nexora-commerce-toolkit' ); ?></option>
							</select></p>
						<p><label for="nx-pm-notes"><?php esc_html_e( 'Note (optional)', 'nexora-commerce-toolkit' ); ?></label><input type="text" id="nx-pm-notes" name="notes" maxlength="200"></p>
						<p><label><input type="checkbox" name="mark" value="1" checked> <?php esc_html_e( 'When fully refunded, mark the order Refunded (puts the stock back and emails the customer)', 'nexora-commerce-toolkit' ); ?></label></p>
						<button type="submit" class="button"><?php esc_html_e( 'Send refund', 'nexora-commerce-toolkit' ); ?></button>
					</form>
				</details>
				<?php if ( 'test' === $mode ) : ?><p class="description"><?php esc_html_e( 'Test mode: refunds are not real.', 'nexora-commerce-toolkit' ); ?></p><?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------- admin */

	/**
	 * Menu under Store.
	 */
	public static function menu() {
		add_submenu_page( 'nexora-store', __( 'PayMongo', 'nexora-commerce-toolkit' ), __( 'PayMongo', 'nexora-commerce-toolkit' ), Nexora_Store_Admin::CAP_STORE, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Create the webhook for the selected mode and save its secret.
	 */
	public static function handle_webhook_setup() {
		if ( ! current_user_can( Nexora_Store_Admin::CAP_STORE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_paymongo_webhook' );
		$s    = self::settings();
		$mode = $s['mode'];
		$url  = self::webhook_url();
		if ( 0 !== strpos( $url, 'https://' ) ) {
			$msg = __( 'PayMongo only sends webhooks to an https:// address on a public domain. Connect the webhook on your live site.', 'nexora-commerce-toolkit' );
		} else {
			$hook = self::api( 'POST', '/v1/webhooks', array( 'url' => $url, 'events' => self::EVENTS ) );
			if ( is_wp_error( $hook ) ) {
				$msg = $hook->get_error_message() . ' ' . __( 'If a webhook for this address already exists, open it in the PayMongo dashboard (Developers → Webhooks), copy its secret key and paste it below.', 'nexora-commerce-toolkit' );
			} elseif ( empty( $hook['attributes']['secret_key'] ) ) {
				$msg = __( 'The webhook was created, but PayMongo did not return its secret key. Copy it from the PayMongo dashboard (Developers → Webhooks) and paste it below.', 'nexora-commerce-toolkit' );
			} else {
				$raw                       = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
				$raw[ $mode . '_webhook' ] = (string) $hook['attributes']['secret_key'];
				$raw[ $mode . '_hook_id' ] = (string) $hook['id'];
				remove_all_filters( 'sanitize_option_' . self::OPTION );
				update_option( self::OPTION, $raw, false );
				/* translators: %s: test or live */
				$msg = sprintf( __( 'Webhook connected for %s mode. PayMongo will now confirm payments to your site.', 'nexora-commerce-toolkit' ), $mode );
			}
		}
		set_transient( 'nexora_paymongo_msg_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Secret field.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $hint  Placeholder.
	 */
	private static function secret_field( $key, $label, $hint ) {
		$constant = self::SECRETS[ $key ];
		$saved    = '' !== (string) self::settings()[ $key ];
		echo '<tr><th scope="row"><label for="nx-pm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( defined( $constant ) ) {
			/* translators: %s: constant */
			echo '<p><span class="dashicons dashicons-lock"></span> ' . esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'nexora-commerce-toolkit' ), $constant ) ) . '</p></td></tr>';
			return;
		}
		printf(
			'<input type="password" class="regular-text code" id="nx-pm-%1$s" name="%2$s[%1$s]" value="" autocomplete="new-password" placeholder="%3$s">',
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			esc_attr( $saved ? __( '•••••••• saved — leave blank to keep', 'nexora-commerce-toolkit' ) : $hint )
		);
		if ( $saved ) {
			printf( ' <label><input type="checkbox" name="%1$s[%2$s_clear]" value="1"> %3$s</label>', esc_attr( self::OPTION ), esc_attr( $key ), esc_html__( 'Remove', 'nexora-commerce-toolkit' ) );
		}
		/* translators: %s: constant */
		echo '<p class="description">' . wp_kses( sprintf( __( 'Safer: define %s in wp-config.php instead.', 'nexora-commerce-toolkit' ), '<code>' . $constant . '</code>' ), array( 'code' => array() ) ) . '</p></td></tr>';
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( Nexora_Store_Admin::CAP_STORE ) ) {
			return;
		}
		$s    = self::settings();
		$msg  = get_transient( 'nexora_paymongo_msg_' . get_current_user_id() );
		$hook = '' !== (string) $s[ $s['mode'] . '_webhook' ];
		$link = static function ( $url, $text ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
		};
		$kses = array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'strong' => array(), 'code' => array() );
		delete_transient( 'nexora_paymongo_msg_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PayMongo', 'nexora-commerce-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Take GCash, Maya, card, QR Ph and other online payments at checkout. Customers pay on PayMongo\'s secure page and come back to your shop; the order is marked paid automatically.', 'nexora-commerce-toolkit' ); ?></p>
			<?php
			settings_errors( self::OPTION );
			if ( $msg ) {
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
			if ( $s['enabled'] && 'live' === $s['mode'] && ! $hook ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Live mode is on but the webhook is not connected. Payments are still confirmed when customers come back from PayMongo, but connect the webhook so payments are never missed.', 'nexora-commerce-toolkit' ) . '</p></div>';
			}
			?>
			<details style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin:12px 0;max-width:900px"<?php echo '' === self::secret( 'test' ) && '' === self::secret( 'live' ) ? ' open' : ''; ?>>
				<summary style="cursor:pointer;font-weight:600;font-size:14px"><?php esc_html_e( 'How to connect: step by step', 'nexora-commerce-toolkit' ); ?></summary>
				<ol style="margin:12px 0 4px 20px">
					<?php /* translators: %s: link */ ?>
					<li style="margin-bottom:8px"><?php echo wp_kses( sprintf( __( '<strong>Sign up</strong> at %s and complete the business verification (needed before live payments are switched on).', 'nexora-commerce-toolkit' ), $link( 'https://dashboard.paymongo.com/signup', 'dashboard.paymongo.com' ) ), $kses ); ?></li>
					<?php /* translators: %s: link */ ?>
					<li style="margin-bottom:8px"><?php echo wp_kses( sprintf( __( 'Open %s and copy the <strong>test secret key</strong> (starts with <code>sk_test_</code>). Paste it below, choose <strong>Test</strong>, tick <strong>Enable</strong> and save.', 'nexora-commerce-toolkit' ), $link( 'https://dashboard.paymongo.com/developers', __( 'PayMongo dashboard → Developers → API keys', 'nexora-commerce-toolkit' ) ) ), $kses ); ?></li>
					<li style="margin-bottom:8px"><?php echo wp_kses( __( 'On your live site (https), click <strong>Connect webhook</strong> below. It creates the webhook in PayMongo and saves its secret, so payments are confirmed even if a customer closes the page after paying.', 'nexora-commerce-toolkit' ), $kses ); ?></li>
					<li style="margin-bottom:8px"><?php echo wp_kses( __( 'Place a test order and pay with a test GCash/Maya account or test card. The order should turn <strong>Processing</strong>. Try a refund from the order screen.', 'nexora-commerce-toolkit' ), $kses ); ?></li>
					<li style="margin-bottom:8px"><?php echo wp_kses( __( 'When PayMongo activates your account, paste the <strong>live secret key</strong> (<code>sk_live_</code>), switch to <strong>Live</strong>, save, and click <strong>Connect webhook</strong> again (live mode has its own webhook).', 'nexora-commerce-toolkit' ), $kses ); ?></li>
				</ol>
				<?php /* translators: %s: link */ ?>
				<p class="description" style="margin:0"><?php echo wp_kses( sprintf( __( 'Test cards and test e-wallet flows: %s.', 'nexora-commerce-toolkit' ), $link( 'https://docs.paymongo.com/', __( 'PayMongo docs', 'nexora-commerce-toolkit' ) ) ), $kses ); ?></p>
			</details>
			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_paymongo' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Enable', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Offer PayMongo at checkout', 'nexora-commerce-toolkit' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Mode', 'nexora-commerce-toolkit' ); ?></th><td>
						<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[mode]" value="test" <?php checked( $s['mode'], 'test' ); ?>> <?php esc_html_e( 'Test (no real money)', 'nexora-commerce-toolkit' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[mode]" value="live" <?php checked( $s['mode'], 'live' ); ?>> <?php esc_html_e( 'Live', 'nexora-commerce-toolkit' ); ?></label>
					</td></tr>
					<?php
					self::secret_field( 'test_secret', __( 'Test secret key', 'nexora-commerce-toolkit' ), 'sk_test_…' );
					self::secret_field( 'live_secret', __( 'Live secret key', 'nexora-commerce-toolkit' ), 'sk_live_…' );
					?>
					<tr><th scope="row"><label for="nx-pm-title"><?php esc_html_e( 'Name at checkout', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="text" class="regular-text" id="nx-pm-title" name="<?php echo esc_attr( self::OPTION ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php echo esc_attr__( 'GCash, Maya, card or QR Ph', 'nexora-commerce-toolkit' ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Payment methods', 'nexora-commerce-toolkit' ); ?></th><td>
						<fieldset>
							<?php foreach ( self::methods() as $key => $label ) : ?>
								<label style="display:inline-block;min-width:260px;margin:0 8px 6px 0"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[methods][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['methods'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Tick only the methods PayMongo has activated for your account (dashboard → Settings → Payment methods), or checkout will show an error.', 'nexora-commerce-toolkit' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="nx-pm-hold"><?php esc_html_e( 'Time to pay', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<input type="number" class="small-text" id="nx-pm-hold" name="<?php echo esc_attr( self::OPTION ); ?>[hold_minutes]" min="0" max="2880" value="<?php echo esc_attr( (string) $s['hold_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'nexora-commerce-toolkit' ); ?>
						<p class="description"><?php esc_html_e( 'Unpaid orders keep their items reserved this long, then are cancelled and the stock is released. 0 keeps them until you cancel them.', 'nexora-commerce-toolkit' ); ?></p>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Receipt', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[email_receipt]" value="1" <?php checked( $s['email_receipt'] ); ?>> <?php esc_html_e( 'PayMongo also emails the customer a payment receipt', 'nexora-commerce-toolkit' ); ?></label></td></tr>
				</table>
				<h2><?php esc_html_e( 'Webhook', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Address', 'nexora-commerce-toolkit' ); ?></th><td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::webhook_url() ); ?>" onclick="this.select()">
						<?php /* translators: %s: event names */ ?>
						<p class="description"><?php echo wp_kses( sprintf( __( 'Events: %s. Use the button below, or create it yourself in the PayMongo dashboard and paste its secret key here.', 'nexora-commerce-toolkit' ), '<code>' . implode( '</code>, <code>', self::EVENTS ) . '</code>' ), array( 'code' => array() ) ); ?></p></td></tr>
					<?php
					self::secret_field( 'test_webhook', __( 'Test webhook secret', 'nexora-commerce-toolkit' ), 'whsk_…' );
					self::secret_field( 'live_webhook', __( 'Live webhook secret', 'nexora-commerce-toolkit' ), 'whsk_…' );
					?>
				</table>
				<?php submit_button(); ?>
			</form>
			<p>
				<?php if ( '' !== self::secret() ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_paymongo_webhook' ), 'nexora_paymongo_webhook' ) ); ?>">
						<?php /* translators: %s: test or live */ echo esc_html( sprintf( __( 'Connect webhook (%s mode)', 'nexora-commerce-toolkit' ), $s['mode'] ) ); ?>
					</a>
				<?php endif; ?>
				<?php /* translators: %s: test or live */ ?>
				<span style="margin-left:8px"><?php echo esc_html( $hook ? sprintf( __( 'Webhook connected for %s mode.', 'nexora-commerce-toolkit' ), $s['mode'] ) : sprintf( __( 'No webhook secret for %s mode yet.', 'nexora-commerce-toolkit' ), $s['mode'] ) ); ?></span>
			</p>
		</div>
		<?php
	}
}
