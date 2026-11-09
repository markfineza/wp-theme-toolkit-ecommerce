<?php
/**
 * Email activation for new customer accounts.
 *
 * When on (Settings → Freshtastic Security, on by default), an account created from the registration
 * form or at checkout is not signed in. The customer gets an email with a one-time link that lasts
 * three days, and cannot sign in until they click it. Works on the built-in store and on WooCommerce.
 * Accounts that existed before, and accounts created by administrators, are never affected.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account activation.
 */
class Nexora_Toolkit_Activation {

	const META      = '_nx_activation';
	const DONE      = '_nx_activated_at';
	const LIFETIME  = 3 * DAY_IN_SECONDS;
	const RESEND    = 5 * MINUTE_IN_SECONDS;

	/**
	 * Set during a request that has just created a pending account.
	 *
	 * @var bool
	 */
	private static $created = false;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'gate' ), 100, 3 ); // After the password check (20), before login codes (1000).
		// determine_current_user is hooked when this file loads (bottom), because other plugins can
		// look up the current user before plugins_loaded.
		add_action( 'template_redirect', array( __CLASS__, 'handle_link' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'show_result' ), 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ) );

		// WooCommerce.
		add_filter( 'woocommerce_registration_auth_new_customer', array( __CLASS__, 'wc_no_auto_login' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'wc_created' ), 5 );
		add_filter( 'woocommerce_add_success', array( __CLASS__, 'wc_notice' ) );
		add_filter( 'woocommerce_add_notice', array( __CLASS__, 'wc_notice' ) );
		add_filter( 'woocommerce_order_received_verify_known_shoppers', array( __CLASS__, 'wc_receipt_by_key' ) );
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'wc_receipt_note' ) );

		// Admin.
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_box' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );
	}

	/**
	 * Whether new accounts must be activated.
	 *
	 * @return bool
	 */
	public static function required() {
		/**
		 * Filter whether new customer accounts need email activation.
		 *
		 * @param bool $required Required.
		 */
		return (bool) apply_filters( 'nexora_account_activation_required', (bool) Nexora_Toolkit_Security::get( 'activation_required' ) );
	}

	/**
	 * Whether a user is waiting for activation.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_pending( $user_id ) {
		return $user_id && '' !== get_user_meta( (int) $user_id, self::META, true );
	}

	/**
	 * Mark a new account as pending and email the link. Called right after the account is created.
	 *
	 * @param int $user_id User.
	 * @return bool Whether the email went out.
	 */
	public static function start( $user_id ) {
		self::$created = true;
		return self::send( (int) $user_id );
	}

	/**
	 * Create a fresh link (the old one stops working) and email it.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function send( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$token = wp_generate_password( 32, false, false );
		update_user_meta(
			$user_id,
			self::META,
			array(
				'hash'    => self::hash( $user_id, $token ),
				'expires' => time() + self::LIFETIME,
				'sent'    => time(),
			)
		);
		$link = add_query_arg(
			array(
				'nx_activate' => $user_id,
				'key'         => $token,
			),
			self::account_url()
		);
		$m    = Nexora_Toolkit_Emails::compose( 'account_activation', array( 'first_name' => $user->first_name ? $user->first_name : $user->display_name ) );
		$body = $m['message']
			. Nexora_Toolkit_Emails::button( $link, __( 'Activate my account', 'nexora-commerce-toolkit' ) )
			. '<p>' . esc_html__( 'The link works once and expires in 3 days. After that, sign in with your email and password and we will send you a new one.', 'nexora-commerce-toolkit' ) . '</p>'
			. '<p style="font-size:12px;color:#5b6475;word-break:break-all;">' . esc_html__( 'If the button does not work, copy this address into your browser:', 'nexora-commerce-toolkit' ) . '<br>' . esc_html( $link ) . '</p>'
			. '<p>' . esc_html__( 'If you did not create this account, you can ignore this email.', 'nexora-commerce-toolkit' ) . '</p>';
		return Nexora_Store_Orders::send_mail( $user->user_email, $m['subject'], $m['heading'], $body );
	}

	/**
	 * Activate an account.
	 *
	 * @param int $user_id User.
	 */
	public static function activate( $user_id ) {
		if ( ! self::is_pending( $user_id ) ) {
			return;
		}
		delete_user_meta( $user_id, self::META );
		update_user_meta( $user_id, self::DONE, gmdate( 'Y-m-d H:i:s' ) );
		/**
		 * Fires when a customer account is activated.
		 *
		 * @param int $user_id User.
		 */
		do_action( 'nexora_account_activated', $user_id );
	}

	/**
	 * Keyed hash of a token.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Token.
	 * @return string
	 */
	private static function hash( $user_id, $token ) {
		return hash_hmac( 'sha256', 'activate|' . $user_id . '|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Where customers sign in.
	 *
	 * @return string
	 */
	public static function account_url() {
		if ( Nexora_Store::active() ) {
			return Nexora_Store::url( 'account' );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			return wc_get_page_permalink( 'myaccount' );
		}
		return wp_login_url();
	}

	/**
	 * Email shown partly hidden, e.g. m***@example.com.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function mask( $email ) {
		$parts = explode( '@', (string) $email, 2 );
		return 2 === count( $parts ) ? substr( $parts[0], 0, 1 ) . '***@' . $parts[1] : '';
	}

	/* ------------------------------------------------------------- sign-in */

	/**
	 * Refuse sign-in until activated. Runs only after the password was right, so a new link is sent
	 * to the account owner and nothing is revealed to someone guessing.
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function gate( $user, $username, $password ) {
		if ( ! $user instanceof WP_User || ! self::is_pending( $user->ID ) ) {
			return $user;
		}
		$data = get_user_meta( $user->ID, self::META, true );
		$sent = is_array( $data ) && ! empty( $data['sent'] ) ? (int) $data['sent'] : 0;
		if ( time() - $sent >= self::RESEND && self::send( $user->ID ) ) {
			/* translators: %s: partly hidden email address */
			$message = sprintf( __( 'Please activate your account first. We just sent a new activation link to %s.', 'nexora-commerce-toolkit' ), self::mask( $user->user_email ) );
		} else {
			/* translators: %s: partly hidden email address */
			$message = sprintf( __( 'Please activate your account first. Click the link in the email we sent to %s (check your spam folder too).', 'nexora-commerce-toolkit' ), self::mask( $user->user_email ) );
		}
		return new WP_Error( 'nx_inactive', $message );
	}

	/**
	 * A pending account is never treated as signed in, even if a login cookie was set while the
	 * account was being created (WooCommerce checkout does this).
	 *
	 * @param int|false $user_id User from earlier handlers.
	 * @return int|false
	 */
	public static function no_session( $user_id ) {
		return $user_id && self::is_pending( $user_id ) ? false : $user_id;
	}

	/**
	 * A password reset link proves the email address, so it activates the account too.
	 *
	 * @param WP_User $user User.
	 */
	public static function on_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::activate( $user->ID );
		}
	}

	/* --------------------------------------------------------------- link */

	/**
	 * Handle ?nx_activate=ID&key=TOKEN, then redirect to a clean URL that shows the result.
	 */
	public static function handle_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the emailed token is the proof.
		if ( ! isset( $_GET['nx_activate'], $_GET['key'] ) ) {
			return;
		}
		$user_id = absint( $_GET['nx_activate'] );
		$token   = sanitize_text_field( wp_unslash( (string) $_GET['key'] ) );
		// phpcs:enable
		$result = 'invalid';
		if ( $user_id && get_userdata( $user_id ) ) {
			$data = get_user_meta( $user_id, self::META, true );
			if ( ! self::is_pending( $user_id ) ) {
				$result = 'already';
			} elseif ( is_array( $data ) && ! empty( $data['hash'] ) && hash_equals( (string) $data['hash'], self::hash( $user_id, $token ) ) ) {
				if ( time() > (int) $data['expires'] ) {
					$result = 'expired';
				} else {
					self::activate( $user_id );
					$result = 'done';
				}
			}
		}
		nocache_headers();
		wp_safe_redirect( add_query_arg( 'nx_activation', $result, self::account_url() ) );
		exit;
	}

	/**
	 * Show the result on the sign-in page. The text comes from a fixed list, never from the URL.
	 */
	public static function show_result() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = isset( $_GET['nx_activation'] ) ? sanitize_key( $_GET['nx_activation'] ) : '';
		$messages = array(
			'done'    => array( 'success', __( 'Your account is now active. Please sign in.', 'nexora-commerce-toolkit' ) ),
			'already' => array( 'success', __( 'Your account is already active. Please sign in.', 'nexora-commerce-toolkit' ) ),
			'expired' => array( 'error', __( 'This activation link has expired. Sign in with your email and password and we will send you a new one.', 'nexora-commerce-toolkit' ) ),
			'invalid' => array( 'error', __( 'This activation link is not valid. It may have been replaced by a newer one. Sign in with your email and password and we will send you a new link.', 'nexora-commerce-toolkit' ) ),
		);
		if ( ! isset( $messages[ $code ] ) || is_user_logged_in() ) {
			return;
		}
		self::notice( $messages[ $code ][0], $messages[ $code ][1] );
	}

	/**
	 * Show a notice on the running store's pages.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	public static function notice( $type, $message ) {
		if ( Nexora_Store::active() ) {
			Nexora_Store_Cart::notice( $type, $message );
		} elseif ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && WC()->session ) {
			wc_add_notice( $message, $type );
		}
	}

	/**
	 * Message after registering.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function created_message( $email ) {
		/* translators: %s: partly hidden email address */
		return sprintf( __( 'Your account was created. We sent an activation link to %s. Click it to activate your account, then sign in.', 'nexora-commerce-toolkit' ), self::mask( $email ) );
	}

	/* ---------------------------------------------------------- WooCommerce */

	/**
	 * WooCommerce registration form: do not sign in.
	 *
	 * @param bool $auth Sign in.
	 * @return bool
	 */
	public static function wc_no_auto_login( $auth ) {
		return self::required() ? false : $auth;
	}

	/**
	 * WooCommerce created a customer (registration form or checkout).
	 *
	 * @param int $user_id User.
	 */
	public static function wc_created( $user_id ) {
		if ( self::required() ) {
			self::start( $user_id );
		}
	}

	/**
	 * Replace WooCommerce's "account created" notice with the activation instructions.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function wc_notice( $message ) {
		$theirs = array(
			__( 'Your account was created successfully and a password has been sent to your email address.', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- matching WooCommerce's own notice.
			__( 'Your account was created successfully. Your login details have been sent to your email address.', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		);
		if ( ! self::$created || ! in_array( (string) $message, $theirs, true ) ) {
			return $message;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the form.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		return esc_html( self::created_message( $email ) );
	}

	/**
	 * The order receipt normally asks a known customer to sign in. A customer whose account is still
	 * waiting for activation cannot, so their order key alone shows the receipt, as for guests.
	 *
	 * @param bool $verify Verify.
	 * @return bool
	 */
	public static function wc_receipt_by_key( $verify ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( get_query_var( 'order-received' ) ) ) : false;
		return $order && self::is_pending( $order->get_customer_id() ) ? false : $verify;
	}

	/**
	 * On the receipt, tell a customer who created an account at checkout to activate it.
	 *
	 * @param int $order_id Order.
	 */
	public static function wc_receipt_note( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && self::is_pending( $order->get_customer_id() ) ) {
			echo '<div class="woocommerce-info" role="status">' . esc_html( self::created_message( $order->get_billing_email() ) ) . '</div>';
		}
	}

	/* ---------------------------------------------------------------- admin */

	/**
	 * Status and actions on Users → Edit.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function profile_box( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$pending = self::is_pending( $user->ID );
		$done    = get_user_meta( $user->ID, self::DONE, true );
		if ( ! $pending && ! $done ) {
			return; // Account from before activation existed, or made by an administrator.
		}
		?>
		<h2><?php esc_html_e( 'Account activation', 'nexora-commerce-toolkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></th>
				<td>
					<?php if ( $pending ) : ?>
						<p><strong><?php esc_html_e( 'Waiting for the customer to click the activation link.', 'nexora-commerce-toolkit' ); ?></strong></p>
						<?php wp_nonce_field( 'nx_activation_admin', 'nx_activation_nonce' ); ?>
						<p><label><input type="radio" name="nx_activation_action" value="" checked> <?php esc_html_e( 'No change', 'nexora-commerce-toolkit' ); ?></label></p>
						<p><label><input type="radio" name="nx_activation_action" value="activate"> <?php esc_html_e( 'Activate now (only if you have confirmed the email address yourself)', 'nexora-commerce-toolkit' ); ?></label></p>
						<p><label><input type="radio" name="nx_activation_action" value="resend"> <?php esc_html_e( 'Email a new activation link', 'nexora-commerce-toolkit' ); ?></label></p>
					<?php else : ?>
						<?php
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'Activated on %s.', 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $done . ' UTC' ) ) ) );
						?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the admin action.
	 *
	 * @param int $user_id User.
	 */
	public static function profile_save( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['nx_activation_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nx_activation_nonce'] ) ), 'nx_activation_admin' ) ) {
			return;
		}
		$action = isset( $_POST['nx_activation_action'] ) ? sanitize_key( $_POST['nx_activation_action'] ) : '';
		if ( 'activate' === $action ) {
			self::activate( $user_id );
		} elseif ( 'resend' === $action && self::is_pending( $user_id ) ) {
			self::send( $user_id );
		}
	}
}

add_filter( 'determine_current_user', array( 'Nexora_Toolkit_Activation', 'no_session' ), 99 );
