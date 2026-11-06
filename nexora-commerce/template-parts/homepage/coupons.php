<?php
/**
 * Coupons band. Only coupons with "Show this code on the homepage" ticked appear, and the section is
 * skipped when there are none. Personal and email-restricted coupons never appear.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() || ! class_exists( 'Nexora_Toolkit_Rewards' ) ) {
	return;
}
$nexora_coupons = Nexora_Toolkit_Rewards::public_coupons( 6 );
if ( ! $nexora_coupons ) {
	return;
}
?>
<section class="nx-coupons" aria-labelledby="nx-coupons-title">
	<div class="nx-coupons__band nx-dark">
		<div class="nx-container">
			<h2 class="nx-coupons__title" id="nx-coupons-title"><?php echo esc_html( nexora_option( 'coupons_title' ) ); ?></h2>
			<p class="nx-coupons__text"><?php echo esc_html( nexora_option( 'coupons_text' ) ); ?></p>
		</div>
	</div>
	<div class="nx-container">
		<ul class="nx-coupons__list">
			<?php foreach ( $nexora_coupons as $nexora_coupon ) : ?>
				<li class="nx-coupon">
					<strong class="nx-coupon__title"><?php echo esc_html( $nexora_coupon['title'] ); ?></strong>
					<?php if ( $nexora_coupon['terms'] ) : ?>
						<p class="nx-coupon__terms"><?php echo esc_html( $nexora_coupon['terms'] ); ?></p>
					<?php endif; ?>
					<button type="button" class="nx-code nx-code--light" data-nexora-copy="<?php echo esc_attr( $nexora_coupon['code'] ); ?>">
						<code><?php echo esc_html( $nexora_coupon['code'] ); ?></code>
						<span class="nx-code__action" data-nexora-copy-label><?php esc_html_e( 'Copy code', 'nexora-commerce' ); ?></span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
