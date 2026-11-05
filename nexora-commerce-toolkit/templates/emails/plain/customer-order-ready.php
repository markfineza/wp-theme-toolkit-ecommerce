<?php
/**
 * Customer "order ready" email (plain text).
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

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

/* translators: %s: customer first name */
echo esc_html( sprintf( __( 'Hi %s,', 'nexora-commerce-toolkit' ), $order->get_billing_first_name() ) ) . "\n\n";

echo esc_html(
	$nexora_pickup
		/* translators: %s: order number */
		? sprintf( __( 'Good news: order #%s is packed and ready to collect. Please bring your order number; someone else can collect it for you.', 'nexora-commerce-toolkit' ), $order->get_order_number() )
		/* translators: %s: order number */
		: sprintf( __( 'Good news: order #%s is packed and waiting for your rider. You can book your courier now.', 'nexora-commerce-toolkit' ), $order->get_order_number() )
) . "\n\n";

if ( $fulfilment ) {
	if ( ! empty( $fulfilment['date'] ) ) {
		echo esc_html( ( $nexora_pickup ? __( 'Pickup time', 'nexora-commerce-toolkit' ) : __( 'Rider pickup time', 'nexora-commerce-toolkit' ) ) . ': ' . Nexora_Toolkit_Fulfilment::when( $fulfilment ) ) . "\n";
	}
	if ( $nexora_pickup && ! empty( $fulfilment['location'] ) ) {
		echo esc_html( __( 'Pickup point', 'nexora-commerce-toolkit' ) . ': ' . $fulfilment['location'] ) . "\n";
	}
	if ( ! $nexora_pickup && ! empty( $fulfilment['courier'] ) ) {
		echo esc_html( __( 'Courier', 'nexora-commerce-toolkit' ) . ': ' . $fulfilment['courier'] ) . "\n";
	}
	if ( ! empty( $fulfilment['address'] ) ) {
		echo esc_html( ( $nexora_pickup ? __( 'Address', 'nexora-commerce-toolkit' ) : __( 'Rider collects from', 'nexora-commerce-toolkit' ) ) . ': ' . $fulfilment['address'] ) . "\n";
	}
	if ( ! empty( $fulfilment['contact'] ) ) {
		echo esc_html( __( 'Store contact', 'nexora-commerce-toolkit' ) . ': ' . $fulfilment['contact'] ) . "\n";
	}
	echo "\n";
}

echo "----------------------------------------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
