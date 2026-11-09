<?php
/**
 * Homepage hero: promotional banners from Customizer → Freshtastic Homepage → Hero promotional banner.
 * With several banners they slide sideways, with swipe, pause, previous and next controls. A banner with
 * no image shows only the section background. The first image is the LCP element, so it is preloaded and
 * never lazy-loaded.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_slides = nexora_promo_slides();
if ( ! $nexora_slides ) {
	return;
}
$nexora_count = count( $nexora_slides );
?>
<section class="nx-promo nx-dark" aria-label="<?php esc_attr_e( 'Promotions', 'nexora-commerce' ); ?>" data-nexora-promo>
	<div class="nx-promo__shape" aria-hidden="true"></div>
	<div class="nx-promo__slides" data-nexora-slides>
	<?php foreach ( $nexora_slides as $nexora_i => $nexora_slide ) : ?>
		<?php
		$nexora_heading = 0 === $nexora_i ? 'h1' : 'h2';
		$nexora_url     = $nexora_slide['url'] ? $nexora_slide['url'] : ( nexora_has_store() ? nexora_shop_url( array( 'on_sale' => 1 ) ) : home_url( '/' ) );
		?>
		<div class="nx-promo__slide<?php echo 0 === $nexora_i ? ' is-active' : ''; ?>" data-nexora-slide<?php echo 0 === $nexora_i ? '' : ' inert'; ?>>
			<div class="nx-container nx-promo__inner">
				<div class="nx-promo__copy">
					<?php if ( $nexora_slide['badge'] ) : ?>
						<p class="nx-promo__badge"><?php echo nexora_icon( 'tag', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $nexora_slide['badge'] ); ?></p>
					<?php endif; ?>
					<?php if ( $nexora_slide['eyebrow'] ) : ?>
						<p class="nx-promo__eyebrow"><?php echo esc_html( $nexora_slide['eyebrow'] ); ?></p>
					<?php endif; ?>
					<<?php echo esc_html( $nexora_heading ); ?> class="nx-promo__title"><?php echo esc_html( $nexora_slide['title'] ); ?></<?php echo esc_html( $nexora_heading ); ?>>
					<?php if ( $nexora_slide['text'] ) : ?>
						<p class="nx-promo__text"><?php echo esc_html( $nexora_slide['text'] ); ?></p>
					<?php endif; ?>
					<?php if ( $nexora_slide['ends'] ) : ?>
						<p class="nx-promo__ends" data-nexora-promo-ends="<?php echo esc_attr( (string) $nexora_slide['ends'] ); ?>">
							<?php echo nexora_icon( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Ends in', 'nexora-commerce' ); ?>
							<time datetime="<?php echo esc_attr( gmdate( 'c', $nexora_slide['ends'] ) ); ?>" data-nexora-promo-time><?php echo esc_html( wp_date( get_option( 'date_format' ), $nexora_slide['ends'] ) ); ?></time>
						</p>
					<?php endif; ?>
					<div class="nx-promo__actions">
						<?php if ( $nexora_slide['label'] ) : ?>
							<a class="nx-btn nx-btn--accent nx-btn--lg" href="<?php echo esc_url( $nexora_url ); ?>"><?php echo esc_html( $nexora_slide['label'] ); ?> <?php echo nexora_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						<?php endif; ?>
						<?php if ( $nexora_slide['code'] ) : ?>
							<button type="button" class="nx-code" data-nexora-copy="<?php echo esc_attr( $nexora_slide['code'] ); ?>">
								<code><?php echo esc_html( $nexora_slide['code'] ); ?></code>
								<span class="nx-code__action" data-nexora-copy-label><?php esc_html_e( 'Copy code', 'nexora-commerce' ); ?></span>
							</button>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( $nexora_slide['image'] ) : ?>
					<div class="nx-promo__media">
						<?php
						echo wp_get_attachment_image(
							$nexora_slide['image'],
							'full',
							false,
							array(
								'class'         => 'nx-promo__img',
								'alt'           => '',
								'loading'       => 0 === $nexora_i ? 'eager' : 'lazy',
								'fetchpriority' => 0 === $nexora_i ? 'high' : 'auto',
								'decoding'      => 'async',
								'sizes'         => '(min-width: 1024px) 50vw, 100vw',
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
	</div>
	<?php if ( $nexora_count > 1 ) : ?>
		<div class="nx-container nx-promo__controls">
			<button type="button" class="nx-promo__btn" data-nexora-prev aria-label="<?php esc_attr_e( 'Previous banner', 'nexora-commerce' ); ?>"><?php echo nexora_icon( 'chevron-left', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<?php for ( $nexora_d = 0; $nexora_d < $nexora_count; $nexora_d++ ) : ?>
				<?php /* translators: %d: banner number */ ?>
				<button type="button" class="nx-promo__dot" data-nexora-dot aria-label="<?php echo esc_attr( sprintf( __( 'Show banner %d', 'nexora-commerce' ), $nexora_d + 1 ) ); ?>"<?php echo 0 === $nexora_d ? ' aria-current="true"' : ''; ?>></button>
			<?php endfor; ?>
			<button type="button" class="nx-promo__btn" data-nexora-next aria-label="<?php esc_attr_e( 'Next banner', 'nexora-commerce' ); ?>"><?php echo nexora_icon( 'chevron-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<button type="button" class="nx-promo__btn nx-promo__pause" data-nexora-promo-pause>
				<span class="nx-promo__icon-pause"><?php echo nexora_icon( 'pause', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="nx-promo__icon-play"><?php echo nexora_icon( 'play', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</button>
			<p class="screen-reader-text" aria-live="polite" data-nexora-promo-live></p>
		</div>
	<?php endif; ?>
</section>
