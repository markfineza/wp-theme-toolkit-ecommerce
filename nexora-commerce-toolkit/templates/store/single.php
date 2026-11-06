<?php
/**
 * Product page. Override by copying to yourtheme/nexora-store/single.php.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	$product = Nexora_Store_Product::get( get_the_ID() );
	if ( ! $product ) {
		continue;
	}
	$nx_market  = function_exists( 'nexora_market' ) ? nexora_market( $product ) : array( 'origin' => (string) $product->get_meta( '_nexora_origin' ), 'change' => null, 'since' => null, 'history' => array(), 'from_per' => null );
	$nx_rows    = (array) apply_filters( 'nexora_unit_prices', array(), $product );
	$nx_options = $product->is_type( 'variable' ) ? $product->get_variations() : array();
	$nx_gallery = $product->get_gallery_image_ids();
	$nx_related = Nexora_Store_Catalog::related_ids( $product->get_id() );
	$nx_cats    = Nexora_Store_Catalog::categories( $product->get_id() );
	$nx_buyable = $product->is_purchasable() && $product->is_in_stock();
	?>
	<main id="primary" class="site-main nx-wc-page">
		<div class="nx-container woocommerce">
			<?php
			if ( function_exists( 'nexora_breadcrumbs' ) ) {
				nexora_breadcrumbs();
			}
			echo Nexora_Store_Cart::notices_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
			?>
			<article class="product nx-store-single">
				<div class="nx-store-single__gallery">
					<div class="nx-store-single__main">
						<?php
						if ( function_exists( 'nexora_render_badges' ) ) {
							nexora_render_badges( $product );
						}
						echo $product->get_image( 'woocommerce_single', array( 'data-nx-main-image' => '1', 'fetchpriority' => 'high', 'alt' => $product->get_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</div>
					<?php if ( $nx_gallery ) : ?>
						<ul class="nx-store-single__thumbs">
							<?php foreach ( array_merge( array( $product->get_image_id() ), $nx_gallery ) as $nx_i => $nx_img ) : ?>
								<?php /* translators: %d: image number */ ?>
								<li><button type="button" data-nx-thumb="<?php echo esc_url( (string) wp_get_attachment_image_url( $nx_img, 'woocommerce_single' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Show image %d', 'nexora-commerce-toolkit' ), $nx_i + 1 ) ); ?>"><?php echo wp_get_attachment_image( $nx_img, 'woocommerce_gallery_thumbnail', false, array( 'alt' => '' ) ); ?></button></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<div class="summary entry-summary">
					<?php if ( $nx_cats ) : ?><p class="nx-eyebrow"><?php echo esc_html( $nx_cats[0]->name ); ?></p><?php endif; ?>
					<h1 class="product_title entry-title"><?php echo esc_html( $product->get_name() ); ?></h1>
					<?php if ( $product->get_rating_count() ) : ?>
						<p class="nx-store-single__rating"><?php echo Nexora_Store_Catalog::stars( $product->get_average_rating() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <a href="#reviews">(<?php echo (int) $product->get_review_count(); ?>)</a></p>
					<?php endif; ?>
					<p class="price" data-nx-price><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
					<?php if ( $nx_market['origin'] || null !== $nx_market['change'] ) : ?>
						<div class="nx-market-meta">
							<?php
							if ( function_exists( 'nexora_change_chip' ) && null !== $nx_market['change'] ) {
								echo nexora_change_chip( $nx_market['change'], $nx_market['since'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo nexora_sparkline( $nx_market['history'], 120, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
							<?php /* translators: %s: origin */ ?>
							<?php if ( $nx_market['origin'] ) : ?><span class="nx-market-meta__origin"><?php echo esc_html( sprintf( __( 'Origin: %s', 'nexora-commerce-toolkit' ), $nx_market['origin'] ) ); ?></span><?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="woocommerce-product-details__short-description"><?php echo wp_kses_post( wpautop( $product->get_short_description() ) ); ?></div>
					<?php if ( $nx_rows ) : ?>
						<table class="nx-unit-table">
							<thead><tr><th><?php esc_html_e( 'Option', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Price', 'nexora-commerce-toolkit' ); ?></th><th><?php esc_html_e( 'Per unit', 'nexora-commerce-toolkit' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $nx_rows as $nx_row ) : ?>
									<tr><td><?php echo esc_html( $nx_row['label'] ); ?></td><td><?php echo Nexora_Store::price( $nx_row['price'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td><?php echo Nexora_Store::price( $nx_row['per'] ) . ' / ' . esc_html( $nx_row['measure'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( $nx_buyable ) : ?>
						<form class="cart nx-store-buy" method="post" action="<?php echo esc_url( Nexora_Store::shop_url() ); ?>" data-nx-buy data-product_name="<?php echo esc_attr( $product->get_name() ); ?>">
							<input type="hidden" name="add-to-cart" value="<?php echo (int) $product->get_id(); ?>">
							<?php if ( $nx_options ) : ?>
								<p class="form-row">
									<label for="nx-variation"><?php esc_html_e( 'Choose an option', 'nexora-commerce-toolkit' ); ?></label>
									<select name="variation_id" id="nx-variation" required>
										<option value=""><?php esc_html_e( 'Choose…', 'nexora-commerce-toolkit' ); ?></option>
										<?php foreach ( $nx_options as $nx_v ) : ?>
											<option value="<?php echo (int) $nx_v->get_id(); ?>" <?php disabled( ! $nx_v->is_in_stock() ); ?> data-price="<?php echo esc_attr( wp_strip_all_tags( $nx_v->get_price_html() ) ); ?>">
												<?php echo esc_html( $nx_v->option_label() . ' — ' . Nexora_Store::money( (float) $nx_v->get_price() ) . ( $nx_v->is_in_stock() ? '' : ' (' . __( 'out of stock', 'nexora-commerce-toolkit' ) . ')' ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endif; ?>
							<div class="nx-store-buy__row">
								<div class="quantity">
									<label class="screen-reader-text" for="nx-qty"><?php esc_html_e( 'Quantity', 'nexora-commerce-toolkit' ); ?></label>
									<input type="number" class="input-text qty text" id="nx-qty" name="quantity" value="1" min="1" step="1" inputmode="numeric" <?php echo null !== $product->max_qty() && ! $nx_options ? 'max="' . (int) $product->max_qty() . '"' : ''; ?>>
								</div>
								<button type="submit" class="single_add_to_cart_button button alt"><?php esc_html_e( 'Add to cart', 'nexora-commerce-toolkit' ); ?></button>
								<button type="submit" class="button nx-store-buy__now" name="buy_now" value="1"><?php esc_html_e( 'Buy now', 'nexora-commerce-toolkit' ); ?></button>
							</div>
						</form>
					<?php else : ?>
						<p class="stock out-of-stock"><?php esc_html_e( 'Out of stock', 'nexora-commerce-toolkit' ); ?></p>
						<?php if ( function_exists( 'nexora_rfq_button' ) ) : ?>
							<?php echo nexora_rfq_button( $product, __( 'Request a quote', 'nexora-commerce-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					<?php endif; ?>
					<?php
					if ( function_exists( 'nexora_wishlist_button' ) ) {
						nexora_wishlist_button( $product, 'nx-wishlist-btn--single' );
					}
					?>
					<?php if ( function_exists( 'nexora_stock_line' ) && ! $nx_options ) : ?>
						<p class="nx-store-single__stock"><?php echo nexora_stock_line( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
					<?php endif; ?>
					<?php if ( $product->get_sku() ) : ?>
						<?php /* translators: %s: SKU */ ?>
						<p class="nx-store-single__sku"><?php echo esc_html( sprintf( __( 'SKU: %s', 'nexora-commerce-toolkit' ), $product->get_sku() ) ); ?></p>
					<?php endif; ?>
				</div>
			</article>
			<?php if ( $product->get_description() ) : ?>
				<section class="nx-store-single__desc">
					<h2><?php esc_html_e( 'Description', 'nexora-commerce-toolkit' ); ?></h2>
					<div class="nx-prose"><?php echo wp_kses_post( wpautop( $product->get_description() ) ); ?></div>
				</section>
			<?php endif; ?>
			<?php if ( Nexora_Store::get( 'reviews' ) ) : ?>
				<section class="nx-store-single__reviews" id="reviews">
					<h2><?php esc_html_e( 'Reviews', 'nexora-commerce-toolkit' ); ?></h2>
					<?php $nx_reviews = get_comments( array( 'post_id' => $product->get_id(), 'status' => 'approve', 'type' => 'review', 'number' => 20 ) ); ?>
					<?php if ( $nx_reviews ) : ?>
						<ol class="nx-store-reviews">
							<?php foreach ( $nx_reviews as $nx_review ) : ?>
								<li>
									<?php $nx_rating = (int) get_comment_meta( $nx_review->comment_ID, 'rating', true ); ?>
									<?php if ( $nx_rating ) { echo Nexora_Store_Catalog::stars( $nx_rating ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, Generic.ControlStructures.InlineControlStructure ?>
									<p><?php echo esc_html( $nx_review->comment_content ); ?></p>
									<small><?php echo esc_html( $nx_review->comment_author ); ?></small>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
						<p><?php esc_html_e( 'No reviews yet.', 'nexora-commerce-toolkit' ); ?></p>
					<?php endif; ?>
					<?php
					if ( comments_open() ) {
						comment_form(
							array(
								'title_reply'   => __( 'Write a review', 'nexora-commerce-toolkit' ),
								'label_submit'  => __( 'Submit review', 'nexora-commerce-toolkit' ),
								'comment_field' => '<p class="comment-form-rating"><label for="rating">' . esc_html__( 'Your rating', 'nexora-commerce-toolkit' ) . '</label><select name="rating" id="rating"><option value="5">★★★★★</option><option value="4">★★★★</option><option value="3">★★★</option><option value="2">★★</option><option value="1">★</option></select></p><p class="comment-form-comment"><label for="comment">' . esc_html__( 'Your review', 'nexora-commerce-toolkit' ) . '</label><textarea id="comment" name="comment" rows="4" required></textarea></p>',
							)
						);
					}
					?>
				</section>
			<?php endif; ?>
			<?php if ( $nx_related ) : ?>
				<section class="related products">
					<h2><?php esc_html_e( 'You may also like', 'nexora-commerce-toolkit' ); ?></h2>
					<?php Nexora_Store_Catalog::grid( $nx_related, 4 ); ?>
				</section>
			<?php endif; ?>
		</div>
	</main>
	<?php
endwhile;

get_footer();
