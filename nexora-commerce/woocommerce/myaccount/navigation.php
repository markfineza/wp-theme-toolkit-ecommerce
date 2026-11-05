<?php
/**
 * Freshtastic account navigation: profile card plus icon links.
 *
 * @package NexoraCommerce
 * @version 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nexora_user = wp_get_current_user();

do_action( 'woocommerce_before_account_navigation' );
?>

<nav class="woocommerce-MyAccount-navigation nx-acc-nav" aria-label="<?php esc_attr_e( 'Account pages', 'woocommerce' ); ?>">
	<div class="nx-acc-nav__profile">
		<span class="nx-avatar" aria-hidden="true"><?php echo esc_html( nexora_user_initials( $nexora_user ) ); ?></span>
		<span class="nx-acc-nav__who">
			<strong><?php echo esc_html( $nexora_user->display_name ); ?></strong>
			<span><?php echo esc_html( $nexora_user->user_email ); ?></span>
		</span>
	</div>
	<ul>
		<?php foreach ( wc_get_account_menu_items() as $endpoint => $label ) : ?>
			<li class="<?php echo wc_get_account_menu_item_classes( $endpoint ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo wc_is_current_account_menu_item( $endpoint ) ? 'aria-current="page"' : ''; ?>>
					<?php echo nexora_icon( nexora_account_icon( $endpoint ), 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>

<?php do_action( 'woocommerce_after_account_navigation' ); ?>
