<?php
/**
 * Freshtastic sign in / create account: brand panel with the logo beside a tabbed card.
 * /my-account/?action=register opens the registration tab. Without JavaScript both forms are shown.
 *
 * @package NexoraCommerce
 * @version 9.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nexora_register = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
// phpcs:disable WordPress.Security.NonceVerification -- only picks which tab to show.
$nexora_tab = $nexora_register && ( isset( $_POST['register'] ) || ( isset( $_GET['action'] ) && 'register' === $_GET['action'] ) ) ? 'register' : 'login';
// phpcs:enable
$nexora_logo = has_custom_logo() ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : nexora_brand_asset( 'logo-320.webp' );

do_action( 'woocommerce_before_customer_login_form' ); ?>

<div class="nx-auth" id="customer_login" data-nexora-auth>

	<aside class="nx-auth__brand">
		<span class="nx-auth__badge"><img src="<?php echo esc_url( $nexora_logo ); ?>" width="333" height="320" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></span>
		<h2 class="nx-auth__headline"><?php esc_html_e( 'Freshness you can always trust', 'nexora-commerce' ); ?></h2>
		<p class="nx-auth__lead"><?php esc_html_e( 'Meat, fish, vegetables, frozen goods and more, packed when you need them.', 'nexora-commerce' ); ?></p>
		<ul class="nx-auth__perks">
			<li><?php echo nexora_icon( 'clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Track orders and pickup times', 'nexora-commerce' ); ?></li>
			<li><?php echo nexora_icon( 'pin', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Check out faster with saved details', 'nexora-commerce' ); ?></li>
			<li><?php echo nexora_icon( 'clipboard', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Request quotes for bulk orders', 'nexora-commerce' ); ?></li>
		</ul>
	</aside>

	<div class="nx-auth__card">

		<?php if ( $nexora_register ) : ?>
			<div class="nx-auth__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Your account', 'nexora-commerce' ); ?>" hidden data-nexora-auth-tabs>
				<button type="button" role="tab" id="nx-tab-login" aria-controls="nx-panel-login" aria-selected="<?php echo 'login' === $nexora_tab ? 'true' : 'false'; ?>" data-nexora-tab="login"><?php esc_html_e( 'Sign in', 'nexora-commerce' ); ?></button>
				<button type="button" role="tab" id="nx-tab-register" aria-controls="nx-panel-register" aria-selected="<?php echo 'register' === $nexora_tab ? 'true' : 'false'; ?>" data-nexora-tab="register"><?php esc_html_e( 'Create account', 'nexora-commerce' ); ?></button>
			</div>
		<?php endif; ?>

		<div class="nx-auth__panel" id="nx-panel-login" <?php echo $nexora_register ? 'role="tabpanel" aria-labelledby="nx-tab-login"' : ''; ?> data-nexora-panel="login" <?php echo 'register' === $nexora_tab ? 'data-inactive' : ''; ?>>
			<h2 class="nx-auth__title"><?php esc_html_e( 'Welcome back', 'nexora-commerce' ); ?></h2>
			<p class="nx-auth__sub"><?php esc_html_e( 'Sign in to see your orders and pickup times.', 'nexora-commerce' ); ?></p>

			<form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>

				<?php do_action( 'woocommerce_login_form_start' ); ?>

				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="username"><?php esc_html_e( 'Email or username', 'nexora-commerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
					<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) && is_string( $_POST['username'] ) && ! isset( $_POST['register'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // @codingStandardsIgnoreLine ?>
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
					<label for="password"><?php esc_html_e( 'Password', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
					<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required aria-required="true" />
				</p>

				<?php do_action( 'woocommerce_login_form' ); ?>

				<div class="nx-auth__row">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme">
						<input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" /> <span><?php esc_html_e( 'Remember me', 'woocommerce' ); ?></span>
					</label>
					<a class="woocommerce-LostPassword lost_password" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot your password?', 'nexora-commerce' ); ?></a>
				</div>

				<p class="form-row nx-auth__submit">
					<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
					<button type="submit" class="woocommerce-button button woocommerce-form-login__submit<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="login" value="<?php esc_attr_e( 'Log in', 'woocommerce' ); ?>"><?php esc_html_e( 'Sign in', 'nexora-commerce' ); ?></button>
				</p>

				<?php do_action( 'woocommerce_login_form_end' ); ?>

			</form>

			<?php if ( $nexora_register ) : ?>
				<p class="nx-auth__switch"><?php esc_html_e( 'New to Freshtastic?', 'nexora-commerce' ); ?> <a href="#nx-panel-register" data-nexora-tab-link="register"><?php esc_html_e( 'Create an account', 'nexora-commerce' ); ?></a></p>
			<?php endif; ?>
		</div>

		<?php if ( $nexora_register ) : ?>
			<div class="nx-auth__panel" id="nx-panel-register" role="tabpanel" aria-labelledby="nx-tab-register" data-nexora-panel="register" <?php echo 'login' === $nexora_tab ? 'data-inactive' : ''; ?>>
				<h2 class="nx-auth__title"><?php esc_html_e( 'Create your account', 'nexora-commerce' ); ?></h2>
				<p class="nx-auth__sub"><?php esc_html_e( 'It only takes a minute. We use your details for your orders and pickups.', 'nexora-commerce' ); ?></p>

				<form method="post" class="woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?> >

					<?php do_action( 'woocommerce_register_form_start' ); ?>

					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>

						<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
							<label for="reg_username"><?php esc_html_e( 'Username', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
							<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" required aria-required="true" /><?php // @codingStandardsIgnoreLine ?>
						</p>

					<?php endif; ?>

					<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
						<label for="reg_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
						<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" required aria-required="true" /><?php // @codingStandardsIgnoreLine ?>
					</p>

					<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>

						<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
							<label for="reg_password"><?php esc_html_e( 'Password', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
							<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" />
						</p>

					<?php else : ?>

						<p class="nx-auth__hint"><?php echo nexora_icon( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'A link to set a new password will be sent to your email address.', 'woocommerce' ); ?></p>

					<?php endif; ?>

					<?php do_action( 'woocommerce_register_form' ); ?>

					<p class="woocommerce-form-row form-row nx-auth__submit">
						<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
						<button type="submit" class="woocommerce-Button woocommerce-button button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?> woocommerce-form-register__submit" name="register" value="<?php esc_attr_e( 'Register', 'woocommerce' ); ?>"><?php esc_html_e( 'Create account', 'nexora-commerce' ); ?></button>
					</p>

					<?php do_action( 'woocommerce_register_form_end' ); ?>

				</form>

				<p class="nx-auth__switch"><?php esc_html_e( 'Already have an account?', 'nexora-commerce' ); ?> <a href="#nx-panel-login" data-nexora-tab-link="login"><?php esc_html_e( 'Sign in', 'nexora-commerce' ); ?></a></p>
			</div>
		<?php endif; ?>

	</div>
</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
