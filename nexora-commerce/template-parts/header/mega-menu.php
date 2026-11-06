<?php
/**
 * Category mega menu, generated from top-level product categories.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_cats = nexora_menu_categories();
if ( ! $nexora_cats ) {
	return;
}
$nexora_promo = nexora_get_product_ids( 'featured', 1 );
?>
<div class="nx-mega" id="nx-mega" hidden>
	<div class="nx-container nx-mega__inner">
		<ul class="nx-mega__cats">
			<?php foreach ( $nexora_cats as $nexora_cat ) : ?>
				<?php
				$nexora_children = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'parent'     => $nexora_cat->term_id,
						'hide_empty' => true,
						'number'     => 5,
					)
				);
				?>
				<li class="nx-mega__cat">
					<a class="nx-mega__cat-link" href="<?php echo esc_url( get_term_link( $nexora_cat ) ); ?>">
						<span class="nx-mega__thumb"><?php echo nexora_category_image( $nexora_cat, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span>
							<strong><?php echo esc_html( $nexora_cat->name ); ?></strong>
							<small>
								<?php
								/* translators: %d: product count */
								echo esc_html( sprintf( _n( '%d product', '%d products', $nexora_cat->count, 'nexora-commerce' ), $nexora_cat->count ) );
								?>
							</small>
						</span>
					</a>
					<?php if ( ! is_wp_error( $nexora_children ) && $nexora_children ) : ?>
						<ul class="nx-mega__sub">
							<?php foreach ( $nexora_children as $nexora_child ) : ?>
								<li><a href="<?php echo esc_url( get_term_link( $nexora_child ) ); ?>"><?php echo esc_html( $nexora_child->name ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="nx-mega__aside">
			<?php
			$nexora_p = $nexora_promo ? nexora_get_product( $nexora_promo[0] ) : null;
			if ( $nexora_p ) :
				?>
				<a class="nx-mega__promo" href="<?php echo esc_url( $nexora_p->get_permalink() ); ?>">
					<?php echo $nexora_p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="nx-eyebrow"><?php esc_html_e( 'Featured', 'nexora-commerce' ); ?></span>
					<strong><?php echo esc_html( $nexora_p->get_name() ); ?></strong>
					<span class="price"><?php echo wp_kses_post( $nexora_p->get_price_html() ); ?></span>
				</a>
			<?php endif; ?>
			<ul class="nx-mega__quick">
				<li><a href="<?php echo esc_url( nexora_shop_url( array( 'orderby' => 'date' ) ) ); ?>"><?php esc_html_e( 'New arrivals', 'nexora-commerce' ); ?></a></li>
				<li><a href="<?php echo esc_url( nexora_shop_url( array( 'orderby' => 'popularity' ) ) ); ?>"><?php esc_html_e( 'Best sellers', 'nexora-commerce' ); ?></a></li>
				<li><a href="<?php echo esc_url( nexora_shop_url( array( 'on_sale' => 1 ) ) ); ?>"><?php esc_html_e( 'Deals', 'nexora-commerce' ); ?></a></li>
			</ul>
		</div>
	</div>
</div>
