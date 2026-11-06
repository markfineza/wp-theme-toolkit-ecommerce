<?php
/**
 * Homepage hero with a live "market board" of recent price movers.
 * The hero image is the LCP element: it is preloaded and never lazy-loaded.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_image_id = (int) nexora_option( 'hero_image' );
$nexora_woo      = nexora_has_store();
$nexora_cta1     = nexora_url_option( 'hero_cta1_url', nexora_shop_url() );
$nexora_cta2     = nexora_option( 'hero_cta2_url' );
if ( ! $nexora_cta2 ) {
	$nexora_cta2 = $nexora_woo && nexora_rfq_enabled() ? nexora_rfq_url() : nexora_shop_url( array( 'orderby' => 'date' ) );
}
$nexora_board     = $nexora_woo && -1 !== (int) nexora_option( 'hero_product' ) ? nexora_board_products( 5 ) : array();
$nexora_threshold = (float) nexora_option( 'free_shipping_threshold' );
?>
<section class="nx-hero nx-dark" aria-labelledby="nx-hero-title">
	<div class="nx-hero__glow" aria-hidden="true"></div>
	<div class="nx-container nx-hero__inner">
		<div class="nx-hero__copy">
			<?php if ( nexora_option( 'hero_badge' ) ) : ?>
				<p class="nx-pill"><span class="nx-pill__dot" aria-hidden="true"></span><?php echo esc_html( nexora_option( 'hero_badge' ) ); ?></p>
			<?php endif; ?>
			<h1 class="nx-hero__title" id="nx-hero-title"><?php echo esc_html( nexora_option( 'hero_title' ) ); ?></h1>
			<p class="nx-hero__text"><?php echo esc_html( nexora_option( 'hero_text' ) ); ?></p>
			<div class="nx-btn-row">
				<?php if ( nexora_option( 'hero_cta1_label' ) ) : ?>
					<a class="nx-btn nx-btn--primary nx-btn--lg" href="<?php echo esc_url( $nexora_cta1 ); ?>"><?php echo esc_html( nexora_option( 'hero_cta1_label' ) ); ?> <?php echo nexora_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php endif; ?>
				<?php if ( nexora_option( 'hero_cta2_label' ) ) : ?>
					<a class="nx-btn nx-btn--glass nx-btn--lg" href="<?php echo esc_url( $nexora_cta2 ); ?>"><?php echo nexora_icon( 'clipboard', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( nexora_option( 'hero_cta2_label' ) ); ?></a>
				<?php endif; ?>
			</div>
			<ul class="nx-hero__perks">
				<?php if ( $nexora_woo && ! nexora_store_offers_free_shipping() ) : ?>
					<li><?php echo nexora_icon( 'store', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Pick up or book your own rider', 'nexora-commerce' ); ?></li>
				<?php elseif ( $nexora_threshold > 0 && $nexora_woo ) : ?>
					<li>
						<?php echo nexora_icon( 'truck', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php
						/* translators: %s: free delivery threshold */
						printf( esc_html__( 'Free delivery over %s', 'nexora-commerce' ), wp_kses_post( nexora_price( $nexora_threshold, array( 'decimals' => 0 ) ) ) );
						?>
					</li>
				<?php endif; ?>
				<li><?php echo nexora_icon( 'snow', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Packed cold', 'nexora-commerce' ); ?></li>
				<li><?php echo nexora_icon( 'scale', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Honest weights', 'nexora-commerce' ); ?></li>
			</ul>
		</div>

		<div class="nx-hero__media">
			<div class="nx-hero__frame">
				<?php
				if ( $nexora_image_id ) {
					echo wp_get_attachment_image(
						$nexora_image_id,
						'full',
						false,
						array(
							'class'         => 'nx-hero__img',
							'loading'       => 'eager',
							'fetchpriority' => 'high',
							'decoding'      => 'async',
							'sizes'         => '(min-width: 1024px) 55vw, 100vw',
						)
					);
				} else {
					printf( '<img class="nx-hero__img" src="%1$s" width="1600" height="1200" alt="" fetchpriority="high" decoding="async">', esc_url( NEXORA_URI . '/assets/images/hero-fallback.jpg' ) );
				}
				?>
			</div>
			<?php if ( $nexora_board ) : ?>
				<div class="nx-board">
					<div class="nx-board__head">
						<h2 class="nx-board__title"><?php echo esc_html( nexora_option( 'hero_board_title' ) ); ?></h2>
						<span class="nx-board__live" data-nexora-market-status data-open="<?php echo esc_attr( (string) nexora_option( 'market_open' ) ); ?>" data-close="<?php echo esc_attr( (string) nexora_option( 'market_close' ) ); ?>" data-offset="<?php echo esc_attr( (string) ( wp_timezone()->getOffset( new DateTime( 'now', wp_timezone() ) ) / 60 ) ); ?>"><span class="nx-ticker__dot" aria-hidden="true"></span><span data-nexora-market-label><?php esc_html_e( 'Prices today', 'nexora-commerce' ); ?></span></span>
					</div>
					<ul class="nx-board__rows">
						<?php foreach ( $nexora_board as $nexora_p ) : ?>
							<?php $nexora_m = nexora_market( $nexora_p ); ?>
							<li>
								<a href="<?php echo esc_url( $nexora_p->get_permalink() ); ?>">
									<span class="nx-board__thumb"><?php echo $nexora_p->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="nx-board__name"><?php echo esc_html( $nexora_p->get_name() ); ?></span>
									<span class="nx-board__price price"><?php echo wp_kses_post( $nexora_p->get_price_html() ); ?></span>
									<?php echo nexora_change_chip( $nexora_m['change'], $nexora_m['since'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<a class="nx-board__more" href="#price-board"><?php esc_html_e( 'Full price board', 'nexora-commerce' ); ?> <?php echo nexora_icon( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
