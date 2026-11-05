<?php
/**
 * Customer "order ready" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/customer-order-ready.php.
 *
 * @package NexoraToolkit
 * @var WC_Order   $order
 * @var array|null $fulfilment
 * @var string     $email_heading
 * @var string     $additional_content
 * @var bool       $sent_to_admin
 * @var bool       $plain_text
 * @var WC_Email   $email
 */

defined( 'ABSPATH' ) || exit;

$nexora_pickup = $fulfilment && 'pickup' === $fulfilment['type'];

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'nexora-commerce-toolkit' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php if ( $nexora_pickup ) : ?>
	<?php /* translators: %s: order number */ ?>
	<p><?php printf( esc_html__( 'Good news: order #%s is packed and ready to collect. Please bring your order number; someone else can collect it for you.', 'nexora-commerce-toolkit' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php else : ?>
	<?php /* translators: %s: order number */ ?>
	<p><?php printf( esc_html__( 'Good news: order #%s is packed and waiting for your rider. You can book your courier now.', 'nexora-commerce-toolkit' ), esc_html( $order->get_order_number() ) ); ?></p>
<?php endif; ?>

<?php if ( $fulfilment ) : ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 24px;" role="presentation">
		<?php if ( ! empty( $fulfilment['date'] ) ) : ?>
			<tr><th style="text-align:left;width:40%;"><?php echo esc_html( $nexora_pickup ? __( 'Pickup time', 'nexora-commerce-toolkit' ) : __( 'Rider pickup time', 'nexora-commerce-toolkit' ) ); ?></th><td><?php echo esc_html( Nexora_Toolkit_Fulfilment::when( $fulfilment ) ); ?></td></tr>
		<?php endif; ?>
		<?php if ( $nexora_pickup && ! empty( $fulfilment['location'] ) ) : ?>
			<tr><th style="text-align:left;"><?php esc_html_e( 'Pickup point', 'nexora-commerce-toolkit' ); ?></th><td><?php echo esc_html( $fulfilment['location'] ); ?><?php echo ! empty( $fulfilment['hours'] ) ? '<br>' . esc_html( $fulfilment['hours'] ) : ''; ?></td></tr>
		<?php endif; ?>
		<?php if ( ! $nexora_pickup && ! empty( $fulfilment['courier'] ) ) : ?>
			<tr><th style="text-align:left;"><?php esc_html_e( 'Courier', 'nexora-commerce-toolkit' ); ?></th><td><?php echo esc_html( $fulfilment['courier'] ); ?></td></tr>
		<?php endif; ?>
		<?php if ( ! empty( $fulfilment['address'] ) ) : ?>
			<tr><th style="text-align:left;"><?php echo esc_html( $nexora_pickup ? __( 'Address', 'nexora-commerce-toolkit' ) : __( 'Rider collects from', 'nexora-commerce-toolkit' ) ); ?></th><td><?php echo esc_html( $fulfilment['address'] ); ?></td></tr>
		<?php endif; ?>
		<?php if ( ! empty( $fulfilment['contact'] ) ) : ?>
			<tr><th style="text-align:left;"><?php esc_html_e( 'Store contact', 'nexora-commerce-toolkit' ); ?></th><td><?php echo esc_html( $fulfilment['contact'] ); ?></td></tr>
		<?php endif; ?>
		<tr><th style="text-align:left;"><?php esc_html_e( 'Order number', 'nexora-commerce-toolkit' ); ?></th><td><strong><?php echo esc_html( $order->get_order_number() ); ?></strong></td></tr>
	</table>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
