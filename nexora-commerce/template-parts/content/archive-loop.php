<?php
/**
 * Post grid with pagination and empty state.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( have_posts() ) : ?>
	<div class="nx-post-grid">
		<?php
		while ( have_posts() ) :
			the_post();
			get_template_part( 'template-parts/content/card' );
		endwhile;
		?>
	</div>
	<?php
	the_posts_pagination(
		array(
			'prev_text' => nexora_icon( 'chevron-left', 16 ) . '<span class="screen-reader-text">' . esc_html__( 'Previous page', 'nexora-commerce' ) . '</span>',
			'next_text' => '<span class="screen-reader-text">' . esc_html__( 'Next page', 'nexora-commerce' ) . '</span>' . nexora_icon( 'chevron-right', 16 ),
		)
	);
else :
	get_template_part( 'template-parts/content/none' );
endif;
