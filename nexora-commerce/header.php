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

		<?php if ( nexora_option( 'header_search' ) ) : ?>
			<?php get_template_part( 'template-parts/header/search' ); ?>
		<?php endif; ?>

		<div class="site-header__actions">
			<?php if ( nexora_has_store() && nexora_option( 'header_rfq' ) && nexora_rfq_enabled() ) : ?>
				<a class="nx-icon-btn nx-header-rfq-icon" href="<?php echo esc_url( nexora_rfq_url() ); ?>">
					<?php echo nexora_icon( 'clipboard', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Request a Quote', 'nexora-commerce' ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( nexora_has_store() ) : ?>
				<a class="nx-header-link nx-hide-sm" href="<?php echo esc_url( nexora_account_url() ); ?>">
					<?php echo nexora_icon( 'user', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'Account', 'nexora-commerce' ); ?></span>
				</a>
				<?php if ( nexora_wishlist_enabled() ) : ?>
					<a class="nx-header-link nx-hide-sm nx-wishlist-link" href="<?php echo esc_url( apply_filters( 'nexora_wishlist_url', nexora_account_url( 'wishlist' ) ) ); ?>">
						<?php echo nexora_icon( 'heart', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="nx-wishlist-count is-empty" data-nexora-wishlist-count>0</span>
						<span><?php esc_html_e( 'Wishlist', 'nexora-commerce' ); ?></span>
					</a>
				<?php endif; ?>
				<a class="nx-header-cart nx-cart-toggle" href="<?php echo esc_url( nexora_cart_url() ); ?>" aria-controls="nx-cart-drawer" aria-expanded="false" data-nexora-open="nx-cart-drawer">
					<?php echo nexora_icon( 'bag', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="nx-header-cart__label"><?php esc_html_e( 'Cart', 'nexora-commerce' ); ?></span>
					<?php echo nexora_cart_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</header>

<div class="nx-navbar">
	<div class="nx-container nx-navbar__inner">
		<nav class="site-nav" aria-label="<?php esc_attr_e( 'Primary', 'nexora-commerce' ); ?>">
			<ul class="site-nav__list">
				<?php if ( nexora_has_store() && nexora_option( 'header_mega_menu' ) ) : ?>
					<li class="site-nav__item nx-has-mega">
						<button type="button" class="site-nav__link nx-mega-toggle nx-navbar__browse" aria-expanded="false" aria-controls="nx-mega">
							<?php echo nexora_icon( 'grid', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Browse Categories', 'nexora-commerce' ); ?>
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
		<?php if ( nexora_has_store() && nexora_option( 'header_rfq' ) && nexora_rfq_enabled() ) : ?>
			<a class="nx-navbar__quote" href="<?php echo esc_url( nexora_rfq_url() ); ?>"><?php echo nexora_icon( 'clipboard', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Request a Quote', 'nexora-commerce' ); ?></a>
		<?php endif; ?>
	</div>
</div>

<?php
get_template_part( 'template-parts/header/ticker' );
get_template_part( 'template-parts/header/mobile-drawer' );
if ( nexora_has_store() && ! nexora_is_cart_or_checkout() ) {
	get_template_part( 'template-parts/header/cart-drawer' );
}
?>
<div class="nx-toast-region" aria-live="polite" aria-atomic="true" data-nexora-toasts></div>
