<?php
/**
 * One-time code by email after the password, for staff and customers.
 *
 * Every login form on the site (wp-login.php, WooCommerce and the built-in store) ends in wp_signon(),
 * so a single check on the authenticate filter covers them all. A correct password creates no session:
 * it starts a challenge tied to this browser by a cookie, and the session begins only after the code.
 *
 * The code is entered on its own storefront page, /verify-login/. The theme's login-code.php lays the
 * page out; templates/login-code.php is the plain fallback for other themes and the wp-admin login box.
 *
 * Application passwords are separate credentials and keep working. Password logins over XML-RPC or
 * other non-browser routes are refused, since they cannot enter a code.
 *
 * Emergency off switch in wp-config.php: define( 'NEXORA_DISABLE_LOGIN_OTP', true );
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login codes.
 */
class Nexora_Toolkit_Login_OTP {

	const ACTION     = 'nx_otp';
	const COOKIE     = 'nx_otp';
	const SLUG       = 'verify-login';
	const QUERY_VAR  = 'nx_login_code';
	const RULE       = '^verify-login/?$';
	const LIFETIME   = 1800; // Seconds a challenge lasts, including resends.
	const MAX_TRIES  = 5;    // Wrong codes before the password is needed again.
	const MAX_SENDS  = 5;    // Emails per challenge.
	const COOLDOWN   = 60;   // Seconds between emails.
	const HOURLY_CAP = 10;   // Emails per account per hour.

