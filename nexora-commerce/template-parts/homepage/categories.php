<?php
/**
 * Market sections: bento grid of product categories, ending with a "Request a quote" tile.
 * When there are only a few top-level categories (e.g. Wet Market / Dry Market) their
 * subcategories are shown instead.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() ) {
	return;
}

$nexora_rfq   = nexora_rfq_enabled();
$nexora_max   = $nexora_rfq ? 7 : 6;
$nexora_slugs = array_filter( array_map( 'trim', explode( ',', (string) nexora_option( 'home_categories' ) ) ) );
$nexora_skip  = array( (int) get_option( 'default_product_cat' ) );
if ( $nexora_slugs ) {
	$nexora_terms = get_terms( array( 'taxonomy' => 'product_cat', 'slug' => $nexora_slugs, 'hide_empty' => false, 'orderby' => 'slug__in' ) );
} else {
	$nexora_terms = nexora_menu_categories();
	if ( ! is_wp_error( $nexora_terms ) && count( $nexora_terms ) < 4 ) {
		$nexora_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'exclude'    => $nexora_skip,
				'childless'  => true,
				'orderby'    => 'menu_order',
			)
		);
	}
}
if ( is_wp_error( $nexora_terms ) || ! $nexora_terms ) {
	return;
}
$nexora_terms = array_slice( $nexora_terms, 0, $nexora_max );
?>
<section class="nx-section nx-cats" aria-labelledby="nx-cats-title">
	<div class="nx-container">
		<?php nexora_section_header( nexora_option( 'categories_title' ), nexora_shop_url(), __( 'Open full market', 'nexora-commerce' ), __( 'Wet and dry market', 'nexora-commerce' ), 'nx-cats-title' ); ?>
		<ul class="nx-cats__grid">
			<?php foreach ( $nexora_terms as $nexora_i => $nexora_term ) : ?>
				<li class="nx-cat-tile<?php echo $nexora_i < 2 ? ' is-large' : ''; ?>">
					<a href="<?php echo esc_url( get_term_link( $nexora_term ) ); ?>">
						<span class="nx-cat-tile__img"><?php echo nexora_category_image( $nexora_term, $nexora_i < 2 ? 'large' : 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="nx-cat-tile__body">
							<strong><?php echo esc_html( $nexora_term->name ); ?></strong>
							<small>
								<?php
								/* translators: %d: product count */
								echo esc_html( sprintf( _n( '%d item', '%d items', $nexora_term->count, 'nexora-commerce' ), $nexora_term->count ) );
								?>
							</small>
						</span>
						<span class="nx-cat-tile__arrow" aria-hidden="true"><?php echo nexora_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</a>
				</li>
			<?php endforeach; ?>
			<?php if ( $nexora_rfq ) : ?>
				<li class="nx-cat-tile nx-cat-tile--rfq">
					<a href="<?php echo esc_url( nexora_rfq_url() ); ?>">
						<span class="nx-cat-tile__rfq-icon" aria-hidden="true"><?php echo nexora_icon( 'clipboard', 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="nx-cat-tile__body">
							<strong><?php esc_html_e( 'Not on the list?', 'nexora-commerce' ); ?></strong>
							<small><?php esc_html_e( 'Request a quotation', 'nexora-commerce' ); ?></small>
						</span>
						<span class="nx-cat-tile__arrow" aria-hidden="true"><?php echo nexora_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</div>
</section>
