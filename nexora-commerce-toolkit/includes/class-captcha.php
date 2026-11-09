<?php
/**
 * Google reCAPTCHA on every form that creates an account: v2 "I'm not a robot" checkbox, or v3
 * (invisible, scored). The key type must match the version chosen in Settings → Freshtastic Security.
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
	 * Whether the v3 helper script was added on this page.
	 *
	 * @var bool
	 */
	private static $v3_script = false;

	/**
	 * Hook up.
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_register_form', array( __CLASS__, 'widget' ), 16 );
		add_action( 'woocommerce_after_checkout_registration_form', array( __CLASS__, 'checkout_widget' ), 16, 0 );
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
	 * v2 (checkbox) or v3 (invisible score).
	 *
	 * @return string
	 */
	public static function version() {
		return 'v3' === Nexora_Toolkit_Security::get( 'captcha_version' ) ? 'v3' : 'v2';
	}

	/**
	 * Lowest v3 score accepted (0.1 to 0.9; Google scores 1.0 as very likely human).
	 *
	 * @return float
	 */
	public static function min_score() {
		$score = (float) Nexora_Toolkit_Security::get( 'captcha_min_score' );
		return $score >= 0.1 && $score <= 0.9 ? $score : 0.5;
	}

	/**
	 * Checkout version of the widget.
	 */
	public static function checkout_widget() {
		self::widget( 'checkout' );
	}

	/**
	 * Print the box and load Google's script.
	 *
	 * @param string $action register|checkout (v3 only: reported to Google and checked on reply).
	 */
	public static function widget( $action = 'register' ) {
		if ( ! self::enabled() ) {
			return;
		}
		if ( 'v3' === self::version() ) {
			self::widget_v3( 'checkout' === $action ? 'checkout' : 'register' );
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
	 * v3: no box. A hidden field gets a fresh token from Google just before the form is sent, and is
	 * refreshed every 90 seconds for WooCommerce's checkout, which sends without a page load.
	 *
	 * @param string $action Action name.
	 */
	private static function widget_v3( $action ) {
		$key = (string) Nexora_Toolkit_Security::get( 'captcha_site_key' );
		wp_enqueue_script( 'nexora-recaptcha', add_query_arg( 'render', rawurlencode( $key ), 'https://www.google.com/recaptcha/api.js' ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's URL takes no version.
		if ( ! self::$v3_script ) {
			self::$v3_script = true;
			$js = <<<'JS'
(function(k){
	var fields=function(root){return (root||document).querySelectorAll('input.nx-recaptcha-v3');};
	var ok=function(){return window.grecaptcha&&grecaptcha.execute;};
	// Never hold a form back: on any Google error, or after 8 seconds, send it and let the server explain.
	function token(i){return new Promise(function(done){var w=setTimeout(function(){done('');},8000);var fin=function(v){clearTimeout(w);done(v);};if(!ok()){fin('');return;}try{grecaptcha.ready(function(){try{grecaptcha.execute(k,{action:i.getAttribute('data-action')}).then(function(t){i.value=t;fin(t);},function(){fin('');});}catch(x){fin('');}});}catch(x){fin('');}});}
	function refresh(){fields().forEach(token);}
	window.addEventListener('load',function(){refresh();setInterval(refresh,90000);});
	if(window.jQuery){jQuery(document.body).on('checkout_error updated_checkout',refresh);}
	document.addEventListener('submit',function(e){
		var f=e.target,i=f&&f.querySelector?f.querySelector('input.nx-recaptcha-v3'):null;
		if(!i||!ok()){return;}
		if(f.matches('form.checkout')&&!f.hasAttribute('data-nx-checkout')){return;}
		if(f.getAttribute('data-nx-v3')==='go'){f.removeAttribute('data-nx-v3');return;}
		e.preventDefault();e.stopImmediatePropagation();
		var by=e.submitter;
		token(i).then(function(){
			f.setAttribute('data-nx-v3','go');
			if(f.requestSubmit){f.requestSubmit(by&&by.form===f?by:undefined);return;}
			if(by&&by.name){var h=document.createElement('input');h.type='hidden';h.name=by.name;h.value=by.value;f.appendChild(h);}
			f.submit();
		});
	},true);
})(%s);
JS;
			wp_add_inline_script( 'nexora-recaptcha', sprintf( $js, wp_json_encode( $key ) ) );
		}
		?>
		<div class="form-row nx-captcha nx-captcha--v3">
			<input type="hidden" name="g-recaptcha-response" class="nx-recaptcha-v3" data-action="<?php echo esc_attr( $action ); ?>" value="">
			<p class="nx-captcha__note"><small><?php esc_html_e( 'This form is protected by Google reCAPTCHA.', 'nexora-commerce-toolkit' ); ?></small></p>
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
		$v3 = 'v3' === self::version();
		if ( '' === $token ) {
			self::$result = $v3 ? __( 'The security check did not load. Please refresh the page and try again.', 'nexora-commerce-toolkit' ) : __( 'Please tick "I\'m not a robot".', 'nexora-commerce-toolkit' );
			return self::$result;
		}
		$reply = self::ask_google( (string) Nexora_Toolkit_Security::get( 'captcha_secret' ), $token );
		if ( null === $reply ) {
			self::$result = __( 'We could not complete the security check. Please try again in a moment.', 'nexora-commerce-toolkit' );
		} elseif ( ! $v3 ) {
			self::$result = ! empty( $reply['success'] ) ? '' : __( 'The security check failed. Please tick "I\'m not a robot" again.', 'nexora-commerce-toolkit' );
		} else {
			// A v3 reply carries a score and the action the page reported. A v2 token has neither.
			$passed = ! empty( $reply['success'] )
				&& isset( $reply['score'], $reply['action'] )
				&& in_array( $reply['action'], array( 'register', 'checkout' ), true )
				&& (float) $reply['score'] >= self::min_score();
			self::$result = $passed ? '' : __( 'We could not confirm this sign-up. Please try again, or contact us if this keeps happening.', 'nexora-commerce-toolkit' );
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
