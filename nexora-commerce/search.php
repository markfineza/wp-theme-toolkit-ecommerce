<?php
/**
 * Search results for posts and pages (product searches use the WooCommerce product archive).
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
			<p class="nx-eyebrow"><?php esc_html_e( 'Search', 'nexora-commerce' ); ?></p>
			<h1 class="nx-page-title">
				<?php
				/* translators: %s: search query */
				printf( esc_html__( 'Results for “%s”', 'nexora-commerce' ), esc_html( get_search_query() ) );
				?>
			</h1>
			<div class="nx-page-search"><?php get_search_form(); ?></div>
		</header>
		<?php get_template_part( 'template-parts/content/archive-loop' ); ?>
	</div>
</main>
<?php
get_footer();
