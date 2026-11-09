<?php
/**
 * Sign-in code page, at /verify-login/. It appears between the password and the session,
 * for customers and staff alike. The toolkit checks the code and prints the form.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_logo = has_custom_logo() ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' ) : nexora_brand_asset( 'logo-320.webp' );

get_header();
?>
<main id="primary" class="site-main nx-wc-page">
	<div class="nx-container">
		<div class="woocommerce">
			<div class="nx-auth">
				<aside class="nx-auth__brand">
					<span class="nx-auth__badge"><img src="<?php echo esc_url( $nexora_logo ); ?>" width="333" height="320" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></span>
					<p class="nx-auth__headline"><?php esc_html_e( 'One more step to keep your account safe', 'nexora-commerce' ); ?></p>
					<p class="nx-auth__lead"><?php esc_html_e( 'Your password was correct. Enter the code from your email to finish signing in.', 'nexora-commerce' ); ?></p>
				</aside>
				<div class="nx-auth__card">
					<h1 class="nx-auth__title"><?php echo esc_html( Nexora_Toolkit_Login_OTP::title() ); ?></h1>
					<?php Nexora_Toolkit_Login_OTP::render(); ?>
				</div>
			</div>
		</div>
	</div>
</main>
<?php
get_footer();
