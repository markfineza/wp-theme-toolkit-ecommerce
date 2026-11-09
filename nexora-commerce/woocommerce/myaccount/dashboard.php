<?php
/**
 * Freshtastic customer dashboard: greeting, what's next, account numbers, recent orders,
 * buy again and the default address.
 *
 * @package NexoraCommerce
 * @version 4.4.0
 * @var WP_User $current_user
 */

defined( 'ABSPATH' ) || exit;

$nexora_summary = nexora_account_summary( $current_user->ID );
$nexora_name    = $current_user->first_name ? $current_user->first_name : $current_user->display_name;
$nexora_next    = $nexora_summary['next'];
$nexora_shop    = wc_get_page_permalink( 'shop' );
$nexora_rfq     = nexora_rfq_enabled() ? nexora_rfq_url() : '';
// Registration saves the name first, so only a street address counts as having an address.
$nexora_address = get_user_meta( $current_user->ID, 'billing_address_1', true ) ? wc_get_account_formatted_address( 'billing' ) : '';
$nexora_phone   = get_user_meta( $current_user->ID, 'billing_phone', true );
$nexora_wallet  = class_exists( 'Nexora_Toolkit_Coupon_Claims' ) && method_exists( 'Nexora_Toolkit_Coupon_Claims', 'dashboard_panel' );
?>
<div class="nx-dash">

	<header class="nx-dash-hero">
		<div class="nx-dash-hero__text">
			<p class="nx-dash-hero__eyebrow"><?php echo esc_html( wp_date( 'l, F j' ) ); ?></p>
			<h2 class="nx-dash-hero__title"><?php echo esc_html( nexora_account_greeting() . ', ' . $nexora_name ); ?></h2>
			<p class="nx-dash-hero__sub">
				<?php
				if ( $nexora_summary['active'] ) {
					/* translators: %d: number of orders */
					echo esc_html( sprintf( _n( 'You have %d order in progress.', 'You have %d orders in progress.', count( $nexora_summary['active'] ), 'nexora-commerce' ), count( $nexora_summary['active'] ) ) );
				} else {
					esc_html_e( 'Fresh meat, fish, vegetables and frozen goods are ready for your next order.', 'nexora-commerce' );
				}
				?>
			</p>
		</div>
		<div class="nx-dash-hero__actions">
			<a class="nx-dash-btn nx-dash-btn--accent" href="<?php echo esc_url( $nexora_shop ); ?>"><?php echo nexora_icon( 'bag', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Shop the market', 'nexora-commerce' ); ?></a>
			<?php if ( $nexora_rfq ) : ?>
				<a class="nx-dash-btn nx-dash-btn--line" href="<?php echo esc_url( $nexora_rfq ); ?>"><?php echo nexora_icon( 'clipboard', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Request a quote', 'nexora-commerce' ); ?></a>
			<?php endif; ?>
		</div>
		<ul class="nx-dash-stats" aria-label="<?php esc_attr_e( 'Account summary', 'nexora-commerce' ); ?>">
			<li class="nx-dash-stat">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
					<span class="nx-dash-stat__icon" aria-hidden="true"><?php echo nexora_icon( 'clock', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<strong><?php echo esc_html( number_format_i18n( count( $nexora_summary['active'] ) ) ); ?></strong>
					<span><?php esc_html_e( 'In progress', 'nexora-commerce' ); ?></span>
				</a>
			</li>
			<li class="nx-dash-stat">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
					<span class="nx-dash-stat__icon" aria-hidden="true"><?php echo nexora_icon( 'bag', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<strong><?php echo esc_html( number_format_i18n( $nexora_summary['total'] ) ); ?></strong>
					<span><?php esc_html_e( 'Total orders', 'nexora-commerce' ); ?></span>
				</a>
			</li>
			<?php if ( null !== $nexora_summary['quotations'] && nexora_rfq_enabled() ) : ?>
				<li class="nx-dash-stat">
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'quotations' ) ); ?>">
						<span class="nx-dash-stat__icon" aria-hidden="true"><?php echo nexora_icon( 'clipboard', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<strong><?php echo esc_html( number_format_i18n( $nexora_summary['quotations'] ) ); ?></strong>
						<span><?php esc_html_e( 'Quotations', 'nexora-commerce' ); ?></span>
					</a>
				</li>
			<?php endif; ?>
			<?php if ( $nexora_wallet ) : ?>
				<li class="nx-dash-stat">
					<a href="<?php echo esc_url( Nexora_Toolkit_Coupon_Claims::url() ); ?>">
						<span class="nx-dash-stat__icon" aria-hidden="true"><?php echo nexora_icon( 'tag', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<strong><?php echo esc_html( number_format_i18n( Nexora_Toolkit_Coupon_Claims::ready_count( $current_user->ID ) ) ); ?></strong>
						<span><?php esc_html_e( 'Coupons ready', 'nexora-commerce' ); ?></span>
					</a>
				</li>
				<?php if ( Nexora_Toolkit_Points::enabled() ) : ?>
					<li class="nx-dash-stat">
						<a href="<?php echo esc_url( Nexora_Toolkit_Points::url() ); ?>">
							<span class="nx-dash-stat__icon" aria-hidden="true"><?php echo nexora_icon( 'star', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<strong><?php echo esc_html( number_format_i18n( Nexora_Toolkit_Points::balance( $current_user->ID ) ) ); ?></strong>
							<span><?php esc_html_e( 'Points', 'nexora-commerce' ); ?></span>
						</a>
					</li>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( null !== $nexora_summary['wishlist'] ) : ?>
				<li class="nx-dash-stat">
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'wishlist' ) ); ?>">
						<span class="nx-dash-stat__icon" aria-hidden="true"><?php echo nexora_icon( 'heart', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<strong><?php echo esc_html( number_format_i18n( $nexora_summary['wishlist'] ) ); ?></strong>
						<span><?php esc_html_e( 'Saved items', 'nexora-commerce' ); ?></span>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</header>

	<?php
	if ( $nexora_next ) :
		$nexora_order  = $nexora_next['order'];
		$nexora_info   = $nexora_next['info'];
		$nexora_pickup = 'pickup' === $nexora_info['type'];
		$nexora_ready  = $nexora_order->has_status( Nexora_Toolkit_Fulfilment::STATUS );
		$nexora_when   = Nexora_Toolkit_Fulfilment::when( $nexora_info );
		?>
		<section class="nx-dash-next<?php echo $nexora_ready ? ' is-ready' : ''; ?>" aria-labelledby="nx-dash-next-title">
			<div class="nx-dash-next__head">
				<span class="nx-dash-next__icon" aria-hidden="true"><?php echo nexora_icon( $nexora_pickup ? 'store' : 'bike', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2 id="nx-dash-next-title" class="nx-dash-next__title">
						<?php
						if ( $nexora_ready ) {
							echo esc_html( $nexora_pickup ? __( 'Ready to collect', 'nexora-commerce' ) : __( 'Packed: book your rider now', 'nexora-commerce' ) );
						} else {
							echo esc_html( $nexora_pickup ? __( 'Your next pickup', 'nexora-commerce' ) : __( 'Your next rider collection', 'nexora-commerce' ) );
						}
						?>
					</h2>
					<p class="nx-dash-next__meta">
						<?php /* translators: %s: order number */ ?>
						<a href="<?php echo esc_url( $nexora_order->get_view_order_url() ); ?>"><?php echo esc_html( sprintf( __( 'Order #%s', 'nexora-commerce' ), $nexora_order->get_order_number() ) ); ?></a>
						<?php if ( $nexora_when ) : ?>
							<span><?php echo esc_html( $nexora_when ); ?></span>
						<?php endif; ?>
						<?php
						$nexora_where = $nexora_pickup ? ( isset( $nexora_info['location'] ) ? $nexora_info['location'] : '' ) : ( isset( $nexora_info['courier'] ) ? $nexora_info['courier'] : '' );
						if ( $nexora_where ) :
							?>
							<span><?php echo esc_html( $nexora_where ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<ol class="nx-dash-steps" aria-label="<?php esc_attr_e( 'Order progress', 'nexora-commerce' ); ?>">
				<?php foreach ( nexora_account_order_steps( $nexora_order ) as $nexora_step ) : ?>
					<li class="is-<?php echo esc_attr( $nexora_step[1] ); ?>"<?php echo 'current' === $nexora_step[1] ? ' aria-current="step"' : ''; ?>><span><?php echo esc_html( $nexora_step[0] ); ?></span></li>
				<?php endforeach; ?>
			</ol>
			<?php if ( ! empty( $nexora_info['address'] ) ) : ?>
				<p class="nx-dash-next__address"><?php echo nexora_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $nexora_info['address'] ); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<div class="nx-dash-grid">

		<section class="nx-dash-panel nx-dash-orders" aria-labelledby="nx-dash-orders-title">
			<header class="nx-dash-panel__head">
				<h2 id="nx-dash-orders-title"><?php esc_html_e( 'Recent orders', 'nexora-commerce' ); ?></h2>
				<?php if ( $nexora_summary['recent'] ) : ?>
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( 'View all', 'nexora-commerce' ); ?></a>
				<?php endif; ?>
			</header>

			<?php if ( $nexora_summary['recent'] ) : ?>
				<ul class="nx-dash-orderlist">
					<?php foreach ( $nexora_summary['recent'] as $nexora_recent ) : ?>
						<li>
							<a class="nx-dash-order" href="<?php echo esc_url( $nexora_recent->get_view_order_url() ); ?>">
								<span class="nx-dash-order__main">
									<?php /* translators: %s: order number */ ?>
									<strong><?php echo esc_html( sprintf( __( 'Order #%s', 'nexora-commerce' ), $nexora_recent->get_order_number() ) ); ?></strong>
									<span>
										<?php
										$nexora_count = $nexora_recent->get_item_count();
										/* translators: 1: order date, 2: number of items */
										echo esc_html( sprintf( _n( '%1$s · %2$d item', '%1$s · %2$d items', $nexora_count, 'nexora-commerce' ), wc_format_datetime( $nexora_recent->get_date_created() ), $nexora_count ) );
										?>
									</span>
								</span>
								<span class="nx-status nx-status--<?php echo esc_attr( $nexora_recent->get_status() ); ?>"><?php echo esc_html( wc_get_order_status_name( $nexora_recent->get_status() ) ); ?></span>
								<span class="nx-dash-order__total"><?php echo wp_kses_post( $nexora_recent->get_formatted_order_total() ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<div class="nx-dash-empty">
					<span class="nx-dash-empty__icon" aria-hidden="true"><?php echo nexora_icon( 'leaf', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<p><strong><?php esc_html_e( 'No orders yet', 'nexora-commerce' ); ?></strong></p>
					<p><?php esc_html_e( 'Your orders and pickup times will appear here after you check out.', 'nexora-commerce' ); ?></p>
					<a class="nx-dash-btn nx-dash-btn--primary" href="<?php echo esc_url( $nexora_shop ); ?>"><?php esc_html_e( 'Browse products', 'nexora-commerce' ); ?></a>
				</div>
			<?php endif; ?>
		</section>

		<div class="nx-dash-side">
			<?php
			if ( $nexora_wallet ) {
				Nexora_Toolkit_Coupon_Claims::dashboard_panel();
			}
			?>
			<section class="nx-dash-panel" aria-labelledby="nx-dash-profile-title">
				<header class="nx-dash-panel__head">
					<h2 id="nx-dash-profile-title"><?php esc_html_e( 'Your details', 'nexora-commerce' ); ?></h2>
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>"><?php esc_html_e( 'Edit', 'nexora-commerce' ); ?></a>
				</header>
				<ul class="nx-dash-details">
					<li><?php echo nexora_icon( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span><?php echo esc_html( $current_user->user_email ); ?></span></li>
					<?php if ( $nexora_phone ) : ?>
						<li><?php echo nexora_icon( 'phone', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <span><?php echo esc_html( $nexora_phone ); ?></span></li>
					<?php endif; ?>
					<li>
						<?php echo nexora_icon( 'pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $nexora_address ) : ?>
							<span><?php echo wp_kses_post( $nexora_address ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', 'billing', wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'Add your address for a faster checkout', 'nexora-commerce' ); ?></a>
						<?php endif; ?>
					</li>
				</ul>
				<?php /* translators: 1: display name, 2: opening link tag, 3: closing link tag */ ?>
				<p class="nx-dash-notyou"><?php printf( esc_html__( 'Not %1$s? %2$sLog out%3$s', 'nexora-commerce' ), esc_html( $current_user->display_name ), '<a href="' . esc_url( wc_logout_url() ) . '">', '</a>' ); ?></p>
			</section>

			<?php if ( $nexora_summary['spent'] > 0 ) : ?>
				<section class="nx-dash-panel nx-dash-spent">
					<p class="nx-dash-spent__label"><?php esc_html_e( 'Spent with us', 'nexora-commerce' ); ?></p>
					<p class="nx-dash-spent__value"><?php echo wp_kses_post( wc_price( $nexora_summary['spent'] ) ); ?></p>
				</section>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $nexora_summary['buy_again'] ) : ?>
		<section class="nx-dash-panel" aria-labelledby="nx-dash-again-title">
			<header class="nx-dash-panel__head">
				<h2 id="nx-dash-again-title"><?php esc_html_e( 'Buy again', 'nexora-commerce' ); ?></h2>
				<a href="<?php echo esc_url( $nexora_shop ); ?>"><?php esc_html_e( 'Shop all', 'nexora-commerce' ); ?></a>
			</header>
			<ul class="nx-dash-again">
				<?php foreach ( $nexora_summary['buy_again'] as $nexora_product ) : ?>
					<li>
						<a class="nx-dash-again__item" href="<?php echo esc_url( $nexora_product->get_permalink() ); ?>">
							<?php echo wp_kses_post( $nexora_product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => '' ) ) ); ?>
							<span class="nx-dash-again__name"><?php echo esc_html( $nexora_product->get_name() ); ?></span>
							<span class="nx-dash-again__price"><?php echo wp_kses_post( $nexora_product->get_price_html() ); ?></span>
						</a>
						<?php /* translators: %s: product name */ ?>
						<a class="nx-dash-again__add" rel="nofollow" href="<?php echo esc_url( $nexora_product->add_to_cart_url() ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Buy %s again', 'nexora-commerce' ), $nexora_product->get_name() ) ); ?>"><?php echo esc_html( $nexora_product->is_type( 'simple' ) ? __( 'Add to cart', 'nexora-commerce' ) : __( 'Choose options', 'nexora-commerce' ) ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );
	?>
</div>
