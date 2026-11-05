<?php
/**
 * Site footer.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>

<footer id="colophon" class="site-footer">
	<div class="nx-container">
		<div class="site-footer__top">
			<div class="site-footer__brand">
				<?php nexora_site_logo(); ?>
				<p><?php echo esc_html( nexora_option( 'footer_about' ) ); ?></p>
				<?php nexora_social_links(); ?>
			</div>
			<div class="site-footer__newsletter">
				<h2 class="footer-title"><?php esc_html_e( 'Join the newsletter', 'nexora-commerce' ); ?></h2>
				<?php get_template_part( 'template-parts/marketing/newsletter-form', null, array( 'id' => 'footer', 'compact' => true ) ); ?>
			</div>
		</div>

		<div class="site-footer__cols">
			<?php get_template_part( 'template-parts/footer/columns' ); ?>
			<div class="site-footer__col">
				<h2 class="footer-title"><?php esc_html_e( 'Contact', 'nexora-commerce' ); ?></h2>
				<ul class="nx-contact">
					<?php if ( nexora_option( 'store_phone' ) ) : ?>
						<li><?php echo nexora_icon( 'phone', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( nexora_option( 'store_phone' ) ); ?></span></li>
					<?php endif; ?>
					<?php if ( nexora_option( 'store_email' ) ) : ?>
						<li><?php echo nexora_icon( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="mailto:<?php echo esc_attr( antispambot( nexora_option( 'store_email' ) ) ); ?>"><?php echo esc_html( antispambot( nexora_option( 'store_email' ) ) ); ?></a></li>
					<?php endif; ?>
					<?php if ( nexora_option( 'store_address' ) ) : ?>
						<li><?php echo nexora_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( nexora_option( 'store_address' ) ); ?></span></li>
					<?php endif; ?>
					<?php if ( nexora_option( 'store_hours' ) ) : ?>
						<li><?php echo nexora_icon( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( nexora_option( 'store_hours' ) ); ?></span></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<?php if ( is_active_sidebar( 'footer-extra' ) ) : ?>
			<div class="site-footer__widgets">
				<?php dynamic_sidebar( 'footer-extra' ); ?>
			</div>
		<?php endif; ?>

		<div class="site-footer__bottom">
			<p class="site-footer__copy"><?php echo esc_html( nexora_copyright() ); ?></p>
			<?php
			if ( has_nav_menu( 'legal' ) ) {
				wp_nav_menu(
					array(
						'theme_location' => 'legal',
						'container'      => 'nav',
						'container_aria_label' => __( 'Legal', 'nexora-commerce' ),
						'menu_class'     => 'nx-legal',
						'depth'          => 1,
					)
				);
			} elseif ( get_privacy_policy_url() ) {
				echo '<nav aria-label="' . esc_attr__( 'Legal', 'nexora-commerce' ) . '"><ul class="nx-legal"><li>' . get_the_privacy_policy_link() . '</li></ul></nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php $nexora_payments = nexora_payment_labels(); ?>
			<?php if ( $nexora_payments ) : ?>
				<ul class="nx-payments" aria-label="<?php esc_attr_e( 'Accepted payment methods', 'nexora-commerce' ); ?>">
					<?php foreach ( $nexora_payments as $nexora_payment ) : ?>
						<li><?php echo esc_html( $nexora_payment ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
