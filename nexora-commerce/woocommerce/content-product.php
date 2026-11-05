<?php
/**
 * Product card used in every product loop (market edition: origin, unit, price change, stock).
 *
 * Override of woocommerce/templates/content-product.php (@version 9.4.0). The default loop callbacks are
 * unhooked in inc/woocommerce.php and rendered explicitly here; all core loop actions still fire so
 * extensions that add to them keep working.
 *
 * @package NexoraCommerce
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! is_a( $product, WC_Product::class ) || ! $product->is_visible() ) {
	return;
}

$nexora_link    = apply_filters( 'woocommerce_loop_product_link', get_the_permalink(), $product );
$nexora_heading = in_array( wc_get_loop_prop( 'nexora_heading', 'h2' ), array( 'h2', 'h3', 'h4' ), true ) ? wc_get_loop_prop( 'nexora_heading', 'h2' ) : 'h2';
$nexora_hover   = 0;
if ( nexora_option( 'card_hover_image' ) ) {
	$nexora_gallery = $product->get_gallery_image_ids();
	$nexora_hover   = $nexora_gallery ? (int) $nexora_gallery[0] : 0;
}
$nexora_cats   = wc_get_product_terms( $product->get_id(), 'product_cat', array( 'number' => 1 ) );
$nexora_market = nexora_market( $product );
?>
<li <?php wc_product_class( 'nx-card', $product ); ?>>
	<?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
	<div class="nx-card__media<?php echo $nexora_hover ? ' has-hover' : ''; ?>">
		<a href="<?php echo esc_url( $nexora_link ); ?>" class="nx-card__image-link" tabindex="-1" aria-hidden="true">
			<?php
			echo woocommerce_get_product_thumbnail( 'woocommerce_thumbnail', array( 'class' => 'nx-card__img', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $nexora_hover ) {
				echo wp_get_attachment_image( $nexora_hover, 'woocommerce_thumbnail', false, array( 'class' => 'nx-card__img nx-card__img--hover', 'alt' => '', 'loading' => 'lazy' ) );
			}
			?>
		</a>
		<?php nexora_render_badges( $product ); ?>
		<?php nexora_wishlist_button( $product ); ?>
		<?php do_action( 'woocommerce_before_shop_loop_item_title' ); ?>
	</div>

	<div class="nx-card__body">
		<p class="nx-card__cat">
			<?php if ( $nexora_cats && ! is_wp_error( $nexora_cats ) ) : ?>
				<span><?php echo esc_html( $nexora_cats[0]->name ); ?></span>
			<?php endif; ?>
			<?php if ( $nexora_market['origin'] ) : ?>
				<span class="nx-card__origin"><?php echo esc_html( $nexora_market['origin'] ); ?></span>
			<?php endif; ?>
		</p>
		<<?php echo esc_html( $nexora_heading ); ?> class="woocommerce-loop-product__title nx-card__title">
			<a href="<?php echo esc_url( $nexora_link ); ?>" class="woocommerce-LoopProduct-link woocommerce-loop-product__link"><?php echo esc_html( $product->get_name() ); ?></a>
		</<?php echo esc_html( $nexora_heading ); ?>>
		<?php do_action( 'woocommerce_shop_loop_item_title' ); ?>

		<?php if ( nexora_option( 'card_rating' ) && wc_review_ratings_enabled() && $product->get_rating_count() ) : ?>
			<div class="nx-card__rating">
				<?php echo wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="nx-card__reviews">(<?php echo esc_html( (string) $product->get_review_count() ); ?>)</span>
			</div>
		<?php endif; ?>

		<div class="nx-card__pricerow">
			<?php woocommerce_template_loop_price(); ?>
			<?php echo nexora_change_chip( $nexora_market['change'], $nexora_market['since'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php if ( $nexora_market['from_per'] ) : ?>
			<p class="nx-card__per">
				<?php
				/* translators: 1: price, 2: kg or L */
				echo wp_kses_post( sprintf( __( 'From %1$s / %2$s', 'nexora-commerce' ), wc_price( $nexora_market['from_per']['per'] ), esc_html( $nexora_market['from_per']['measure'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php do_action( 'woocommerce_after_shop_loop_item_title' ); ?>

		<p class="nx-card__stock"><?php echo nexora_stock_line( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<p class="nx-card__excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 18 ) ); ?></p>

		<div class="nx-card__actions">
			<?php
			if ( ! $product->is_in_stock() && nexora_rfq_enabled() ) {
				echo nexora_rfq_button( $product, __( 'Request quote', 'nexora-commerce' ), 'button nx-btn nx-btn--accent nx-btn--sm nx-card__rfq' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( nexora_option( 'card_quick_add' ) ) {
				woocommerce_template_loop_add_to_cart();
			}
			do_action( 'woocommerce_after_shop_loop_item' );
			?>
		</div>
	</div>
</li>
