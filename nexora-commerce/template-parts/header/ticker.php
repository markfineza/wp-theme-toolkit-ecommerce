<?php
/**
 * Market price ticker: live market status plus the products whose prices changed most recently.
 * Changes come from the store's own price history (Freshtastic Food Hub Toolkit); when no history
 * exists yet, the ticker lists current prices without arrows.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_has_store() || ! nexora_option( 'ticker_enabled' ) ) {
	return;
}

$nexora_products = nexora_board_products( (int) nexora_option( 'ticker_limit' ) );
if ( ! $nexora_products ) {
	return;
}

$nexora_open  = (string) nexora_option( 'market_open' );
$nexora_close = (string) nexora_option( 'market_close' );
$nexora_hours = preg_match( '/^\d{1,2}:\d{2}$/', $nexora_open ) && preg_match( '/^\d{1,2}:\d{2}$/', $nexora_close );

ob_start();
foreach ( $nexora_products as $nexora_p ) {
	$nexora_m = nexora_market( $nexora_p );
	printf(
		'<li><a href="%1$s"><span class="nx-ticker__name">%2$s</span> <span class="nx-ticker__price">%3$s</span> %4$s</a></li>',
		esc_url( $nexora_p->get_permalink() ),
		esc_html( $nexora_p->get_name() ),
		wp_kses_post( $nexora_p->get_price_html() ),
		nexora_change_chip( $nexora_m['change'], $nexora_m['since'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}
$nexora_items = ob_get_clean();
?>
<div class="nx-ticker" role="region" aria-label="<?php esc_attr_e( 'Market prices', 'nexora-commerce' ); ?>" data-nexora-ticker>
	<?php if ( $nexora_hours ) : ?>
		<p class="nx-ticker__status" data-nexora-market-status data-open="<?php echo esc_attr( $nexora_open ); ?>" data-close="<?php echo esc_attr( $nexora_close ); ?>" data-offset="<?php echo esc_attr( (string) ( wp_timezone()->getOffset( new DateTime( 'now', wp_timezone() ) ) / 60 ) ); ?>">
			<span class="nx-ticker__dot" aria-hidden="true"></span>
			<span data-nexora-market-label>
				<?php
				/* translators: 1: opening time, 2: closing time */
				echo esc_html( sprintf( __( 'Market hours %1$s–%2$s', 'nexora-commerce' ), $nexora_open, $nexora_close ) );
				?>
			</span>
		</p>
	<?php endif; ?>
	<div class="nx-ticker__viewport">
		<ul class="nx-ticker__track"><?php echo $nexora_items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></ul>
		<ul class="nx-ticker__track" aria-hidden="true" data-nexora-ticker-clone><?php echo str_replace( '<a href=', '<a tabindex="-1" href=', $nexora_items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- decorative duplicate for the seamless loop; kept out of the tab order. ?></ul>
	</div>
	<button type="button" class="nx-ticker__toggle" aria-pressed="false" data-nexora-ticker-toggle>
		<span class="nx-ticker__pause"><?php echo nexora_icon( 'pause', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="nx-ticker__play"><?php echo nexora_icon( 'play', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<span class="screen-reader-text"><?php esc_html_e( 'Pause price ticker', 'nexora-commerce' ); ?></span>
	</button>
</div>
