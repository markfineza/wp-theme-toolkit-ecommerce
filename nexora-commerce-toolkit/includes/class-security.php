<?php
/**
 * Settings → Freshtastic Security: registration consent, reCAPTCHA and email login codes.
 *
 * The reCAPTCHA secret is write-only here and NEXORA_RECAPTCHA_SECRET in wp-config.php overrides it.
 * Login codes cannot be switched on until a test code has reached the administrator's inbox, because
 * a site that cannot send email would otherwise lock everybody out.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security settings and admin screen.
 */
class Nexora_Toolkit_Security {

	const OPTION = 'nexora_security';
	const PAGE   = 'nexora-security';
	const TESTED = 'nexora_otp_mail_tested';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_nexora_legal_drafts', array( __CLASS__, 'create_drafts' ) );
		add_action( 'admin_post_nexora_otp_test_send', array( __CLASS__, 'test_send' ) );
		add_action( 'admin_post_nexora_otp_test_check', array( __CLASS__, 'test_check' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_assets' ) );
		add_filter( 'nexora_terms_url', array( __CLASS__, 'terms_url' ) );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'consent_enabled'   => true,
			'terms_page'        => 0,
			'legal_version'     => '1.0',
			'consent_text'      => '',
			'marketing_enabled' => true,
			'marketing_text'    => '',
			'captcha_site_key'  => '',
			'captcha_secret'    => '',
			'otp_staff'         => false,
			'otp_customers'     => false,
			'otp_minutes'       => 10,
		);
	}

	/**
	 * Get a setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		if ( 'captcha_secret' === $key && defined( 'NEXORA_RECAPTCHA_SECRET' ) ) {
			return (string) NEXORA_RECAPTCHA_SECRET;
		}
		$all = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Has a test code reached an administrator's inbox?
	 *
	 * @return bool
	 */
	public static function mail_tested() {
		return (bool) get_option( self::TESTED );
	}

	/* ------------------------------------------------------------ legal pages */

	/**
	 * Terms page ID (our setting, then WooCommerce's), whatever its status.
	 *
	 * @return int
	 */
	public static function terms_page_id() {
		$id = (int) self::get( 'terms_page' );
		if ( ! $id && function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			$id = (int) wc_terms_and_conditions_page_id();
		}
		return $id;
	}

	/**
	 * Public terms link, or the value passed in when the page is not published.
	 *
	 * @param string $default_url Fallback.
	 * @return string
	 */
	public static function terms_url( $default_url = '' ) {
		$id = self::terms_page_id();
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : $default_url;
	}

	/**
	 * Are both documents published?
	 *
	 * @return bool
	 */
	public static function legal_ready() {
		return '' !== self::terms_url() && '' !== (string) get_privacy_policy_url();
	}

