<?php
/**
 * Freshtastic address form.
 *
 * @package NexoraCommerce
 * @version 9.3.0
 * @var string $load_address
 * @var array  $address
 */

defined( 'ABSPATH' ) || exit;

$page_title = ( 'billing' === $load_address ) ? esc_html__( 'Billing address', 'woocommerce' ) : esc_html__( 'Shipping address', 'woocommerce' );

do_action( 'woocommerce_before_edit_account_address_form' ); ?>

<?php if ( ! $load_address ) : ?>
	<?php wc_get_template( 'myaccount/my-address.php' ); ?>
<?php else : ?>

	<form method="post" class="nx-form" novalidate>

		<header class="nx-form__head">
			<a class="nx-form__back" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>"><?php echo nexora_icon( 'chevron-left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'All addresses', 'nexora-commerce' ); ?></a>
			<h2><?php echo apply_filters( 'woocommerce_my_account_edit_address_title', $page_title, $load_address ); ?></h2><?php // @codingStandardsIgnoreLine ?>
			<p><?php echo esc_html( 'billing' === $load_address ? __( 'Printed on receipts and used to contact you about orders.', 'nexora-commerce' ) : __( 'Where your orders are delivered. Store pickup orders do not need it.', 'nexora-commerce' ) ); ?></p>
		</header>

		<div class="woocommerce-address-fields nx-form__section">
			<?php do_action( "woocommerce_before_edit_address_form_{$load_address}" ); ?>

			<div class="woocommerce-address-fields__field-wrapper nx-form__grid">
				<?php
				foreach ( $address as $key => $field ) {
					woocommerce_form_field( $key, $field, wc_get_post_data_by_key( $key, $field['value'] ) );
				}
				?>
			</div>

			<?php do_action( "woocommerce_after_edit_address_form_{$load_address}" ); ?>
		</div>

		<div class="nx-form__actions">
			<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="save_address" value="<?php esc_attr_e( 'Save address', 'woocommerce' ); ?>"><?php esc_html_e( 'Save address', 'woocommerce' ); ?></button>
			<a class="nx-form__cancel" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>"><?php esc_html_e( 'Cancel', 'nexora-commerce' ); ?></a>
			<?php wp_nonce_field( 'woocommerce-edit_address', 'woocommerce-edit-address-nonce' ); ?>
			<input type="hidden" name="action" value="edit_address" />
		</div>

	</form>

<?php endif; ?>

<?php do_action( 'woocommerce_after_edit_account_address_form' ); ?>