	/**
	 * What the page shows for this request.
	 *
	 * @var array
	 */
	private static $view = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'gate' ), 1000, 3 );
		add_action( 'login_form_' . self::ACTION, array( __CLASS__, 'old_address' ) );
		add_action( 'init', array( __CLASS__, 'rewrite' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'posts_pre_query', array( __CLASS__, 'skip_posts' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 ); // After the maintenance gate at 0.
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
	}

	/* ------------------------------------------------------------------ route */

	/**
	 * Register the page address.
	 */
	public static function rewrite() {
		add_rewrite_rule( self::RULE, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Save the address once, including after an update that adds it.
	 */
	public static function maybe_flush() {
		if ( self::SLUG !== get_option( 'nexora_otp_route' ) ) {
			update_option( 'nexora_otp_route', self::SLUG );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Allow the query variable.
	 *
	 * @param string[] $vars Variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * The page has no posts to load.
	 *
	 * @param array|null $posts Posts.
	 * @param WP_Query   $query Query.
	 * @return array|null
	 */
	public static function skip_posts( $posts, $query ) {
		return $query->is_main_query() && $query->get( self::QUERY_VAR ) ? array() : $posts;
	}

	/**
	 * Address of the page. Without a saved rewrite rule, as on plain permalinks, it uses a query string,
	 * so a missing rule can never leave people stuck on a 404 between the password and the code.
	 *
	 * @return string
	 */
	public static function url() {
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( is_array( $rules ) && isset( $rules[ self::RULE ] ) ) {
			return home_url( '/' . $wp_rewrite->root . self::SLUG . '/' );
		}
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Is this the code page?
	 *
	 * @return bool
	 */
	public static function is_request() {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Is this browser part-way through a sign-in on the code page? Maintenance mode lets these through,
	 * because staff need the page to get in. Anyone else on that address still gets the maintenance page.
	 *
	 * @return bool
	 */
	public static function in_progress() {
		return self::is_request() && (bool) self::load()['user'];
	}

	/**
	 * Old address of the screen: bookmarks and sign-ins started before an update.
	 */
	public static function old_address() {
		nocache_headers();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * This browser's challenge.
	 *
	 * @return array{token:string,challenge:array|false,user:WP_User|false}
	 */
	private static function load() {
		$token     = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$challenge = '' !== $token ? get_transient( self::key( $token ) ) : false;
		return array(
			'token'     => $token,
			'challenge' => $challenge,
			'user'      => is_array( $challenge ) ? get_userdata( $challenge['user'] ) : false,
		);
	}

	/**
	 * Staff are all roles other than customer and subscriber, so custom roles get the stricter treatment.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_staff( $user ) {
		return (bool) array_diff( (array) $user->roles, array( 'customer', 'subscriber' ) );
	}

	/**
	 * Does this user need a code?
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function required( $user ) {
		if ( defined( 'NEXORA_DISABLE_LOGIN_OTP' ) && NEXORA_DISABLE_LOGIN_OTP ) {
			return false;
		}
		$on = Nexora_Toolkit_Security::get( self::is_staff( $user ) ? 'otp_staff' : 'otp_customers' );
		/**
		 * Filter whether a user must enter an email code.
		 *
		 * @param bool    $required Required.
		 * @param WP_User $user     User.
		 */
		return (bool) apply_filters( 'nexora_login_otp_required', (bool) $on, $user );
	}

	/**
	 * New six-digit code.
	 *
	 * @return string
	 */
	public static function new_code() {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Keyed hash of a code, so the database never holds it.
	 *
	 * @param string $context Challenge token.
	 * @param string $code    Code.
	 * @return string
	 */
	public static function hash( $context, $code ) {
		return hash_hmac( 'sha256', $context . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * Email a code.
	 *
	 * @param WP_User $user    User.
	 * @param string  $code    Code.
	 * @param int     $minutes Minutes it lasts.
	 * @return bool
	 */
	public static function mail_code( $user, $code, $minutes ) {
		$m    = Nexora_Toolkit_Emails::compose( 'login_code', array( 'first_name' => $user->first_name ) );
		$body = $m['message']
			. '<p style="margin:18px 0;font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;">' . esc_html( $code ) . '</p>'
			/* translators: %d: minutes */
			. '<p>' . esc_html( sprintf( _n( 'It works once and expires in %d minute.', 'It works once and expires in %d minutes.', $minutes, 'nexora-commerce-toolkit' ), $minutes ) ) . '</p>';
		return Nexora_Store_Orders::send_mail( $user->user_email, $m['subject'], $m['heading'], $body );
	}

	/**
	 * Check a correct password. A browser login goes to the code screen and does not return.
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function gate( $user, $username, $password ) {
		// An empty password means an existing session cookie was checked, not a new sign-in.
		if ( ! $user instanceof WP_User || '' === (string) $password || ! self::required( $user ) ) {
			return $user;
		}
		if ( did_action( 'application_password_did_authenticate' ) ) {
			return $user;
		}
		global $pagenow;
		$api    = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || wp_doing_cron();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( $api || 'post' !== $method ) {
			return new WP_Error( 'nx_otp_browser', __( 'Please sign in on the website to receive your login code.', 'nexora-commerce-toolkit' ) );
		}
		return self::start( $user, 'wp-login.php' !== $pagenow );
	}

	/**
	 * Begin a challenge.
	 *
	 * @param WP_User $user  User.
	 * @param bool    $front Came from a storefront form.
	 * @return WP_Error Only when the email could not go out.
	 */
	private static function start( $user, $front ) {
		// phpcs:disable WordPress.Security.NonceVerification -- the login form verified its nonce; redirects are validated before use.
		$raw       = static function ( $key ) {
			return isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ? esc_url_raw( wp_unslash( $_REQUEST[ $key ] ) ) : '';
		};
		$requested = $raw( 'redirect_to' );
		$requested = '' !== $requested || ! $front ? $requested : $raw( 'redirect' );
		$referer   = wp_validate_redirect( (string) wp_get_raw_referer(), '' ); // Login forms post to their own page, which wp_get_referer() rejects.
		$token     = wp_generate_password( 48, false );
		$challenge = array(
			'user'      => (int) $user->ID,
			'remember'  => ! empty( $_POST['rememberme'] ),
			'front'     => $front,
			'requested' => $requested,
			'back'      => $front && $referer ? $referer : ( empty( $_REQUEST['interim-login'] ) ? wp_login_url() : add_query_arg( 'interim-login', '1', wp_login_url() ) ),
			'interim'   => ! empty( $_REQUEST['interim-login'] ),
			'tries'     => 0,
			'sends'     => 0,
			'last_sent' => 0,
			'expires'   => 0,
			'hash'      => '',
		);
		// phpcs:enable
		if ( ! self::send( $token, $challenge, $user ) ) {
			return new WP_Error( 'nx_otp_mail', __( 'We could not send your login code by email. Please try again in a few minutes.', 'nexora-commerce-toolkit' ) );
		}
		self::cookie( $token, time() + self::LIFETIME );
		nocache_headers();
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Make a code, email it and save the challenge.
	 *
	 * @param string  $token     Token.
	 * @param array   $challenge Challenge.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	private static function send( $token, $challenge, $user ) {
		$rate = 'nx_otp_rate_' . $user->ID;
		$sent = (int) get_transient( $rate );
		if ( $sent >= self::HOURLY_CAP ) {
			return false;
		}
		$code    = self::new_code();
		$minutes = (int) Nexora_Toolkit_Security::get( 'otp_minutes' );
		if ( ! self::mail_code( $user, $code, $minutes ) ) {
			return false;
		}
		set_transient( $rate, $sent + 1, HOUR_IN_SECONDS );
		$challenge['hash']      = self::hash( $token, $code );
		$challenge['expires']   = time() + $minutes * MINUTE_IN_SECONDS;
		$challenge['last_sent'] = time();
		++$challenge['sends'];
		self::save( $token, $challenge );
		return true;
	}

	/**
	 * Transient key of a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function key( $token ) {
		return 'nx_otp_' . hash( 'sha256', $token );
	}

	/**
	 * Save a challenge.
	 *
	 * @param string $token     Token.
	 * @param array  $challenge Challenge.
	 */
	private static function save( $token, $challenge ) {
		set_transient( self::key( $token ), $challenge, self::LIFETIME );
	}

	/**
	 * Set or clear the browser cookie.
	 *
	 * @param string $value   Value.
	 * @param int    $expires Expiry.
	 */
	private static function cookie( $value, $expires ) {
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/', // The page is under the home address, which can differ from the WordPress folder.
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Hide most of an address.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function mask( $email ) {
		$parts = explode( '@', $email, 2 );
		return substr( $parts[0], 0, 1 ) . '•••@' . ( isset( $parts[1] ) ? $parts[1] : '' );
	}

	/**
	 * Run the page before anything prints: check a posted code and work out what to show.
	 */
	public static function handle() {
		if ( ! self::is_request() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		send_frame_options_header();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );

		$current   = self::load();
		$token     = $current['token'];
		$challenge = $current['challenge'];
		$user      = $current['user'];
		if ( ! $user ) {
			if ( is_user_logged_in() ) { // Back button after signing in.
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			self::dead_end( self::sign_in_url(), __( 'Your sign-in has expired. Please sign in again.', 'nexora-commerce-toolkit' ) );
			return;
		}

		$error = '';
		$note  = '';
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['_nx_otp'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_nx_otp'] ) ), 'nx_otp' ) ) {
				$error = __( 'Your session expired. Please try again.', 'nexora-commerce-toolkit' );
			} elseif ( isset( $_POST['resend'] ) ) {
				list( $note, $error ) = self::resend( $token, $challenge, $user );
				$challenge            = get_transient( self::key( $token ) );
			} else {
				$code = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
				if ( time() > $challenge['expires'] ) {
					$error = __( 'That code has expired. Please send a new one.', 'nexora-commerce-toolkit' );
				} elseif ( hash_equals( $challenge['hash'], self::hash( $token, $code ) ) ) {
					self::complete( $token, $challenge, $user );
				} else {
					++$challenge['tries'];
					if ( $challenge['tries'] >= self::MAX_TRIES ) {
						delete_transient( self::key( $token ) );
						self::cookie( '', time() - YEAR_IN_SECONDS );
						self::dead_end( $challenge['back'], __( 'Too many wrong codes. Please sign in again.', 'nexora-commerce-toolkit' ), $challenge['interim'] );
						return;
					}
					self::save( $token, $challenge );
					$error = __( 'That code is not correct. Please check it and try again.', 'nexora-commerce-toolkit' );
				}
			}
		}

		self::$view = array(
			'dead'    => false,
			'error'   => $error,
			'note'    => $note,
			'email'   => self::mask( $user->user_email ),
			'back'    => $challenge['back'],
			'interim' => ! empty( $challenge['interim'] ),
		);
	}

	/**
	 * Show the theme's page, or the plain one. The wp-admin login box is too small for a full page.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function template( $template ) {
		if ( ! self::is_request() ) {
			return $template;
		}
		$theme = empty( self::$view['interim'] ) ? locate_template( 'login-code.php' ) : '';
		return $theme ? $theme : NEXORA_TOOLKIT_DIR . 'templates/login-code.php';
	}

	/**
	 * Browser tab title.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public static function document_title( $parts ) {
		if ( self::is_request() ) {
			$parts['title'] = __( 'Enter your code', 'nexora-commerce-toolkit' );
		}
		return $parts;
	}

	/**
	 * Body class of the page.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		$classes[] = 'nx-login-code';
		return $classes;
	}

	/**
	 * Heading for the page's template.
	 *
	 * @return string
	 */
	public static function title() {
		return empty( self::$view['dead'] ) ? __( 'Check your email', 'nexora-commerce-toolkit' ) : __( 'Please sign in again', 'nexora-commerce-toolkit' );
	}

	/**
	 * Print the messages, code form and links, for the page's template.
	 */
	public static function render() {
		$view = self::$view;
		$notices = '';
		if ( ! empty( $view['error'] ) ) {
			$notices .= '<p class="nx-otp__notice is-error" id="nx-otp-error" role="alert">' . esc_html( $view['error'] ) . '</p>';
		}
		if ( ! empty( $view['note'] ) ) {
			$notices .= '<p class="nx-otp__notice is-note" role="status">' . esc_html( $view['note'] ) . '</p>';
		}
		if ( empty( $view['dead'] ) ) :
			$action = self::url();
			?>
			<?php /* translators: %s: partly hidden email address */ ?>
			<p class="nx-auth__sub nx-otp__sub"><?php echo esc_html( sprintf( __( 'We sent a 6-digit code to %s.', 'nexora-commerce-toolkit' ), $view['email'] ) ); ?></p>
			<?php echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			<form method="post" action="<?php echo esc_url( $action ); ?>" id="nx-otp-form" class="nx-otp__form">
				<?php wp_nonce_field( 'nx_otp', '_nx_otp', false ); ?>
				<p class="form-row">
					<label for="nx-otp-code"><?php esc_html_e( 'Login code', 'nexora-commerce-toolkit' ); ?></label>
					<input type="text" class="input-text nx-otp__code" id="nx-otp-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus<?php echo ! empty( $view['error'] ) ? ' aria-invalid="true" aria-describedby="nx-otp-error"' : ''; ?>>
				</p>
				<p class="form-row nx-auth__submit"><button type="submit" class="button"><?php esc_html_e( 'Verify and sign in', 'nexora-commerce-toolkit' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( $action ); ?>" class="nx-otp__resend">
				<?php wp_nonce_field( 'nx_otp', '_nx_otp', false ); ?>
				<button type="submit" name="resend" value="1"><?php esc_html_e( 'Send a new code', 'nexora-commerce-toolkit' ); ?></button>
			</form>
			<?php
		else :
			echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		endif;
		?>
		<p class="nx-otp__back"><a href="<?php echo esc_url( $view['back'] ); ?>"><?php esc_html_e( 'Back to sign in', 'nexora-commerce-toolkit' ); ?></a></p>
		<?php
	}

	/**
	 * Send another code, within limits.
	 *
	 * @param string  $token     Token.
	 * @param array   $challenge Challenge.
	 * @param WP_User $user      User.
	 * @return array{0:string,1:string} Note and error.
	 */
	private static function resend( $token, $challenge, $user ) {
		$wait = (int) $challenge['last_sent'] + self::COOLDOWN - time();
		if ( $wait > 0 ) {
			/* translators: %d: seconds */
			return array( '', sprintf( _n( 'Please wait %d second before asking for a new code.', 'Please wait %d seconds before asking for a new code.', $wait, 'nexora-commerce-toolkit' ), $wait ) );
		}
		if ( $challenge['sends'] >= self::MAX_SENDS || ! self::send( $token, $challenge, $user ) ) {
			return array( '', __( 'We could not send another code right now. Please try again later.', 'nexora-commerce-toolkit' ) );
		}
		return array( __( 'A new code is on its way. Earlier codes no longer work.', 'nexora-commerce-toolkit' ), '' );
	}

	/**
	 * Right code: start the session and carry on to where the login form would have gone.
	 *
	 * @param string  $token     Token.
	 * @param array   $challenge Challenge.
	 * @param WP_User $user      User.
	 */
	private static function complete( $token, $challenge, $user ) {
		delete_transient( self::key( $token ) );
		self::cookie( '', time() - YEAR_IN_SECONDS );
		wp_set_auth_cookie( $user->ID, $challenge['remember'], is_ssl() );
		wp_set_current_user( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );

		if ( $challenge['interim'] ) { // The "session expired" box inside wp-admin closes itself on this class.
			echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8"><title>' . esc_html__( 'You have logged in successfully.', 'nexora-commerce-toolkit' ) . '</title></head><body class="interim-login-success"><p>' . esc_html__( 'You have logged in successfully.', 'nexora-commerce-toolkit' ) . '</p></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built attributes.
			exit;
		}

		$requested = (string) $challenge['requested'];
		$fallback  = $challenge['front'] ? $challenge['back'] : admin_url();
		$target    = apply_filters( 'login_redirect', '' !== $requested ? $requested : $fallback, $requested, $user );
		if ( $challenge['front'] ) {
			$target = apply_filters( 'woocommerce_login_redirect', $target, $user );
		}
		wp_safe_redirect( wp_validate_redirect( $target, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Where someone with no sign-in in progress should go.
	 *
	 * @return string
	 */
	private static function sign_in_url() {
		if ( Nexora_Store::active() ) {
			return Nexora_Store::url( 'account' );
		}
		return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
	}

	/**
	 * Nothing more can be done here: the page shows the message and a link back.
	 *
	 * @param string $back    Link back.
	 * @param string $message Message.
	 * @param bool   $interim Inside the wp-admin login box.
	 */
	private static function dead_end( $back, $message, $interim = false ) {
		self::$view = array(
			'dead'    => true,
			'error'   => $message,
			'back'    => $back,
			'interim' => $interim,
		);
	}
}
