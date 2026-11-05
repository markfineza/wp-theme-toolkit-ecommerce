<?php
/**
 * Single blog post.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main">
	<div class="nx-container">
		<?php
		nexora_breadcrumbs();
		while ( have_posts() ) :
			the_post();
			?>
			<div class="nx-single-layout">
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'nx-article' ); ?>>
					<header class="nx-article__header">
						<?php
						$nexora_cats = get_the_category();
						if ( $nexora_cats ) {
							printf( '<a class="nx-eyebrow" href="%1$s">%2$s</a>', esc_url( get_category_link( $nexora_cats[0] ) ), esc_html( $nexora_cats[0]->name ) );
						}
						the_title( '<h1 class="nx-article__title">', '</h1>' );
						nexora_posted_on();
						?>
					</header>
					<?php if ( has_post_thumbnail() ) : ?>
						<figure class="nx-article__media"><?php the_post_thumbnail( 'nexora-wide', array( 'fetchpriority' => 'high', 'loading' => 'eager' ) ); ?></figure>
					<?php endif; ?>
					<div class="entry-content nx-prose">
						<?php
						the_content();
						wp_link_pages( array( 'before' => '<nav class="page-links">', 'after' => '</nav>' ) );
						?>
					</div>
					<footer class="nx-article__footer">
						<?php the_tags( '<ul class="nx-tags"><li>', '</li><li>', '</li></ul>' ); ?>
					</footer>
					<?php
					the_post_navigation(
						array(
							'prev_text' => '<span class="nx-eyebrow">' . esc_html__( 'Previous', 'nexora-commerce' ) . '</span><span>%title</span>',
							'next_text' => '<span class="nx-eyebrow">' . esc_html__( 'Next', 'nexora-commerce' ) . '</span><span>%title</span>',
						)
					);
					if ( comments_open() || get_comments_number() ) {
						comments_template();
					}
					?>
				</article>
				<?php get_sidebar(); ?>
			</div>
			<?php
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
