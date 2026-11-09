<?php
/**
 * Email sender, design and notification text.
 *
 * Settings → Freshtastic Emails:
 *  - Sender: the From name (replaces "WordPress"), From address and Reply-To for every email the site sends.
 *  - Design: logo, colours and footer shared by every email. WordPress's own plain-text emails (password
 *    reset, new user, password changed...) are wrapped in the same design.
 *  - Notifications: subject, heading and message of each email from the toolkit, the built-in store and
 *    WordPress, with placeholders, on/off where turning it off is safe, preview and test send.
 *  - WooCommerce keeps its own editor (WooCommerce → Settings → Emails); its sender, logo, colours and
 *    footer can follow these settings.
 *
 * Generated parts of each email (order details, codes, links and buttons) are always added after the
 * editable message, so editing the text cannot break them.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emails.
 */
class Nexora_Toolkit_Emails {

	const OPTION = 'nexora_emails';
	const PAGE   = 'nexora-emails';
	const MARK   = '<!-- nexora-email -->';

	/**
	 * Notification being built by a WordPress core filter, for the heading of its wrapped email.
	 *
	 * @var string
	 */
	private static $core = '';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 20 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ), 20 );
		add_filter( 'wp_mail', array( __CLASS__, 'filter_mail' ), 1000 );

		// WordPress core emails.
		add_filter( 'retrieve_password_title', array( __CLASS__, 'core_reset_title' ), 20, 2 );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'core_reset_message' ), 20, 4 );
		add_filter( 'wp_new_user_notification_email', array( __CLASS__, 'core_new_user' ), 20, 3 );
		add_filter( 'wp_new_user_notification_email_admin', array( __CLASS__, 'core_new_user_admin' ), 20, 3 );
		add_filter( 'password_change_email', array( __CLASS__, 'core_password_changed' ), 20, 3 );
		add_filter( 'email_change_email', array( __CLASS__, 'core_email_changed' ), 20, 3 );
		add_filter( 'wp_password_change_notification_email', array( __CLASS__, 'core_password_change_admin' ), 20, 3 );
		add_filter( 'wp_send_new_user_notification_to_user', array( __CLASS__, 'core_send_new_user' ) );
		add_filter( 'wp_send_new_user_notification_to_admin', array( __CLASS__, 'core_send_new_user_admin' ) );
		add_filter( 'send_password_change_email', array( __CLASS__, 'core_send_password_changed' ) );
		add_filter( 'send_email_change_email', array( __CLASS__, 'core_send_email_changed' ) );

		// WooCommerce follows the sender and design when asked.
		foreach ( array( 'from_name', 'from_address', 'header_image', 'footer_text', 'base_color', 'background_color', 'text_color' ) as $key ) {
			add_filter( 'option_woocommerce_email_' . $key, array( __CLASS__, 'wc_option' ), 20, 2 );
		}

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
			add_action( 'admin_post_nexora_email_preview', array( __CLASS__, 'preview' ) );
			add_action( 'admin_post_nexora_email_test', array( __CLASS__, 'test' ) );
			add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );
		}
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'from_name'    => '',
			'from_email'   => '',
			'reply_to'     => '',
			'logo'         => '',
			'color_header' => '',
			'color_button' => '',
			'color_bg'     => '#f5f7fb',
			'color_text'   => '#0f1b3d',
			'footer'       => '',
			'wrap_core'    => true,
			'wc_sync'      => true,
			'n'            => array(),
		);
	}

	/**
	 * Saved settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * One setting, with the theme's brand as the fallback.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$s = self::settings();
		$v = isset( $s[ $key ] ) ? $s[ $key ] : '';
		switch ( $key ) {
			case 'from_name':
				return '' !== $v ? $v : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			case 'logo':
				if ( '' !== $v ) {
					return $v;
				}
				$custom = (int) get_theme_mod( 'custom_logo' );
				$url    = $custom ? wp_get_attachment_image_url( $custom, 'medium' ) : '';
				return $url ? $url : (string) apply_filters( 'nexora_store_email_logo', '' );
			case 'color_header':
				return '' !== $v ? $v : self::theme_color( 'nexora_color_primary', '#12306E' );
			case 'color_button':
				return '' !== $v ? $v : self::theme_color( 'nexora_color_secondary', '#F0762B' );
			case 'footer':
				return '' !== $v ? $v : '{site_name} · {site_url}';
		}
		return $v;
	}

	/**
	 * Colour from the theme's Customizer.
	 *
	 * @param string $mod      Theme mod.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function theme_color( $mod, $fallback ) {
		$c = sanitize_hex_color( (string) get_theme_mod( $mod, $fallback ) );
		return $c ? $c : $fallback;
	}

	/* ------------------------------------------------------- notifications */

	/**
	 * Every notification that can be edited.
	 *
	 * Keys: group (customer|staff|wordpress), title, desc, subject, heading, message (null = WordPress's
	 * own text is kept), vars (placeholder => description), off (can be turned off), sample (preview values).
	 *
	 * @return array<string,array>
	 */
	public static function notifications() {
		$order_vars = array(
			'{first_name}'   => __( 'Customer first name', 'nexora-commerce-toolkit' ),
			'{order_number}' => __( 'Order number', 'nexora-commerce-toolkit' ),
		);
		$status     = static function ( $title, $heading, $message ) use ( $order_vars ) {
			return array(
				'group'   => 'customer',
				'title'   => $title,
				'desc'    => __( 'Built-in store. Followed by the order details and a link to the order.', 'nexora-commerce-toolkit' ),
				'subject' => '{site_name} order #{order_number}: {heading}',
				'heading' => $heading,
				'message' => $message,
				'vars'    => $order_vars + array( '{heading}' => __( 'This email\'s heading', 'nexora-commerce-toolkit' ) ),
				'off'     => true,
			);
		};
		$list = array(
			// Customers.
			'order_received'      => array(
				'group'   => 'customer',
				'title'   => __( 'Order received', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Built-in store, when an order is placed. Followed by bank details (bank transfer), the order details and a link to the order.', 'nexora-commerce-toolkit' ),
				'subject' => __( 'Your {site_name} order #{order_number}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Order received', 'nexora-commerce-toolkit' ),
				'message' => __( 'Hi {first_name}, we received your order. Thank you!', 'nexora-commerce-toolkit' ),
				'vars'    => $order_vars,
				'off'     => true,
			),
			'order_processing'    => $status( __( 'Order being prepared', 'nexora-commerce-toolkit' ), __( 'We are preparing your order', 'nexora-commerce-toolkit' ), __( 'Your order is confirmed and we are preparing it now.', 'nexora-commerce-toolkit' ) ),
			'order_ready_pickup'  => $status( __( 'Order ready: store pickup', 'nexora-commerce-toolkit' ), __( 'Your order is packed and ready', 'nexora-commerce-toolkit' ), __( 'Your order is packed and ready to collect. Please bring your order number; someone else can collect it for you.', 'nexora-commerce-toolkit' ) ),
			'order_ready_courier' => $status( __( 'Order ready: customer books a courier', 'nexora-commerce-toolkit' ), __( 'Your order is packed and ready', 'nexora-commerce-toolkit' ), __( 'Your order is packed and waiting for your rider. You can book your courier now.', 'nexora-commerce-toolkit' ) ),
			'order_ready_other'   => $status( __( 'Order ready: delivery', 'nexora-commerce-toolkit' ), __( 'Your order is packed and ready', 'nexora-commerce-toolkit' ), __( 'Your order is packed and will be delivered soon.', 'nexora-commerce-toolkit' ) ),
			'order_completed'     => $status( __( 'Order complete', 'nexora-commerce-toolkit' ), __( 'Your order is complete', 'nexora-commerce-toolkit' ), __( 'Your order is complete. Thank you for buying from our market!', 'nexora-commerce-toolkit' ) ),
			'order_cancelled'     => $status( __( 'Order cancelled', 'nexora-commerce-toolkit' ), __( 'Your order was cancelled', 'nexora-commerce-toolkit' ), __( 'Your order was cancelled. Please contact us if this is unexpected.', 'nexora-commerce-toolkit' ) ),
			'order_refunded'      => $status( __( 'Order refunded', 'nexora-commerce-toolkit' ), __( 'Your order was refunded', 'nexora-commerce-toolkit' ), __( 'Your order was marked as refunded.', 'nexora-commerce-toolkit' ) ),
			'account_activation'  => array(
				'group'   => 'customer',
				'title'   => __( 'Activate your account', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'New account, when email activation is on. Followed by the Activate button, how long the link lasts and the link written out. Always sent.', 'nexora-commerce-toolkit' ),
				'subject' => __( 'Activate your {site_name} account', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Activate your account', 'nexora-commerce-toolkit' ),
				'message' => __( "Hi {first_name},\n\nThanks for creating an account with {site_name}. Please confirm your email address to activate it.", 'nexora-commerce-toolkit' ),
				'vars'    => array( '{first_name}' => __( 'Customer first name', 'nexora-commerce-toolkit' ) ),
				'off'     => false,
			),
			'account_welcome'     => array(
				'group'   => 'customer',
				'title'   => __( 'Welcome', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Built-in store, new account when email activation is off. Followed by a link to the account.', 'nexora-commerce-toolkit' ),
				'subject' => __( 'Welcome to {site_name}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Your account is ready', 'nexora-commerce-toolkit' ),
				'message' => __( 'You can now track orders, save your address and check out faster.', 'nexora-commerce-toolkit' ),
				'vars'    => array( '{first_name}' => __( 'Customer first name', 'nexora-commerce-toolkit' ) ),
				'off'     => true,
			),
			'login_code'          => array(
				'group'   => 'customer',
				'title'   => __( 'Sign-in code', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Customers and staff, when login codes are on. Followed by the code and how long it lasts. Always sent.', 'nexora-commerce-toolkit' ),
				'subject' => __( 'Your {site_name} sign-in code', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Your sign-in code', 'nexora-commerce-toolkit' ),
				'message' => __( 'Enter this code to finish signing in. Never share it; we will not ask you for it. If you did not try to sign in, someone may know your password, so please change it.', 'nexora-commerce-toolkit' ),
				'vars'    => array( '{first_name}' => __( 'First name', 'nexora-commerce-toolkit' ) ),
				'off'     => false,
			),
			'reward'              => array(
				'group'   => 'customer',
				'title'   => __( 'First customers reward', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'When a new customer gets a first customers reward. Followed by the reward, the code, its terms and a link to the account.', 'nexora-commerce-toolkit' ),
				'subject' => '{reward_title}',
				'heading' => '{reward_title}',
				'message' => __( 'Thank you for joining early! You are customer #{spot}, so this reward is yours:', 'nexora-commerce-toolkit' ),
				'vars'    => array(
					'{first_name}'   => __( 'Customer first name', 'nexora-commerce-toolkit' ),
					'{reward_title}' => __( 'Promotion title from the First customers screen', 'nexora-commerce-toolkit' ),
					'{spot}'         => __( 'Customer number', 'nexora-commerce-toolkit' ),
				),
				'off'     => true,
			),
			'rfq_received'        => array(
				'group'   => 'customer',
				'title'   => __( 'Quotation request received', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'After a customer sends a Request a Quote form. Followed by their request.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] We received your quotation request {reference}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Quotation request received', 'nexora-commerce-toolkit' ),
				'message' => __( 'Hi {name}, thank you! Our team will check supply and pricing and get back to you with a quotation, usually within one business day.', 'nexora-commerce-toolkit' ),
				'vars'    => array(
					'{name}'      => __( 'Customer name', 'nexora-commerce-toolkit' ),
					'{reference}' => __( 'Request reference', 'nexora-commerce-toolkit' ),
				),
				'off'     => true,
			),
			'rfq_quote'           => array(
				'group'   => 'customer',
				'title'   => __( 'Your quotation', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'When staff send a quotation. A message typed on the quotation itself is used instead of this one. Followed by the prices.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] Your quotation {reference}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Your quotation', 'nexora-commerce-toolkit' ),
				'message' => __( 'Here is our quotation for your request. Reply to this email or call us to confirm.', 'nexora-commerce-toolkit' ),
				'vars'    => array( '{reference}' => __( 'Request reference', 'nexora-commerce-toolkit' ) ),
				'off'     => false,
			),

			// Store staff.
			'admin_new_order'     => array(
				'group'   => 'staff',
				'title'   => __( 'New order (to the store)', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Built-in store. Sent to the order email in Store → Settings. Followed by the order details and a link to open it.', 'nexora-commerce-toolkit' ),
				'subject' => __( 'New order #{order_number} from {customer_name}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'New order', 'nexora-commerce-toolkit' ),
				'message' => '{customer_email} · {customer_phone}',
				'vars'    => array(
					'{order_number}'   => __( 'Order number', 'nexora-commerce-toolkit' ),
					'{customer_name}'  => __( 'Customer name', 'nexora-commerce-toolkit' ),
					'{customer_email}' => __( 'Customer email', 'nexora-commerce-toolkit' ),
					'{customer_phone}' => __( 'Customer mobile', 'nexora-commerce-toolkit' ),
				),
				'off'     => true,
			),
			'rfq_admin'           => array(
				'group'   => 'staff',
				'title'   => __( 'New quotation request (to the store)', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Sent to the quotation email in Settings → Freshtastic Toolkit. Followed by the request. Replying goes to the customer.', 'nexora-commerce-toolkit' ),
				'subject' => __( 'New quotation request {reference}: {item}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'New quotation request', 'nexora-commerce-toolkit' ),
				'message' => __( 'Reply from Quotations in the admin, or reply to this email to contact the customer.', 'nexora-commerce-toolkit' ),
				'vars'    => array(
					'{reference}' => __( 'Request reference', 'nexora-commerce-toolkit' ),
					'{item}'      => __( 'Requested item', 'nexora-commerce-toolkit' ),
					'{name}'      => __( 'Customer name', 'nexora-commerce-toolkit' ),
				),
				'off'     => true,
			),
			'daily_report'        => array(
				'group'   => 'staff',
				'title'   => __( 'Daily report', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Analytics daily report. Turn it on or off, and choose recipients, under Analytics. Followed by the figures.', 'nexora-commerce-toolkit' ),
				'subject' => __( '{site_name} daily report for {date}: {sales} from {orders}', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Daily report', 'nexora-commerce-toolkit' ),
				'message' => '',
				'vars'    => array(
					'{date}'   => __( 'Report day', 'nexora-commerce-toolkit' ),
					'{sales}'  => __( 'Sales', 'nexora-commerce-toolkit' ),
					'{orders}' => __( 'Number of orders, e.g. "3 orders"', 'nexora-commerce-toolkit' ),
				),
				'off'     => false,
			),

			// WordPress.
			'wp_password_reset'   => array(
				'group'   => 'wordpress',
				'title'   => __( 'Password reset', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'When someone asks to reset their password. Leave the message empty to keep WordPress\'s text. If your message has no {link}, the reset link is added at the end. Always sent.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] Password Reset', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Reset your password', 'nexora-commerce-toolkit' ),
				'message' => null,
				'vars'    => array(
					'{username}' => __( 'Username', 'nexora-commerce-toolkit' ),
					'{link}'     => __( 'Reset link', 'nexora-commerce-toolkit' ),
				),
				'off'     => false,
			),
			'wp_new_user'         => array(
				'group'   => 'wordpress',
				'title'   => __( 'New account: set your password', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Accounts made on wp-login.php or by an administrator. Leave the message empty to keep WordPress\'s text. If your message has no {link}, the set-password link is added at the end.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] Login Details', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Welcome to {site_name}', 'nexora-commerce-toolkit' ),
				'message' => null,
				'vars'    => array(
					'{username}' => __( 'Username', 'nexora-commerce-toolkit' ),
					'{link}'     => __( 'Set-password link', 'nexora-commerce-toolkit' ),
				),
				'off'     => true,
			),
			'wp_password_changed' => array(
				'group'   => 'wordpress',
				'title'   => __( 'Your password was changed', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'To the account holder after their password changes. Leave the message empty to keep WordPress\'s text.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] Password Changed', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Your password was changed', 'nexora-commerce-toolkit' ),
				'message' => null,
				'vars'    => array( '{username}' => __( 'Username', 'nexora-commerce-toolkit' ) ),
				'off'     => true,
			),
			'wp_email_changed'    => array(
				'group'   => 'wordpress',
				'title'   => __( 'Your email address was changed', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'To the old address after an account\'s email changes. Leave the message empty to keep WordPress\'s text.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] Email Changed', 'nexora-commerce-toolkit' ),
				'heading' => __( 'Your email address was changed', 'nexora-commerce-toolkit' ),
				'message' => null,
				'vars'    => array( '{username}' => __( 'Username', 'nexora-commerce-toolkit' ) ),
				'off'     => true,
			),
			'wp_new_user_admin'   => array(
				'group'   => 'wordpress',
				'title'   => __( 'New user registered (to the site admin)', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'Accounts made on wp-login.php or by an administrator. Leave the message empty to keep WordPress\'s text.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] New User Registration', 'nexora-commerce-toolkit' ),
				'heading' => __( 'New user registered', 'nexora-commerce-toolkit' ),
				'message' => null,
				'vars'    => array( '{username}' => __( 'Username', 'nexora-commerce-toolkit' ) ),
				'off'     => true,
			),
			'wp_password_admin'   => array(
				'group'   => 'wordpress',
				'title'   => __( 'Password reset by a user (to the site admin)', 'nexora-commerce-toolkit' ),
				'desc'    => __( 'When someone resets a forgotten password. Leave the message empty to keep WordPress\'s text.', 'nexora-commerce-toolkit' ),
				'subject' => __( '[{site_name}] Password Changed', 'nexora-commerce-toolkit' ),
				'heading' => __( 'A password was reset', 'nexora-commerce-toolkit' ),
				'message' => null,
				'vars'    => array( '{username}' => __( 'Username', 'nexora-commerce-toolkit' ) ),
				'off'     => false,
			),
		);
		/**
		 * Filter the editable notifications.
		 *
		 * @param array $list Notifications.
		 */
		return (array) apply_filters( 'nexora_email_notifications', $list );
	}

	/**
	 * Saved text for one notification and field, or ''.
	 *
	 * @param string $id    Notification.
	 * @param string $field subject|heading|message.
	 * @return string
	 */
	private static function saved( $id, $field ) {
		$n = self::settings()['n'];
		return isset( $n[ $id ][ $field ] ) ? (string) $n[ $id ][ $field ] : '';
	}

	/**
	 * Whether a notification is on.
	 *
	 * @param string $id Notification.
	 * @return bool
	 */
	public static function enabled( $id ) {
		$all = self::notifications();
		if ( empty( $all[ $id ]['off'] ) ) {
			return true; // Cannot be turned off.
		}
		$n = self::settings()['n'];
		return ! isset( $n[ $id ]['on'] ) || ! empty( $n[ $id ]['on'] );
	}

	/**
	 * Fill placeholders.
	 *
	 * @param string $text   Text.
	 * @param array  $vars   Placeholder => value (without braces or with).
	 * @param bool   $escape Escape values for HTML.
	 * @return string
	 */
	public static function fill( $text, $vars, $escape = false ) {
		$map = array(
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
			'{year}'      => wp_date( 'Y' ),
		);
		foreach ( (array) $vars as $k => $v ) {
			$map[ '{' . trim( (string) $k, '{}' ) . '}' ] = (string) $v;
		}
		if ( $escape ) {
			$map = array_map( 'esc_html', $map );
		}
		return strtr( (string) $text, $map );
	}

	/**
	 * Subject, heading and message HTML for a notification, or null when it is turned off.
	 *
	 * @param string $id   Notification.
	 * @param array  $vars Placeholders.
	 * @return array{subject:string,heading:string,message:string}|null
	 */
	public static function compose( $id, $vars = array() ) {
		$all = self::notifications();
		if ( ! isset( $all[ $id ] ) || ! self::enabled( $id ) ) {
			return null;
		}
		$def     = $all[ $id ];
		$heading = self::fill( '' !== self::saved( $id, 'heading' ) ? self::saved( $id, 'heading' ) : $def['heading'], $vars );
		$vars    = array_merge( array( 'heading' => $heading ), (array) $vars );
		$subject = self::fill( '' !== self::saved( $id, 'subject' ) ? self::saved( $id, 'subject' ) : $def['subject'], $vars );
		$message = '' !== self::saved( $id, 'message' ) ? self::saved( $id, 'message' ) : (string) $def['message'];
		return array(
			'subject' => wp_strip_all_tags( $subject ),
			'heading' => wp_strip_all_tags( $heading ),
			'message' => '' !== trim( $message ) ? wpautop( wp_kses_post( self::fill( $message, $vars, true ) ) ) : '',
			'custom'  => '' !== self::saved( $id, 'message' ),
		);
	}

	/* --------------------------------------------------------------- layout */

	/**
	 * Wrap a message in the email design.
	 *
	 * @param string $heading Heading.
	 * @param string $body    Body HTML.
	 * @return string
	 */
	public static function wrap( $heading, $body ) {
		$header = (string) self::get( 'color_header' );
		$bg     = (string) self::get( 'color_bg' );
		$text   = (string) self::get( 'color_text' );
		$button = (string) self::get( 'color_button' );
		$logo   = (string) self::get( 'logo' );
		$name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$footer = wpautop( wp_kses_post( self::fill( (string) self::get( 'footer' ), array(), true ) ) );
		// Links in the body take the button colour.
		$body = preg_replace( '/<a (?![^>]*style=)/i', '<a style="color:' . esc_attr( $button ) . ';" ', $body );
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. self::MARK
			. '<body style="margin:0;background:' . esc_attr( $bg ) . ';padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:' . esc_attr( $text ) . ';">'
			. '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e6e8f0;">'
			. ( $logo ? '<div style="padding:18px;text-align:center;background:#ffffff;"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" width="110" style="height:auto;max-width:110px;"></div>' : '' )
			. '<div style="background:' . esc_attr( $header ) . ';color:#ffffff;padding:20px 24px;"><h1 style="margin:0;font-size:20px;line-height:1.3;color:#ffffff;">' . esc_html( $heading ) . '</h1></div>'
			. '<div style="padding:24px;font-size:14px;line-height:1.6;color:' . esc_attr( $text ) . ';">' . $body . '</div>'
			. '<div style="padding:14px 24px;background:' . esc_attr( $bg ) . ';font-size:12px;line-height:1.5;color:#5f6676;">' . $footer . '</div>'
			. '</div></body></html>';
	}

	/**
	 * A button in the email design.
	 *
	 * @param string $url   URL.
	 * @param string $label Label.
	 * @return string
	 */
	public static function button( $url, $label ) {
		return '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:' . esc_attr( (string) self::get( 'color_button' ) ) . ';color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 26px;border-radius:999px;">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Send a designed HTML email.
	 *
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $heading Heading.
	 * @param string $body    Body HTML.
	 * @param array  $headers Extra headers.
	 * @return bool
	 */
	public static function send( $to, $subject, $heading, $body, $headers = array() ) {
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		return wp_mail( $to, $subject, self::wrap( $heading, $body ), $headers );
	}

	/* ------------------------------------------------------- every email */

	/**
	 * From name: replaces WordPress's default "WordPress" only, so an SMTP plugin's or WooCommerce's
	 * own setting still wins.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function from_name( $name ) {
		return 'WordPress' === $name ? (string) self::get( 'from_name' ) : $name;
	}

	/**
	 * From address: replaces WordPress's default wordpress@yourdomain only.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function from_email( $email ) {
		$mine = (string) self::get( 'from_email' );
		return $mine && is_email( $mine ) && 0 === strpos( strtolower( (string) $email ), 'wordpress@' ) ? $mine : $email;
	}

	/**
	 * Reply-To on every email, and WordPress's plain-text emails in the design.
	 *
	 * @param array $args wp_mail arguments.
	 * @return array
	 */
	public static function filter_mail( $args ) {
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		$list    = is_array( $headers ) ? $headers : preg_split( "/\r\n|\n/", (string) $headers );
		$list    = array_values( array_filter( array_map( 'trim', (array) $list ) ) );
		$joined  = strtolower( implode( "\n", $list ) );
		$message = isset( $args['message'] ) ? (string) $args['message'] : '';

		$reply = (string) self::get( 'reply_to' );
		if ( $reply && is_email( $reply ) && false === strpos( $joined, 'reply-to:' ) ) {
			$list[] = 'Reply-To: ' . $reply;
		}

		$is_html = false !== strpos( $joined, 'text/html' ) || false !== stripos( $message, '<html' ) || false !== stripos( $message, '<body' );
		if ( false !== strpos( $message, self::MARK ) ) {
			if ( false === strpos( $joined, 'text/html' ) ) {
				$list[] = 'Content-Type: text/html; charset=UTF-8';
			}
		} elseif ( ! $is_html && self::get( 'wrap_core' ) && '' !== trim( $message ) ) {
			$heading = '';
			if ( self::$core ) {
				$all     = self::notifications();
				$saved   = self::saved( self::$core, 'heading' );
				$heading = self::fill( '' !== $saved ? $saved : $all[ self::$core ]['heading'], array() );
			}
			if ( '' === $heading ) {
				$heading = trim( preg_replace( '/^\[[^\]]*\]\s*/', '', (string) $args['subject'] ) );
			}
			$args['message'] = self::wrap( $heading, self::text_to_html( $message ) );
			$list            = array_values( array_filter( $list, static function ( $h ) {
				return 0 !== stripos( $h, 'content-type:' );
			} ) );
			$list[]          = 'Content-Type: text/html; charset=UTF-8';
		}
		self::$core      = '';
		$args['headers'] = $list;
		return $args;
	}

	/**
	 * Plain text to safe HTML with clickable links.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function text_to_html( $text ) {
		$text = preg_replace( '/<(https?:\/\/[^>\s]+)>/i', '$1', (string) $text ); // Older WordPress puts links in <>.
		return wpautop( make_clickable( esc_html( $text ) ) );
	}

	/* ------------------------------------------------------ WordPress core */

	/**
	 * Custom message for a core email, with {link} added when missing.
	 *
	 * @param string $id   Notification.
	 * @param array  $vars Placeholders.
	 * @return string Message HTML in the design, or '' to keep WordPress's text.
	 */
	private static function core_custom( $id, $vars ) {
		$c = self::compose( $id, $vars );
		if ( ! $c || empty( $c['custom'] ) ) {
			return '';
		}
		$body = $c['message'];
		if ( isset( $vars['link'] ) && false === strpos( self::saved( $id, 'message' ), '{link}' ) ) {
			$body .= '<p><a href="' . esc_url( $vars['link'] ) . '">' . esc_html( $vars['link'] ) . '</a></p>';
		}
		return self::wrap( $c['heading'], $body );
	}

	/**
	 * Saved subject for a core email, or WordPress's.
	 *
	 * @param string $id      Notification.
	 * @param string $subject WordPress's subject.
	 * @param array  $vars    Placeholders.
	 * @return string
	 */
	private static function core_subject( $id, $subject, $vars = array() ) {
		$saved = self::saved( $id, 'subject' );
		return '' !== $saved ? wp_strip_all_tags( self::fill( $saved, $vars ) ) : $subject;
	}

	/**
	 * Apply subject and message to a core email array.
	 *
	 * @param string $id    Notification.
	 * @param array  $email Email: to, subject, message, headers.
	 * @param array  $vars  Placeholders.
	 * @return array
	 */
	private static function core_email( $id, $email, $vars ) {
		self::$core       = $id;
		$email['subject'] = self::core_subject( $id, $email['subject'], $vars );
		$custom           = self::core_custom( $id, $vars );
		if ( '' !== $custom ) {
			$email['message'] = $custom;
		}
		return $email;
	}

	/**
	 * Password reset subject.
	 *
	 * @param string $title Title.
	 * @param string $login Username.
	 * @return string
	 */
	public static function core_reset_title( $title, $login = '' ) {
		self::$core = 'wp_password_reset';
		return self::core_subject( 'wp_password_reset', $title, array( 'username' => $login ) );
	}

	/**
	 * Password reset message.
	 *
	 * @param string  $message Message.
	 * @param string  $key     Key.
	 * @param string  $login   Login.
	 * @param WP_User $user    User.
	 * @return string
	 */
	public static function core_reset_message( $message, $key, $login, $user ) {
		self::$core = 'wp_password_reset';
		$link       = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $login ), 'login' );
		$custom     = self::core_custom( 'wp_password_reset', array( 'username' => $login, 'link' => $link, 'first_name' => $user instanceof WP_User ? $user->first_name : '' ) );
		return '' !== $custom ? $custom : $message;
	}

	/**
	 * New user, to the user.
	 *
	 * @param array   $email Email.
	 * @param WP_User $user  User.
	 * @return array
	 */
	public static function core_new_user( $email, $user ) {
		$link = '';
		if ( preg_match( '#https?://\S+action=rp\S*#', (string) $email['message'], $m ) ) {
			$link = rtrim( $m[0], '>' );
		}
		return self::core_email( 'wp_new_user', $email, array( 'username' => $user->user_login, 'link' => $link, 'first_name' => $user->first_name ) );
	}

	/**
	 * New user, to the admin.
	 *
	 * @param array   $email Email.
	 * @param WP_User $user  User.
	 * @return array
	 */
	public static function core_new_user_admin( $email, $user ) {
		return self::core_email( 'wp_new_user_admin', $email, array( 'username' => $user->user_login ) );
	}

	/**
	 * Password changed, to the user.
	 *
	 * @param array $email Email.
	 * @param array $user  Old user data.
	 * @return array
	 */
	public static function core_password_changed( $email, $user ) {
		return self::core_email( 'wp_password_changed', $email, array( 'username' => isset( $user['user_login'] ) ? $user['user_login'] : '' ) );
	}

	/**
	 * Email changed, to the old address.
	 *
	 * @param array $email Email.
	 * @param array $user  Old user data.
	 * @return array
	 */
	public static function core_email_changed( $email, $user ) {
		return self::core_email( 'wp_email_changed', $email, array( 'username' => isset( $user['user_login'] ) ? $user['user_login'] : '' ) );
	}

	/**
	 * Password reset by a user, to the admin.
	 *
	 * @param array   $email Email.
	 * @param WP_User $user  User.
	 * @return array
	 */
	public static function core_password_change_admin( $email, $user ) {
		return self::core_email( 'wp_password_admin', $email, array( 'username' => $user instanceof WP_User ? $user->user_login : '' ) );
	}

	/**
	 * On/off filters for core emails.
	 *
	 * @param bool $send Send.
	 * @return bool
	 */
	public static function core_send_new_user( $send ) {
		return $send && self::enabled( 'wp_new_user' );
	}

	/**
	 * On/off.
	 *
	 * @param bool $send Send.
	 * @return bool
	 */
	public static function core_send_new_user_admin( $send ) {
		return $send && self::enabled( 'wp_new_user_admin' );
	}

	/**
	 * On/off.
	 *
	 * @param bool $send Send.
	 * @return bool
	 */
	public static function core_send_password_changed( $send ) {
		return $send && self::enabled( 'wp_password_changed' );
	}

	/**
	 * On/off.
	 *
	 * @param bool $send Send.
	 * @return bool
	 */
	public static function core_send_email_changed( $send ) {
		return $send && self::enabled( 'wp_email_changed' );
	}

	/* ----------------------------------------------------------- WooCommerce */

	/**
	 * WooCommerce email options follow these settings when "Use for WooCommerce" is on.
	 *
	 * @param mixed  $value  Saved value.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public static function wc_option( $value, $option ) {
		if ( ! self::get( 'wc_sync' ) ) {
			return $value;
		}
		switch ( substr( $option, strlen( 'woocommerce_email_' ) ) ) {
			case 'from_name':
				return self::get( 'from_name' );
			case 'from_address':
				$mine = (string) self::get( 'from_email' );
				return $mine && is_email( $mine ) ? $mine : $value;
			case 'header_image':
				return self::get( 'logo' );
			case 'footer_text':
				return self::fill( wp_strip_all_tags( (string) self::get( 'footer' ) ), array() );
			case 'base_color':
				return self::get( 'color_header' );
			case 'background_color':
				return self::get( 'color_bg' );
			case 'text_color':
				return self::get( 'color_text' );
		}
		return $value;
	}

	/**
	 * Explain on WooCommerce's email screen where the sender and design come from.
	 */
	public static function wc_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::get( 'wc_sync' ) || ! isset( $_GET['page'], $_GET['tab'] ) || 'wc-settings' !== $_GET['page'] || 'email' !== $_GET['tab'] ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'The sender name and address, header image, footer text and colours below follow Settings → Freshtastic Emails. Subjects and text of each WooCommerce email are still edited here.', 'nexora-commerce-toolkit' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Open Freshtastic Emails', 'nexora-commerce-toolkit' )
		);
	}

	/* ---------------------------------------------------------------- admin */

	/**
	 * Menu.
	 */
	public static function menu() {
		add_options_page( __( 'Freshtastic Emails', 'nexora-commerce-toolkit' ), __( 'Freshtastic Emails', 'nexora-commerce-toolkit' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'nexora_emails',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$in    = is_array( $in ) ? wp_unslash( $in ) : array();
		$html  = static function ( $v ) {
			// Drop script and style blocks with their contents, then keep post-safe HTML.
			return trim( wp_kses_post( preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', (string) $v ) ) );
		};
		$text  = static function ( $k ) use ( $in ) {
			return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? trim( sanitize_text_field( (string) $in[ $k ] ) ) : '';
		};
		$color = static function ( $k, $fallback ) use ( $in ) {
			$c = isset( $in[ $k ] ) ? sanitize_hex_color( (string) $in[ $k ] ) : '';
			return $c ? $c : $fallback;
		};
		$out = array(
			'from_name'    => $text( 'from_name' ),
			'from_email'   => sanitize_email( $text( 'from_email' ) ),
			'reply_to'     => sanitize_email( $text( 'reply_to' ) ),
			'logo'         => isset( $in['logo'] ) ? esc_url_raw( trim( (string) $in['logo'] ) ) : '',
			'color_header' => $color( 'color_header', '' ),
			'color_button' => $color( 'color_button', '' ),
			'color_bg'     => $color( 'color_bg', '#f5f7fb' ),
			'color_text'   => $color( 'color_text', '#0f1b3d' ),
			'footer'       => isset( $in['footer'] ) ? $html( $in['footer'] ) : '',
			'wrap_core'    => ! empty( $in['wrap_core'] ),
			'wc_sync'      => ! empty( $in['wc_sync'] ),
			'n'            => array(),
		);
		if ( '' !== $text( 'from_email' ) && ! is_email( $out['from_email'] ) ) {
			$out['from_email'] = '';
			add_settings_error( self::OPTION, 'nx_from', __( 'The From email address is not valid, so the default is used.', 'nexora-commerce-toolkit' ) );
		}
		$all = self::notifications();
		$raw = isset( $in['n'] ) && is_array( $in['n'] ) ? $in['n'] : array();
		foreach ( $all as $id => $def ) {
			$r      = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
			$clean  = static function ( $v ) {
				return trim( sanitize_text_field( (string) $v ) );
			};
			$row    = array(
				'on'      => empty( $def['off'] ) ? true : ! empty( $r['on'] ),
				'subject' => isset( $r['subject'] ) ? $clean( $r['subject'] ) : '',
				'heading' => isset( $r['heading'] ) ? $clean( $r['heading'] ) : '',
				'message' => isset( $r['message'] ) ? $html( $r['message'] ) : '',
			);
			// Text equal to the default is stored as empty, so later default changes still apply.
			foreach ( array( 'subject', 'heading', 'message' ) as $f ) {
				if ( null !== $def[ $f ] && trim( (string) $def[ $f ] ) === $row[ $f ] ) {
					$row[ $f ] = '';
				}
			}
			$out['n'][ $id ] = $row;
		}
		return $out;
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){$(".nx-color").wpColorPicker();$(".nx-logo-pick").on("click",function(e){e.preventDefault();var f=wp.media({title:' . wp_json_encode( __( 'Email logo', 'nexora-commerce-toolkit' ) ) . ',library:{type:"image"},multiple:false});f.on("select",function(){var a=f.state().get("selection").first().toJSON();$("#nx-email-logo").val(a.url);$(".nx-logo-preview").attr("src",a.url).prop("hidden",false);});f.open();});$(".nx-logo-clear").on("click",function(e){e.preventDefault();$("#nx-email-logo").val("");$(".nx-logo-preview").prop("hidden",true);});});'
		);
		wp_add_inline_style( 'wp-color-picker', '.nx-em details{background:#fff;border:1px solid #c3c4c7;border-radius:6px;margin:0 0 8px;max-width:900px}.nx-em summary{cursor:pointer;padding:12px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.nx-em summary strong{flex:1 1 220px}.nx-em .nx-em-body{padding:4px 14px 14px;border-top:1px solid #f0f0f1}.nx-em .nx-em-body .widefat{max-width:100%}.nx-em .nx-pill{font-size:11px;padding:1px 8px;border-radius:10px;background:#f0f0f1}.nx-em .nx-pill.is-on{background:#d1e7dd;color:#0a3622}.nx-em .nx-pill.is-off{background:#f8d7da;color:#58151c}.nx-em code{user-select:all}.nx-em .nx-vars{margin:6px 0 10px;color:#50575e}.nx-logo-preview{max-width:120px;height:auto;display:block;margin:8px 0;border:1px solid #dcdcde;padding:6px;background:#fff}' );
	}

	/**
	 * Field name.
	 *
	 * @param string ...$keys Keys.
	 * @return string
	 */
	private static function name( ...$keys ) {
		return self::OPTION . '[' . implode( '][', $keys ) . ']';
	}

	/**
	 * Preview or test link.
	 *
	 * @param string $action nexora_email_preview|nexora_email_test.
	 * @param string $id     Notification ('' for the design).
	 * @return string
	 */
	private static function action_url( $action, $id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $action . '&id=' . rawurlencode( $id ) ), $action );
	}

	/**
	 * Settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		$groups = array(
			'customer'  => __( 'Emails to customers', 'nexora-commerce-toolkit' ),
			'staff'     => __( 'Emails to the store', 'nexora-commerce-toolkit' ),
			'wordpress' => __( 'WordPress account emails', 'nexora-commerce-toolkit' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sent = isset( $_GET['nx_sent'] ) ? sanitize_key( $_GET['nx_sent'] ) : '';
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="wrap nx-em">
			<h1><?php esc_html_e( 'Freshtastic Emails', 'nexora-commerce-toolkit' ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<?php if ( 'ok' === $sent ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: email */ __( 'Test email sent to %s. Check your inbox and spam folder.', 'nexora-commerce-toolkit' ), wp_get_current_user()->user_email ) ); ?></p></div>
			<?php elseif ( 'fail' === $sent ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The test email could not be sent. Your site may need an SMTP plugin to send email.', 'nexora-commerce-toolkit' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Sender, design and text of the emails your site sends. Order details, codes, links and buttons are always added after your message, so editing the text cannot break them. Leave a field empty to use the default shown in grey.', 'nexora-commerce-toolkit' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_emails' ); ?>

				<h2><?php esc_html_e( 'Sender', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nx-from-name"><?php esc_html_e( 'From name', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="nx-from-name" name="<?php echo esc_attr( self::name( 'from_name' ) ); ?>" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>">
							<p class="description"><?php esc_html_e( 'Shown as the sender in the inbox, in place of "WordPress". Empty uses your site title.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nx-from-email"><?php esc_html_e( 'From email', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="nx-from-email" name="<?php echo esc_attr( self::name( 'from_email' ) ); ?>" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( 'noreply@' . preg_replace( '/^www\./', '', (string) $host ) ); ?>">
							<p class="description"><?php esc_html_e( 'Replaces wordpress@yourdomain. Use an address on your own domain (for example noreply@ or orders@), or emails may land in spam. An SMTP plugin\'s own sender setting still takes priority.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nx-reply"><?php esc_html_e( 'Reply-To', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="nx-reply" name="<?php echo esc_attr( self::name( 'reply_to' ) ); ?>" value="<?php echo esc_attr( $s['reply_to'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Where customer replies go, for example your support inbox. Emails that already set their own Reply-To (quotation emails) keep it.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Design', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nx-email-logo"><?php esc_html_e( 'Logo', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="nx-email-logo" name="<?php echo esc_attr( self::name( 'logo' ) ); ?>" value="<?php echo esc_attr( $s['logo'] ); ?>" placeholder="<?php esc_attr_e( 'Site logo', 'nexora-commerce-toolkit' ); ?>">
							<button type="button" class="button nx-logo-pick"><?php esc_html_e( 'Choose image', 'nexora-commerce-toolkit' ); ?></button>
							<button type="button" class="button-link nx-logo-clear"><?php esc_html_e( 'Use site logo', 'nexora-commerce-toolkit' ); ?></button>
							<img class="nx-logo-preview" src="<?php echo esc_url( (string) self::get( 'logo' ) ); ?>" alt="" <?php echo self::get( 'logo' ) ? '' : 'hidden'; ?>>
							<p class="description"><?php esc_html_e( 'PNG or JPG works in every email app (some do not show WebP or SVG). Shown about 110 pixels wide.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
					<?php
					$colors = array(
						'color_header' => array( __( 'Header colour', 'nexora-commerce-toolkit' ), self::get( 'color_header' ), __( 'Empty follows the theme\'s primary colour.', 'nexora-commerce-toolkit' ) ),
						'color_button' => array( __( 'Button and link colour', 'nexora-commerce-toolkit' ), self::get( 'color_button' ), __( 'Empty follows the theme\'s secondary colour.', 'nexora-commerce-toolkit' ) ),
						'color_bg'     => array( __( 'Background colour', 'nexora-commerce-toolkit' ), '#f5f7fb', '' ),
						'color_text'   => array( __( 'Text colour', 'nexora-commerce-toolkit' ), '#0f1b3d', '' ),
					);
					foreach ( $colors as $key => $c ) :
						?>
						<tr>
							<th scope="row"><label for="nx-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $c[0] ); ?></label></th>
							<td>
								<input type="text" class="nx-color" id="nx-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::name( $key ) ); ?>" value="<?php echo esc_attr( (string) $s[ $key ] ); ?>" data-default-color="<?php echo esc_attr( (string) $c[1] ); ?>">
								<?php if ( $c[2] ) : ?>
									<p class="description"><?php echo esc_html( $c[2] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="nx-footer"><?php esc_html_e( 'Footer', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="nx-footer" name="<?php echo esc_attr( self::name( 'footer' ) ); ?>" placeholder="{site_name} · {site_url}"><?php echo esc_textarea( (string) $s['footer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'For example your address, phone and opening hours. Placeholders: {site_name}, {site_url}, {year}. Links are allowed.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Apply to', 'nexora-commerce-toolkit' ); ?></th>
						<td>
							<p><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'wrap_core' ) ); ?>" value="1" <?php checked( ! empty( $s['wrap_core'] ) ); ?>> <?php esc_html_e( 'WordPress\'s own emails and other plain-text emails (password reset, new user, password changed, and plugins that send plain text)', 'nexora-commerce-toolkit' ); ?></label></p>
							<p><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'wc_sync' ) ); ?>" value="1" <?php checked( ! empty( $s['wc_sync'] ) ); ?>> <?php esc_html_e( 'WooCommerce emails: sender, logo, colours and footer (when WooCommerce is active)', 'nexora-commerce-toolkit' ); ?></label></p>
							<p><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( self::action_url( 'nexora_email_preview', '' ) ); ?>"><?php esc_html_e( 'Preview the design', 'nexora-commerce-toolkit' ); ?></a> <span class="description"><?php esc_html_e( 'Save first to preview changes.', 'nexora-commerce-toolkit' ); ?></span></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Notifications', 'nexora-commerce-toolkit' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Every email also understands {site_name}, {site_url} and {year}.', 'nexora-commerce-toolkit' ); ?>
					<?php if ( class_exists( 'WooCommerce' ) ) : ?>
						<?php
						printf(
							/* translators: 1: opening link, 2: closing link */
							esc_html__( 'WooCommerce\'s own emails (orders, accounts, refunds) are edited under %1$sWooCommerce → Settings → Emails%2$s.', 'nexora-commerce-toolkit' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">',
							'</a>'
						);
						?>
					<?php endif; ?>
				</p>
				<?php
				$all = self::notifications();
				foreach ( $groups as $group => $label ) :
					?>
					<h3><?php echo esc_html( $label ); ?></h3>
					<?php
					foreach ( $all as $id => $def ) :
						if ( $group !== $def['group'] ) {
							continue;
						}
						$row = isset( $s['n'][ $id ] ) ? (array) $s['n'][ $id ] : array();
						$on  = self::enabled( $id );
						$edited = ! empty( $row['subject'] ) || ! empty( $row['heading'] ) || ! empty( $row['message'] );
						$uid    = 'nx-n-' . $id;
						?>
						<details>
							<summary>
								<strong><?php echo esc_html( $def['title'] ); ?></strong>
								<span class="nx-pill <?php echo $on ? 'is-on' : 'is-off'; ?>"><?php echo $on ? esc_html__( 'On', 'nexora-commerce-toolkit' ) : esc_html__( 'Off', 'nexora-commerce-toolkit' ); ?></span>
								<?php if ( $edited ) : ?>
									<span class="nx-pill"><?php esc_html_e( 'Edited', 'nexora-commerce-toolkit' ); ?></span>
								<?php endif; ?>
							</summary>
							<div class="nx-em-body">
								<p class="description"><?php echo esc_html( $def['desc'] ); ?></p>
								<?php if ( ! empty( $def['off'] ) ) : ?>
									<p><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'n', $id, 'on' ) ); ?>" value="1" <?php checked( $on ); ?>> <?php esc_html_e( 'Send this email', 'nexora-commerce-toolkit' ); ?></label></p>
								<?php endif; ?>
								<p>
									<label for="<?php echo esc_attr( $uid ); ?>-s"><strong><?php esc_html_e( 'Subject', 'nexora-commerce-toolkit' ); ?></strong></label><br>
									<input type="text" class="widefat" id="<?php echo esc_attr( $uid ); ?>-s" name="<?php echo esc_attr( self::name( 'n', $id, 'subject' ) ); ?>" value="<?php echo esc_attr( isset( $row['subject'] ) ? $row['subject'] : '' ); ?>" placeholder="<?php echo esc_attr( $def['subject'] ); ?>">
								</p>
								<p>
									<label for="<?php echo esc_attr( $uid ); ?>-h"><strong><?php esc_html_e( 'Heading', 'nexora-commerce-toolkit' ); ?></strong></label><br>
									<input type="text" class="widefat" id="<?php echo esc_attr( $uid ); ?>-h" name="<?php echo esc_attr( self::name( 'n', $id, 'heading' ) ); ?>" value="<?php echo esc_attr( isset( $row['heading'] ) ? $row['heading'] : '' ); ?>" placeholder="<?php echo esc_attr( $def['heading'] ); ?>">
								</p>
								<p>
									<label for="<?php echo esc_attr( $uid ); ?>-m"><strong><?php esc_html_e( 'Message', 'nexora-commerce-toolkit' ); ?></strong></label><br>
									<textarea class="widefat" rows="4" id="<?php echo esc_attr( $uid ); ?>-m" name="<?php echo esc_attr( self::name( 'n', $id, 'message' ) ); ?>" placeholder="<?php echo esc_attr( null === $def['message'] ? __( 'WordPress\'s own text', 'nexora-commerce-toolkit' ) : (string) $def['message'] ); ?>"><?php echo esc_textarea( isset( $row['message'] ) ? $row['message'] : '' ); ?></textarea>
								</p>
								<p class="nx-vars">
									<?php esc_html_e( 'Placeholders:', 'nexora-commerce-toolkit' ); ?>
									<?php foreach ( $def['vars'] as $var => $what ) : ?>
										<code><?php echo esc_html( $var ); ?></code> <?php echo esc_html( $what ); ?>&nbsp;&nbsp;
									<?php endforeach; ?>
								</p>
								<p>
									<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( self::action_url( 'nexora_email_preview', $id ) ); ?>"><?php esc_html_e( 'Preview', 'nexora-commerce-toolkit' ); ?></a>
									<a class="button" href="<?php echo esc_url( self::action_url( 'nexora_email_test', $id ) ); ?>"><?php esc_html_e( 'Send me a test', 'nexora-commerce-toolkit' ); ?></a>
									<span class="description"><?php esc_html_e( 'Uses saved settings and sample details.', 'nexora-commerce-toolkit' ); ?></span>
								</p>
							</div>
						</details>
						<?php
					endforeach;
				endforeach;
				?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------ preview / test */

	/**
	 * Sample email for a notification.
	 *
	 * @param string $id Notification ('' for a design sample).
	 * @return array{subject:string,html:string}
	 */
	private static function sample( $id ) {
		$all  = self::notifications();
		$user = wp_get_current_user();
		$vars = array(
			'first_name'     => $user->first_name ? $user->first_name : __( 'Maria', 'nexora-commerce-toolkit' ),
			'order_number'   => '1024',
			'customer_name'  => __( 'Maria Santos', 'nexora-commerce-toolkit' ),
			'customer_email' => 'maria@example.com',
			'customer_phone' => '0917 123 4567',
			'reward_title'   => __( 'Thank you for being one of our first 100 customers!', 'nexora-commerce-toolkit' ),
			'spot'           => '7',
			'name'           => __( 'Maria Santos', 'nexora-commerce-toolkit' ),
			'reference'      => 'RFQ-0042',
			'item'           => __( 'Jasmine rice, 25 kg', 'nexora-commerce-toolkit' ),
			'date'           => wp_date( get_option( 'date_format' ), time() - DAY_IN_SECONDS ),
			'sales'          => '₱12,450.00',
			'orders'         => __( '18 orders', 'nexora-commerce-toolkit' ),
			'username'       => 'maria',
			'link'           => home_url( '/sample-link/' ),
		);
		$note = '<p style="margin:16px 0;padding:12px;border:1px dashed #c3c4c7;border-radius:8px;color:#5f6676;font-size:13px;">' . esc_html__( 'Sample: the details, code or link for this email appear here.', 'nexora-commerce-toolkit' ) . '</p>';
		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			$body = '<p>' . esc_html__( 'Hi Maria, this is how your emails look. The heading bar uses your header colour, and buttons and links use your button colour.', 'nexora-commerce-toolkit' ) . '</p>'
				. self::button( home_url( '/' ), __( 'Sample button', 'nexora-commerce-toolkit' ) ) . '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'A sample link', 'nexora-commerce-toolkit' ) . '</a></p>';
			/* translators: %s: site name */
			return array( 'subject' => sprintf( __( '%s email design', 'nexora-commerce-toolkit' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 'html' => self::wrap( __( 'Email design', 'nexora-commerce-toolkit' ), $body ) );
		}
		$def = $all[ $id ];
		$n   = self::settings()['n'];
		$row = isset( $n[ $id ] ) ? (array) $n[ $id ] : array();
		// Preview even when turned off.
		$heading = self::fill( ! empty( $row['heading'] ) ? $row['heading'] : $def['heading'], $vars );
		$vars    = array( 'heading' => $heading ) + $vars;
		$subject = wp_strip_all_tags( self::fill( ! empty( $row['subject'] ) ? $row['subject'] : $def['subject'], $vars ) );
		$message = ! empty( $row['message'] ) ? $row['message'] : (string) $def['message'];
		if ( null === $def['message'] && empty( $row['message'] ) ) {
			$body = '<p style="color:#5f6676;">' . esc_html__( '(WordPress\'s own text appears here.)', 'nexora-commerce-toolkit' ) . '</p>';
		} else {
			$body = '' !== trim( $message ) ? wpautop( wp_kses_post( self::fill( $message, $vars, true ) ) ) : '';
		}
		return array( 'subject' => $subject, 'html' => self::wrap( wp_strip_all_tags( $heading ), $body . $note ) );
	}

	/**
	 * Show a preview.
	 */
	public static function preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_email_preview' );
		$id     = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$sample = self::sample( $id );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!-- ' . esc_html( $sample['subject'] ) . ' -->';
		echo $sample['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in wrap().
		exit;
	}

	/**
	 * Send a test to the current user.
	 */
	public static function test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_email_test' );
		$id     = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$sample = self::sample( $id );
		/* translators: %s: subject */
		$ok = wp_mail( wp_get_current_user()->user_email, sprintf( __( '[Test] %s', 'nexora-commerce-toolkit' ), $sample['subject'] ), $sample['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&nx_sent=' . ( $ok ? 'ok' : 'fail' ) ) );
		exit;
	}
}
