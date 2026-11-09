<?php
/**
 * Plain sign-in code page, for the wp-admin login box and themes without their own login-code.php.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'nx-otp-page' ); ?>>
<?php wp_body_open(); ?>
<main class="nx-otp-card">
	<h1><?php echo esc_html( Nexora_Toolkit_Login_OTP::title() ); ?></h1>
	<?php Nexora_Toolkit_Login_OTP::render(); ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
