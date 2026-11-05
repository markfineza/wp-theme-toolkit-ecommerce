<?php
/**
 * Sticky add-to-cart bar (theme-specific template, not a WooCommerce override).
 *
 * Simple, in-stock products post to WooCommerce's normal add-to-cart handler, so stock and
 * validation rules still apply. Other product types jump to the main form to choose options.
 *
 * @package NexoraCommerce
 *
 * @var WC_Product $product
 */

defined( 'ABSPATH' ) || exit;

$nexora_direct = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() && ! $product->is_sold_individually();
?>
<div class="nx-sticky-atc" data-nexora-sticky-atc hidden>
	<div class="nx-container nx-sticky-atc__inner">
		<div class="nx-sticky-atc__product">
			<?php echo $product->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div>
				<p class="nx-sticky-atc__name"><?php echo esc_html( $product->get_name() ); ?></p>
				<p class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
			</div>
		</div>
		<?php if ( $nexora_direct ) : ?>
			<form class="cart" action="<?php echo esc_url( $product->get_permalink() ); ?>" method="post" enctype="multipart/form-data">
				<input type="hidden" name="quantity" value="1">
				<button type="submit" name="add-to-cart" value="<?php echo esc_attr( (string) $product->get_id() ); ?>" class="nx-btn nx-btn--primary"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>
			</form>
		<?php elseif ( $product->is_type( 'external' ) ) : ?>
			<a class="nx-btn nx-btn--primary" href="<?php echo esc_url( $product->get_product_url() ); ?>" rel="nofollow noopener" target="_blank"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></a>
		<?php elseif ( $product->is_in_stock() ) : ?>
			<a class="nx-btn nx-btn--primary" href="#product-<?php echo esc_attr( (string) $product->get_id() ); ?>" data-nexora-scroll-to-form><?php esc_html_e( 'Choose options', 'nexora-commerce' ); ?></a>
		<?php else : ?>
			<?php echo nexora_rfq_button( $product, __( 'Request a quotation', 'nexora-commerce' ), 'nx-btn nx-btn--accent' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
	</div>
</div>
