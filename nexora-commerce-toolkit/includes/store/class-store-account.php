<?php
/**
 * Customer portal of the built-in store: login, registration, dashboard, orders, address and profile.
 * Customers are normal WordPress users with the "customer" role (the same as WooCommerce's), so
 * accounts keep working after switching engines.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer accounts.
 */
class Nexora_Store_Account {

	/**
	 * Form errors.
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
		add_action( 'admin_init', array( __CLASS__, 'keep_customers_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
	}

	/**
	 * Staff who may use wp-admin.
	 *
	 * @param WP_User|null $user User.
	 * @return bool
	 */
	private static function is_staff( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return user_can( $user, 'edit_posts' ) || user_can( $user, 'nx_manage_orders' ) || user_can( $user, 'manage_options' );
	}

	/**
	 * Customers use their portal, not wp-admin.
	 */
	public static function keep_customers_out_of_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() || self::is_staff() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' !== $pagenow ) {
			wp_safe_redirect( Nexora_Store::url( 'account' ) );
			exit;
		}
	}

	/**
	 * No admin toolbar for customers.
	 *
	 * @param bool $show Show.
	 * @return bool
	 */
	public static function admin_bar( $show ) {
		return is_user_logged_in() && ! self::is_staff() ? false : $show;
	}

	/**
	 * After wp-login.php: Order Desk staff go to the orders, customers to their portal.
	 *
	 * @param string           $redirect  Redirect.
	 * @param string           $requested Requested.
	 * @param WP_User|WP_Error $user      User.
	 * @return string
	 */
	public static function login_redirect( $redirect, $requested, $user ) {
		if ( ! $user instanceof WP_User || ( $requested && admin_url() !== $requested ) ) {
			return $redirect;
		}
		if ( user_can( $user, Nexora_Store_Admin::CAP_ORDERS ) && ! user_can( $user, Nexora_Store_Admin::CAP_STORE ) ) {
			return admin_url( 'admin.php?page=nexora-store-orders' );
		}
		return self::is_staff( $user ) ? $redirect : Nexora_Store::url( 'account' );
	}

	/**
	 * Create a customer.
	 *
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @param string $first    First name.
	 * @param string $last     Last name.
	 * @param string $phone    Mobile.
	 * @return int|WP_Error
	 */
	public static function create_customer( $email, $password, $first, $last, $phone ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		$base = $base ? $base : 'customer';
		$name = $base;
		for ( $i = 2; username_exists( $name ); $i++ ) {
			$name = $base . $i;
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $name,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_user_meta( $id, 'billing_first_name', $first );
		update_user_meta( $id, 'billing_last_name', $last );
		update_user_meta( $id, 'billing_phone', $phone );
		update_user_meta( $id, 'billing_email', $email );
		if ( Nexora_Toolkit_Activation::required() ) {
			Nexora_Toolkit_Activation::start( $id ); // The activation email replaces the welcome email.
			return (int) $id;
		}
		$m = Nexora_Toolkit_Emails::compose( 'account_welcome', array( 'first_name' => $first ) );
		if ( $m ) {
			Nexora_Store_Orders::send_mail( $email, $m['subject'], $m['heading'], $m['message'] . Nexora_Toolkit_Emails::button( Nexora_Store::url( 'account' ), __( 'Go to your account', 'nexora-commerce-toolkit' ) ) );
		}
		return (int) $id;
	}

	/* ----------------------------------------------------------------- forms */

	/**
	 * Posted text.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verifies the nonce before acting.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Same-site page to continue to after login.
	 *
	 * @return string
	 */
	private static function redirect_target() {
		$raw = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return $raw ? wp_validate_redirect( $raw, Nexora_Store::url( 'account' ) ) : Nexora_Store::url( 'account' );
	}

	/**
	 * Handle account forms and logout.
	 */
	public static function handle() {
		if ( ! Nexora_Store::is_page( 'account' ) ) {
			return;
		}
		if ( false !== get_query_var( 'customer-logout', false ) && is_user_logged_in() ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'nexora_logout' ) ) {
				wp_logout();
				wp_safe_redirect( Nexora_Store::url( 'account' ) );
				exit;
			}
			wp_safe_redirect( Nexora_Store::url( 'account' ) );
			exit;
		}
		if ( empty( $_POST['nx_account_action'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_nx_account'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_nx_account'] ) ), 'nexora_account' ) ) {
			self::$errors[] = __( 'Your session expired. Please try again.', 'nexora-commerce-toolkit' );
			return;
		}
		switch ( sanitize_key( wp_unslash( $_POST['nx_account_action'] ) ) ) {
			case 'login':
				self::handle_login();
				break;
			case 'register':
				self::handle_register();
				break;
			case 'address':
				self::handle_address();
				break;
			case 'details':
				self::handle_details();
				break;
		}
	}

	/**
	 * Log in, with a limit on failed attempts per address and account.
	 */
	private static function handle_login() {
		$login = self::posted( 'username' );
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'nx_login_' . md5( $ip . '|' . strtolower( $login ) );
		$tries = (int) get_transient( $key );
		if ( $tries >= 8 ) {
			self::$errors[] = __( 'Too many failed attempts. Please wait 10 minutes or reset your password.', 'nexora-commerce-toolkit' );
			return;
		}
		$user = wp_signon(
			array(
				'user_login'    => $login,
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in handle(); passwords are not sanitized.
				'user_password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
				'remember'      => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			if ( 0 === strpos( $user->get_error_code(), 'nx_otp' ) || 'nx_inactive' === $user->get_error_code() ) { // Password was right; a login code could not go out, or the account is not activated yet.
				self::$errors[] = $user->get_error_message();
				return;
			}
			set_transient( $key, $tries + 1, 10 * MINUTE_IN_SECONDS );
			self::$errors[] = __( 'The email, username or password is not correct.', 'nexora-commerce-toolkit' );
			return;
		}
		delete_transient( $key );
		$target = self::redirect_target();
		if ( user_can( $user, 'nx_manage_orders' ) && ! isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$target = admin_url( 'admin.php?page=nexora-store-orders' );
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Register.
	 */
	private static function handle_register() {
		if ( ! Nexora_Store::get( 'registration' ) ) {
			return;
		}
		$first = self::posted( 'nexora_first_name' );
		$last  = self::posted( 'nexora_last_name' );
		$email = sanitize_email( self::posted( 'email' ) );
		$phone = self::posted( 'nexora_mobile' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in handle(); passwords are not sanitized.
		$pass = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( '' !== self::posted( 'company_url' ) ) { // Honeypot: real visitors never see this field.
			return;
		}
		if ( '' === $first ) {
			self::$errors[] = __( 'Please enter your first name.', 'nexora-commerce-toolkit' );
		}
		if ( '' === $last ) {
			self::$errors[] = __( 'Please enter your last name.', 'nexora-commerce-toolkit' );
		}
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
			self::$errors[] = __( 'Please enter a valid mobile number, for example 0917 123 4567.', 'nexora-commerce-toolkit' );
		}
		if ( ! is_email( $email ) ) {
			self::$errors[] = __( 'Please enter a valid email address.', 'nexora-commerce-toolkit' );
		} elseif ( email_exists( $email ) ) {
			self::$errors[] = __( 'An account with this email already exists. Please sign in instead.', 'nexora-commerce-toolkit' );
		}
		if ( strlen( $pass ) < 8 ) {
			self::$errors[] = __( 'Please choose a password of at least 8 characters.', 'nexora-commerce-toolkit' );
		}
		$consent = Nexora_Toolkit_Consent::error();
		if ( '' !== $consent ) {
			self::$errors[] = $consent;
		}
		$captcha = Nexora_Toolkit_Captcha::error();
		if ( '' !== $captcha ) {
			self::$errors[] = $captcha;
		}
		if ( self::$errors ) {
			return;
		}
		$id = self::create_customer( $email, $pass, $first, $last, $phone );
		if ( is_wp_error( $id ) ) {
			self::$errors[] = $id->get_error_message();
			return;
		}
		if ( Nexora_Toolkit_Activation::is_pending( $id ) ) {
			// Not signed in until the emailed link is clicked.
			Nexora_Store_Cart::notice( 'success', Nexora_Toolkit_Activation::created_message( $email ) );
			wp_safe_redirect( Nexora_Store::url( 'account' ) );
			exit;
		}
		$user = get_userdata( $id );
		wp_set_current_user( $id );
		wp_set_auth_cookie( $id, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		wp_safe_redirect( self::redirect_target() );
		exit;
	}

	/**
	 * Save the address.
	 */
	private static function handle_address() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return;
		}
		foreach ( array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone' ) as $key ) {
			update_user_meta( $user, 'billing_' . $key, self::posted( 'billing_' . $key ) );
		}
		Nexora_Store_Cart::notice( 'success', __( 'Address saved.', 'nexora-commerce-toolkit' ) );
		wp_safe_redirect( Nexora_Store::url( 'account', 'edit-address' ) );
		exit;
	}

	/**
	 * Save profile and optionally change the password.
	 */
	private static function handle_details() {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		$first   = self::posted( 'account_first_name' );
		$last    = self::posted( 'account_last_name' );
		$display = self::posted( 'account_display_name' );
		$email   = sanitize_email( self::posted( 'account_email' ) );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in handle(); passwords are not sanitized.
		$current = isset( $_POST['password_current'] ) ? (string) wp_unslash( $_POST['password_current'] ) : '';
		$new     = isset( $_POST['password_1'] ) ? (string) wp_unslash( $_POST['password_1'] ) : '';
		$confirm = isset( $_POST['password_2'] ) ? (string) wp_unslash( $_POST['password_2'] ) : '';
		// phpcs:enable
		if ( '' === $first || '' === $last ) {
			self::$errors[] = __( 'Please enter your first and last name.', 'nexora-commerce-toolkit' );
		}
		if ( ! is_email( $email ) ) {
			self::$errors[] = __( 'Please enter a valid email address.', 'nexora-commerce-toolkit' );
		} elseif ( email_exists( $email ) && (int) email_exists( $email ) !== $user->ID ) {
			self::$errors[] = __( 'That email address is already used by another account.', 'nexora-commerce-toolkit' );
		}
		// Changing the email or password needs the current password.
		$email_changed = strtolower( $email ) !== strtolower( $user->user_email );
		if ( ( '' !== $new || $email_changed ) && ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			self::$errors[] = __( 'Please enter your current password to change your email or password.', 'nexora-commerce-toolkit' );
		}
		if ( '' !== $new && strlen( $new ) < 8 ) {
			self::$errors[] = __( 'Your new password must have at least 8 characters.', 'nexora-commerce-toolkit' );
		}
		if ( $new !== $confirm ) {
			self::$errors[] = __( 'The new passwords do not match.', 'nexora-commerce-toolkit' );
		}
		if ( self::$errors ) {
			return;
		}
		$args = array(
			'ID'           => $user->ID,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $display ? $display : trim( $first . ' ' . $last ),
			'user_email'   => $email,
		);
		if ( '' !== $new ) {
			$args['user_pass'] = $new;
		}
		$result = wp_update_user( $args );
		if ( is_wp_error( $result ) ) {
			self::$errors[] = $result->get_error_message();
			return;
		}
		if ( '' !== $new ) {
			wp_set_auth_cookie( $user->ID, true, is_ssl() ); // Changing the password ended the session; keep this device signed in.
		}
		Nexora_Store_Cart::notice( 'success', __( 'Account details saved.', 'nexora-commerce-toolkit' ) );
		wp_safe_redirect( Nexora_Store::url( 'account', 'edit-account' ) );
		exit;
	}

	/* ---------------------------------------------------------------- output */

	/**
	 * Errors list.
	 *
	 * @return string
	 */
	private static function errors_html() {
		if ( ! self::$errors ) {
			return '';
		}
		$out = '<ul class="woocommerce-error" role="alert">';
		foreach ( self::$errors as $message ) {
			$out .= '<li>' . esc_html( $message ) . '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Page.
	 *
	 * @return string
	 */
	public static function render() {
		ob_start();
		echo '<div class="woocommerce">';
		echo Nexora_Store_Cart::notices_html() . self::errors_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		if ( ! is_user_logged_in() ) {
			self::render_auth();
		} else {
			self::render_nav();
			echo '<div class="woocommerce-MyAccount-content">';
			self::render_tab();
			echo '</div>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private static function current_tab() {
		foreach ( array( 'orders', 'view-order', 'coupons', 'points', 'edit-address', 'edit-account', 'wishlist', 'quotations' ) as $tab ) {
			if ( false !== get_query_var( $tab, false ) ) {
				return $tab;
			}
		}
		return 'dashboard';
	}

	/**
	 * Initials for the avatar.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function initials( $user ) {
		$name  = trim( $user->first_name . ' ' . $user->last_name );
		$name  = '' !== $name ? $name : $user->display_name;
		$out   = '';
		foreach ( array_slice( preg_split( '/\s+/', $name ), 0, 2 ) as $part ) {
			$out .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}
		return $out ? $out : '?';
	}

	/**
	 * Menu.
	 */
	private static function render_nav() {
		$current = self::current_tab();
		$items   = array(
			'dashboard'    => array( 'grid', __( 'Dashboard', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account' ) ),
			'orders'       => array( 'bag', __( 'Orders', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'orders' ) ),
			'edit-address' => array( 'pin', __( 'Addresses', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'edit-address' ) ),
			'edit-account' => array( 'user', __( 'Account details', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'edit-account' ) ),
		);
		if ( Nexora_Toolkit_Settings::get( 'wishlist_enabled' ) ) {
			$items['wishlist'] = array( 'heart', __( 'Wishlist', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'wishlist' ) );
		}
		if ( Nexora_Toolkit_Settings::get( 'rfq_enabled' ) ) {
			$items['quotations'] = array( 'clipboard', __( 'Quotations', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'quotations' ) );
		}
		$items['customer-logout'] = array( 'logout', __( 'Log out', 'nexora-commerce-toolkit' ), self::logout_url() );
		$items                    = (array) apply_filters( 'nexora_store_account_menu', $items );
		?>
		<nav class="woocommerce-MyAccount-navigation nx-acc-nav" aria-label="<?php esc_attr_e( 'Account pages', 'nexora-commerce-toolkit' ); ?>">
			<ul>
				<?php foreach ( $items as $key => $item ) : ?>
					<?php $active = $key === $current || ( 'orders' === $key && 'view-order' === $current ); ?>
					<li class="woocommerce-MyAccount-navigation-link woocommerce-MyAccount-navigation-link--<?php echo esc_attr( $key ); ?><?php echo $active ? ' is-active' : ''; ?>">
						<a href="<?php echo esc_url( $item[2] ); ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>><?php echo Nexora_Store::icon( $item[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $item[1] ); ?></span></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Logout link.
	 *
	 * @return string
	 */
	public static function logout_url() {
		return wp_nonce_url( Nexora_Store::url( 'account', 'customer-logout' ), 'nexora_logout' );
	}

	/**
	 * Current tab's content.
	 */
	private static function render_tab() {
		switch ( self::current_tab() ) {
			case 'orders':
				self::render_orders();
				break;
			case 'view-order':
				self::render_order( absint( get_query_var( 'view-order' ) ) );
				break;
			case 'coupons':
				Nexora_Toolkit_Coupon_Claims::account_tab();
				break;
			case 'points':
				Nexora_Toolkit_Points::account_tab();
				break;
			case 'edit-address':
				self::render_address();
				break;
			case 'edit-account':
				self::render_details();
				break;
			case 'wishlist':
				Nexora_Toolkit_Wishlist::account_tab();
				break;
			case 'quotations':
				Nexora_Toolkit_RFQ::account_tab();
				break;
			default:
				self::render_dashboard();
		}
	}

	/**
	 * Sign in and create account.
	 */
	private static function render_auth() {
		$register = (bool) Nexora_Store::get( 'registration' );
		// phpcs:disable WordPress.Security.NonceVerification -- only picks which tab to show.
		$action = isset( $_POST['nx_account_action'] ) ? sanitize_key( wp_unslash( $_POST['nx_account_action'] ) ) : '';
		$tab    = $register && ( 'register' === $action || ( isset( $_GET['action'] ) && 'register' === $_GET['action'] ) ) ? 'register' : 'login';
		// phpcs:enable
		$logo = has_custom_logo() ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : ( function_exists( 'nexora_brand_asset' ) ? nexora_brand_asset( 'logo-320.webp' ) : '' );
		$back = isset( $_REQUEST['redirect_to'] ) ? self::redirect_target() : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$req  = '&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text">' . esc_html__( 'Required', 'nexora-commerce-toolkit' ) . '</span>';
		?>
		<div class="nx-auth" id="customer_login" data-nexora-auth>
			<aside class="nx-auth__brand">
				<?php if ( $logo ) : ?>
					<span class="nx-auth__badge"><img src="<?php echo esc_url( $logo ); ?>" width="333" height="320" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></span>
				<?php endif; ?>
				<h2 class="nx-auth__headline"><?php esc_html_e( 'Freshness you can always trust', 'nexora-commerce-toolkit' ); ?></h2>
				<p class="nx-auth__lead"><?php esc_html_e( 'Meat, fish, vegetables, frozen goods and more, packed when you need them.', 'nexora-commerce-toolkit' ); ?></p>
				<ul class="nx-auth__perks">
					<li><?php echo Nexora_Store::icon( 'clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Track orders and pickup times', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php echo Nexora_Store::icon( 'pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Check out faster with saved details', 'nexora-commerce-toolkit' ); ?></li>
					<li><?php echo Nexora_Store::icon( 'clipboard', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Request quotes for bulk orders', 'nexora-commerce-toolkit' ); ?></li>
				</ul>
			</aside>
			<div class="nx-auth__card">
				<?php if ( $register ) : ?>
					<div class="nx-auth__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Your account', 'nexora-commerce-toolkit' ); ?>" hidden data-nexora-auth-tabs>
						<button type="button" role="tab" id="nx-tab-login" aria-controls="nx-panel-login" aria-selected="<?php echo 'login' === $tab ? 'true' : 'false'; ?>" data-nexora-tab="login"><?php esc_html_e( 'Sign in', 'nexora-commerce-toolkit' ); ?></button>
						<button type="button" role="tab" id="nx-tab-register" aria-controls="nx-panel-register" aria-selected="<?php echo 'register' === $tab ? 'true' : 'false'; ?>" data-nexora-tab="register"><?php esc_html_e( 'Create account', 'nexora-commerce-toolkit' ); ?></button>
					</div>
				<?php endif; ?>
				<div class="nx-auth__panel" id="nx-panel-login" <?php echo $register ? 'role="tabpanel" aria-labelledby="nx-tab-login"' : ''; ?> data-nexora-panel="login" <?php echo 'register' === $tab ? 'data-inactive' : ''; ?>>
					<h2 class="nx-auth__title"><?php esc_html_e( 'Welcome back', 'nexora-commerce-toolkit' ); ?></h2>
					<p class="nx-auth__sub"><?php esc_html_e( 'Sign in to see your orders and pickup times.', 'nexora-commerce-toolkit' ); ?></p>
					<form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>
						<input type="hidden" name="nx_account_action" value="login">
						<?php wp_nonce_field( 'nexora_account', '_nx_account' ); ?>
						<?php if ( $back ) : ?><input type="hidden" name="redirect_to" value="<?php echo esc_url( $back ); ?>"><?php endif; ?>
						<p class="form-row form-row-wide">
							<label for="username"><?php esc_html_e( 'Email or username', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
							<input type="text" class="woocommerce-Input input-text" name="username" id="username" autocomplete="username" value="<?php echo 'login' === $tab ? esc_attr( self::posted( 'username' ) ) : ''; ?>" required aria-required="true">
						</p>
						<p class="form-row form-row-wide">
							<label for="password"><?php esc_html_e( 'Password', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
							<input type="password" class="woocommerce-Input input-text" name="password" id="password" autocomplete="current-password" required aria-required="true">
						</p>
						<div class="nx-auth__row">
							<label class="woocommerce-form__label woocommerce-form__label-for-checkbox"><input type="checkbox" name="rememberme" value="forever"> <span><?php esc_html_e( 'Remember me', 'nexora-commerce-toolkit' ); ?></span></label>
							<a class="lost_password" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot your password?', 'nexora-commerce-toolkit' ); ?></a>
						</div>
						<p class="form-row nx-auth__submit"><button type="submit" class="woocommerce-button button"><?php esc_html_e( 'Sign in', 'nexora-commerce-toolkit' ); ?></button></p>
					</form>
					<?php if ( $register ) : ?>
						<p class="nx-auth__switch"><?php esc_html_e( 'New here?', 'nexora-commerce-toolkit' ); ?> <a href="#nx-panel-register" data-nexora-tab-link="register"><?php esc_html_e( 'Create an account', 'nexora-commerce-toolkit' ); ?></a></p>
					<?php endif; ?>
				</div>
				<?php if ( $register ) : ?>
					<div class="nx-auth__panel" id="nx-panel-register" role="tabpanel" aria-labelledby="nx-tab-register" data-nexora-panel="register" <?php echo 'login' === $tab ? 'data-inactive' : ''; ?>>
						<h2 class="nx-auth__title"><?php esc_html_e( 'Create your account', 'nexora-commerce-toolkit' ); ?></h2>
						<p class="nx-auth__sub"><?php esc_html_e( 'It only takes a minute. We use your details for your orders and pickups.', 'nexora-commerce-toolkit' ); ?></p>
						<?php echo Nexora_Toolkit_Rewards::signup_banner(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
						<form method="post" class="woocommerce-form woocommerce-form-register register">
							<input type="hidden" name="nx_account_action" value="register">
							<?php wp_nonce_field( 'nexora_account', '_nx_account' ); ?>
							<?php if ( $back ) : ?><input type="hidden" name="redirect_to" value="<?php echo esc_url( $back ); ?>"><?php endif; ?>
							<p class="nx-store-hp" aria-hidden="true"><label for="company_url"><?php esc_html_e( 'Leave this empty', 'nexora-commerce-toolkit' ); ?></label><input type="text" name="company_url" id="company_url" tabindex="-1" autocomplete="off"></p>
							<div class="nexora-register-names">
								<p class="form-row form-row-first">
									<label for="reg_first_name"><?php esc_html_e( 'First name', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
									<input type="text" class="woocommerce-Input input-text" name="nexora_first_name" id="reg_first_name" autocomplete="given-name" maxlength="60" value="<?php echo esc_attr( self::posted( 'nexora_first_name' ) ); ?>" required aria-required="true">
								</p>
								<p class="form-row form-row-last">
									<label for="reg_last_name"><?php esc_html_e( 'Last name', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
									<input type="text" class="woocommerce-Input input-text" name="nexora_last_name" id="reg_last_name" autocomplete="family-name" maxlength="60" value="<?php echo esc_attr( self::posted( 'nexora_last_name' ) ); ?>" required aria-required="true">
								</p>
							</div>
							<p class="form-row form-row-wide">
								<label for="reg_mobile"><?php esc_html_e( 'Mobile number', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
								<input type="tel" class="woocommerce-Input input-text" name="nexora_mobile" id="reg_mobile" autocomplete="tel" placeholder="09XX XXX XXXX" maxlength="20" value="<?php echo esc_attr( self::posted( 'nexora_mobile' ) ); ?>" required aria-required="true">
							</p>
							<p class="form-row form-row-wide">
								<label for="reg_email"><?php esc_html_e( 'Email address', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
								<input type="email" class="woocommerce-Input input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo esc_attr( self::posted( 'email' ) ); ?>" required aria-required="true">
							</p>
							<p class="form-row form-row-wide">
								<label for="reg_password"><?php esc_html_e( 'Password (8 characters or more)', 'nexora-commerce-toolkit' ); ?><?php echo $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
								<input type="password" class="woocommerce-Input input-text" name="password" id="reg_password" autocomplete="new-password" minlength="8" required aria-required="true">
							</p>
							<?php if ( Nexora_Toolkit_Consent::enabled() ) : ?>
								<?php Nexora_Toolkit_Consent::fields(); ?>
							<?php elseif ( get_privacy_policy_url() ) : ?>
								<?php /* translators: 1: opening link, 2: closing link */ ?>
								<p class="woocommerce-privacy-policy-text"><?php printf( esc_html__( 'Your personal data will be used to manage your account and for other purposes described in our %1$sprivacy policy%2$s.', 'nexora-commerce-toolkit' ), '<a href="' . esc_url( get_privacy_policy_url() ) . '">', '</a>' ); ?></p>
							<?php endif; ?>
							<?php Nexora_Toolkit_Captcha::widget(); ?>
							<p class="form-row nx-auth__submit"><button type="submit" class="woocommerce-button button"><?php esc_html_e( 'Create account', 'nexora-commerce-toolkit' ); ?></button></p>
						</form>
						<p class="nx-auth__switch"><?php esc_html_e( 'Already have an account?', 'nexora-commerce-toolkit' ); ?> <a href="#nx-panel-login" data-nexora-tab-link="login"><?php esc_html_e( 'Sign in', 'nexora-commerce-toolkit' ); ?></a></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Status pill.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function pill( $status ) {
		return '<span class="nx-status nx-status--' . esc_attr( $status ) . '">' . esc_html( Nexora_Store_Orders::status_label( $status ) ) . '</span>';
	}

	/**
	 * Dashboard.
	 */
	private static function render_dashboard() {
		$user   = wp_get_current_user();
		$name   = $user->first_name ? $user->first_name : $user->display_name;
		$hour   = (int) current_datetime()->format( 'G' );
		$greet  = $hour < 12 ? __( 'Good morning', 'nexora-commerce-toolkit' ) : ( $hour < 18 ? __( 'Good afternoon', 'nexora-commerce-toolkit' ) : __( 'Good evening', 'nexora-commerce-toolkit' ) );
		$recent = Nexora_Store_Orders::for_user( $user->ID, 4 );
		$active = Nexora_Store_Orders::for_user( $user->ID, 50, 0, Nexora_Store_Orders::active_statuses() );
		$total  = Nexora_Store_Orders::count_for_user( $user->ID );
		$spent  = Nexora_Store_Orders::spent_by_user( $user->ID );
		$rfq    = Nexora_Toolkit_Settings::get( 'rfq_enabled' );
		$wish   = Nexora_Toolkit_Settings::get( 'wishlist_enabled' );

		// Next collection: ready orders first, then the earliest scheduled.
		$next = null;
		$best = '';
		foreach ( $active as $o ) {
			if ( empty( $o->fulfilment['type'] ) ) {
				continue;
			}
			$rank = ( 'nx-ready' === $o->status ? '0' : '1' ) . ( $o->fulfilment_date ? $o->fulfilment_date : '9999' );
			if ( ! $next || $rank < $best ) {
				$next = $o;
				$best = $rank;
			}
		}
		// Buy again.
		$again = array();
		foreach ( $recent as $o ) {
			foreach ( Nexora_Store_Orders::items( $o->id ) as $line ) {
				$p = Nexora_Store_Product::get( $line->product_id );
				if ( $p && $p->is_purchasable() && $p->is_in_stock() && count( $again ) < 4 ) {
					$again[ $p->get_id() ] = $p;
				}
			}
		}
		?>
		<div class="nx-dash">
			<header class="nx-dash-hero">
				<div class="nx-dash-hero__text">
					<p class="nx-dash-hero__eyebrow"><?php echo esc_html( wp_date( 'l, F j' ) ); ?></p>
					<h2 class="nx-dash-hero__title"><?php echo esc_html( $greet . ', ' . $name ); ?></h2>
					<p class="nx-dash-hero__sub">
						<?php
						if ( $active ) {
							/* translators: %d: orders */
							echo esc_html( sprintf( _n( 'You have %d order in progress.', 'You have %d orders in progress.', count( $active ), 'nexora-commerce-toolkit' ), count( $active ) ) );
						} else {
							esc_html_e( 'Fresh meat, fish, vegetables and frozen goods are ready for your next order.', 'nexora-commerce-toolkit' );
						}
						?>
					</p>
				</div>
				<div class="nx-dash-hero__actions">
					<a class="nx-dash-btn nx-dash-btn--accent" href="<?php echo esc_url( Nexora_Store::shop_url() ); ?>"><?php echo Nexora_Store::icon( 'bag', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Shop the market', 'nexora-commerce-toolkit' ); ?></a>
					<?php if ( $rfq ) : ?>
						<a class="nx-dash-btn nx-dash-btn--line" href="<?php echo esc_url( Nexora_Toolkit_RFQ::url() ); ?>"><?php echo Nexora_Store::icon( 'clipboard', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Request a quote', 'nexora-commerce-toolkit' ); ?></a>
					<?php endif; ?>
				</div>
				<ul class="nx-dash-stats" aria-label="<?php esc_attr_e( 'Account summary', 'nexora-commerce-toolkit' ); ?>">
					<?php
					$stats = array(
						array( 'clock', count( $active ), __( 'In progress', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'orders' ) ),
						array( 'bag', $total, __( 'Total orders', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'orders' ) ),
					);
					if ( $rfq ) {
						$stats[] = array( 'clipboard', Nexora_Toolkit_RFQ::count_for_current_user(), __( 'Quotations', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'quotations' ) );
					}
					$stats[] = array( 'tag', Nexora_Toolkit_Coupon_Claims::ready_count( $user->ID ), __( 'Coupons ready', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Coupon_Claims::url() );
					if ( Nexora_Toolkit_Points::enabled() ) {
						$stats[] = array( 'star', Nexora_Toolkit_Points::balance( $user->ID ), __( 'Points', 'nexora-commerce-toolkit' ), Nexora_Toolkit_Points::url() );
					}
					if ( $wish ) {
						$stats[] = array( 'heart', count( Nexora_Toolkit_Wishlist::get_ids( $user->ID ) ), __( 'Saved items', 'nexora-commerce-toolkit' ), Nexora_Store::url( 'account', 'wishlist' ) );
					}
					foreach ( $stats as $s ) :
						?>
						<li class="nx-dash-stat"><a href="<?php echo esc_url( $s[3] ); ?>"><span class="nx-dash-stat__icon" aria-hidden="true"><?php echo Nexora_Store::icon( $s[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><strong><?php echo esc_html( number_format_i18n( $s[1] ) ); ?></strong><span><?php echo esc_html( $s[2] ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			</header>

			<?php Nexora_Toolkit_Consent::prompt(); ?>

			<?php echo Nexora_Toolkit_Rewards::reward_card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>

			<?php if ( $next ) : ?>
				<?php
				$type  = $next->fulfilment['type'];
				$ready = 'nx-ready' === $next->status;
				$title = 'pickup' === $type
					? ( $ready ? __( 'Ready to collect', 'nexora-commerce-toolkit' ) : __( 'Your next pickup', 'nexora-commerce-toolkit' ) )
					: ( 'courier' === $type ? ( $ready ? __( 'Packed: book your rider now', 'nexora-commerce-toolkit' ) : __( 'Your next rider collection', 'nexora-commerce-toolkit' ) ) : __( 'Your next delivery', 'nexora-commerce-toolkit' ) );
				$where = ! empty( $next->fulfilment['location'] ) ? $next->fulfilment['location'] : ( ! empty( $next->fulfilment['courier'] ) ? $next->fulfilment['courier'] : '' );
				$steps = array(
					array( __( 'Order placed', 'nexora-commerce-toolkit' ), 'done' ),
					array( __( 'Packing', 'nexora-commerce-toolkit' ), $ready ? 'done' : ( 'processing' === $next->status ? 'current' : 'todo' ) ),
					array( __( 'Ready', 'nexora-commerce-toolkit' ), $ready ? 'current' : 'todo' ),
					array( __( 'Collected', 'nexora-commerce-toolkit' ), 'todo' ),
				);
				?>
				<section class="nx-dash-next<?php echo $ready ? ' is-ready' : ''; ?>" aria-labelledby="nx-dash-next-title">
					<div class="nx-dash-next__head">
						<span class="nx-dash-next__icon" aria-hidden="true"><?php echo Nexora_Store::icon( 'pickup' === $type ? 'store' : ( 'courier' === $type ? 'bike' : 'truck' ), 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div>
							<h2 id="nx-dash-next-title" class="nx-dash-next__title"><?php echo esc_html( $title ); ?></h2>
							<p class="nx-dash-next__meta">
								<?php /* translators: %d: order */ ?>
								<a href="<?php echo esc_url( Nexora_Store::url( 'account', 'view-order/' . (int) $next->id ) ); ?>"><?php echo esc_html( sprintf( __( 'Order #%d', 'nexora-commerce-toolkit' ), $next->id ) ); ?></a>
								<?php if ( Nexora_Store_Orders::when( $next ) ) : ?><span><?php echo esc_html( Nexora_Store_Orders::when( $next ) ); ?></span><?php endif; ?>
								<?php if ( $where ) : ?><span><?php echo esc_html( $where ); ?></span><?php endif; ?>
							</p>
						</div>
					</div>
					<ol class="nx-dash-steps" aria-label="<?php esc_attr_e( 'Order progress', 'nexora-commerce-toolkit' ); ?>">
						<?php foreach ( $steps as $s ) : ?>
							<li class="is-<?php echo esc_attr( $s[1] ); ?>"<?php echo 'current' === $s[1] ? ' aria-current="step"' : ''; ?>><span><?php echo esc_html( $s[0] ); ?></span></li>
						<?php endforeach; ?>
					</ol>
					<?php if ( ! empty( $next->fulfilment['address'] ) ) : ?>
						<p class="nx-dash-next__address"><?php echo Nexora_Store::icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $next->fulfilment['address'] ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<div class="nx-dash-grid">
				<section class="nx-dash-panel nx-dash-orders" aria-labelledby="nx-dash-orders-title">
					<header class="nx-dash-panel__head">
						<h2 id="nx-dash-orders-title"><?php esc_html_e( 'Recent orders', 'nexora-commerce-toolkit' ); ?></h2>
						<?php if ( $recent ) : ?><a href="<?php echo esc_url( Nexora_Store::url( 'account', 'orders' ) ); ?>"><?php esc_html_e( 'View all', 'nexora-commerce-toolkit' ); ?></a><?php endif; ?>
					</header>
					<?php if ( $recent ) : ?>
						<ul class="nx-dash-orderlist"><?php foreach ( $recent as $o ) { echo self::order_row( $o ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, Generic.ControlStructures.InlineControlStructure ?></ul>
					<?php else : ?>
						<div class="nx-dash-empty">
							<span class="nx-dash-empty__icon" aria-hidden="true"><?php echo Nexora_Store::icon( 'leaf', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<p><strong><?php esc_html_e( 'No orders yet', 'nexora-commerce-toolkit' ); ?></strong></p>
							<p><?php esc_html_e( 'Your orders and pickup times will appear here after you check out.', 'nexora-commerce-toolkit' ); ?></p>
							<a class="nx-dash-btn nx-dash-btn--primary" href="<?php echo esc_url( Nexora_Store::shop_url() ); ?>"><?php esc_html_e( 'Browse products', 'nexora-commerce-toolkit' ); ?></a>
						</div>
					<?php endif; ?>
				</section>
				<div class="nx-dash-side">
					<?php Nexora_Toolkit_Coupon_Claims::dashboard_panel(); ?>
					<section class="nx-dash-panel" aria-labelledby="nx-dash-profile-title">
						<header class="nx-dash-panel__head">
							<h2 id="nx-dash-profile-title"><?php esc_html_e( 'Your details', 'nexora-commerce-toolkit' ); ?></h2>
							<a href="<?php echo esc_url( Nexora_Store::url( 'account', 'edit-account' ) ); ?>"><?php esc_html_e( 'Edit', 'nexora-commerce-toolkit' ); ?></a>
						</header>
						<ul class="nx-dash-details">
							<li><?php echo Nexora_Store::icon( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span><?php echo esc_html( $user->user_email ); ?></span></li>
							<?php if ( get_user_meta( $user->ID, 'billing_phone', true ) ) : ?>
								<li><?php echo Nexora_Store::icon( 'phone', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span><?php echo esc_html( (string) get_user_meta( $user->ID, 'billing_phone', true ) ); ?></span></li>
							<?php endif; ?>
							<li><?php echo Nexora_Store::icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( get_user_meta( $user->ID, 'billing_address_1', true ) ) : ?>
									<span><?php echo wp_kses( implode( '<br>', array_map( 'esc_html', array_filter( array( get_user_meta( $user->ID, 'billing_address_1', true ), get_user_meta( $user->ID, 'billing_address_2', true ), get_user_meta( $user->ID, 'billing_city', true ), get_user_meta( $user->ID, 'billing_state', true ), get_user_meta( $user->ID, 'billing_postcode', true ) ) ) ) ), array( 'br' => array() ) ); ?></span>
								<?php else : ?>
									<a href="<?php echo esc_url( Nexora_Store::url( 'account', 'edit-address' ) ); ?>"><?php esc_html_e( 'Add your address for a faster checkout', 'nexora-commerce-toolkit' ); ?></a>
								<?php endif; ?>
							</li>
						</ul>
						<?php /* translators: 1: name, 2: opening link, 3: closing link */ ?>
						<p class="nx-dash-notyou"><?php printf( esc_html__( 'Not %1$s? %2$sLog out%3$s', 'nexora-commerce-toolkit' ), esc_html( $user->display_name ), '<a href="' . esc_url( self::logout_url() ) . '">', '</a>' ); ?></p>
					</section>
					<?php if ( $spent > 0 ) : ?>
						<section class="nx-dash-panel nx-dash-spent">
							<p class="nx-dash-spent__label"><?php esc_html_e( 'Spent with us', 'nexora-commerce-toolkit' ); ?></p>
							<p class="nx-dash-spent__value"><?php echo Nexora_Store::price( $spent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
						</section>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $again ) : ?>
				<section class="nx-dash-panel" aria-labelledby="nx-dash-again-title">
					<header class="nx-dash-panel__head">
						<h2 id="nx-dash-again-title"><?php esc_html_e( 'Buy again', 'nexora-commerce-toolkit' ); ?></h2>
						<a href="<?php echo esc_url( Nexora_Store::shop_url() ); ?>"><?php esc_html_e( 'Shop all', 'nexora-commerce-toolkit' ); ?></a>
					</header>
					<ul class="nx-dash-again">
						<?php foreach ( $again as $p ) : ?>
							<li>
								<a class="nx-dash-again__item" href="<?php echo esc_url( $p->get_permalink() ); ?>">
									<?php echo $p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="nx-dash-again__name"><?php echo esc_html( $p->get_name() ); ?></span>
									<span class="nx-dash-again__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
								</a>
								<?php /* translators: %s: product */ ?>
								<a class="nx-dash-again__add" rel="nofollow" href="<?php echo esc_url( $p->add_to_cart_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Buy %s again', 'nexora-commerce-toolkit' ), $p->get_name() ) ); ?>"><?php echo esc_html( $p->is_type( 'simple' ) ? __( 'Add to cart', 'nexora-commerce-toolkit' ) : __( 'Choose options', 'nexora-commerce-toolkit' ) ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One order row.
	 *
	 * @param object $o Order.
	 * @return string
	 */
	private static function order_row( $o ) {
		$count = 0;
		foreach ( Nexora_Store_Orders::items( $o->id ) as $line ) {
			$count += (int) $line->qty;
		}
		/* translators: %d: order */
		$title = sprintf( __( 'Order #%d', 'nexora-commerce-toolkit' ), $o->id );
		/* translators: 1: date, 2: items */
		$sub = sprintf( _n( '%1$s · %2$d item', '%1$s · %2$d items', $count, 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ), strtotime( $o->created_at . ' UTC' ) ), $count );
		return '<li><a class="nx-dash-order" href="' . esc_url( Nexora_Store::url( 'account', 'view-order/' . (int) $o->id ) ) . '"><span class="nx-dash-order__main"><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( $sub ) . '</span></span>' . self::pill( $o->status ) . '<span class="nx-dash-order__total">' . Nexora_Store::price( $o->total ) . '</span></a></li>';
	}

	/**
	 * Orders list.
	 */
	private static function render_orders() {
		$user  = get_current_user_id();
		$page  = max( 1, absint( get_query_var( 'orders' ) ) );
		$per   = 10;
		$total = Nexora_Store_Orders::count_for_user( $user );
		$rows  = Nexora_Store_Orders::for_user( $user, $per, ( $page - 1 ) * $per );
		echo '<header class="nx-form__head"><h2>' . esc_html__( 'Your orders', 'nexora-commerce-toolkit' ) . '</h2></header>';
		if ( ! $rows ) {
			echo '<div class="nx-dash-empty"><p><strong>' . esc_html__( 'No orders yet', 'nexora-commerce-toolkit' ) . '</strong></p><a class="nx-dash-btn nx-dash-btn--primary" href="' . esc_url( Nexora_Store::shop_url() ) . '">' . esc_html__( 'Browse products', 'nexora-commerce-toolkit' ) . '</a></div>';
			return;
		}
		echo '<ul class="nx-dash-orderlist">';
		foreach ( $rows as $o ) {
			echo self::order_row( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		}
		echo '</ul>';
		if ( $total > $per ) {
			echo '<p class="nx-store-pager">';
			if ( $page > 1 ) {
				echo '<a class="button" href="' . esc_url( Nexora_Store::url( 'account', 'orders/' . ( $page - 1 ) ) ) . '">' . esc_html__( 'Newer', 'nexora-commerce-toolkit' ) . '</a> ';
			}
			if ( $page * $per < $total ) {
				echo '<a class="button" href="' . esc_url( Nexora_Store::url( 'account', 'orders/' . ( $page + 1 ) ) ) . '">' . esc_html__( 'Older', 'nexora-commerce-toolkit' ) . '</a>';
			}
			echo '</p>';
		}
	}

	/**
	 * One order. Customers can only open their own.
	 *
	 * @param int $id Order.
	 */
	private static function render_order( $id ) {
		$order = Nexora_Store_Orders::get( $id );
		if ( ! $order || (int) $order->user_id !== get_current_user_id() ) {
			echo '<div class="nx-dash-empty"><p><strong>' . esc_html__( 'We could not find that order.', 'nexora-commerce-toolkit' ) . '</strong></p></div>';
			return;
		}
		/* translators: 1: order, 2: date */
		echo '<header class="nx-form__head"><h2>' . esc_html( sprintf( __( 'Order #%1$d, %2$s', 'nexora-commerce-toolkit' ), $order->id, wp_date( get_option( 'date_format' ), strtotime( $order->created_at . ' UTC' ) ) ) ) . '</h2><p>' . self::pill( $order->status ) . '</p></header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Nexora_Store_Orders::fulfilment_card( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		echo '<section class="woocommerce-order-details"><h2>' . esc_html__( 'Order details', 'nexora-commerce-toolkit' ) . '</h2>' . Nexora_Store_Orders::details_table( $order ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$lines = Nexora_Store_Orders::address_lines( $order );
		if ( $lines ) {
			echo '<section class="nx-store-order-address"><h2>' . esc_html__( 'Address', 'nexora-commerce-toolkit' ) . '</h2><address>' . wp_kses( implode( '<br>', array_map( 'esc_html', $lines ) ), array( 'br' => array() ) ) . '</address></section>';
		}
	}

	/**
	 * Address form.
	 */
	private static function render_address() {
		$user = get_current_user_id();
		$v    = static function ( $key ) use ( $user ) {
			return (string) get_user_meta( $user, 'billing_' . $key, true );
		};
		$row  = static function ( $key, $label, $class, $autocomplete ) use ( $v ) {
			printf(
				'<p class="form-row %1$s"><label for="billing_%2$s">%3$s</label><input type="text" class="input-text" id="billing_%2$s" name="billing_%2$s" value="%4$s" autocomplete="%5$s"></p>',
				esc_attr( $class ),
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $v( $key ) ),
				esc_attr( $autocomplete )
			);
		};
		?>
		<form method="post" class="nx-form">
			<input type="hidden" name="nx_account_action" value="address">
			<?php wp_nonce_field( 'nexora_account', '_nx_account' ); ?>
			<header class="nx-form__head"><h2><?php esc_html_e( 'Your address', 'nexora-commerce-toolkit' ); ?></h2><p><?php esc_html_e( 'Used to fill in checkout. Store pickup orders do not need it.', 'nexora-commerce-toolkit' ); ?></p></header>
			<div class="nx-form__section">
				<div class="nx-form__grid">
					<?php
					$row( 'first_name', __( 'First name', 'nexora-commerce-toolkit' ), 'form-row-first', 'given-name' );
					$row( 'last_name', __( 'Last name', 'nexora-commerce-toolkit' ), 'form-row-last', 'family-name' );
					$row( 'phone', __( 'Mobile number', 'nexora-commerce-toolkit' ), 'form-row-wide', 'tel' );
					$row( 'address_1', __( 'Street address', 'nexora-commerce-toolkit' ), 'form-row-wide', 'address-line1' );
					$row( 'address_2', __( 'Barangay, unit or building', 'nexora-commerce-toolkit' ), 'form-row-wide', 'address-line2' );
					$row( 'city', __( 'City or town', 'nexora-commerce-toolkit' ), 'form-row-first', 'address-level2' );
					$row( 'state', __( 'Province', 'nexora-commerce-toolkit' ), 'form-row-last', 'address-level1' );
					$row( 'postcode', __( 'Postcode', 'nexora-commerce-toolkit' ), 'form-row-first', 'postal-code' );
					?>
				</div>
			</div>
			<div class="nx-form__actions"><button type="submit" class="button"><?php esc_html_e( 'Save address', 'nexora-commerce-toolkit' ); ?></button></div>
		</form>
		<?php
	}

	/**
	 * Profile form.
	 */
	private static function render_details() {
		$user = wp_get_current_user();
		?>
		<header class="nx-profile-head">
			<span class="nx-avatar nx-avatar--lg" aria-hidden="true"><?php echo esc_html( self::initials( $user ) ); ?></span>
			<div>
				<h2 class="nx-profile-head__name"><?php echo esc_html( $user->display_name ); ?></h2>
				<?php /* translators: %s: date */ ?>
				<p class="nx-profile-head__meta"><?php echo esc_html( $user->user_email ); ?> <span><?php echo esc_html( sprintf( __( 'Member since %s', 'nexora-commerce-toolkit' ), wp_date( get_option( 'date_format' ), strtotime( $user->user_registered . ' UTC' ) ) ) ); ?></span></p>
			</div>
		</header>
		<form method="post" class="nx-form">
			<input type="hidden" name="nx_account_action" value="details">
			<?php wp_nonce_field( 'nexora_account', '_nx_account' ); ?>
			<fieldset class="nx-form__section">
				<legend class="nx-form__legend"><span><?php esc_html_e( 'Personal details', 'nexora-commerce-toolkit' ); ?></span><small><?php esc_html_e( 'Used on your orders and receipts.', 'nexora-commerce-toolkit' ); ?></small></legend>
				<div class="nx-form__grid">
					<p class="form-row form-row-first"><label for="account_first_name"><?php esc_html_e( 'First name', 'nexora-commerce-toolkit' ); ?></label><input type="text" class="input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" aria-required="true"></p>
					<p class="form-row form-row-last"><label for="account_last_name"><?php esc_html_e( 'Last name', 'nexora-commerce-toolkit' ); ?></label><input type="text" class="input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" aria-required="true"></p>
					<p class="form-row form-row-wide"><label for="account_display_name"><?php esc_html_e( 'Display name', 'nexora-commerce-toolkit' ); ?></label><input type="text" class="input-text" name="account_display_name" id="account_display_name" value="<?php echo esc_attr( $user->display_name ); ?>"></p>
					<p class="form-row form-row-wide"><label for="account_email"><?php esc_html_e( 'Email address', 'nexora-commerce-toolkit' ); ?></label><input type="email" class="input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" aria-required="true"></p>
				</div>
			</fieldset>
			<fieldset class="nx-form__section">
				<legend class="nx-form__legend"><span><?php esc_html_e( 'Password', 'nexora-commerce-toolkit' ); ?></span><small><?php esc_html_e( 'Your current password is needed to change your email or password.', 'nexora-commerce-toolkit' ); ?></small></legend>
				<div class="nx-form__grid">
					<p class="form-row form-row-wide"><label for="password_current"><?php esc_html_e( 'Current password', 'nexora-commerce-toolkit' ); ?></label><input type="password" class="input-text" name="password_current" id="password_current" autocomplete="current-password"></p>
					<p class="form-row form-row-first"><label for="password_1"><?php esc_html_e( 'New password', 'nexora-commerce-toolkit' ); ?></label><input type="password" class="input-text" name="password_1" id="password_1" autocomplete="new-password"></p>
					<p class="form-row form-row-last"><label for="password_2"><?php esc_html_e( 'Confirm new password', 'nexora-commerce-toolkit' ); ?></label><input type="password" class="input-text" name="password_2" id="password_2" autocomplete="new-password"></p>
				</div>
			</fieldset>
			<div class="nx-form__actions"><button type="submit" class="button"><?php esc_html_e( 'Save changes', 'nexora-commerce-toolkit' ); ?></button></div>
		</form>
		<?php
		Nexora_Toolkit_Consent::marketing_form();
	}
}
