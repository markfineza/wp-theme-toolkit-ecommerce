<?php
/**
 * Empty state for post loops.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="nx-empty">
	<span class="nx-empty__icon" aria-hidden="true"><?php echo nexora_icon( 'search', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<h2><?php esc_html_e( 'Nothing found', 'nexora-commerce' ); ?></h2>
	<p><?php echo is_search() ? esc_html__( 'No results matched your search. Try different keywords.', 'nexora-commerce' ) : esc_html__( 'There is nothing here yet. Check back soon.', 'nexora-commerce' ); ?></p>
	<a class="nx-btn nx-btn--ghost" href="<?php echo esc_url( nexora_shop_url() ); ?>"><?php esc_html_e( 'Browse products', 'nexora-commerce' ); ?></a>
</section>
