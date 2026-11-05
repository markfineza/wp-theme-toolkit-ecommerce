<?php
/**
 * Newsletter section.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! apply_filters( 'nexora_newsletter_enabled', false ) && ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<section class="nx-section nx-newsletter-section" aria-labelledby="nx-newsletter-title">
	<div class="nx-container">
		<div class="nx-newsletter nx-dark">
			<div class="nx-newsletter__copy">
				<p class="nx-eyebrow"><?php esc_html_e( 'Newsletter', 'nexora-commerce' ); ?></p>
				<h2 class="nx-section-title" id="nx-newsletter-title"><?php echo esc_html( nexora_option( 'newsletter_title' ) ); ?></h2>
				<p><?php echo esc_html( nexora_option( 'newsletter_text' ) ); ?></p>
			</div>
			<?php get_template_part( 'template-parts/marketing/newsletter-form', null, array( 'id' => 'home' ) ); ?>
		</div>
	</div>
</section>
