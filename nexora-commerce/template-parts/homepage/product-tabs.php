<?php
/**
 * Products with tabs (new, popular, best rated, on sale) beside a promotional card.
 * Every tab is in the page, so it works on cached pages; without JavaScript the first tab shows.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() ) {
	return;
}

$nexora_limit = max( 4, min( 12, (int) nexora_option( 'tabs_limit' ) ) );
$nexora_tabs  = array();
foreach (
	array(
		'latest'       => __( 'New arrivals', 'nexora-commerce' ),
		'best_selling' => __( 'Popular', 'nexora-commerce' ),
		'top_rated'    => __( 'Best rated', 'nexora-commerce' ),
		'on_sale'      => __( 'On sale', 'nexora-commerce' ),
	) as $nexora_type => $nexora_label
) {
	$nexora_ids = nexora_get_product_ids( $nexora_type, $nexora_limit );
	if ( $nexora_ids ) {
		$nexora_tabs[ $nexora_type ] = array( $nexora_label, $nexora_ids );
	}
}
if ( ! $nexora_tabs ) {
	return;
}

$nexora_card_url = nexora_option( 'tabs_card_url' );
if ( ! $nexora_card_url ) {
	$nexora_card_url = nexora_rfq_enabled() ? nexora_rfq_url() : nexora_shop_url();
}
$nexora_cats = nexora_menu_categories();
$nexora_all  = array_values( array_filter( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ), 'orderby' => 'name' ) ), static function ( $t ) { return $t instanceof WP_Term; } ) );
?>
<section class="nx-section nx-ptabs" aria-labelledby="nx-ptabs-title">
	<div class="nx-container nx-ptabs__layout">
		<aside class="nx-ptabs__card nx-dark">
			<p class="nx-ptabs__card-title"><?php echo esc_html( nexora_option( 'tabs_card_title' ) ); ?></p>
			<p class="nx-ptabs__card-big"><?php echo esc_html( nexora_option( 'tabs_card_big' ) ); ?></p>
			<p class="nx-ptabs__card-text"><?php echo esc_html( nexora_option( 'tabs_card_text' ) ); ?></p>
			<?php if ( nexora_option( 'tabs_card_label' ) ) : ?>
				<a class="nx-btn nx-btn--accent" href="<?php echo esc_url( $nexora_card_url ); ?>"><?php echo esc_html( nexora_option( 'tabs_card_label' ) ); ?></a>
			<?php endif; ?>
		</aside>
		<div class="nx-ptabs__main" data-nexora-tabs>
			<div class="nx-ptabs__bar">
				<h2 class="nx-ptabs__title" id="nx-ptabs-title"><?php echo esc_html( nexora_option( 'tabs_title' ) ); ?></h2>
				<div class="nx-ptabs__tabs" role="tablist" aria-labelledby="nx-ptabs-title">
					<?php $nexora_first = true; foreach ( $nexora_tabs as $nexora_type => $nexora_tab ) : ?>
						<button type="button" role="tab" class="nx-ptabs__tab" id="nx-tab-<?php echo esc_attr( $nexora_type ); ?>" aria-controls="nx-panel-<?php echo esc_attr( $nexora_type ); ?>" aria-selected="<?php echo $nexora_first ? 'true' : 'false'; ?>"<?php echo $nexora_first ? '' : ' tabindex="-1"'; ?>><?php echo esc_html( $nexora_tab[0] ); ?></button>
						<?php $nexora_first = false; endforeach; ?>
				</div>
				<?php if ( $nexora_all ) : ?>
					<details class="nx-ptabs__cats">
						<summary><?php esc_html_e( 'Choose category', 'nexora-commerce' ); ?> <?php echo nexora_icon( 'chevron-down', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
						<ul>
							<?php foreach ( $nexora_all as $nexora_term ) : ?>
								<li><a href="<?php echo esc_url( get_term_link( $nexora_term ) ); ?>"><?php echo esc_html( $nexora_term->name ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</div>
			<?php $nexora_first = true; foreach ( $nexora_tabs as $nexora_type => $nexora_tab ) : ?>
				<div role="tabpanel" class="nx-ptabs__panel" id="nx-panel-<?php echo esc_attr( $nexora_type ); ?>" aria-labelledby="nx-tab-<?php echo esc_attr( $nexora_type ); ?>" tabindex="0"<?php echo $nexora_first ? '' : ' hidden'; ?>>
					<?php nexora_product_grid( $nexora_tab[1], array( 'columns' => 4, 'name' => 'nexora_tabs_' . $nexora_type ) ); ?>
				</div>
				<?php $nexora_first = false; endforeach; ?>
			<a class="nx-link-arrow nx-ptabs__all" href="<?php echo esc_url( nexora_shop_url() ); ?>"><?php esc_html_e( 'See all products', 'nexora-commerce' ); ?> <?php echo nexora_icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
	</div>
</section>
