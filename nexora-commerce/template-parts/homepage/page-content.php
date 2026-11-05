<?php
/**
 * Block editor content of the static front page, so editors can add their own blocks.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_front = (int) get_option( 'page_on_front' );
if ( 'page' !== get_option( 'show_on_front' ) || ! $nexora_front ) {
	return;
}
$nexora_content = get_post_field( 'post_content', $nexora_front );
if ( '' === trim( wp_strip_all_tags( (string) $nexora_content, true ) ) && false === strpos( (string) $nexora_content, '<!-- wp:' ) ) {
	return;
}
?>
<section class="nx-section nx-front-content">
	<div class="nx-container entry-content nx-prose">
		<?php echo apply_filters( 'the_content', $nexora_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core content filter. ?>
	</div>
</section>
