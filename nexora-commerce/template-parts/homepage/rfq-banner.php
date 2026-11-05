<?php
/**
 * Request-for-quotation banner: for items not on the board, bulk volumes and regular deliveries.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_is_woocommerce_active() || ! nexora_rfq_enabled() ) {
	return;
}
$nexora_steps = array(
	array( 'clipboard', __( 'Tell us the item', 'nexora-commerce' ), __( 'Quantity, unit, target price and delivery date.', 'nexora-commerce' ) ),
	array( 'store', __( 'We source it', 'nexora-commerce' ), __( 'We check farms, ports and suppliers for supply and price.', 'nexora-commerce' ) ),
	array( 'chat', __( 'Get your quote', 'nexora-commerce' ), __( 'Usually within one business day, by email or phone.', 'nexora-commerce' ) ),
);
?>
<section class="nx-section nx-rfq-banner-wrap" aria-labelledby="nx-rfq-banner-title">
	<div class="nx-container">
		<div class="nx-rfq-banner">
			<div class="nx-rfq-banner__copy">
				<p class="nx-eyebrow"><?php esc_html_e( 'Request for quotation', 'nexora-commerce' ); ?></p>
				<h2 class="nx-section-title" id="nx-rfq-banner-title"><?php echo esc_html( nexora_option( 'rfq_title' ) ); ?></h2>
				<p><?php echo esc_html( nexora_option( 'rfq_text' ) ); ?></p>
				<a class="nx-btn nx-btn--accent nx-btn--lg" href="<?php echo esc_url( nexora_rfq_url() ); ?>"><?php echo nexora_icon( 'clipboard', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Request a quotation', 'nexora-commerce' ); ?></a>
			</div>
			<ol class="nx-rfq-steps">
				<?php foreach ( $nexora_steps as $nexora_i => $nexora_step ) : ?>
					<li>
						<span class="nx-rfq-steps__num" aria-hidden="true"><?php echo esc_html( (string) ( $nexora_i + 1 ) ); ?></span>
						<span class="nx-rfq-steps__icon" aria-hidden="true"><?php echo nexora_icon( $nexora_step[0], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<strong><?php echo esc_html( $nexora_step[1] ); ?></strong>
						<span><?php echo esc_html( $nexora_step[2] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</div>
</section>
