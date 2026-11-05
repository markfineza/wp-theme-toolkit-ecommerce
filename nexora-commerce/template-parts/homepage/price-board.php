<?php
/**
 * Price board: an exchange-style table of market prices with category tabs.
 * "Change" is the change since each product's previous recorded price.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_is_woocommerce_active() ) {
	return;
}

$nexora_products = nexora_board_products( max( 4, (int) nexora_option( 'price_board_limit' ) ) );
if ( ! $nexora_products ) {
	return;
}

// Tabs: categories (leaf level) that appear on the board, in menu order.
$nexora_tab_counts = array();
foreach ( $nexora_products as $nexora_p ) {
	foreach ( wc_get_product_terms( $nexora_p->get_id(), 'product_cat' ) as $nexora_t ) {
		$nexora_tab_counts[ $nexora_t->slug ] = $nexora_t;
	}
}
uasort(
	$nexora_tab_counts,
	static function ( $a, $b ) {
		return (int) get_term_meta( $a->term_id, 'order', true ) <=> (int) get_term_meta( $b->term_id, 'order', true );
	}
);
$nexora_latest = 0;
foreach ( $nexora_products as $nexora_p ) {
	$nexora_latest = max( $nexora_latest, (int) nexora_market( $nexora_p )['since'] );
}
?>
<section class="nx-section nx-pboard-wrap" id="price-board" aria-labelledby="nx-pboard-title">
	<div class="nx-container">
		<div class="nx-pboard nx-dark">
			<div class="nx-pboard__head">
				<div>
					<p class="nx-eyebrow"><?php echo nexora_icon( 'chart', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Market board', 'nexora-commerce' ); ?></p>
					<h2 class="nx-section-title" id="nx-pboard-title"><?php echo esc_html( nexora_option( 'price_board_title' ) ); ?></h2>
					<p class="nx-pboard__note">
						<?php
						if ( $nexora_latest ) {
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Last price update %s.', 'nexora-commerce' ), wp_date( get_option( 'date_format' ), $nexora_latest ) ) ) . ' ';
						}
						esc_html_e( 'Arrows show the change since each item’s previous price. Store selling prices, not official price monitoring.', 'nexora-commerce' );
						?>
					</p>
				</div>
				<a class="nx-btn nx-btn--glass nx-btn--sm" href="<?php echo esc_url( nexora_shop_url() ); ?>"><?php esc_html_e( 'Open full market', 'nexora-commerce' ); ?> <?php echo nexora_icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			</div>

			<?php if ( count( $nexora_tab_counts ) > 1 ) : ?>
				<div class="nx-pboard__tabs" role="group" aria-label="<?php esc_attr_e( 'Filter the price board by section', 'nexora-commerce' ); ?>" data-nexora-board-tabs>
					<button type="button" aria-pressed="true" data-cat=""><?php esc_html_e( 'All', 'nexora-commerce' ); ?></button>
					<?php foreach ( $nexora_tab_counts as $nexora_slug => $nexora_term ) : ?>
						<button type="button" aria-pressed="false" data-cat="<?php echo esc_attr( $nexora_slug ); ?>"><?php echo esc_html( $nexora_term->name ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="nx-pboard__table" role="region" aria-labelledby="nx-pboard-title" tabindex="0">
				<table>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Item', 'nexora-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Price', 'nexora-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Change', 'nexora-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Trend', 'nexora-commerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Availability', 'nexora-commerce' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'nexora-commerce' ); ?></span></th>
						</tr>
					</thead>
					<tbody data-nexora-board-rows>
						<?php foreach ( $nexora_products as $nexora_p ) : ?>
							<?php $nexora_m = nexora_market( $nexora_p ); ?>
							<tr data-cats="<?php echo esc_attr( implode( ' ', nexora_product_cat_slugs( $nexora_p ) ) ); ?>">
								<th scope="row">
									<a class="nx-pboard__item" href="<?php echo esc_url( $nexora_p->get_permalink() ); ?>">
										<span class="nx-board__thumb"><?php echo $nexora_p->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<span>
											<span class="nx-pboard__name"><?php echo esc_html( $nexora_p->get_name() ); ?></span>
											<?php if ( $nexora_m['origin'] ) : ?>
												<span class="nx-pboard__origin"><?php echo esc_html( $nexora_m['origin'] ); ?></span>
											<?php endif; ?>
										</span>
									</a>
								</th>
								<td class="nx-pboard__price price" data-label="<?php esc_attr_e( 'Price', 'nexora-commerce' ); ?>"><?php echo wp_kses_post( $nexora_p->get_price_html() ); ?></td>
								<td data-label="<?php esc_attr_e( 'Change', 'nexora-commerce' ); ?>"><?php echo null !== $nexora_m['change'] ? nexora_change_chip( $nexora_m['change'], $nexora_m['since'] ) : '<span class="nx-chg is-none">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="nx-pboard__trend" data-label="<?php esc_attr_e( 'Trend', 'nexora-commerce' ); ?>"><?php echo nexora_sparkline( $nexora_m['history'], 90, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td data-label="<?php esc_attr_e( 'Availability', 'nexora-commerce' ); ?>"><?php echo nexora_stock_line( $nexora_p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="nx-pboard__action"><?php echo nexora_board_action( $nexora_p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="nx-pboard__empty" data-nexora-board-empty hidden><?php esc_html_e( 'Nothing listed in this section right now.', 'nexora-commerce' ); ?> <?php echo nexora_rfq_enabled() ? '<a href="' . esc_url( nexora_rfq_url() ) . '">' . esc_html__( 'Request a quotation', 'nexora-commerce' ) . '</a>' : ''; ?></p>
		</div>
	</div>
</section>
