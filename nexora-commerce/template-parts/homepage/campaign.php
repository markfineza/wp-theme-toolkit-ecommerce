<?php
/**
 * Campaign banner with optional schedule.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_start = nexora_datetime_to_timestamp( nexora_option( 'campaign_start' ) );
$nexora_end   = nexora_datetime_to_timestamp( nexora_option( 'campaign_end' ) );
if ( ( $nexora_start && $nexora_start > time() ) || ( $nexora_end && $nexora_end < time() ) || ! nexora_option( 'campaign_title' ) ) {
	return;
}

$nexora_url = nexora_option( 'campaign_cta_url' );
if ( ! $nexora_url && nexora_is_woocommerce_active() ) {
	$nexora_term = get_term_by( 'slug', 'dry-market', 'product_cat' );
	$nexora_url  = $nexora_term ? get_term_link( $nexora_term ) : nexora_shop_url();
}
$nexora_img = (int) nexora_option( 'campaign_image' );
?>
<section class="nx-section nx-campaign-wrap" aria-labelledby="nx-campaign-title">
	<div class="nx-container">
		<div class="nx-campaign nx-dark">
			<?php if ( $nexora_img ) : ?>
				<?php echo wp_get_attachment_image( $nexora_img, 'full', false, array( 'class' => 'nx-campaign__bg', 'alt' => '', 'loading' => 'lazy', 'sizes' => '(min-width: 1280px) 1240px, 100vw' ) ); ?>
			<?php endif; ?>
			<div class="nx-campaign__content">
				<?php if ( nexora_option( 'campaign_eyebrow' ) ) : ?>
					<p class="nx-pill"><?php echo esc_html( nexora_option( 'campaign_eyebrow' ) ); ?></p>
				<?php endif; ?>
				<h2 class="nx-campaign__title" id="nx-campaign-title"><?php echo esc_html( nexora_option( 'campaign_title' ) ); ?></h2>
				<p><?php echo esc_html( nexora_option( 'campaign_text' ) ); ?></p>
				<?php if ( $nexora_url && nexora_option( 'campaign_cta_label' ) ) : ?>
					<a class="nx-btn nx-btn--light" href="<?php echo esc_url( $nexora_url ); ?>"><?php echo esc_html( nexora_option( 'campaign_cta_label' ) ); ?> <?php echo nexora_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
