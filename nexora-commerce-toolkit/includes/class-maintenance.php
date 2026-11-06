<?php
/**
 * Maintenance mode with temporary access passes.
 *
 * - Whole storefront or shop-only maintenance, served as HTTP 503 + Retry-After + noindex.
 * - Purchases are disabled for everyone without access (including the Store API used by blocks).
 * - Staff roles bypass it. Anyone else can be given a time-limited access pass: a link
 *   (?nexora_access=CODE) or a code typed on the maintenance page. Codes are stored only as
 *   hashes; access is kept in a signed, HttpOnly cookie that expires with the pass.
 * - Payment gateway callbacks (wc-api), wp-admin, login, cron and WP-CLI are never blocked.
 *
 * Settings → Freshtastic Maintenance, or `wp nexora maintenance`.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance module.
 */
class Nexora_Toolkit_Maintenance {

	const OPTION = 'nexora_maintenance';
	const GRANTS = 'nexora_maintenance_grants';
	const COOKIE = 'nexora_preview';
	const PARAM  = 'nexora_access';

	/**
	 * Grant validated for this request (array) or false.
	 *
	 * @var array|false|null
	 */
	private static $grant = null;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_nexora_maint_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_nexora_maint_grant', array( __CLASS__, 'handle_grant' ) );
		add_action( 'admin_post_nexora_maint_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'purge_caches' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );

		add_action( 'init', array( __CLASS__, 'handle_access_link' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_rest' ), 10, 3 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 99 );
		add_filter( 'nexora_store_purchasable', array( __CLASS__, 'purchasable' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'preview_bar' ), 99 );
	}

	/* ------------------------------------------------------------- settings */

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'      => false,
			'scope'        => 'site',
			'headline'     => __( 'We’re restocking the market', 'nexora-commerce-toolkit' ),
			'message'      => __( 'Our store is closed for scheduled maintenance while we update prices and stock. Please check back soon — existing orders will be delivered as scheduled.', 'nexora-commerce-toolkit' ),
			'return_at'    => '',
			'auto_off'     => true,
			'contact'      => '',
			'bypass_roles' => array( 'administrator', 'shop_manager' ),
			'code_form'    => true,
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Expected return time as a timestamp, or null.
	 *
	 * @return int|null
	 */
	public static function return_ts() {
		$value = self::settings()['return_at'];
		if ( ! $value ) {
			return null;
		}
		$date = date_create_immutable_from_format( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $date ? $date->getTimestamp() : null;
	}

	/**
	 * Whether maintenance mode is in effect right now.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		$ts = self::return_ts();
		return ! ( $s['auto_off'] && $ts && time() >= $ts );
	}

	/**
	 * Register the option.
	 */
	public static function register_setting() {
		register_setting( 'nexora_maintenance', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => self::defaults() ) );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $in Input.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$in    = is_array( $in ) ? $in : array();
		$roles = array_keys( wp_roles()->roles );
		$out   = array(
			'enabled'      => ! empty( $in['enabled'] ),
			'scope'        => isset( $in['scope'] ) && 'shop' === $in['scope'] ? 'shop' : 'site',
			'headline'     => isset( $in['headline'] ) ? sanitize_text_field( wp_unslash( $in['headline'] ) ) : '',
			'message'      => isset( $in['message'] ) ? sanitize_textarea_field( wp_unslash( $in['message'] ) ) : '',
			'return_at'    => isset( $in['return_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $in['return_at'] ) ? $in['return_at'] : '',
			'auto_off'     => ! empty( $in['auto_off'] ),
			'contact'      => isset( $in['contact'] ) ? sanitize_text_field( wp_unslash( $in['contact'] ) ) : '',
			'bypass_roles' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( isset( $in['bypass_roles'] ) ? $in['bypass_roles'] : array() ) ), $roles ) ),
			'code_form'    => ! empty( $in['code_form'] ),
		);
		if ( ! in_array( 'administrator', $out['bypass_roles'], true ) ) {
			$out['bypass_roles'][] = 'administrator'; // Never lock administrators out.
		}
		return $out;
	}

	/* ----------------------------------------------------------- access passes */

	/**
	 * All passes: id => [label, hash, created, expires, uses, last_used, revoked].
	 *
	 * @return array
	 */
	public static function grants() {
		return (array) get_option( self::GRANTS, array() );
	}

	/**
	 * Hash an access code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function hash( $code ) {
		return hash_hmac( 'sha256', self::normalize( $code ), wp_salt( 'nonce' ) );
	}

	/**
	 * Normalize a typed code (case, spaces, dashes).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function normalize( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
	}

	/**
	 * Create a pass. Returns the plain code (shown once) and the pass.
	 *
	 * @param string $label   Label.
	 * @param int    $seconds Lifetime.
	 * @return array{code:string,id:string,grant:array}
	 */
	public static function create_grant( $label, $seconds ) {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code     = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		$id     = wp_generate_password( 12, false );
		$grants = self::grants();
		// Drop passes that ended more than 30 days ago.
		foreach ( $grants as $gid => $g ) {
			if ( (int) $g['expires'] < time() - 30 * DAY_IN_SECONDS ) {
				unset( $grants[ $gid ] );
			}
		}
		$grant         = array(
			'label'     => $label ? $label : __( 'Preview access', 'nexora-commerce-toolkit' ),
			'hash'      => self::hash( $code ),
			'created'   => time(),
			'expires'   => time() + max( 300, (int) $seconds ),
			'uses'      => 0,
			'last_used' => 0,
			'revoked'   => false,
		);
		$grants[ $id ] = $grant;
		update_option( self::GRANTS, $grants, false );
		return array( 'code' => substr( $code, 0, 5 ) . '-' . substr( $code, 5 ), 'id' => $id, 'grant' => $grant );
	}

	/**
	 * Revoke a pass.
	 *
	 * @param string $id Pass ID.
	 * @return bool
	 */
	public static function revoke( $id ) {
		$grants = self::grants();
		if ( ! isset( $grants[ $id ] ) ) {
			return false;
		}
		$grants[ $id ]['revoked'] = true;
		update_option( self::GRANTS, $grants, false );
		return true;
	}

	/**
	 * Whether a pass is currently valid.
	 *
	 * @param array|null $g Pass.
	 * @return bool
	 */
	private static function grant_valid( $g ) {
		return is_array( $g ) && empty( $g['revoked'] ) && (int) $g['expires'] > time();
	}

	/**
	 * Find a valid pass by its plain code.
	 *
	 * @param string $code Code.
	 * @return string|null Pass ID.
	 */
	private static function find_by_code( $code ) {
		if ( strlen( self::normalize( $code ) ) !== 10 ) {
			return null;
		}
		$hash = self::hash( $code );
		foreach ( self::grants() as $id => $g ) {
			if ( self::grant_valid( $g ) && hash_equals( $g['hash'], $hash ) ) {
				return (string) $id;
			}
		}
		return null;
	}

	/**
	 * Cookie signature.
	 *
	 * @param string $id  Pass ID.
	 * @param int    $exp Expiry.
	 * @return string
	 */
	private static function sign( $id, $exp ) {
		return hash_hmac( 'sha256', $id . '|' . $exp, wp_salt( 'auth' ) );
	}

	/**
	 * Set or clear the access cookie.
	 *
	 * @param string|null $id  Pass ID, or null to clear.
	 * @param int         $exp Expiry.
	 */
	private static function set_cookie( $id, $exp = 0 ) {
		$value = $id ? $id . '|' . $exp . '|' . self::sign( $id, $exp ) : '';
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $id ? $exp : time() - YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Pass granted to this visitor via cookie, or false.
	 *
	 * @return array|false
	 */
	public static function current_grant() {
		if ( null !== self::$grant ) {
			return self::$grant;
		}
		self::$grant = false;
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}
		$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		if ( 3 !== count( $parts ) || ! hash_equals( self::sign( $parts[0], (int) $parts[1] ), $parts[2] ) ) {
			return false;
		}
		$grants = self::grants();
		if ( isset( $grants[ $parts[0] ] ) && self::grant_valid( $grants[ $parts[0] ] ) ) {
			self::$grant = $grants[ $parts[0] ] + array( 'id' => $parts[0] );
		}
		return self::$grant;
	}

	/**
	 * Whether the current visitor bypasses maintenance.
	 *
	 * @return bool
	 */
	public static function can_bypass() {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( user_can( $user, 'manage_options' ) || array_intersect( (array) $user->roles, self::settings()['bypass_roles'] ) ) {
				return true;
			}
		}
		return (bool) self::current_grant();
	}

