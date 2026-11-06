<?php
/**
 * Shop by category: a compact grid of category cells, ending with a "Request a quote" cell.
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
$nexora_max   = $nexora_rfq ? 7 : 8;
$nexora_slugs = array_filter( array_map( 'trim', explode( ',', (string) nexora_option( 'home_categories' ) ) ) );
if ( $nexora_slugs ) {
	$nexora_terms = get_terms( array( 'taxonomy' => 'product_cat', 'slug' => $nexora_slugs, 'hide_empty' => false, 'orderby' => 'slug__in' ) );
} else {
	$nexora_terms = nexora_menu_categories();
	if ( ! is_wp_error( $nexora_terms ) && count( $nexora_terms ) < 4 ) {
		$nexora_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
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
<section class="nx-section nx-catgrid-wrap" aria-labelledby="nx-cats-title">
	<div class="nx-container">
		<?php nexora_center_heading( nexora_option( 'categories_title' ), __( 'Wet and dry market', 'nexora-commerce' ), 'nx-cats-title' ); ?>
		<ul class="nx-catgrid">
			<?php foreach ( $nexora_terms as $nexora_term ) : ?>
				<li>
					<a class="nx-catgrid__cell" href="<?php echo esc_url( get_term_link( $nexora_term ) ); ?>">
						<span class="nx-catgrid__text">
							<strong><?php echo esc_html( $nexora_term->name ); ?></strong>
							<small>
								<?php
								/* translators: %d: product count */
								echo esc_html( sprintf( _n( '%d item', '%d items', $nexora_term->count, 'nexora-commerce' ), $nexora_term->count ) );
								?>
							</small>
						</span>
						<span class="nx-catgrid__img"><?php echo nexora_category_image( $nexora_term, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</a>
				</li>
			<?php endforeach; ?>
			<?php if ( $nexora_rfq ) : ?>
				<li>
					<a class="nx-catgrid__cell nx-catgrid__cell--rfq" href="<?php echo esc_url( nexora_rfq_url() ); ?>">
						<span class="nx-catgrid__text">
							<strong><?php esc_html_e( 'Not on the list?', 'nexora-commerce' ); ?></strong>
							<small><?php esc_html_e( 'Request a quotation', 'nexora-commerce' ); ?></small>
						</span>
						<span class="nx-catgrid__img" aria-hidden="true"><?php echo nexora_icon( 'clipboard', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</div>
</section>
