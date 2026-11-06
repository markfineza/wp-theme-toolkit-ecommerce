<?php
/**
 * Mini-cart drawer. Contents are kept current by WooCommerce cart fragments.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="nx-drawer nx-drawer--right" id="nx-cart-drawer" role="dialog" aria-modal="true" aria-labelledby="nx-cart-drawer-title" hidden>
	<div class="nx-drawer__backdrop" data-nexora-close></div>
	<div class="nx-drawer__panel">
		<div class="nx-drawer__head">
			<h2 class="nx-drawer__title" id="nx-cart-drawer-title"><?php esc_html_e( 'Your cart', 'nexora-commerce' ); ?></h2>
			<button type="button" class="nx-icon-btn" data-nexora-close>
				<?php echo nexora_icon( 'close', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Close cart', 'nexora-commerce' ); ?></span>
			</button>
		</div>
		<div class="nx-drawer__body">
			<?php echo nexora_free_shipping_progress( 'drawer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="nx-mini-cart-content widget_shopping_cart_content">
				<?php
				if ( nexora_is_native_store() ) {
					echo Nexora_Store_Cart::mini_cart(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
				} else {
					woocommerce_mini_cart();
				}
				?>
			</div>
		</div>
	</div>
</div>
