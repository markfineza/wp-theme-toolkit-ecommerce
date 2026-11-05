<?php
/**
 * Final conversion banner.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_option( 'final_cta_title' ) ) {
	return;
}
$nexora_img = (int) nexora_option( 'final_cta_image' );
?>
<section class="nx-section nx-final-cta-wrap" aria-labelledby="nx-final-cta-title">
	<div class="nx-container">
		<div class="nx-final-cta">
			<?php if ( $nexora_img ) : ?>
				<?php echo wp_get_attachment_image( $nexora_img, 'full', false, array( 'class' => 'nx-final-cta__bg', 'alt' => '', 'loading' => 'lazy', 'sizes' => '(min-width: 1280px) 1240px, 100vw' ) ); ?>
			<?php endif; ?>
			<div class="nx-final-cta__content">
				<h2 class="nx-campaign__title" id="nx-final-cta-title"><?php echo esc_html( nexora_option( 'final_cta_title' ) ); ?></h2>
				<p><?php echo esc_html( nexora_option( 'final_cta_text' ) ); ?></p>
				<div class="nx-btn-row">
					<a class="nx-btn nx-btn--primary nx-btn--lg" href="<?php echo esc_url( nexora_url_option( 'final_cta_url', nexora_shop_url() ) ); ?>"><?php echo esc_html( nexora_option( 'final_cta_label' ) ); ?></a>
					<?php if ( nexora_is_woocommerce_active() ) : ?>
						<a class="nx-btn nx-btn--ghost nx-btn--lg" href="<?php echo esc_url( nexora_shop_url( array( 'on_sale' => 1 ) ) ); ?>"><?php esc_html_e( 'View current deals', 'nexora-commerce' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
