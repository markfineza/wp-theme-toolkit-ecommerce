<?php
/**
 * Freshtastic profile form: profile header, then "Personal details" and "Password" sections.
 *
 * @package NexoraCommerce
 * @version 11.0.0
 * @var WP_User $user
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_edit_account_form' );
?>

<header class="nx-profile-head">
	<span class="nx-avatar nx-avatar--lg" aria-hidden="true"><?php echo esc_html( nexora_user_initials( $user ) ); ?></span>
	<div>
		<h2 class="nx-profile-head__name"><?php echo esc_html( $user->display_name ); ?></h2>
		<p class="nx-profile-head__meta">
			<?php echo esc_html( $user->user_email ); ?>
			<?php /* translators: %s: registration date */ ?>
			<span><?php echo esc_html( sprintf( __( 'Member since %s', 'nexora-commerce' ), wp_date( get_option( 'date_format' ), strtotime( $user->user_registered ) ) ) ); ?></span>
		</p>
	</div>
</header>

<form class="woocommerce-EditAccountForm edit-account nx-form" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?> >

	<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

	<fieldset class="nx-form__section">
		<legend class="nx-form__legend">
			<span><?php esc_html_e( 'Personal details', 'nexora-commerce' ); ?></span>
			<small><?php esc_html_e( 'Used on your orders and receipts.', 'nexora-commerce' ); ?></small>
		</legend>

		<div class="nx-form__grid">
			<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
				<label for="account_first_name"><?php esc_html_e( 'First name', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" aria-required="true" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
				<label for="account_last_name"><?php esc_html_e( 'Last name', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" aria-required="true" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="account_display_name"><?php esc_html_e( 'Display name', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_display_name" id="account_display_name" aria-describedby="account_display_name_description" value="<?php echo esc_attr( $user->display_name ); ?>" aria-required="true" />
				<span id="account_display_name_description" class="nx-form__help"><?php echo wc_reviews_enabled() ? esc_html__( 'This will be how your name will be displayed in the account section and in reviews', 'woocommerce' ) : esc_html__( 'This will be how your name will be displayed in the account section', 'woocommerce' ); ?></span>
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="account_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" aria-required="true" />
			</p>

			<?php do_action( 'woocommerce_edit_account_form_fields' ); ?>
		</div>
	</fieldset>

	<fieldset class="nx-form__section">
		<legend class="nx-form__legend">
			<span><?php esc_html_e( 'Password', 'nexora-commerce' ); ?></span>
			<small><?php esc_html_e( 'Leave these empty to keep your current password.', 'nexora-commerce' ); ?></small>
		</legend>

		<div class="nx-form__grid">
			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="password_current"><?php esc_html_e( 'Current password', 'nexora-commerce' ); ?></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
				<label for="password_1"><?php esc_html_e( 'New password', 'nexora-commerce' ); ?></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" />
			</p>
			<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
				<label for="password_2"><?php esc_html_e( 'Confirm new password', 'woocommerce' ); ?></label>
				<input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" />
			</p>
		</div>
	</fieldset>

	<?php do_action( 'woocommerce_edit_account_form' ); ?>

	<div class="nx-form__actions">
		<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
		<button type="submit" class="woocommerce-Button button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="save_account_details" value="<?php esc_attr_e( 'Save changes', 'woocommerce' ); ?>"><?php esc_html_e( 'Save changes', 'woocommerce' ); ?></button>
		<input type="hidden" name="action" value="save_account_details" />
	</div>

	<?php do_action( 'woocommerce_edit_account_form_end' ); ?>
</form>

<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