	/**
	 * Create the Philippine draft pages. Existing published pages are left alone.
	 */
	public static function create_drafts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_legal_drafts' );

		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( ! self::terms_page_id() ) {
			$id = self::insert_draft( __( 'Terms and Conditions', 'nexora-commerce-toolkit' ), 'terms.html' );
			if ( $id ) {
				$settings['terms_page'] = $id;
				update_option( self::OPTION, $settings );
			}
		}
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		$ours    = false !== strpos( (string) get_post_field( 'post_content', $privacy ), 'Republic Act No. 10173' );
		if ( 'publish' !== get_post_status( $privacy ) && ! $ours ) { // WordPress's own unpublished sample is replaced; ours is not repeated.
			$id = self::insert_draft( __( 'Privacy Policy', 'nexora-commerce-toolkit' ), 'privacy.html' );
			if ( $id ) {
				update_option( 'wp_page_for_privacy_policy', $id );
			}
		}
		wp_safe_redirect( add_query_arg( 'nx_msg', 'drafts', self::url() ) );
		exit;
	}

	/**
	 * Insert a draft page from a bundled template.
	 *
	 * @param string $title Title.
	 * @param string $file  File in includes/legal.
	 * @return int
	 */
	private static function insert_draft( $title, $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		$content = (string) file_get_contents( NEXORA_TOOLKIT_DIR . 'includes/legal/' . $file );
		$id      = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * One line of status for a legal page.
	 *
	 * @param int $id Page ID.
	 * @return string Escaped HTML.
	 */
	private static function page_status( $id ) {
		$status = $id ? get_post_status( $id ) : false;
		if ( ! $status ) {
			return '<span class="nx-sec-bad">' . esc_html__( 'Not set', 'nexora-commerce-toolkit' ) . '</span>';
		}
		$edit = '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		if ( 'publish' !== $status ) {
			return $edit . ' <span class="nx-sec-bad">' . esc_html__( 'Draft: not public yet', 'nexora-commerce-toolkit' ) . '</span>';
		}
		$placeholder = false !== strpos( (string) get_post_field( 'post_content', $id ), '[Your' );
		return $edit . ' ' . ( $placeholder
			? '<span class="nx-sec-bad">' . esc_html__( 'Published, but still has [Your ...] blanks to fill in', 'nexora-commerce-toolkit' ) . '</span>'
			: '<span class="nx-sec-ok">' . esc_html__( 'Published', 'nexora-commerce-toolkit' ) . '</span>' );
	}

	/* ----------------------------------------------------------------- assets */

	/**
	 * Styles on the pages that carry the forms.
	 */
	public static function front_assets() {
		$account = function_exists( 'is_account_page' ) && ( is_account_page() || is_checkout() );
		if ( $account || Nexora_Store::is_page( 'account' ) || Nexora_Store::is_page( 'checkout' ) ) {
			wp_enqueue_style( 'nexora-security', NEXORA_TOOLKIT_URL . 'assets/css/security.css', array(), NEXORA_TOOLKIT_VERSION );
		}
	}

	/**
	 * Styles on wp-login.php.
	 */
	public static function login_assets() {
		wp_enqueue_style( 'nexora-security', NEXORA_TOOLKIT_URL . 'assets/css/security.css', array(), NEXORA_TOOLKIT_VERSION );
	}

	/* --------------------------------------------------------------- settings */

	/**
	 * Admin URL of the screen.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page( __( 'Freshtastic Security', 'nexora-commerce-toolkit' ), __( 'Freshtastic Security', 'nexora-commerce-toolkit' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'nexora_security',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize. The secret keeps its value when left blank; login codes stay off until mail is proven.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$text    = static function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $input[ $key ] ) ) ) : '';
		};

		$out = array(
			'consent_enabled'   => ! empty( $input['consent_enabled'] ),
			'terms_page'        => isset( $input['terms_page'] ) ? absint( $input['terms_page'] ) : 0,
			'legal_version'     => $text( 'legal_version' ),
			'consent_text'      => $text( 'consent_text' ),
			'marketing_enabled' => ! empty( $input['marketing_enabled'] ),
			'marketing_text'    => $text( 'marketing_text' ),
			'captcha_site_key'  => preg_replace( '/[^A-Za-z0-9_-]/', '', $text( 'captcha_site_key' ) ),
			'captcha_secret'    => $current['captcha_secret'],
			'otp_staff'         => ! empty( $input['otp_staff'] ),
			'otp_customers'     => ! empty( $input['otp_customers'] ),
			'otp_minutes'       => isset( $input['otp_minutes'] ) ? min( 30, max( 3, absint( $input['otp_minutes'] ) ) ) : 10,
		);
		$out['legal_version'] = '' !== $out['legal_version'] ? $out['legal_version'] : '1.0';

		$secret = preg_replace( '/[^A-Za-z0-9_-]/', '', $text( 'captcha_secret' ) );
		if ( '' !== $secret ) {
			$out['captcha_secret'] = $secret;
		}
		if ( ! empty( $input['captcha_secret_clear'] ) ) {
			$out['captcha_secret'] = '';
		}

		if ( ( $out['otp_staff'] || $out['otp_customers'] ) && ! self::mail_tested() ) {
			$out['otp_staff']     = false;
			$out['otp_customers'] = false;
			add_settings_error( self::OPTION, 'nexora_otp_untested', __( 'Login codes stay off until a test code has reached your inbox. Use "Test email delivery" below.', 'nexora-commerce-toolkit' ) );
		}

		// A wrong key would stop every registration, so say so straight away.
		$key = defined( 'NEXORA_RECAPTCHA_SECRET' ) ? (string) NEXORA_RECAPTCHA_SECRET : $out['captcha_secret'];
		if ( '' !== $key && $key !== $current['captcha_secret'] && 'bad_secret' === Nexora_Toolkit_Captcha::check_secret( $key ) ) {
			add_settings_error( self::OPTION, 'nexora_captcha_secret', __( 'Google rejected the reCAPTCHA secret key. Please check it, or customers will not be able to register.', 'nexora-commerce-toolkit' ) );
		}
		return $out;
	}

	/**
	 * Warn administrators on the Dashboard while customers are asked to accept a page they cannot read.
	 */
	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'dashboard' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::get( 'consent_enabled' ) && ! self::legal_ready() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Customers are asked to accept your Terms and Privacy Policy, but they are not both published yet.', 'nexora-commerce-toolkit' ),
				esc_url( self::url() ),
				esc_html__( 'Set them up', 'nexora-commerce-toolkit' )
			);
		}
	}

	/**
	 * Printed input name.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function name( $key ) {
		return self::OPTION . '[' . $key . ']';
	}

	/**
	 * Settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tested = self::mail_tested();
		$off    = defined( 'NEXORA_DISABLE_LOGIN_OTP' ) && NEXORA_DISABLE_LOGIN_OTP;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message.
		$msg  = isset( $_GET['nx_msg'] ) ? sanitize_key( wp_unslash( $_GET['nx_msg'] ) ) : '';
		$msgs = array(
			'drafts'    => array( 'success', __( 'Draft pages are ready. Fill in the [Your ...] blanks, have them reviewed, then publish them.', 'nexora-commerce-toolkit' ) ),
			'sent'      => array( 'success', __( 'Test code sent. Check your inbox and spam folder, then enter the code below.', 'nexora-commerce-toolkit' ) ),
			'mailfail'  => array( 'error', __( 'WordPress could not send the email. Fix your site email (an SMTP plugin usually helps) before turning on login codes.', 'nexora-commerce-toolkit' ) ),
			'badcode'   => array( 'error', __( 'That code is not correct or has expired. Please send a new one.', 'nexora-commerce-toolkit' ) ),
			'mailok'    => array( 'success', __( 'Email delivery confirmed. You can now turn on login codes above.', 'nexora-commerce-toolkit' ) ),
		);
		$pages = get_pages( array( 'post_status' => array( 'publish', 'draft' ) ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Freshtastic Security', 'nexora-commerce-toolkit' ); ?></h1>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $msg ][0] ); ?>"><p><?php echo esc_html( $msgs[ $msg ][1] ); ?></p></div>
			<?php endif; ?>
			<style>.nx-sec-ok{color:#0b5a45;font-weight:600}.nx-sec-bad{color:#a02b12;font-weight:600}.nx-sec-box{max-width:760px;margin:20px 0;padding:14px 18px;border:1px solid #c3c4c7;background:#fff}.nx-sec-box code{user-select:all}</style>

			<form method="post" action="options.php">
				<?php settings_fields( 'nexora_security' ); ?>

				<h2><?php esc_html_e( 'Terms and consent at registration', 'nexora-commerce-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Require consent', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'consent_enabled' ) ); ?>" value="1" <?php checked( (bool) self::get( 'consent_enabled' ) ); ?>> <?php esc_html_e( 'Customers must accept the Terms and Privacy Policy to create an account. The date, version and address of each acceptance are saved.', 'nexora-commerce-toolkit' ); ?></label></td></tr>
					<tr>
						<th scope="row"><label for="nx-terms-page"><?php esc_html_e( 'Terms and Conditions page', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<select id="nx-terms-page" name="<?php echo esc_attr( self::name( 'terms_page' ) ); ?>">
								<option value="0"><?php esc_html_e( 'Choose a page', 'nexora-commerce-toolkit' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo (int) $page->ID; ?>" <?php selected( self::terms_page_id(), (int) $page->ID ); ?>><?php echo esc_html( $page->post_title . ( 'publish' === $page->post_status ? '' : ' (' . __( 'draft', 'nexora-commerce-toolkit' ) . ')' ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo self::page_status( self::terms_page_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Privacy Policy page', 'nexora-commerce-toolkit' ); ?></th>
						<td>
							<p><?php echo self::page_status( (int) get_option( 'wp_page_for_privacy_policy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></p>
							<p class="description"><a href="<?php echo esc_url( admin_url( 'options-privacy.php' ) ); ?>"><?php esc_html_e( 'Change it under Settings → Privacy', 'nexora-commerce-toolkit' ); ?></a></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nx-legal-version"><?php esc_html_e( 'Version of these documents', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="nx-legal-version" name="<?php echo esc_attr( self::name( 'legal_version' ) ); ?>" value="<?php echo esc_attr( (string) self::get( 'legal_version' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Change this only when the terms really change. Customers then see a prompt on their account page to accept the new version.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nx-consent-text"><?php esc_html_e( 'Consent wording', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="nx-consent-text" name="<?php echo esc_attr( self::name( 'consent_text' ) ); ?>" placeholder="<?php echo esc_attr( Nexora_Toolkit_Consent::default_text() ); ?>"><?php echo esc_textarea( (string) self::get( 'consent_text' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Leave blank for the wording shown. {terms} and {privacy} become links.', 'nexora-commerce-toolkit' ); ?></p>
						</td>
					</tr>
					<tr><th scope="row"><?php esc_html_e( 'Marketing emails', 'nexora-commerce-toolkit' ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'marketing_enabled' ) ); ?>" value="1" <?php checked( (bool) self::get( 'marketing_enabled' ) ); ?>> <?php esc_html_e( 'Offer a separate, optional, unticked box for marketing emails. Ticking it adds the customer to your newsletter list.', 'nexora-commerce-toolkit' ); ?></label></td></tr>
					<tr>
						<th scope="row"><label for="nx-marketing-text"><?php esc_html_e( 'Marketing wording', 'nexora-commerce-toolkit' ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="nx-marketing-text" name="<?php echo esc_attr( self::name( 'marketing_text' ) ); ?>" placeholder="<?php echo esc_attr( Nexora_Toolkit_Consent::default_marketing_text() ); ?>"><?php echo esc_textarea( (string) self::get( 'marketing_text' ) ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Google reCAPTCHA at registration', 'nexora-commerce-toolkit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: opening link, 2: closing link */
						esc_html__( 'Create a v2 "I\'m not a robot" Checkbox key for your domain in the %1$sreCAPTCHA admin console%2$s. The box appears once both keys are saved.', 'nexora-commerce-toolkit' ),
						'<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">',
						'</a>'
					);
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nx-captcha-site"><?php esc_html_e( 'Site key', 'nexora-commerce-toolkit' ); ?></label></th>
						<td><input type="text" class="regular-text" id="nx-captcha-site" name="<?php echo esc_attr( self::name( 'captcha_site_key' ) ); ?>" value="<?php echo esc_attr( (string) self::get( 'captcha_site_key' ) ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="nx-captcha-secret"><?php esc_html_e( 'Secret key', 'nexora-commerce-toolkit' ); ?></label></th>
						<td>
							<?php if ( defined( 'NEXORA_RECAPTCHA_SECRET' ) ) : ?>
								<p><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Set in wp-config.php (NEXORA_RECAPTCHA_SECRET).', 'nexora-commerce-toolkit' ); ?></p>
							<?php else : ?>
								<input type="password" class="regular-text" id="nx-captcha-secret" name="<?php echo esc_attr( self::name( 'captcha_secret' ) ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( self::get( 'captcha_secret' ) ? __( '•••••••• saved — leave blank to keep', 'nexora-commerce-toolkit' ) : '' ); ?>">
								<?php if ( self::get( 'captcha_secret' ) ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'captcha_secret_clear' ) ); ?>" value="1"> <?php esc_html_e( 'Remove saved key', 'nexora-commerce-toolkit' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Recommended: define NEXORA_RECAPTCHA_SECRET in wp-config.php instead of saving the key here.', 'nexora-commerce-toolkit' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php echo Nexora_Toolkit_Captcha::enabled() ? '<span class="nx-sec-ok">' . esc_html__( 'reCAPTCHA is on.', 'nexora-commerce-toolkit' ) . '</span>' : '<span class="nx-sec-bad">' . esc_html__( 'reCAPTCHA is off until both keys are saved.', 'nexora-commerce-toolkit' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Login codes by email (OTP)', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( $off ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Login codes are switched off by NEXORA_DISABLE_LOGIN_OTP in wp-config.php. Remove that line to use them again.', 'nexora-commerce-toolkit' ); ?></p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ask for a code after the password', 'nexora-commerce-toolkit' ); ?></th>
						<td>
							<fieldset>
								<p><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'otp_staff' ) ); ?>" value="1" <?php checked( (bool) self::get( 'otp_staff' ) ); ?> <?php disabled( ! $tested ); ?>> <?php esc_html_e( 'Administrators and staff', 'nexora-commerce-toolkit' ); ?></label></p>
								<p><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'otp_customers' ) ); ?>" value="1" <?php checked( (bool) self::get( 'otp_customers' ) ); ?> <?php disabled( ! $tested ); ?>> <?php esc_html_e( 'Customers', 'nexora-commerce-toolkit' ); ?></label></p>
							</fieldset>
							<?php if ( ! $tested ) : ?>
								<p class="description nx-sec-bad"><?php esc_html_e( 'Locked until you pass the email test below, so a mail problem cannot lock you out.', 'nexora-commerce-toolkit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nx-otp-minutes"><?php esc_html_e( 'Code valid for (minutes)', 'nexora-commerce-toolkit' ); ?></label></th>
						<td><input type="number" class="small-text" min="3" max="30" id="nx-otp-minutes" name="<?php echo esc_attr( self::name( 'otp_minutes' ) ); ?>" value="<?php echo (int) self::get( 'otp_minutes' ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<div class="nx-sec-box">
				<h2><?php esc_html_e( 'Draft legal pages', 'nexora-commerce-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Creates draft Terms and Privacy pages written for a Philippine food store, with [Your ...] blanks to complete. They are starting points, not legal advice: have a lawyer or your Data Protection Officer review them before publishing. Published pages are left alone.', 'nexora-commerce-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="nexora_legal_drafts">
					<?php wp_nonce_field( 'nexora_legal_drafts' ); ?>
					<?php submit_button( __( 'Create draft pages', 'nexora-commerce-toolkit' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="nx-sec-box" id="nx-otp-test">
				<h2><?php esc_html_e( 'Test email delivery', 'nexora-commerce-toolkit' ); ?></h2>
				<?php $me = wp_get_current_user(); ?>
				<p>
					<?php if ( $tested ) : ?>
						<span class="nx-sec-ok"><?php esc_html_e( 'Passed.', 'nexora-commerce-toolkit' ); ?></span>
					<?php endif; ?>
					<?php
					/* translators: %s: email address */
					echo esc_html( sprintf( __( 'Sends a code to %s. Enter it here to prove that this site can deliver login codes.', 'nexora-commerce-toolkit' ), $me->user_email ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="nexora_otp_test_send">
					<?php wp_nonce_field( 'nexora_otp_test' ); ?>
					<?php submit_button( __( 'Send test code', 'nexora-commerce-toolkit' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( get_transient( 'nx_otp_test_' . get_current_user_id() ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
						<input type="hidden" name="action" value="nexora_otp_test_check">
						<?php wp_nonce_field( 'nexora_otp_test' ); ?>
						<label for="nx-otp-test-code"><?php esc_html_e( 'Code from the email', 'nexora-commerce-toolkit' ); ?></label>
						<input type="text" id="nx-otp-test-code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="regular-text" style="width:10em">
						<?php submit_button( __( 'Confirm', 'nexora-commerce-toolkit' ), 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
				<h3><?php esc_html_e( 'If you are ever locked out', 'nexora-commerce-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'Add this line to wp-config.php using your hosting file manager. It turns login codes off until you remove it:', 'nexora-commerce-toolkit' ); ?><br><code>define( 'NEXORA_DISABLE_LOGIN_OTP', true );</code></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Permission and nonce for the test forms.
	 */
	private static function test_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'nexora-commerce-toolkit' ), 403 );
		}
		check_admin_referer( 'nexora_otp_test' );
	}

	/**
	 * Send a test code to the current administrator.
	 */
	public static function test_send() {
		self::test_guard();
		$user = wp_get_current_user();
		$code = Nexora_Toolkit_Login_OTP::new_code();
		$sent = Nexora_Toolkit_Login_OTP::mail_code( $user, $code, 10 );
		if ( $sent ) {
			set_transient(
				'nx_otp_test_' . $user->ID,
				array(
					'hash'  => Nexora_Toolkit_Login_OTP::hash( 'test' . $user->ID, $code ),
					'tries' => 0,
				),
				10 * MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( add_query_arg( 'nx_msg', $sent ? 'sent' : 'mailfail', self::url() ) . '#nx-otp-test' );
		exit;
	}

	/**
	 * Check the test code.
	 */
	public static function test_check() {
		self::test_guard();
		$uid  = get_current_user_id();
		$key  = 'nx_otp_test_' . $uid;
		$test = get_transient( $key );
		$code = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
		$good = is_array( $test ) && $test['tries'] < 5 && hash_equals( $test['hash'], Nexora_Toolkit_Login_OTP::hash( 'test' . $uid, $code ) );
		if ( $good ) {
			delete_transient( $key );
			update_option( self::TESTED, time(), false );
		} elseif ( is_array( $test ) ) {
			++$test['tries'];
			set_transient( $key, $test, 10 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( 'nx_msg', $good ? 'mailok' : 'badcode', self::url() ) . '#nx-otp-test' );
		exit;
	}
}
