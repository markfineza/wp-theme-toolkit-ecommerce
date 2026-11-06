<?php
/**
 * Market basket collection: a large card beside eight product tiles (featured products, topped up with new ones).
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() ) {
	return;
}

$nexora_ids = nexora_get_product_ids( 'featured', 8 );
if ( count( $nexora_ids ) < 8 ) {
	$nexora_ids = array_slice( array_unique( array_merge( $nexora_ids, nexora_get_product_ids( 'latest', 16 ) ) ), 0, 8 );
}
if ( ! $nexora_ids ) {
	return;
}
$nexora_url   = nexora_url_option( 'basket_card_url', nexora_shop_url() );
$nexora_image = (int) nexora_option( 'basket_image' );
$nexora_image = $nexora_image ? $nexora_image : (int) nexora_option( 'campaign_image' );
?>
<section class="nx-section nx-basket" aria-labelledby="nx-basket-title">
	<div class="nx-container">
		<?php nexora_center_heading( nexora_option( 'basket_title' ), nexora_option( 'basket_eyebrow' ), 'nx-basket-title' ); ?>
		<div class="nx-basket__layout">
			<a class="nx-basket__card" href="<?php echo esc_url( $nexora_url ); ?>">
				<span class="nx-basket__media">
					<?php
					if ( $nexora_image ) {
						echo wp_get_attachment_image( $nexora_image, 'large', false, array( 'alt' => '', 'loading' => 'lazy', 'sizes' => '(min-width: 1024px) 400px, 100vw' ) );
					} else {
						printf( '<img src="%s" width="1600" height="1200" alt="" loading="lazy">', esc_url( NEXORA_URI . '/assets/images/hero-fallback.jpg' ) );
					}
					?>
				</span>
				<span class="nx-basket__text">
					<small><?php echo esc_html( nexora_option( 'basket_card_small' ) ); ?></small>
					<strong><?php echo esc_html( nexora_option( 'basket_card_title' ) ); ?> <span class="nx-basket__arrow" aria-hidden="true"><?php echo nexora_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></strong>
				</span>
			</a>
			<ul class="nx-basket__tiles">
				<?php foreach ( $nexora_ids as $nexora_id ) : ?>
					<?php
					$nexora_p = nexora_get_product( $nexora_id );
					if ( ! $nexora_p ) {
						continue;
					}
					?>
					<li>
						<a class="nx-basket__tile" href="<?php echo esc_url( $nexora_p->get_permalink() ); ?>">
							<?php if ( $nexora_p->is_on_sale() ) : ?>
								<span class="nx-basket__sale"><?php esc_html_e( 'Sale', 'nexora-commerce' ); ?></span>
							<?php endif; ?>
							<span class="nx-basket__tile-img"><?php echo $nexora_p->get_image( 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="nx-basket__tile-name"><?php echo esc_html( $nexora_p->get_name() ); ?></span>
							<span class="nx-basket__tile-price price"><?php echo wp_kses_post( $nexora_p->get_price_html() ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</section>
