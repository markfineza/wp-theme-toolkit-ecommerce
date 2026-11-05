<?php
/**
 * Not found.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main">
	<div class="nx-container">
		<section class="nx-empty nx-empty--page">
			<p class="nx-empty__code" aria-hidden="true">404</p>
			<h1 class="nx-page-title"><?php esc_html_e( 'This page drifted out of orbit.', 'nexora-commerce' ); ?></h1>
			<p><?php esc_html_e( 'The link may be broken or the page may have moved. Try a search or head back to the shop.', 'nexora-commerce' ); ?></p>
			<div class="nx-page-search"><?php get_search_form(); ?></div>
			<p class="nx-btn-row">
				<a class="nx-btn nx-btn--primary" href="<?php echo esc_url( nexora_shop_url() ); ?>"><?php esc_html_e( 'Go to the shop', 'nexora-commerce' ); ?></a>
				<a class="nx-btn nx-btn--ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'nexora-commerce' ); ?></a>
			</p>
		</section>
	</div>
</main>
<?php
get_footer();
