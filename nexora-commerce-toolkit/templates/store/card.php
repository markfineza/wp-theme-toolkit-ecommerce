<?php
/**
 * Product card. Override by copying to yourtheme/nexora-store/card.php.
 *
 * @package NexoraToolkit
 * @var Nexora_Store_Product $product
 * @var string               $heading
 */

defined( 'ABSPATH' ) || exit;

$nx_link   = $product->get_permalink();
$nx_cats   = Nexora_Store_Catalog::categories( $product->get_id() );
$nx_market = function_exists( 'nexora_market' ) ? nexora_market( $product ) : array( 'origin' => (string) $product->get_meta( '_nexora_origin' ), 'change' => null, 'since' => null, 'from_per' => null );
$nx_simple = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();
?>
<li class="product nx-card type-product status-publish <?php echo $product->is_in_stock() ? 'instock' : 'outofstock'; ?> <?php echo $product->is_on_sale() ? 'sale' : ''; ?> product-type-<?php echo esc_attr( $product->get_type() ); ?>">
	<div class="nx-card__media">
		<a href="<?php echo esc_url( $nx_link ); ?>" class="nx-card__image-link" tabindex="-1" aria-hidden="true"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'nx-card__img', 'alt' => '', 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<?php
		if ( function_exists( 'nexora_render_badges' ) ) {
			nexora_render_badges( $product );
		}
		if ( function_exists( 'nexora_wishlist_button' ) ) {
			nexora_wishlist_button( $product );
		}
		?>
	</div>
	<div class="nx-card__body">
		<p class="nx-card__cat">
			<?php if ( $nx_cats ) : ?><span><?php echo esc_html( $nx_cats[0]->name ); ?></span><?php endif; ?>
			<?php if ( $nx_market['origin'] ) : ?><span class="nx-card__origin"><?php echo esc_html( $nx_market['origin'] ); ?></span><?php endif; ?>
		</p>
		<<?php echo esc_html( $heading ); ?> class="woocommerce-loop-product__title nx-card__title"><a href="<?php echo esc_url( $nx_link ); ?>" class="woocommerce-LoopProduct-link"><?php echo esc_html( $product->get_name() ); ?></a></<?php echo esc_html( $heading ); ?>>
		<?php if ( $product->get_rating_count() ) : ?>
			<div class="nx-card__rating"><?php echo Nexora_Store_Catalog::stars( $product->get_average_rating() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span class="nx-card__reviews">(<?php echo (int) $product->get_review_count(); ?>)</span></div>
		<?php endif; ?>
		<div class="nx-card__pricerow">
			<span class="price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			<?php
			if ( function_exists( 'nexora_change_chip' ) ) {
				echo nexora_change_chip( $nx_market['change'], $nx_market['since'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php if ( ! empty( $nx_market['from_per'] ) ) : ?>
			<?php /* translators: 1: price, 2: kg or L */ ?>
			<p class="nx-card__per"><?php echo wp_kses_post( sprintf( __( 'From %1$s / %2$s', 'nexora-commerce-toolkit' ), Nexora_Store::price( $nx_market['from_per']['per'] ), esc_html( $nx_market['from_per']['measure'] ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( function_exists( 'nexora_stock_line' ) ) : ?>
			<p class="nx-card__stock"><?php echo nexora_stock_line( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<?php endif; ?>
		<div class="nx-card__actions">
			<?php if ( $nx_simple ) : ?>
				<?php /* translators: %s: product */ ?>
				<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" rel="nofollow" class="button add_to_cart_button nx-btn nx-btn--sm" data-product_id="<?php echo (int) $product->get_id(); ?>" data-product_name="<?php echo esc_attr( $product->get_name() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Add one %s to cart', 'nexora-commerce-toolkit' ), $product->get_name() ) ); ?>"><?php esc_html_e( 'Add to cart', 'nexora-commerce-toolkit' ); ?></a>
			<?php elseif ( ! $product->is_in_stock() && function_exists( 'nexora_rfq_button' ) && nexora_rfq_enabled() ) : ?>
				<?php echo nexora_rfq_button( $product, __( 'Request quote', 'nexora-commerce-toolkit' ), 'button nx-btn nx-btn--accent nx-btn--sm nx-card__rfq' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<?php /* translators: %s: product */ ?>
				<a href="<?php echo esc_url( $nx_link ); ?>" class="button nx-btn nx-btn--sm" aria-label="<?php echo esc_attr( sprintf( __( 'Choose options for %s', 'nexora-commerce-toolkit' ), $product->get_name() ) ); ?>"><?php esc_html_e( 'Select options', 'nexora-commerce-toolkit' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</li>
