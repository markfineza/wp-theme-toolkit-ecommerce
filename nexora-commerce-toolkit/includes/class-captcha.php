<?php
/**
 * Google reCAPTCHA v2 checkbox on every form that creates an account.
 *
 * Off until both keys are saved. If Google cannot be reached the registration is refused and the
 * customer is asked to try again; logging in is never affected.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration reCAPTCHA.
 */
class Nexora_Toolkit_Captcha {

	const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Result for this request: a token only works once, and one form can reach two validators.
	 *
	 * @var string|null
	 */
	private static $result = null;

	/**
	 * Hook up.
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_register_form', array( __CLASS__, 'widget' ), 16 );
		add_action( 'woocommerce_after_checkout_registration_form', array( __CLASS__, 'widget' ), 16, 0 );
		add_action( 'register_form', array( __CLASS__, 'widget' ), 16 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'filter_errors' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'filter_errors' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'wc_checkout' ), 20, 2 );
	}

	/**
	 * Are both keys saved?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== (string) Nexora_Toolkit_Security::get( 'captcha_site_key' ) && '' !== (string) Nexora_Toolkit_Security::get( 'captcha_secret' );
	}

	/**
	 * Print the box and load Google's script.
	 */
	public static function widget() {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_script( 'nexora-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's URL takes no version.
		// WooCommerce checks out without a page load, and a token only works once.
		wp_add_inline_script( 'nexora-recaptcha', 'if(window.jQuery){jQuery(document.body).on("checkout_error",function(){if(window.grecaptcha){grecaptcha.reset();}});}' );
		?>
		<div class="form-row nx-captcha">
			<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( (string) Nexora_Toolkit_Security::get( 'captcha_site_key' ) ); ?>"></div>
			<noscript><p><?php esc_html_e( 'Please turn on JavaScript to complete the security check.', 'nexora-commerce-toolkit' ); ?></p></noscript>
		</div>
		<?php
	}

	/**
	 * Check the posted token.
	 *
	 * @return string Error message, or '' when it passed or the feature is off.
	 */
	public static function error() {
		if ( ! self::enabled() ) {
			return '';
		}
		if ( null !== self::$result ) {
			return self::$result;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the calling form verified its nonce.
		$token = isset( $_POST['g-recaptcha-response'] ) && is_string( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
		if ( '' === $token ) {
			self::$result = __( 'Please tick "I\'m not a robot".', 'nexora-commerce-toolkit' );
			return self::$result;
		}
		$reply = self::ask_google( (string) Nexora_Toolkit_Security::get( 'captcha_secret' ), $token );
		if ( null === $reply ) {
			self::$result = __( 'We could not complete the security check. Please try again in a moment.', 'nexora-commerce-toolkit' );
		} else {
			self::$result = ! empty( $reply['success'] ) ? '' : __( 'The security check failed. Please tick "I\'m not a robot" again.', 'nexora-commerce-toolkit' );
		}
		return self::$result;
	}

	/**
	 * Call Google.
	 *
	 * @param string $secret Secret key.
	 * @param string $token  Token.
	 * @return array|null Decoded reply, or null when Google could not be reached.
	 */
	private static function ask_google( $secret, $token ) {
		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$reply = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $reply ) ? $reply : null;
	}

	/**
	 * Does Google accept this secret key? A dummy token is enough to tell.
	 *
	 * @param string $secret Secret key.
	 * @return string ok|bad_secret|unknown
	 */
	public static function check_secret( $secret ) {
		$reply = self::ask_google( $secret, 'nexora-key-check' );
		if ( null === $reply ) {
			return 'unknown';
		}
		$codes = isset( $reply['error-codes'] ) ? (array) $reply['error-codes'] : array();
		return in_array( 'invalid-input-secret', $codes, true ) ? 'bad_secret' : 'ok';
	}

	/**
	 * Add to a WP_Error (My Account and wp-login.php registration).
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function filter_errors( $errors ) {
		$message = self::error();
		if ( '' !== $message ) {
			$errors->add( 'nexora_captcha', $message );
		}
		return $errors;
	}

	/**
	 * WooCommerce classic checkout, only when this order creates an account.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function wc_checkout( $data, $errors ) {
		if ( Nexora_Toolkit_Consent::wc_checkout_creates_account( $data ) ) {
			self::filter_errors( $errors );
		}
	}
}
