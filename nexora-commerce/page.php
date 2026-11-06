<?php
/**
 * Pages. WooCommerce cart/checkout/account pages use a wider, title-only layout.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_header();
$nexora_is_wc_page = nexora_is_native_store() ? Nexora_Store::is_page() : ( nexora_is_woocommerce_active() && ( is_cart() || is_checkout() || is_account_page() ) );
?>
<main id="primary" class="site-main<?php echo $nexora_is_wc_page ? ' nx-wc-page' : ''; ?>">
	<div class="nx-container">
		<?php
		nexora_breadcrumbs();
		while ( have_posts() ) :
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( $nexora_is_wc_page ? 'nx-wc-article' : 'nx-page' ); ?>>
				<header class="nx-page-header<?php echo $nexora_is_wc_page ? ' is-compact' : ''; ?>">
					<?php the_title( '<h1 class="nx-page-title">', '</h1>' ); ?>
				</header>
				<?php if ( ! $nexora_is_wc_page && has_post_thumbnail() ) : ?>
					<figure class="nx-page-hero"><?php the_post_thumbnail( 'nexora-wide', array( 'fetchpriority' => 'high', 'loading' => 'eager' ) ); ?></figure>
				<?php endif; ?>
				<div class="entry-content<?php echo $nexora_is_wc_page ? '' : ' nx-prose'; ?>">
					<?php
					the_content();
					wp_link_pages( array( 'before' => '<nav class="page-links">', 'after' => '</nav>' ) );
					?>
				</div>
			</article>
			<?php
			if ( ! $nexora_is_wc_page && ( comments_open() || get_comments_number() ) ) {
				comments_template();
			}
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