	/**
	 * Record a use of a pass (at most once per hour per pass).
	 *
	 * @param string $id Pass ID.
	 */
	private static function touch( $id ) {
		$grants = self::grants();
		if ( isset( $grants[ $id ] ) ) {
			$grants[ $id ]['uses']      = (int) $grants[ $id ]['uses'] + 1;
			$grants[ $id ]['last_used'] = time();
			update_option( self::GRANTS, $grants, false );
		}
	}

	/**
	 * Rate-limit code attempts per IP: 8 per 10 minutes.
	 *
	 * @return bool
	 */
	private static function attempt_allowed() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'nexora_maint_try_' . substr( wp_hash( $ip ), 0, 20 );
		$n   = (int) get_transient( $key );
		if ( $n >= 8 ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Handle ?nexora_access=CODE links, the code form and "End preview".
	 */
	public static function handle_access_link() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- access codes are themselves the secret; the form also carries a nonce checked below.
		if ( isset( $_GET['nexora_end_preview'] ) ) {
			self::set_cookie( null );
			wp_safe_redirect( remove_query_arg( 'nexora_end_preview' ) );
			exit;
		}
		$code = '';
		if ( isset( $_GET[ self::PARAM ] ) ) {
			$code = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		} elseif ( isset( $_POST['nexora_access_code'] ) ) {
			if ( ! isset( $_POST['_nexora_access_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_nexora_access_nonce'] ), 'nexora_access' ) ) {
				return;
			}
			$code = sanitize_text_field( wp_unslash( $_POST['nexora_access_code'] ) );
		}
		// phpcs:enable
		if ( '' === $code ) {
			return;
		}
		$id = self::attempt_allowed() ? self::find_by_code( $code ) : null;
		if ( $id ) {
			$grants = self::grants();
			self::set_cookie( $id, (int) $grants[ $id ]['expires'] );
			self::touch( $id );
			wp_safe_redirect( remove_query_arg( self::PARAM ) );
			exit;
		}
		// Invalid or expired: show the maintenance page with an error (when maintenance is on).
		$GLOBALS['nexora_access_error'] = true;
	}

	/* ----------------------------------------------------------------- gate */

	/**
	 * Whether the current front-end request is covered by the configured scope.
	 *
	 * @return bool
	 */
	private static function in_scope() {
		if ( 'shop' !== self::settings()['scope'] ) {
			return true;
		}
		if ( Nexora_Store::active() ) {
			return Nexora_Store::is_store_request();
		}
		return function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
	}

	/**
	 * Serve the maintenance page when needed.
	 */
	public static function gate() {
		if ( ! self::is_active() || self::can_bypass() || ! self::in_scope() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- payment gateway callbacks must keep working.
		if ( isset( $_GET['wc-api'] ) || is_robots() || is_favicon() ) {
			return;
		}
		self::render_page();
	}

	/**
	 * Block the WooCommerce Store API (cart/checkout blocks) and toolkit write endpoints for visitors without access.
	 *
	 * @param mixed           $result  Result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function gate_rest( $result, $server, $request ) {
		if ( null !== $result || ! self::is_active() || self::can_bypass() ) {
			return $result;
		}
		$route = $request->get_route();
		$block = 0 === strpos( $route, '/wc/store' ) || 0 === strpos( $route, '/nexora/v1/store' );
		// With the whole storefront closed, lead forms are closed too; in shop-only mode they stay open.
		if ( 'site' === self::settings()['scope'] && ( 0 === strpos( $route, '/nexora/v1/rfq' ) || 0 === strpos( $route, '/nexora/v1/subscribe' ) ) ) {
			$block = true;
		}
		if ( $block ) {
			return new WP_Error( 'nexora_maintenance', __( 'The store is in maintenance mode. Please try again later.', 'nexora-commerce-toolkit' ), array( 'status' => 503 ) );
		}
		return $result;
	}

	/**
	 * No purchases while maintenance is active (for visitors without access).
	 *
	 * @param bool $purchasable Purchasable.
	 * @return bool
	 */
	public static function purchasable( $purchasable ) {
		if ( $purchasable && self::is_active() && ! is_admin() && ! self::can_bypass() ) {
			return false;
		}
		return $purchasable;
	}

	/**
	 * Output the maintenance page (HTTP 503) and stop.
	 */
	public static function render_page() {
		$s       = self::settings();
		$ts      = self::return_ts();
		$name    = get_bloginfo( 'name' );
		$primary = sanitize_hex_color( (string) get_theme_mod( 'nexora_color_primary', '#12306E' ) );
		$primary = $primary ? $primary : '#12306E';
		$accent  = sanitize_hex_color( (string) get_theme_mod( 'nexora_color_secondary', '#F0762B' ) );
		$accent  = $accent ? $accent : '#F0762B';
		$retry   = $ts && $ts > time() ? $ts - time() : HOUR_IN_SECONDS;
		$error   = ! empty( $GLOBALS['nexora_access_error'] );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Tell page-cache plugins not to store this response.
		}
		nocache_headers();
		status_header( 503 );
		header( 'Retry-After: ' . (int) $retry );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $s['headline'] . ' — ' . $name ); ?></title>
