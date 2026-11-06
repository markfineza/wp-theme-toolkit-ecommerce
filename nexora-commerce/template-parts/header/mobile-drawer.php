<?php
/**
 * Mobile navigation drawer (dialog).
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="nx-drawer nx-drawer--left" id="nx-mobile-drawer" role="dialog" aria-modal="true" aria-labelledby="nx-mobile-drawer-title" hidden>
	<div class="nx-drawer__backdrop" data-nexora-close></div>
	<div class="nx-drawer__panel">
		<div class="nx-drawer__head">
			<h2 class="nx-drawer__title" id="nx-mobile-drawer-title"><?php esc_html_e( 'Menu', 'nexora-commerce' ); ?></h2>
			<button type="button" class="nx-icon-btn" data-nexora-close>
				<?php echo nexora_icon( 'close', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Close menu', 'nexora-commerce' ); ?></span>
			</button>
		</div>
		<div class="nx-drawer__body">
			<nav aria-label="<?php esc_attr_e( 'Mobile', 'nexora-commerce' ); ?>">
				<ul class="nx-mobile-nav">
					<?php
					$nexora_location = has_nav_menu( 'mobile' ) ? 'mobile' : ( has_nav_menu( 'primary' ) ? 'primary' : '' );
					if ( $nexora_location ) {
						wp_nav_menu(
							array(
								'theme_location' => $nexora_location,
								'container'      => false,
								'items_wrap'     => '%3$s',
								'depth'          => 2,
							)
						);
					} else {
						get_template_part( 'template-parts/header/default-links' );
					}
					?>
				</ul>
			</nav>
			<?php if ( nexora_has_store() ) : ?>
				<?php $nexora_cats = nexora_menu_categories(); ?>
				<?php if ( $nexora_cats ) : ?>
					<h3 class="nx-drawer__subtitle"><?php esc_html_e( 'Shop by category', 'nexora-commerce' ); ?></h3>
					<ul class="nx-mobile-cats">
						<?php foreach ( $nexora_cats as $nexora_cat ) : ?>
							<li><a href="<?php echo esc_url( get_term_link( $nexora_cat ) ); ?>"><span class="nx-mega__thumb"><?php echo nexora_category_image( $nexora_cat, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php echo esc_html( $nexora_cat->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<ul class="nx-mobile-account">
					<li><a href="<?php echo esc_url( nexora_account_url() ); ?>"><?php echo nexora_icon( 'user', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo is_user_logged_in() ? esc_html__( 'My account', 'nexora-commerce' ) : esc_html__( 'Sign in / Register', 'nexora-commerce' ); ?></a></li>
					<?php if ( nexora_wishlist_enabled() ) : ?>
						<li><a href="<?php echo esc_url( apply_filters( 'nexora_wishlist_url', nexora_account_url( 'wishlist' ) ) ); ?>"><?php echo nexora_icon( 'heart', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Wishlist', 'nexora-commerce' ); ?></a></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>
