<?php
/**
 * Personalized discovery. Server renders recommendations (top rated) as the no-history fallback;
 * main.js swaps in recently viewed products and related picks from the visitor's own
 * browser history (localStorage) — nothing is tracked on the server.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() ) {
	return;
}
// Avoid repeating the products already shown in "Trending now".
$nexora_exclude = array();
if ( in_array( 'trending', nexora_enabled_home_sections(), true ) ) {
	$nexora_source  = nexora_option( 'trending_source' );
	$nexora_exclude = nexora_get_product_ids( $nexora_source, nexora_option( 'trending_limit' ), array( 'include' => 'manual' === $nexora_source ? explode( ',', (string) nexora_option( 'trending_ids' ) ) : array() ) );
}
$nexora_ids = nexora_get_product_ids( 'top_rated', 4, array( 'exclude' => $nexora_exclude ) );
if ( ! $nexora_ids ) {
	return;
}
?>
<section class="nx-section nx-discovery" data-nexora-discovery aria-labelledby="nx-discovery-title">
	<div class="nx-container">
		<?php nexora_section_header( nexora_option( 'discovery_title' ), '', '', __( 'Personalized', 'nexora-commerce' ), 'nx-discovery-title' ); ?>
		<p class="nx-discovery__note" data-nexora-discovery-note><?php esc_html_e( 'Top-rated picks to start with. As you browse, this space adapts to products you view — stored only in your browser.', 'nexora-commerce' ); ?></p>
		<div data-nexora-discovery-grid>
			<?php nexora_product_grid( $nexora_ids, array( 'columns' => 4, 'name' => 'nexora_discovery' ) ); ?>
		</div>
		<?php nexora_recently_viewed_section( 0 ); ?>
	</div>
</section>
