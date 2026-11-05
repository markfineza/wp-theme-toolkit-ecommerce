<?php
/**
 * Footer link columns. Each column uses its menu location, with sensible automatic fallbacks.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_columns = array(
	'footer-1' => __( 'Shop', 'nexora-commerce' ),
	'footer-2' => __( 'Customer service', 'nexora-commerce' ),
	'footer-3' => __( 'Company', 'nexora-commerce' ),
);

foreach ( $nexora_columns as $nexora_location => $nexora_title ) :
	$nexora_locations = get_nav_menu_locations();
	$nexora_menu      = isset( $nexora_locations[ $nexora_location ] ) ? wp_get_nav_menu_object( $nexora_locations[ $nexora_location ] ) : null;
	?>
	<div class="site-footer__col">
		<h2 class="footer-title"><?php echo esc_html( $nexora_menu ? $nexora_menu->name : $nexora_title ); ?></h2>
		<?php
		if ( $nexora_menu ) {
			wp_nav_menu(
				array(
					'theme_location' => $nexora_location,
					'container'      => false,
					'menu_class'     => 'nx-footer-links',
					'depth'          => 1,
				)
			);
		} else {
			$nexora_links = array();
			if ( 'footer-1' === $nexora_location && nexora_is_woocommerce_active() ) {
				foreach ( nexora_menu_categories() as $nexora_cat ) {
					$nexora_links[] = array( get_term_link( $nexora_cat ), $nexora_cat->name );
				}
			} elseif ( 'footer-2' === $nexora_location ) {
				foreach ( array( 'shipping-returns', 'faq', 'contact' ) as $nexora_slug ) {
					$nexora_page = get_page_by_path( $nexora_slug );
					if ( $nexora_page ) {
						$nexora_links[] = array( get_permalink( $nexora_page ), get_the_title( $nexora_page ) );
					}
				}
				if ( nexora_is_woocommerce_active() ) {
					$nexora_links[] = array( wc_get_page_permalink( 'myaccount' ), __( 'My account', 'nexora-commerce' ) );
				}
			} elseif ( 'footer-3' === $nexora_location ) {
				foreach ( array( 'about', 'terms-and-conditions' ) as $nexora_slug ) {
					$nexora_page = get_page_by_path( $nexora_slug );
					if ( $nexora_page ) {
						$nexora_links[] = array( get_permalink( $nexora_page ), get_the_title( $nexora_page ) );
					}
				}
				if ( get_privacy_policy_url() ) {
					$nexora_links[] = array( get_privacy_policy_url(), __( 'Privacy policy', 'nexora-commerce' ) );
				}
				if ( get_option( 'page_for_posts' ) ) {
					$nexora_links[] = array( get_permalink( get_option( 'page_for_posts' ) ), get_the_title( get_option( 'page_for_posts' ) ) );
				}
			}
			if ( $nexora_links ) {
				echo '<ul class="nx-footer-links">';
				foreach ( $nexora_links as $nexora_link ) {
					printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $nexora_link[0] ), esc_html( $nexora_link[1] ) );
				}
				echo '</ul>';
			}
		}
		?>
	</div>
<?php endforeach; ?>
