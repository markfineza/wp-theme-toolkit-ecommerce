<?php
/**
 * Post card.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'nx-post-card' ); ?>>
	<a class="nx-post-card__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php
		if ( has_post_thumbnail() ) {
			the_post_thumbnail( 'nexora-post', array( 'loading' => 'lazy', 'alt' => '' ) );
		} else {
			echo '<span class="nx-post-card__placeholder"></span>';
		}
		?>
	</a>
	<div class="nx-post-card__body">
		<?php
		$nexora_cats = get_the_category();
		if ( $nexora_cats ) {
			printf( '<p class="nx-eyebrow">%s</p>', esc_html( $nexora_cats[0]->name ) );
		}
		?>
		<h2 class="nx-post-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p class="nx-post-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>
		<?php nexora_posted_on(); ?>
	</div>
</article>
