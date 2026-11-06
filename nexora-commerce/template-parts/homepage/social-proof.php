<?php
/**
 * Social proof: recent approved WooCommerce product reviews plus testimonials (if the toolkit's
 * testimonial post type exists). Sample content is labelled via the `nexora_is_sample_content` filter.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_items = array();

if ( nexora_has_store() && 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) ) {
	$nexora_reviews = get_comments(
		array(
			'post_type'  => 'product',
			'status'     => 'approve',
			'type'       => 'review',
			'number'     => 6,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'rating',
					'value'   => 4,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	foreach ( $nexora_reviews as $nexora_review ) {
		$nexora_items[] = array(
			'quote'    => wp_trim_words( $nexora_review->comment_content, 40 ),
			'name'     => $nexora_review->comment_author,
			'meta'     => get_the_title( $nexora_review->comment_post_ID ),
			'url'      => get_permalink( $nexora_review->comment_post_ID ),
			'rating'   => (int) get_comment_meta( $nexora_review->comment_ID, 'rating', true ),
			'verified' => function_exists( 'wc_review_is_from_verified_owner' ) && wc_review_is_from_verified_owner( $nexora_review->comment_ID ),
			'sample'   => (bool) apply_filters( 'nexora_is_sample_content', false, 'comment', $nexora_review->comment_ID ),
		);
	}
}

if ( post_type_exists( 'nexora_testimonial' ) ) {
	$nexora_testimonials = get_posts( array( 'post_type' => 'nexora_testimonial', 'numberposts' => 3, 'post_status' => 'publish' ) );
	foreach ( $nexora_testimonials as $nexora_t ) {
		$nexora_items[] = array(
			'quote'    => wp_strip_all_tags( $nexora_t->post_content ),
			'name'     => get_the_title( $nexora_t ),
			'meta'     => (string) get_post_meta( $nexora_t->ID, 'nexora_role', true ),
			'url'      => '',
			'rating'   => (int) get_post_meta( $nexora_t->ID, 'nexora_rating', true ),
			'verified' => false,
			'sample'   => (bool) apply_filters( 'nexora_is_sample_content', false, 'post', $nexora_t->ID ),
		);
	}
}

if ( ! $nexora_items ) {
	return;
}
$nexora_items = array_slice( $nexora_items, 0, 6 );
?>
<section class="nx-section nx-social-proof nx-section--tint" aria-labelledby="nx-social-title">
	<div class="nx-container">
		<?php nexora_section_header( nexora_option( 'social_title' ), '', '', __( 'Reviews', 'nexora-commerce' ), 'nx-social-title' ); ?>
		<ul class="nx-quotes" data-nexora-scroller>
			<?php foreach ( $nexora_items as $nexora_item ) : ?>
				<li class="nx-quote">
					<?php if ( $nexora_item['rating'] ) : ?>
						<?php echo nexora_rating_html( $nexora_item['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<blockquote><p><?php echo esc_html( $nexora_item['quote'] ); ?></p></blockquote>
					<p class="nx-quote__by">
						<strong><?php echo esc_html( $nexora_item['name'] ); ?></strong>
						<?php if ( $nexora_item['verified'] ) : ?>
							<span class="nx-tag nx-tag--success"><?php esc_html_e( 'Verified owner', 'nexora-commerce' ); ?></span>
						<?php endif; ?>
						<?php if ( $nexora_item['sample'] ) : ?>
							<span class="nx-tag"><?php esc_html_e( 'Sample content', 'nexora-commerce' ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $nexora_item['meta'] ) : ?>
						<p class="nx-quote__meta">
							<?php if ( $nexora_item['url'] ) : ?>
								<a href="<?php echo esc_url( $nexora_item['url'] ); ?>"><?php echo esc_html( $nexora_item['meta'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $nexora_item['meta'] ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
