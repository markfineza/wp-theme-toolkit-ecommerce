<?php
/**
 * Post archives (categories, tags, dates, authors).
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
			<?php the_archive_title( '<h1 class="nx-page-title">', '</h1>' ); ?>
			<?php the_archive_description( '<div class="nx-page-intro">', '</div>' ); ?>
		</header>
		<?php get_template_part( 'template-parts/content/archive-loop' ); ?>
	</div>
</main>
<?php
get_footer();
