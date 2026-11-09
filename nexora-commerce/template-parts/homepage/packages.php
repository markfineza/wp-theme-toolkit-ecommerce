<?php
/**
 * Packages: products sold together at one price, such as Package A and Package B. Hidden until one exists.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() || ! class_exists( 'Nexora_Toolkit_Packages' ) ) {
	return;
}
$nexora_ids = Nexora_Toolkit_Packages::ids( max( 1, (int) nexora_option( 'packages_limit' ) ) );
if ( ! $nexora_ids ) {
	return;
}
?>
<section class="nx-section nx-packages" aria-labelledby="nx-packages-title">
	<div class="nx-container">
		<?php nexora_center_heading( nexora_option( 'packages_title' ), nexora_option( 'packages_eyebrow' ), 'nx-packages-title', nexora_option( 'packages_text' ) ); ?>
		<ul class="nx-packages__grid">
			<?php foreach ( $nexora_ids as $nexora_id ) : ?>
				<?php
				$nexora_p = nexora_get_product( $nexora_id );
				if ( ! $nexora_p || ! $nexora_p->is_visible() ) {
					continue;
				}
				$nexora_save = Nexora_Toolkit_Packages::separate_total( $nexora_id ) - (float) $nexora_p->get_price();
				$nexora_buy  = $nexora_p->is_purchasable() && $nexora_p->is_in_stock();
				?>
				<li class="nx-package">
					<a class="nx-package__media" href="<?php echo esc_url( $nexora_p->get_permalink() ); ?>" tabindex="-1" aria-hidden="true"><?php echo wp_kses_post( $nexora_p->get_image( 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) ); ?></a>
					<h3 class="nx-package__name"><a href="<?php echo esc_url( $nexora_p->get_permalink() ); ?>"><?php echo esc_html( $nexora_p->get_name() ); ?></a></h3>
					<p class="nx-package__price"><?php echo wp_kses_post( $nexora_p->get_price_html() ); ?></p>
					<?php if ( $nexora_save > 0.004 ) : ?>
						<?php /* translators: %s: amount saved */ ?>
						<p class="nx-package__save"><?php echo wp_kses_post( sprintf( __( 'Save %s', 'nexora-commerce' ), Nexora_Toolkit_Packages::money( $nexora_save ) ) ); ?></p>
					<?php endif; ?>
					<ul class="nx-package__items">
						<?php foreach ( Nexora_Toolkit_Packages::contents( $nexora_id ) as $nexora_row ) : ?>
							<li><?php echo esc_html( (int) $nexora_row['qty'] . ' × ' . Nexora_Toolkit_Packages::name( (int) $nexora_row['id'] ) ); ?></li>
						<?php endforeach; ?>
					</ul>
					<div class="nx-package__action">
						<?php if ( $nexora_buy ) : ?>
							<?php /* translators: %s: package name */ ?>
							<a class="button add_to_cart_button ajax_add_to_cart nx-btn nx-btn--primary" rel="nofollow" href="<?php echo esc_url( $nexora_p->add_to_cart_url() ); ?>" data-product_id="<?php echo (int) $nexora_id; ?>" data-quantity="1" data-product_name="<?php echo esc_attr( $nexora_p->get_name() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Add %s to cart', 'nexora-commerce' ), $nexora_p->get_name() ) ); ?>"><?php esc_html_e( 'Add to cart', 'nexora-commerce' ); ?></a>
						<?php else : ?>
							<span class="nx-package__out"><?php esc_html_e( 'Out of stock', 'nexora-commerce' ); ?></span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
