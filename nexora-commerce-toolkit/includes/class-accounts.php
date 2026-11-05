<?php
/**
 * Customer registration: name and mobile number on the My Account form, saved to the customer's
 * profile and billing details so the first checkout is already filled in.
 *
 * Also turns registration on once for existing stores, because WooCommerce ships with it off and
 * the form would not appear. Admins can change it afterwards in WooCommerce → Settings → Accounts & Privacy.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accounts module.
 */
class Nexora_Toolkit_Accounts {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'enable_registration_once' ) );
		add_action( 'woocommerce_register_form_start', array( __CLASS__, 'fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'save' ) );
	}

	/**
	 * Open registration once, with customers choosing their own password.
	 */
	public static function enable_registration_once() {
		if ( get_option( 'nexora_registration_defaulted' ) ) {
			return;
		}
		update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
		update_option( 'woocommerce_registration_generate_password', 'no' );
		update_option( 'nexora_registration_defaulted', 1, false );
	}

	/**
	 * Posted value (WC_Form_Handler verifies the registration nonce before these hooks run).
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Name and mobile fields.
	 */
	public static function fields() {
		$required = '&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text">' . esc_html__( 'Required', 'nexora-commerce-toolkit' ) . '</span>';
		?>
		<div class="nexora-register-names">
			<p class="woocommerce-form-row form-row form-row-first">
				<label for="reg_first_name"><?php esc_html_e( 'First name', 'nexora-commerce-toolkit' ); ?><?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="nexora_first_name" id="reg_first_name" autocomplete="given-name" maxlength="60" value="<?php echo esc_attr( self::posted( 'nexora_first_name' ) ); ?>" required aria-required="true">
			</p>
			<p class="woocommerce-form-row form-row form-row-last">
				<label for="reg_last_name"><?php esc_html_e( 'Last name', 'nexora-commerce-toolkit' ); ?><?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="nexora_last_name" id="reg_last_name" autocomplete="family-name" maxlength="60" value="<?php echo esc_attr( self::posted( 'nexora_last_name' ) ); ?>" required aria-required="true">
			</p>
		</div>
		<p class="woocommerce-form-row form-row form-row-wide">
			<label for="reg_mobile"><?php esc_html_e( 'Mobile number', 'nexora-commerce-toolkit' ); ?><?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="nexora_mobile" id="reg_mobile" autocomplete="tel" placeholder="09XX XXX XXXX" maxlength="20" value="<?php echo esc_attr( self::posted( 'nexora_mobile' ) ); ?>" required aria-required="true">
		</p>
		<?php
	}

	/**
	 * Validate. This filter only runs for the My Account form, not for accounts created at checkout.
	 *
	 * @param WP_Error $errors Errors.
	 * @return WP_Error
	 */
	public static function validate( $errors ) {
		if ( '' === self::posted( 'nexora_first_name' ) ) {
			$errors->add( 'nexora_first_name', __( 'Please enter your first name.', 'nexora-commerce-toolkit' ) );
		}
		if ( '' === self::posted( 'nexora_last_name' ) ) {
			$errors->add( 'nexora_last_name', __( 'Please enter your last name.', 'nexora-commerce-toolkit' ) );
		}
		$mobile = preg_replace( '/[\s\-().]/', '', self::posted( 'nexora_mobile' ) );
		if ( ! WC_Validation::is_phone( $mobile ) || strlen( preg_replace( '/\D/', '', $mobile ) ) < 10 ) {
			$errors->add( 'nexora_mobile', __( 'Please enter a valid mobile number, for example 0917 123 4567.', 'nexora-commerce-toolkit' ) );
		}
		return $errors;
	}

	/**
	 * Save to the new customer.
	 *
	 * @param int $customer_id Customer ID.
	 */
	public static function save( $customer_id ) {
		$first = self::posted( 'nexora_first_name' );
		$last  = self::posted( 'nexora_last_name' );
		if ( '' === $first && '' === $last ) {
			return; // Account created at checkout: WooCommerce fills these in from the order.
		}
		wp_update_user(
			array(
				'ID'           => $customer_id,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
			)
		);
		update_user_meta( $customer_id, 'billing_first_name', $first );
		update_user_meta( $customer_id, 'billing_last_name', $last );
		update_user_meta( $customer_id, 'billing_phone', self::posted( 'nexora_mobile' ) );
		$user = get_userdata( $customer_id );
		if ( $user ) {
			update_user_meta( $customer_id, 'billing_email', $user->user_email );
		}
	}
}