<?php if ( has_site_icon() ) : ?>
	<link rel="icon" href="<?php echo esc_url( get_site_icon_url( 64 ) ); ?>">
<?php endif; ?>
<style>
	:root { --p: <?php echo esc_attr( $primary ); ?>; --a: <?php echo esc_attr( $accent ); ?>; }
	* { box-sizing: border-box; }
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: radial-gradient(60% 60% at 85% 10%, color-mix(in srgb, var(--p) 35%, transparent), transparent 70%), #0B1220; color: #E2E8F0; font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
	main { width: 100%; max-width: 620px; padding: clamp(24px, 5vw, 44px); border-radius: 22px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1); box-shadow: 0 30px 60px -30px rgba(0,0,0,.6); }
	.brand { display: flex; align-items: center; gap: 12px; margin: 0 0 28px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #fff; }
	.brand img { max-height: 40px; width: auto; }
	.status { display: inline-flex; align-items: center; gap: 8px; margin: 0 0 14px; padding: 5px 12px; border-radius: 999px; background: rgba(245,158,11,.14); color: #FCD34D; font-size: 13px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
	.status::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--a); }
	h1 { margin: 0 0 12px; font-size: clamp(28px, 5vw, 40px); line-height: 1.1; letter-spacing: -.02em; color: #fff; }
	p { margin: 0 0 16px; color: #CBD5E1; }
	.back { padding: 14px 16px; border-radius: 14px; background: rgba(255,255,255,.05); color: #fff; }
	.back strong { color: #FCD34D; }
	form { margin-top: 26px; padding-top: 22px; border-top: 1px solid rgba(255,255,255,.1); }
	label { display: block; margin-bottom: 8px; font-weight: 600; color: #fff; font-size: 15px; }
	.row { display: flex; gap: 8px; flex-wrap: wrap; }
	input[type=text] { flex: 1; min-width: 180px; min-height: 48px; padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.06); color: #fff; font: inherit; letter-spacing: .12em; text-transform: uppercase; }
	input[type=text]:focus { outline: 2px solid var(--a); outline-offset: 2px; }
	button { min-height: 48px; padding: 10px 22px; border: 0; border-radius: 999px; background: var(--a); color: #0B1220; font: 700 15px/1 inherit; cursor: pointer; }
	button:focus-visible, a:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
	.err { color: #FCA5A5; font-weight: 600; }
	.foot { margin: 26px 0 0; font-size: 14px; color: #94A3B8; }
	a { color: #FCD34D; }
	@media (prefers-reduced-motion: no-preference) { main { animation: in .5s ease both; } @keyframes in { from { opacity: 0; transform: translateY(8px); } } }
</style>
</head>
<body>
<main>
	<p class="brand">
		<?php
		$logo = (int) get_theme_mod( 'custom_logo' );
		echo $logo ? wp_get_attachment_image( $logo, 'medium', false, array( 'alt' => $name ) ) : esc_html( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
		?>
	</p>
	<p class="status"><?php echo 'shop' === $s['scope'] ? esc_html__( 'Shop under maintenance', 'nexora-commerce-toolkit' ) : esc_html__( 'Under maintenance', 'nexora-commerce-toolkit' ); ?></p>
	<h1><?php echo esc_html( $s['headline'] ); ?></h1>
	<p><?php echo nl2br( esc_html( $s['message'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<?php if ( $ts && $ts > time() ) : ?>
		<p class="back">
			<?php
			/* translators: %s: date and time */
			printf( esc_html__( 'We expect to be back by %s.', 'nexora-commerce-toolkit' ), '<strong><time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( wp_date( 'D, M j, g:i A', $ts ) ) . '</time></strong>' );
			?>
		</p>
	<?php endif; ?>
	<?php if ( $s['contact'] ) : ?>
		<p>
			<?php
			/* translators: %s: contact details */
			echo esc_html( sprintf( __( 'Need help with an order? %s', 'nexora-commerce-toolkit' ), $s['contact'] ) );
			?>
		</p>
	<?php endif; ?>
	<?php if ( 'shop' === $s['scope'] ) : ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Visit our homepage and journal', 'nexora-commerce-toolkit' ); ?></a></p>
	<?php endif; ?>

	<?php if ( $s['code_form'] ) : ?>
		<form method="post">
			<?php wp_nonce_field( 'nexora_access', '_nexora_access_nonce' ); ?>
			<label for="nexora-access-code"><?php esc_html_e( 'Have a preview access code?', 'nexora-commerce-toolkit' ); ?></label>
			<?php if ( $error ) : ?>
				<p class="err" id="nexora-access-error" role="alert"><?php esc_html_e( 'That code is not valid or has expired. Check it and try again.', 'nexora-commerce-toolkit' ); ?></p>
			<?php endif; ?>
			<div class="row">
				<input type="text" id="nexora-access-code" name="nexora_access_code" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXXX-XXXXX" required <?php echo $error ? 'aria-invalid="true" aria-describedby="nexora-access-error"' : ''; ?>>
				<button type="submit"><?php esc_html_e( 'Enter site', 'nexora-commerce-toolkit' ); ?></button>
			</div>
		</form>
	<?php endif; ?>
	<p class="foot">
		<?php echo esc_html( '© ' . wp_date( 'Y' ) . ' ' . $name ); ?> ·
		<a href="<?php echo esc_url( wp_login_url( home_url( add_query_arg( null, null ) ) ) ); ?>"><?php esc_html_e( 'Staff login', 'nexora-commerce-toolkit' ); ?></a>
	</p>
</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Preview bar for visitors using an access pass.
	 */
	public static function preview_bar() {
		if ( ! self::is_active() ) {
			return;
		}
		$grant = self::current_grant();
		// Shop-only maintenance: tell public visitors on pages that stay open.
		if ( ! $grant && ! self::can_bypass() && 'shop' === self::settings()['scope'] ) {
			$ts = self::return_ts();
			printf(
				'<div role="status" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;padding:12px 16px;border-radius:12px;background:#0B1220;color:#F1F5F9;font:14px/1.5 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35)"><strong style="color:#FCD34D">%1$s</strong> %2$s</div>',
				esc_html__( 'Shop temporarily closed:', 'nexora-commerce-toolkit' ),
				esc_html(
					$ts && $ts > time()
						/* translators: %s: date and time */
						? sprintf( __( 'online ordering is paused for maintenance and will reopen by %s.', 'nexora-commerce-toolkit' ), wp_date( 'D, M j, g:i A', $ts ) )
						: __( 'online ordering is paused for maintenance. Please check back soon.', 'nexora-commerce-toolkit' )
				)
			);
			return;
		}
		if ( ! $grant || is_user_logged_in() ) {
			return;
		}
		printf(
			'<div role="region" aria-label="%1$s" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:12px;background:#0B1220;color:#F1F5F9;font:14px/1.4 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35)"><span><strong style="color:#FCD34D">%2$s</strong> %3$s</span><a href="%4$s" style="color:#0B1220;background:#F59E0B;padding:6px 14px;border-radius:999px;font-weight:700;text-decoration:none">%5$s</a></div>',
			esc_attr__( 'Preview access', 'nexora-commerce-toolkit' ),
			esc_html__( 'Preview mode:', 'nexora-commerce-toolkit' ),
			/* translators: %s: date and time */
			esc_html( sprintf( __( 'the store is in maintenance; you have access until %s. Purchases stay disabled for the public.', 'nexora-commerce-toolkit' ), wp_date( 'M j, g:i A', (int) $grant['expires'] ) ) ),
			esc_url( add_query_arg( 'nexora_end_preview', '1' ) ),
			esc_html__( 'End preview', 'nexora-commerce-toolkit' )
		);
	}

	/**
	 * Admin bar indicator for staff.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! self::is_active() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'nexora-maintenance',
				'title' => '<span style="background:#DC2626;color:#fff;padding:2px 8px;border-radius:4px;font-weight:600">' . esc_html__( 'Maintenance ON', 'nexora-commerce-toolkit' ) . '</span>',
				'href'  => admin_url( 'options-general.php?page=nexora-maintenance' ),
			)
		);
	}

	/**
	 * Purge common page caches when maintenance settings change.
	 */
	public static function purge_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'litespeed_purge_all' );
		do_action( 'nexora_maintenance_changed', self::settings() );
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * Menu.
	 */
	public static function menu() {
		add_options_page( __( 'Freshtastic Maintenance', 'nexora-commerce-toolkit' ), __( 'Freshtastic Maintenance', 'nexora-commerce-toolkit' ), 'manage_options', 'nexora-maintenance', array( __CLASS__, 'render_admin' ) );
	}

	/**
	 * Quick on/off toggle.
	 */
	public static function handle_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_maint_toggle' );
		$s            = self::settings();
		$s['enabled'] = ! empty( $_POST['enable'] );
		update_option( self::OPTION, $s );
		wp_safe_redirect( admin_url( 'options-general.php?page=nexora-maintenance&nexora_msg=' . ( $s['enabled'] ? 'on' : 'off' ) ) );
		exit;
	}

	/**
	 * Create an access pass.
	 */
	public static function handle_grant() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_maint_grant' );
		$durations = self::durations();
		$key       = isset( $_POST['duration'] ) ? sanitize_key( $_POST['duration'] ) : '1d';
		$seconds   = isset( $durations[ $key ] ) ? $durations[ $key ][1] : DAY_IN_SECONDS;
		$label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$created   = self::create_grant( $label, $seconds );
		// Show the code once, to the admin who created it.
		set_transient( 'nexora_maint_new_' . get_current_user_id(), $created, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=nexora-maintenance&nexora_msg=granted#nexora-access' ) );
		exit;
	}

	/**
	 * Revoke an access pass.
	 */
	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_maint_revoke' );
		self::revoke( isset( $_POST['grant'] ) ? sanitize_text_field( wp_unslash( $_POST['grant'] ) ) : '' );
		wp_safe_redirect( admin_url( 'options-general.php?page=nexora-maintenance&nexora_msg=revoked#nexora-access' ) );
		exit;
	}

	/**
	 * Pass durations.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function durations() {
		return array(
			'1h'  => array( __( '1 hour', 'nexora-commerce-toolkit' ), HOUR_IN_SECONDS ),
			'8h'  => array( __( '8 hours', 'nexora-commerce-toolkit' ), 8 * HOUR_IN_SECONDS ),
			'1d'  => array( __( '1 day', 'nexora-commerce-toolkit' ), DAY_IN_SECONDS ),
			'3d'  => array( __( '3 days', 'nexora-commerce-toolkit' ), 3 * DAY_IN_SECONDS ),
			'7d'  => array( __( '7 days', 'nexora-commerce-toolkit' ), WEEK_IN_SECONDS ),
			'30d' => array( __( '30 days', 'nexora-commerce-toolkit' ), 30 * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Settings screen.
	 */
	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		$active = self::is_active();
		$new    = get_transient( 'nexora_maint_new_' . get_current_user_id() );
		if ( $new ) {
			delete_transient( 'nexora_maint_new_' . get_current_user_id() );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$msg   = isset( $_GET['nexora_msg'] ) ? sanitize_key( $_GET['nexora_msg'] ) : '';
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$roles = wp_roles()->get_names();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Freshtastic Maintenance', 'nexora-commerce-toolkit' ); ?></h1>
			<?php if ( 'on' === $msg || 'off' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'on' === $msg ? esc_html__( 'Maintenance mode is on. Page caches were purged where supported.', 'nexora-commerce-toolkit' ) : esc_html__( 'Maintenance mode is off. The store is open.', 'nexora-commerce-toolkit' ); ?></p></div>
			<?php elseif ( 'revoked' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Access pass revoked. It stops working immediately.', 'nexora-commerce-toolkit' ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:860px">
				<h2 style="display:flex;align-items:center;gap:10px">
					<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo $active ? '#DC2626' : '#16A34A'; ?>"></span>
					<?php echo $active ? esc_html__( 'Maintenance mode is ON', 'nexora-commerce-toolkit' ) : esc_html__( 'Store is open', 'nexora-commerce-toolkit' ); ?>
				</h2>
				<p>
					<?php
					if ( $active ) {
						echo 'shop' === $s['scope'] ? esc_html__( 'Shop, product, cart, checkout and account pages show the maintenance page (HTTP 503). Other pages stay visible. Purchases are disabled for visitors without access.', 'nexora-commerce-toolkit' ) : esc_html__( 'All front-end pages show the maintenance page (HTTP 503). Purchases are disabled for visitors without access.', 'nexora-commerce-toolkit' );
					} elseif ( $s['enabled'] ) {
						esc_html_e( 'Maintenance mode ended automatically at the return time. Switch it off below or set a new return time.', 'nexora-commerce-toolkit' );
					} else {
						esc_html_e( 'Visitors can browse and buy normally.', 'nexora-commerce-toolkit' );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap">
					<input type="hidden" name="action" value="nexora_maint_toggle">
					<?php wp_nonce_field( 'nexora_maint_toggle' ); ?>
					<?php if ( $s['enabled'] ) : ?>
						<button class="button button-primary" name="enable" value="0"><?php esc_html_e( 'Turn maintenance off — open the store', 'nexora-commerce-toolkit' ); ?></button>
					<?php else : ?>
						<button class="button button-primary" name="enable" value="1" onclick="return confirm('<?php echo esc_js( __( 'Put the store into maintenance mode now? Visitors without access will not be able to shop.', 'nexora-commerce-toolkit' ) ); ?>')"><?php esc_html_e( 'Turn maintenance on', 'nexora-commerce-toolkit' ); ?></button>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View site', 'nexora-commerce-toolkit' ); ?></a>
				</form>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_maintenance' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="<?php echo $s['enabled'] ? '1' : ''; ?>">
				<h2><?php esc_html_e( 'Maintenance page', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Scope', 'nexora-commerce-toolkit' ); ?></th><td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[scope]" value="site" <?php checked( $s['scope'], 'site' ); ?>> <?php esc_html_e( 'Whole storefront', 'nexora-commerce-toolkit' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[scope]" value="shop" <?php checked( $s['scope'], 'shop' ); ?>> <?php esc_html_e( 'Shop only (products, cart, checkout, account) — homepage, journal and info pages stay up', 'nexora-commerce-toolkit' ); ?></label>
						</fieldset>
					</td></tr>
					<tr><th scope="row"><label for="nm-headline"><?php esc_html_e( 'Headline', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="text" class="regular-text" id="nm-headline" name="<?php echo esc_attr( self::OPTION ); ?>[headline]" value="<?php echo esc_attr( $s['headline'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="nm-message"><?php esc_html_e( 'Message', 'nexora-commerce-toolkit' ); ?></label></th><td><textarea class="large-text" rows="3" id="nm-message" name="<?php echo esc_attr( self::OPTION ); ?>[message]"><?php echo esc_textarea( $s['message'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="nm-return"><?php esc_html_e( 'Expected back', 'nexora-commerce-toolkit' ); ?></label></th><td>
						<input type="datetime-local" id="nm-return" name="<?php echo esc_attr( self::OPTION ); ?>[return_at]" value="<?php echo esc_attr( $s['return_at'] ); ?>">
						<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_off]" value="1" <?php checked( $s['auto_off'] ); ?>> <?php esc_html_e( 'Open the store automatically at this time', 'nexora-commerce-toolkit' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Site time zone. Shown on the maintenance page and sent to search engines as Retry-After.', 'nexora-commerce-toolkit' ); ?></p>
					</td></tr>
					<tr><th scope="row"><label for="nm-contact"><?php esc_html_e( 'Contact line', 'nexora-commerce-toolkit' ); ?></label></th><td><input type="text" class="regular-text" id="nm-contact" name="<?php echo esc_attr( self::OPTION ); ?>[contact]" value="<?php echo esc_attr( $s['contact'] ); ?>" placeholder="<?php esc_attr_e( 'Message us at orders@example.com or 0917 000 0000', 'nexora-commerce-toolkit' ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Roles that always see the site', 'nexora-commerce-toolkit' ); ?></th><td><fieldset>
						<?php foreach ( $roles as $key => $label ) : ?>
							<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[bypass_roles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['bypass_roles'], true ) ); ?> <?php disabled( 'administrator' === $key ); ?>> <?php echo esc_html( translate_user_role( $label ) ); ?></label>
						<?php endforeach; ?>
						<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[bypass_roles][]" value="administrator">
					</fieldset></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Access code box', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[code_form]" value="1" <?php checked( $s['code_form'] ); ?>> <?php esc_html_e( 'Show “Have a preview access code?” on the maintenance page', 'nexora-commerce-toolkit' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Save maintenance page', 'nexora-commerce-toolkit' ) ); ?>
			</form>

			<h2 id="nexora-access"><?php esc_html_e( 'Temporary access', 'nexora-commerce-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'Give clients, suppliers or testers access to the site while it is in maintenance. Each pass is a link and a code; it expires automatically and can be revoked at any time. Pass holders see the site but the public still cannot buy.', 'nexora-commerce-toolkit' ); ?></p>

			<?php if ( $new ) : ?>
				<?php $link = add_query_arg( self::PARAM, rawurlencode( $new['code'] ), home_url( '/' ) ); ?>
				<div class="notice notice-success inline" style="padding:12px 16px">
					<p><strong><?php echo esc_html( sprintf( /* translators: 1: label, 2: date */ __( 'Access pass “%1$s” created — valid until %2$s.', 'nexora-commerce-toolkit' ), $new['grant']['label'], wp_date( $fmt, $new['grant']['expires'] ) ) ); ?></strong></p>
					<p><?php esc_html_e( 'Copy it now — for security the code is not shown again.', 'nexora-commerce-toolkit' ); ?></p>
					<p><label for="nm-link"><?php esc_html_e( 'Access link', 'nexora-commerce-toolkit' ); ?></label><br><input type="text" readonly class="large-text code" id="nm-link" value="<?php echo esc_attr( $link ); ?>" onfocus="this.select()"></p>
					<p><label for="nm-code"><?php esc_html_e( 'Access code', 'nexora-commerce-toolkit' ); ?></label><br><input type="text" readonly class="regular-text code" id="nm-code" value="<?php echo esc_attr( $new['code'] ); ?>" onfocus="this.select()" style="font-size:18px;letter-spacing:.12em"></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="max-width:860px">
				<input type="hidden" name="action" value="nexora_maint_grant">
				<?php wp_nonce_field( 'nexora_maint_grant' ); ?>
				<h3><?php esc_html_e( 'Create an access pass', 'nexora-commerce-toolkit' ); ?></h3>
				<p>
					<label for="nm-label"><?php esc_html_e( 'Who is it for?', 'nexora-commerce-toolkit' ); ?></label><br>
					<input type="text" class="regular-text" id="nm-label" name="label" placeholder="<?php esc_attr_e( 'e.g. Client review, Supplier — Juan', 'nexora-commerce-toolkit' ); ?>" maxlength="80">
				</p>
				<p>
					<label for="nm-duration"><?php esc_html_e( 'Valid for', 'nexora-commerce-toolkit' ); ?></label><br>
					<select id="nm-duration" name="duration">
						<?php foreach ( self::durations() as $key => $d ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, '1d' ); ?>><?php echo esc_html( $d[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php submit_button( __( 'Create access pass', 'nexora-commerce-toolkit' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php $grants = self::grants(); ?>
			<?php if ( $grants ) : ?>
				<table class="widefat striped" style="max-width:860px;margin-top:16px">
					<thead><tr><th><?php esc_html_e( 'Pass', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Created', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Expires', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Used', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Status', 'nexora-commerce-toolkit' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php foreach ( array_reverse( $grants, true ) as $id => $g ) : ?>
							<?php
							$status = ! empty( $g['revoked'] ) ? __( 'Revoked', 'nexora-commerce-toolkit' ) : ( (int) $g['expires'] > time() ? __( 'Active', 'nexora-commerce-toolkit' ) : __( 'Expired', 'nexora-commerce-toolkit' ) );
							?>
							<tr>
								<td><strong><?php echo esc_html( $g['label'] ); ?></strong></td>
								<td><?php echo esc_html( wp_date( $fmt, (int) $g['created'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( $fmt, (int) $g['expires'] ) ); ?></td>
								<td>
									<?php
									/* translators: %d: number of uses */
									echo esc_html( sprintf( _n( '%d time', '%d times', (int) $g['uses'], 'nexora-commerce-toolkit' ), (int) $g['uses'] ) );
									echo $g['last_used'] ? '<br><small>' . esc_html( sprintf( /* translators: %s: date */ __( 'last %s', 'nexora-commerce-toolkit' ), wp_date( $fmt, (int) $g['last_used'] ) ) ) . '</small>' : '';
									?>
								</td>
								<td><?php echo esc_html( $status ); ?></td>
								<td>
									<?php if ( __( 'Active', 'nexora-commerce-toolkit' ) === $status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="nexora_maint_revoke">
											<input type="hidden" name="grant" value="<?php echo esc_attr( $id ); ?>">
											<?php wp_nonce_field( 'nexora_maint_revoke' ); ?>
											<button class="button button-link-delete"><?php esc_html_e( 'Revoke', 'nexora-commerce-toolkit' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Good to know', 'nexora-commerce-toolkit' ); ?></h2>
			<ul style="list-style:disc;padding-left:20px;max-width:860px">
				<li><?php esc_html_e( 'Payment gateway callbacks (wc-api), wp-admin and the login page keep working, so pending payments still complete.', 'nexora-commerce-toolkit' ); ?></li>
				<li><?php esc_html_e( 'If you use a page cache or CDN, purge it after switching maintenance on or off, and exclude the “nexora_preview” cookie from caching so pass holders are not served cached pages.', 'nexora-commerce-toolkit' ); ?></li>
				<li><?php esc_html_e( 'WooCommerce also has its own “Coming soon” mode (WooCommerce → Settings → Site visibility). Use one or the other.', 'nexora-commerce-toolkit' ); ?></li>
			</ul>
		</div>
		<?php
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Maintenance mode and temporary access.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nexora maintenance on --scope=shop
	 *     wp nexora maintenance grant --label="Client review" --hours=24
	 *     wp nexora maintenance off
	 */
	WP_CLI::add_command(
		'nexora maintenance',
		new class() {
			/**
			 * Turn maintenance on.
			 *
			 * [--scope=<scope>]
			 * : site or shop.
			 *
			 * @param array $args  Args.
			 * @param array $assoc Assoc.
			 */
			public function on( $args, $assoc ) {
				$s            = Nexora_Toolkit_Maintenance::settings();
				$s['enabled'] = true;
				if ( isset( $assoc['scope'] ) ) {
					$s['scope'] = 'shop' === $assoc['scope'] ? 'shop' : 'site';
				}
				update_option( Nexora_Toolkit_Maintenance::OPTION, $s );
				WP_CLI::success( 'Maintenance mode on (' . $s['scope'] . ').' );
			}

			/**
			 * Turn maintenance off.
			 */
			public function off() {
				$s            = Nexora_Toolkit_Maintenance::settings();
				$s['enabled'] = false;
				update_option( Nexora_Toolkit_Maintenance::OPTION, $s );
				WP_CLI::success( 'Maintenance mode off.' );
			}

			/**
			 * Show status and passes.
			 */
			public function status() {
				$s = Nexora_Toolkit_Maintenance::settings();
				WP_CLI::log( 'Active: ' . ( Nexora_Toolkit_Maintenance::is_active() ? 'yes' : 'no' ) . ' | scope: ' . $s['scope'] );
				foreach ( Nexora_Toolkit_Maintenance::grants() as $id => $g ) {
					WP_CLI::log( sprintf( '%s  %-24s expires %s  uses %d%s', $id, $g['label'], wp_date( 'Y-m-d H:i', (int) $g['expires'] ), (int) $g['uses'], empty( $g['revoked'] ) ? '' : '  REVOKED' ) );
				}
			}

			/**
			 * Create a temporary access pass.
			 *
			 * [--label=<label>]
			 * : Who it is for.
			 *
			 * [--hours=<hours>]
			 * : Lifetime in hours. Default 24.
			 *
			 * @param array $args  Args.
			 * @param array $assoc Assoc.
			 */
			public function grant( $args, $assoc ) {
				$hours = isset( $assoc['hours'] ) ? max( 1, (int) $assoc['hours'] ) : 24;
				$g     = Nexora_Toolkit_Maintenance::create_grant( isset( $assoc['label'] ) ? sanitize_text_field( $assoc['label'] ) : '', $hours * HOUR_IN_SECONDS );
				WP_CLI::log( 'ID:   ' . $g['id'] );
				WP_CLI::log( 'Code: ' . $g['code'] );
				WP_CLI::log( 'Link: ' . add_query_arg( Nexora_Toolkit_Maintenance::PARAM, rawurlencode( $g['code'] ), home_url( '/' ) ) );
				WP_CLI::success( 'Access pass created (valid ' . $hours . ' h).' );
			}

			/**
			 * Revoke an access pass.
			 *
			 * <id>
			 * : Pass ID.
			 *
			 * @param array $args Args.
			 */
			public function revoke( $args ) {
				Nexora_Toolkit_Maintenance::revoke( $args[0] ) ? WP_CLI::success( 'Revoked.' ) : WP_CLI::error( 'Pass not found.' );
			}
		}
	);
}
