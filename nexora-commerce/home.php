<?php
/**
 * Blog posts index.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main">
	<div class="nx-container">
		<?php nexora_breadcrumbs(); ?>
		<header class="nx-page-header">
			<p class="nx-eyebrow"><?php esc_html_e( 'Journal', 'nexora-commerce' ); ?></p>
			<h1 class="nx-page-title"><?php echo esc_html( get_option( 'page_for_posts' ) ? get_the_title( get_option( 'page_for_posts' ) ) : __( 'Latest stories', 'nexora-commerce' ) ); ?></h1>
			<p class="nx-page-intro"><?php esc_html_e( 'Shopping guides, product comparisons and ideas for everyday upgrades.', 'nexora-commerce' ); ?></p>
		</header>
		<?php get_template_part( 'template-parts/content/archive-loop' ); ?>
	</div>
</main>
<?php
get_footer();
