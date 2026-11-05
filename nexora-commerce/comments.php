<?php
/**
 * Comments.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<section id="comments" class="nx-comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="nx-section-title">
			<?php
			/* translators: %d: comment count */
			printf( esc_html( _n( '%d comment', '%d comments', get_comments_number(), 'nexora-commerce' ) ), (int) get_comments_number() );
			?>
		</h2>
		<ol class="comment-list">
			<?php wp_list_comments( array( 'style' => 'ol', 'short_ping' => true, 'avatar_size' => 48 ) ); ?>
		</ol>
		<?php the_comments_navigation(); ?>
	<?php endif; ?>
	<?php if ( ! comments_open() && get_comments_number() ) : ?>
		<p class="no-comments"><?php esc_html_e( 'Comments are closed.', 'nexora-commerce' ); ?></p>
	<?php endif; ?>
	<?php comment_form( array( 'class_submit' => 'nx-btn nx-btn--primary' ) ); ?>
</section>
