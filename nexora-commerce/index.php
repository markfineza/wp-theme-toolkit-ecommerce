<?php
/**
 * Fallback template.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main">
	<div class="nx-container">
		<?php nexora_breadcrumbs(); ?>
		<?php get_template_part( 'template-parts/content/archive-loop' ); ?>
	</div>
</main>
<?php
get_footer();
