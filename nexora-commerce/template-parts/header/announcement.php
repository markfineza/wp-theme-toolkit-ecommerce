<?php
/**
 * Announcement bar. The countdown only renders for a real end date set in the Customizer.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_announcement_is_visible() ) {
	return;
}

$nexora_ends   = nexora_datetime_to_timestamp( nexora_option( 'announcement_countdown' ) );
$nexora_link   = nexora_option( 'announcement_link_url' );
$nexora_label  = nexora_option( 'announcement_link_label' );
$nexora_hash   = substr( md5( nexora_option( 'announcement_text' ) . $nexora_ends ), 0, 8 );
if ( ! $nexora_link && nexora_has_store() ) {
	$nexora_link = nexora_shop_url( array( 'on_sale' => 1 ) );
}
?>
<div class="nx-announcement" data-nexora-announcement="<?php echo esc_attr( $nexora_hash ); ?>">
	<div class="nx-container nx-announcement__inner">
		<p class="nx-announcement__text">
			<?php echo nexora_icon( 'sparkle', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php echo esc_html( nexora_option( 'announcement_text' ) ); ?></span>
			<?php if ( $nexora_ends ) : ?>
				<span class="nx-countdown" data-nexora-countdown="<?php echo esc_attr( (string) $nexora_ends ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( 'Offer ends', 'nexora-commerce' ); ?></span>
					<time datetime="<?php echo esc_attr( gmdate( 'c', $nexora_ends ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $nexora_ends ) ); ?></time>
				</span>
			<?php endif; ?>
			<?php if ( $nexora_link && $nexora_label ) : ?>
				<a href="<?php echo esc_url( $nexora_link ); ?>"><?php echo esc_html( $nexora_label ); ?></a>
			<?php endif; ?>
		</p>
		<?php if ( nexora_option( 'announcement_dismissible' ) ) : ?>
			<button type="button" class="nx-announcement__close" data-nexora-dismiss>
				<?php echo nexora_icon( 'close', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss announcement', 'nexora-commerce' ); ?></span>
			</button>
		<?php endif; ?>
	</div>
</div>
