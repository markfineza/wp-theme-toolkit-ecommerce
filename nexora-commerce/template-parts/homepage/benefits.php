<?php
/**
 * Brand benefits.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="nx-section nx-benefits" aria-label="<?php esc_attr_e( 'Why shop with us', 'nexora-commerce' ); ?>">
	<div class="nx-container">
		<ul class="nx-benefits__grid">
			<?php for ( $nexora_i = 1; $nexora_i <= 4; $nexora_i++ ) : ?>
				<?php
				$nexora_title = nexora_option( "benefit_{$nexora_i}_title" );
				if ( ! $nexora_title ) {
					continue;
				}
				?>
				<li class="nx-benefit">
					<span class="nx-benefit__icon" aria-hidden="true"><?php echo nexora_icon( nexora_option( "benefit_{$nexora_i}_icon" ), 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<h3><?php echo esc_html( $nexora_title ); ?></h3>
					<p><?php echo esc_html( nexora_option( "benefit_{$nexora_i}_text" ) ); ?></p>
				</li>
			<?php endfor; ?>
		</ul>
	</div>
</section>
