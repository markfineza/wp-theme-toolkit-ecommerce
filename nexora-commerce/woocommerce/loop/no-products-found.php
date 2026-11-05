<?php
/**
 * Empty product archive / search state, with an inline quotation request so shoppers can ask
 * for items that are not currently posted.
 *
 * Override of woocommerce/templates/loop/no-products-found.php (@version 7.8.0).
 *
 * @package NexoraCommerce
 * @version 7.8.0
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$nexora_filtered = (bool) array_filter( array_keys( $_GET ), static function ( $k ) {
	return in_array( $k, array( 'min_price', 'max_price', 'rating_filter', 'on_sale', 'stock' ), true ) || 0 === strpos( $k, 'filter_' );
} );
$nexora_term = is_search() ? get_search_query() : ( is_product_taxonomy() ? single_term_title( '', false ) : '' );
$nexora_term = str_replace( array( '[', ']', '"' ), '', (string) $nexora_term ); // Safe inside a shortcode attribute.
?>
<section class="nx-empty nx-empty--rfq woocommerce-no-products-found">
	<div class="nx-empty__intro">
		<span class="nx-empty__icon" aria-hidden="true"><?php echo nexora_icon( 'search', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<h2>
			<?php
			if ( is_search() && $nexora_term ) {
				/* translators: %s: search term */
				echo esc_html( sprintf( __( '“%s” isn’t on the market board today', 'nexora-commerce' ), $nexora_term ) );
			} else {
				esc_html_e( 'Nothing posted here right now', 'nexora-commerce' );
			}
			?>
		</h2>
		<p>
			<?php
			if ( $nexora_filtered ) {
				esc_html_e( 'Try removing a filter or widening the price range — or ask us to source it for you.', 'nexora-commerce' );
			} elseif ( nexora_rfq_enabled() ) {
				esc_html_e( 'Supply changes daily. Send a quotation request and we will check farms, ports and suppliers for you.', 'nexora-commerce' );
			} else {
				esc_html_e( 'Check the spelling or try a more general term, like “rice” or “fish”.', 'nexora-commerce' );
			}
			?>
		</p>
		<p class="nx-btn-row">
			<?php if ( $nexora_filtered ) : ?>
				<a class="nx-btn nx-btn--primary" href="<?php echo esc_url( nexora_archive_base_url() ); ?>" data-nexora-ajax><?php esc_html_e( 'Clear all filters', 'nexora-commerce' ); ?></a>
			<?php endif; ?>
			<a class="nx-btn nx-btn--ghost" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"><?php esc_html_e( 'Browse the market', 'nexora-commerce' ); ?></a>
		</p>
	</div>
	<?php if ( nexora_rfq_enabled() && shortcode_exists( 'nexora_rfq_form' ) ) : ?>
		<div class="nx-empty__rfq">
			<h3><?php esc_html_e( 'Request a quotation', 'nexora-commerce' ); ?></h3>
			<?php echo do_shortcode( '[nexora_rfq_form compact="1" item="' . esc_attr( $nexora_term ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output is escaped. ?>
		</div>
	<?php endif; ?>
</section>
