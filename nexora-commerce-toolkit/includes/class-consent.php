<?php
/**
 * Terms and privacy consent when customers register, with a time-stamped record of each decision.
 *
 * Covers all four ways to create an account: My Account (WooCommerce and built-in store), checkout
 * (WooCommerce classic and built-in store) and wp-login.php. Marketing is a separate optional choice.
 * Customers with no record for the current version, such as existing customers or accounts made in
 * the WooCommerce checkout block, are prompted on their account dashboard.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent at registration.
 */
class Nexora_Toolkit_Consent {

	const CURRENT   = '_nx_consent';
	const LOG       = '_nx_consent_log';
	const MARKETING = '_nx_marketing';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_forms' ), 5 );
		add_action( 'woocommerce_after_edit_account_form', array( __CLASS__, 'marketing_form' ) );

		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_register_form', array( __CLASS__, 'drop_wc_privacy_text' ), 1 );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'fields' ), 15 );
		add_action( 'woocommerce_after_checkout_registration_form', array( __CLASS__, 'checkout_fields' ), 15, 0 );
		add_action( 'register_form', array( __CLASS__, 'fields' ), 15 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'filter_errors' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'filter_errors' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'wc_checkout' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'record_signup' ), 5 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'prompt' ), 1 );
	}

	/**
	 * Is consent switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Nexora_Toolkit_Security::get( 'consent_enabled' );
	}

	/**
	 * Default wording.
	 *
	 * @return string
	 */
	public static function default_text() {
		return __( 'I am 18 or older and I agree to the {terms}. I have read the {privacy} and consent to my personal data being processed to run my account and fill my orders.', 'nexora-commerce-toolkit' );
	}

	/**
	 * Default marketing wording.
	 *
	 * @return string
	 */
	public static function default_marketing_text() {
		return __( 'Send me offers and market news by email. Optional. You can change this any time in your account.', 'nexora-commerce-toolkit' );
	}

	/**
	 * Is the optional marketing choice available?
	 *
	 * @return bool
	 */
	private static function marketing_on() {
		return (bool) Nexora_Toolkit_Security::get( 'marketing_enabled' ) && Nexora_Toolkit_Newsletter::enabled();
	}

	/**
	 * A linked document name, or plain text while the page is not public.
	 *
	 * @param string $url   URL.
	 * @param string $label Label.
	 * @return string Escaped HTML.
	 */
	private static function doc_link( $url, $label ) {
		return $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}

	/**
	 * Consent sentence with its links.
	 *
	 * @return string Escaped HTML.
	 */
	private static function sentence() {
		$text = (string) Nexora_Toolkit_Security::get( 'consent_text' );
		$text = '' !== $text ? $text : self::default_text();
		return strtr(
			esc_html( $text ),
			array(
				'{terms}'   => self::doc_link( Nexora_Toolkit_Security::terms_url(), __( 'Terms and Conditions', 'nexora-commerce-toolkit' ) ),
				'{privacy}' => self::doc_link( (string) get_privacy_policy_url(), __( 'Privacy Policy', 'nexora-commerce-toolkit' ) ),
			)
		);
	}

	/**
	 * The privacy line WooCommerce adds would repeat what the consent box already says.
	 */
	public static function drop_wc_privacy_text() {
		remove_action( 'woocommerce_register_form', 'wc_registration_privacy_policy_text', 20 );
	}

	/**
	 * Registration boxes.
	 */
	public static function fields() {
		self::print_boxes( true );
	}

	/**
	 * The same boxes in a checkout, where they sit in a section that may stay hidden, so they
	 * cannot use the browser's own required check.
	 */
	public static function checkout_fields() {
		self::print_boxes( false );
	}

	/**
	 * Print the boxes. Neither is ticked for the customer.
	 *
	 * @param bool $required Add the required attribute.
	 */
	private static function print_boxes( $required ) {
		if ( ! self::enabled() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- only restores what was ticked on a failed attempt.
		$terms     = ! empty( $_POST['nx_terms'] );
		$marketing = ! empty( $_POST['nx_marketing'] );
		// phpcs:enable
		?>
		<div class="form-row nx-consent">
			<label class="nx-consent__box" for="nx_terms">
				<input type="checkbox" name="nx_terms" id="nx_terms" value="1" <?php checked( $terms ); ?> <?php echo $required ? 'required aria-required="true"' : ''; ?>>
				<span><?php echo self::sentence(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?> <span class="required" aria-hidden="true">*</span></span>
			</label>
			<?php if ( self::marketing_on() ) : ?>
				<label class="nx-consent__box" for="nx_marketing">
					<input type="checkbox" name="nx_marketing" id="nx_marketing" value="1" <?php checked( $marketing ); ?>>
					<span><?php echo esc_html( self::marketing_wording() ); ?></span>
				</label>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Marketing wording.
	 *
	 * @return string
	 */
	private static function marketing_wording() {
		$text = (string) Nexora_Toolkit_Security::get( 'marketing_text' );
		return '' !== $text ? $text : self::default_marketing_text();
	}

	/**
	 * Check the posted form.
	 *
	 * @return string Error message or ''.
	 */
	public static function error() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the calling form verified its nonce.
		return self::enabled() && empty( $_POST['nx_terms'] ) ? __( 'Please accept the Terms and Conditions and Privacy Policy to create an account.', 'nexora-commerce-toolkit' ) : '';
	}

	/**
	 * Add to a WP_Error.
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function filter_errors( $errors ) {
		$message = self::error();
		if ( '' !== $message ) {
			$errors->add( 'nexora_terms', $message );
		}
		return $errors;
	}

	/**
	 * Will this WooCommerce checkout create an account?
	 *
	 * @param array $data Posted data.
	 * @return bool
	 */
	public static function wc_checkout_creates_account( $data ) {
		return ! is_user_logged_in() && ( ! empty( $data['createaccount'] ) || WC()->checkout()->is_registration_required() );
	}

	/**
	 * WooCommerce classic checkout.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function wc_checkout( $data, $errors ) {
		if ( self::wc_checkout_creates_account( $data ) ) {
			self::filter_errors( $errors );
		}
	}

	/* ----------------------------------------------------------------- record */

	/**
	 * Add to the customer's consent history.
	 *
	 * @param int    $user_id User.
	 * @param string $type    terms|marketing.
	 * @param bool   $granted Given or withdrawn.
	 * @param string $source  Where it happened.
	 */
	private static function log( $user_id, $type, $granted, $source ) {
		$entry = array(
			'type'    => $type,
			'granted' => $granted,
			'version' => (string) Nexora_Toolkit_Security::get( 'legal_version' ),
			'at'      => gmdate( 'c' ),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'source'  => $source,
		);
		$log   = get_user_meta( $user_id, self::LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = $entry;
		update_user_meta( $user_id, self::LOG, array_slice( $log, -50 ) );
		if ( 'terms' === $type ) {
			update_user_meta( $user_id, self::CURRENT, $entry );
		} else {
			update_user_meta( $user_id, self::MARKETING, $granted ? 1 : 0 );
		}
	}

	/**
	 * Save what was accepted when a form creates an account. Accounts added by staff post no box and get no record.
	 *
	 * @param int $user_id New user.
	 */
	public static function record_signup( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the calling form verified its nonce and validated these.
		if ( empty( $_POST['nx_terms'] ) ) {
			return;
		}
		self::log( $user_id, 'terms', true, 'registration' );
		if ( ! empty( $_POST['nx_marketing'] ) && self::marketing_on() ) {
			self::set_marketing( $user_id, true, 'registration' );
		}
		// phpcs:enable
	}

	/**
	 * Change the marketing choice and keep the newsletter list in step.
	 *
	 * @param int    $user_id User.
	 * @param bool   $on      Subscribed.
	 * @param string $source  Where it happened.
	 */
	private static function set_marketing( $user_id, $on, $source ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		self::log( $user_id, 'marketing', $on, $source );
		if ( $on ) {
			Nexora_Toolkit_Newsletter::subscribe(
				array(
					'email'   => $user->user_email,
					'consent' => 1,
					'source'  => $source,
				)
			);
		} else {
			Nexora_Toolkit_Newsletter::erase_personal_data( $user->user_email );
		}
	}

	/* -------------------------------------------------------- account screens */

	/**
	 * Does this customer still need to accept the current version?
	 *
	 * @return bool
	 */
	private static function needs_prompt() {
		$user = wp_get_current_user();
		if ( ! self::enabled() || ! $user->exists() || ! Nexora_Toolkit_Security::legal_ready() || Nexora_Toolkit_Login_OTP::is_staff( $user ) ) {
			return false;
		}
		$current = get_user_meta( $user->ID, self::CURRENT, true );
		return ! is_array( $current ) || ! isset( $current['version'] ) || (string) Nexora_Toolkit_Security::get( 'legal_version' ) !== (string) $current['version'];
	}

	/**
	 * Dashboard card asking for acceptance.
	 */
	public static function prompt() {
		if ( ! self::needs_prompt() ) {
			return;
		}
		?>
		<form method="post" class="nx-consent-card">
			<input type="hidden" name="nx_consent_action" value="accept">
			<?php wp_nonce_field( 'nexora_consent', '_nx_consent' ); ?>
			<p class="nx-consent-card__title"><?php esc_html_e( 'Please review our terms', 'nexora-commerce-toolkit' ); ?></p>
			<label class="nx-consent__box" for="nx_terms">
				<input type="checkbox" name="nx_terms" id="nx_terms" value="1" required aria-required="true">
				<span><?php echo self::sentence(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></span>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Accept', 'nexora-commerce-toolkit' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Marketing choice on the account details screen.
	 */
	public static function marketing_form() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! self::marketing_on() ) {
			return;
		}
		$on = (bool) get_user_meta( $user_id, self::MARKETING, true );
		?>
		<form method="post" class="nx-consent-card">
			<input type="hidden" name="nx_consent_action" value="<?php echo $on ? 'marketing_off' : 'marketing_on'; ?>">
			<?php wp_nonce_field( 'nexora_consent', '_nx_consent' ); ?>
			<p class="nx-consent-card__title"><?php esc_html_e( 'Marketing emails', 'nexora-commerce-toolkit' ); ?></p>
			<p><?php echo $on ? esc_html__( 'You are receiving offers and market news by email.', 'nexora-commerce-toolkit' ) : esc_html__( 'You are not receiving offers and market news by email.', 'nexora-commerce-toolkit' ); ?></p>
			<button type="submit" class="button"><?php echo $on ? esc_html__( 'Stop marketing emails', 'nexora-commerce-toolkit' ) : esc_html__( 'Send me marketing emails', 'nexora-commerce-toolkit' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Handle the two account forms.
	 */
	public static function handle_forms() {
		if ( empty( $_POST['nx_consent_action'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'nexora_consent', '_nx_consent' );
		$uid = get_current_user_id();
		switch ( sanitize_key( wp_unslash( $_POST['nx_consent_action'] ) ) ) {
			case 'accept':
				if ( ! empty( $_POST['nx_terms'] ) ) {
					self::log( $uid, 'terms', true, 'account' );
				}
				break;
			case 'marketing_on':
				if ( self::marketing_on() ) {
					self::set_marketing( $uid, true, 'account' );
				}
				break;
			case 'marketing_off':
				self::set_marketing( $uid, false, 'account' );
				break;
		}
		wp_safe_redirect( wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) ); // These forms post to their own page, which wp_get_referer() rejects.
		exit;
	}

	/* ------------------------------------------------------------------ admin */

	/**
	 * Words for a record.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	private static function describe( $entry ) {
		$what = 'marketing' === $entry['type'] ? __( 'Marketing emails', 'nexora-commerce-toolkit' ) : __( 'Terms and Privacy Policy', 'nexora-commerce-toolkit' );
		$verb = $entry['granted'] ? __( 'accepted', 'nexora-commerce-toolkit' ) : __( 'withdrawn', 'nexora-commerce-toolkit' );
		return $what . ': ' . $verb;
	}

	/**
	 * Read-only history on the user screen.
	 *
	 * @param WP_User $user User.
	 */
	public static function profile( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$log = get_user_meta( $user->ID, self::LOG, true );
		?>
		<h2><?php esc_html_e( 'Consent record', 'nexora-commerce-toolkit' ); ?></h2>
		<?php if ( ! is_array( $log ) || ! $log ) : ?>
			<p><?php esc_html_e( 'No consent has been recorded for this account.', 'nexora-commerce-toolkit' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:800px">
				<thead><tr>
					<th><?php esc_html_e( 'Decision', 'nexora-commerce-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Version', 'nexora-commerce-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Date (UTC)', 'nexora-commerce-toolkit' ); ?></th>
					<th><?php esc_html_e( 'IP address', 'nexora-commerce-toolkit' ); ?></th>
					<th><?php esc_html_e( 'Where', 'nexora-commerce-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( array_reverse( $log ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( self::describe( $entry ) ); ?></td>
							<td><?php echo esc_html( $entry['version'] ); ?></td>
							<td><?php echo esc_html( $entry['at'] ); ?></td>
							<td><?php echo esc_html( $entry['ip'] ); ?></td>
							<td><?php echo esc_html( $entry['source'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Add to Tools → Export Personal Data.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nexora-consent'] = array(
			'exporter_friendly_name' => __( 'Freshtastic consent records', 'nexora-commerce-toolkit' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Export a customer's consent records.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$user = get_user_by( 'email', $email );
		$log  = $user ? get_user_meta( $user->ID, self::LOG, true ) : array();
		$data = array();
		foreach ( is_array( $log ) ? $log : array() as $i => $entry ) {
			$data[] = array(
				'group_id'    => 'nexora-consent',
				'group_label' => __( 'Consent records', 'nexora-commerce-toolkit' ),
				'item_id'     => 'nexora-consent-' . $i,
				'data'        => array(
					array( 'name' => __( 'Decision', 'nexora-commerce-toolkit' ), 'value' => self::describe( $entry ) ),
					array( 'name' => __( 'Version', 'nexora-commerce-toolkit' ), 'value' => $entry['version'] ),
					array( 'name' => __( 'Date (UTC)', 'nexora-commerce-toolkit' ), 'value' => $entry['at'] ),
					array( 'name' => __( 'IP address', 'nexora-commerce-toolkit' ), 'value' => $entry['ip'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}
}
