<?php
/**
 * Freshtastic address book: one card per address.
 *
 * @package NexoraCommerce
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

$customer_id = get_current_user_id();

if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
	$get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		array(
			'billing'  => __( 'Billing address', 'woocommerce' ),
			'shipping' => __( 'Shipping address', 'woocommerce' ),
		),
		$customer_id
	);
} else {
	$get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		array(
			'billing' => __( 'Billing address', 'woocommerce' ),
		),
		$customer_id
	);
}
?>

<header class="nx-form__head">
	<h2><?php esc_html_e( 'Addresses', 'nexora-commerce' ); ?></h2>
	<p><?php echo apply_filters( 'woocommerce_my_account_my_address_description', esc_html__( 'The following addresses will be used on the checkout page by default.', 'woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
</header>

<div class="woocommerce-Addresses addresses nx-address-grid">

<?php foreach ( $get_addresses as $name => $address_title ) : ?>
	<?php $address = wc_get_account_formatted_address( $name ); ?>

	<div class="woocommerce-Address nx-address<?php echo $address ? '' : ' is-empty'; ?>">
		<header class="woocommerce-Address-title title nx-address__head">
			<span class="nx-address__icon" aria-hidden="true"><?php echo nexora_icon( 'billing' === $name ? 'card' : 'truck', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<h3><?php echo esc_html( $address_title ); ?></h3>
		</header>
		<address>
			<?php
			echo $address ? wp_kses_post( $address ) : '<span class="nx-muted">' . esc_html__( 'You have not set up this type of address yet.', 'woocommerce' ) . '</span>';

			do_action( 'woocommerce_my_account_after_my_address', $name );
			?>
		</address>
		<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', $name ) ); ?>" class="edit nx-btn nx-btn--ghost nx-btn--sm">
			<?php
			printf(
				/* translators: %s: Address title */
				$address ? esc_html__( 'Edit %s', 'woocommerce' ) : esc_html__( 'Add %s', 'woocommerce' ),
				esc_html( $address_title )
			);
			?>
		</a>
	</div>

<?php endforeach; ?>

</div>
