<?php
/**
 * Site header.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e( 'Skip to content', 'nexora-commerce' ); ?></a>

<?php get_template_part( 'template-parts/header/announcement' ); ?>

<header id="masthead" class="site-header" data-nexora-header>
	<div class="nx-container site-header__inner">
		<button class="nx-icon-btn nx-menu-toggle" type="button" aria-controls="nx-mobile-drawer" aria-expanded="false" data-nexora-open="nx-mobile-drawer">
			<?php echo nexora_icon( 'menu', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'nexora-commerce' ); ?></span>
		</button>

		<div class="site-branding">
			<?php nexora_site_logo(); ?>
		</div>

		<nav class="site-nav" aria-label="<?php esc_attr_e( 'Primary', 'nexora-commerce' ); ?>">
			<ul class="site-nav__list">
				<?php if ( nexora_is_woocommerce_active() && nexora_option( 'header_mega_menu' ) ) : ?>
					<li class="site-nav__item nx-has-mega">
						<button type="button" class="site-nav__link nx-mega-toggle" aria-expanded="false" aria-controls="nx-mega">
							<?php esc_html_e( 'Categories', 'nexora-commerce' ); ?>
							<?php echo nexora_icon( 'chevron-down', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
						<?php get_template_part( 'template-parts/header/mega-menu' ); ?>
					</li>
				<?php endif; ?>
				<?php
				if ( has_nav_menu( 'primary' ) ) {
					wp_nav_menu(
						array(
							'theme_location' => 'primary',
							'container'      => false,
							'items_wrap'     => '%3$s',
							'depth'          => 2,
							'fallback_cb'    => false,
						)
					);
				} else {
					get_template_part( 'template-parts/header/default-links' );
				}
				?>
			</ul>
		</nav>

		<div class="site-header__actions">
			<?php if ( nexora_option( 'header_search' ) ) : ?>
				<?php get_template_part( 'template-parts/header/search' ); ?>
			<?php endif; ?>

			<?php if ( nexora_is_woocommerce_active() && nexora_option( 'header_rfq' ) && nexora_rfq_enabled() ) : ?>
				<a class="nx-btn nx-btn--accent nx-btn--sm nx-header-rfq" href="<?php echo esc_url( nexora_rfq_url() ); ?>"><?php echo nexora_icon( 'clipboard', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Request a Quote', 'nexora-commerce' ); ?></span></a>
			<?php endif; ?>
			<?php if ( nexora_is_woocommerce_active() ) : ?>
				<a class="nx-icon-btn nx-hide-sm" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">
					<?php echo nexora_icon( 'user', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="screen-reader-text"><?php esc_html_e( 'My account', 'nexora-commerce' ); ?></span>
				</a>
				<?php if ( nexora_wishlist_enabled() ) : ?>
					<a class="nx-icon-btn nx-hide-sm nx-wishlist-link" href="<?php echo esc_url( apply_filters( 'nexora_wishlist_url', wc_get_account_endpoint_url( 'wishlist' ) ) ); ?>">
						<?php echo nexora_icon( 'heart', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="nx-wishlist-count is-empty" data-nexora-wishlist-count>0</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Wishlist', 'nexora-commerce' ); ?></span>
					</a>
				<?php endif; ?>
				<a class="nx-icon-btn nx-cart-toggle" href="<?php echo esc_url( wc_get_cart_url() ); ?>" aria-controls="nx-cart-drawer" aria-expanded="false" data-nexora-open="nx-cart-drawer">
					<?php echo nexora_icon( 'bag', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo nexora_cart_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Cart', 'nexora-commerce' ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	</div>
</header>

<?php
get_template_part( 'template-parts/header/ticker' );
get_template_part( 'template-parts/header/mobile-drawer' );
if ( nexora_is_woocommerce_active() && ! is_cart() && ! is_checkout() ) {
	get_template_part( 'template-parts/header/cart-drawer' );
}
?>
<div class="nx-toast-region" aria-live="polite" aria-atomic="true" data-nexora-toasts></div>
