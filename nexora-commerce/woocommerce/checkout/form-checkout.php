<?php
/**
 * Freshtastic checkout: numbered step cards (details → delivery or pickup → review and pay)
 * beside a sticky order summary. All WooCommerce hooks are kept.
 *
 * @package NexoraCommerce
 * @version 9.4.0
 * @var WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

$nexora_steps          = nexora_checkout_steps();
$nexora_needs_shipping = isset( $nexora_steps['fulfilment'] );
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout nx-co" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>" novalidate>

	<div class="nx-co__main">

		<nav class="nx-co-steps" aria-label="<?php esc_attr_e( 'Checkout steps', 'nexora-commerce' ); ?>">
			<ol>
				<?php $nexora_n = 0; ?>
				<?php foreach ( $nexora_steps as $nexora_key => $nexora_label ) : ?>
					<li><a href="#nx-step-<?php echo esc_attr( $nexora_key ); ?>" data-nexora-step-link="<?php echo esc_attr( $nexora_key ); ?>"><span class="nx-co-steps__num"><?php echo esc_html( (string) ++$nexora_n ); ?></span><span class="nx-co-steps__label"><?php echo esc_html( $nexora_label ); ?></span></a></li>
				<?php endforeach; ?>
			</ol>
		</nav>

		<?php if ( $checkout->get_checkout_fields() ) : ?>

			<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

			<section class="nx-co-card" id="nx-step-details" aria-labelledby="nx-step-details-title" data-nexora-step="details">
				<?php nexora_checkout_step_head( 'details', __( 'Your details', 'nexora-commerce' ), __( 'We send order updates to this email and mobile number.', 'nexora-commerce' ) ); ?>
				<div id="customer_details" class="nx-co-card__body">
					<?php do_action( 'woocommerce_checkout_billing' ); ?>
					<?php if ( ! $nexora_needs_shipping ) : ?>
						<?php do_action( 'woocommerce_checkout_shipping' ); ?>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( $nexora_needs_shipping ) : ?>
				<section class="nx-co-card" id="nx-step-fulfilment" aria-labelledby="nx-step-fulfilment-title" data-nexora-step="fulfilment">
					<?php nexora_checkout_step_head( 'fulfilment', __( 'Delivery or pickup', 'nexora-commerce' ), __( 'Choose how you will get your order, then pick a time.', 'nexora-commerce' ) ); ?>
					<div class="nx-co-card__body">
						<?php echo nexora_checkout_shipping_options_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building. ?>
						<?php do_action( 'nexora_checkout_fulfilment', $checkout ); ?>
						<div class="nx-co-shipto">
							<?php do_action( 'woocommerce_checkout_shipping' ); ?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

		<?php endif; ?>

		<section class="nx-co-card" id="nx-step-payment" aria-labelledby="nx-step-payment-title" data-nexora-step="payment">
			<?php nexora_checkout_step_head( 'payment', __( 'Review and pay', 'nexora-commerce' ), __( 'Add a note for the store if you need to, then choose how to pay.', 'nexora-commerce' ) ); ?>
			<div class="nx-co-card__body">
				<?php nexora_checkout_order_notes( $checkout ); ?>
				<?php woocommerce_checkout_payment(); ?>
			</div>
		</section>

	</div>

	<aside class="nx-co__aside">
		<details class="nx-co-summary" open data-nexora-summary>
			<summary class="nx-co-summary__toggle">
				<span><?php echo nexora_icon( 'bag', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Order summary', 'nexora-commerce' ); ?></span>
				<span class="nx-co-summary__total" data-nexora-summary-total><?php echo wp_kses_post( WC()->cart->get_total() ); ?></span>
			</summary>

			<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>

			<h2 id="order_review_heading" class="nx-co-summary__title">
				<?php esc_html_e( 'Order summary', 'nexora-commerce' ); ?>
				<?php /* translators: %d: number of items */ ?>
				<span><?php echo esc_html( sprintf( _n( '%d item', '%d items', WC()->cart->get_cart_contents_count(), 'nexora-commerce' ), WC()->cart->get_cart_contents_count() ) ); ?></span>
			</h2>

			<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

			<div id="order_review" class="woocommerce-checkout-review-order">
				<?php do_action( 'woocommerce_checkout_order_review' ); ?>
			</div>

			<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

			<ul class="nx-co-trust">
				<li><?php echo nexora_icon( 'lock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Secure checkout', 'nexora-commerce' ); ?></li>
				<li><?php echo nexora_icon( 'leaf', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Packed fresh on your schedule', 'nexora-commerce' ); ?></li>
				<li><?php echo nexora_icon( 'chat', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Questions? Reply to your order email', 'nexora-commerce' ); ?></li>
			</ul>
		</details>
	</aside>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
