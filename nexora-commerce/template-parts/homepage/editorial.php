<?php
/**
 * Editorial: latest journal posts.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_posts = new WP_Query(
	array(
		'post_type'           => 'post',
		'posts_per_page'      => max( 1, min( 6, (int) nexora_option( 'editorial_count' ) ) ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	)
);
if ( ! $nexora_posts->have_posts() ) {
	return;
}
$nexora_blog = get_option( 'page_for_posts' ) ? get_permalink( get_option( 'page_for_posts' ) ) : '';
?>
<section class="nx-section nx-editorial" aria-labelledby="nx-editorial-title">
	<div class="nx-container">
		<?php nexora_center_heading( nexora_option( 'editorial_title' ), __( 'Guides and stories', 'nexora-commerce' ), 'nx-editorial-title' ); ?>
		<div class="nx-post-grid">
			<?php
			while ( $nexora_posts->have_posts() ) {
				$nexora_posts->the_post();
				get_template_part( 'template-parts/content/card' );
			}
			wp_reset_postdata();
			?>
		</div>
	</div>
</section>
