<?php
/**
 * Questions and answers: heading on one side, accordion on the other. Native <details>, so no script is needed.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_items = array();
foreach ( array( 1, 2, 3, 4, 5, 6 ) as $nexora_n ) {
	$nexora_q = trim( (string) nexora_option( "faq{$nexora_n}_q" ) );
	$nexora_a = trim( (string) nexora_option( "faq{$nexora_n}_a" ) );
	if ( $nexora_q && $nexora_a ) {
		$nexora_items[] = array( $nexora_q, $nexora_a );
	}
}
if ( ! $nexora_items ) {
	return;
}
?>
<section class="nx-section nx-faq" aria-labelledby="nx-faq-title">
	<div class="nx-container nx-faq__layout">
		<div class="nx-faq__intro">
			<?php if ( nexora_option( 'faq_eyebrow' ) ) : ?>
				<p class="nx-center-head__eyebrow"><?php echo esc_html( nexora_option( 'faq_eyebrow' ) ); ?></p>
			<?php endif; ?>
			<h2 class="nx-faq__title" id="nx-faq-title"><?php echo esc_html( nexora_option( 'faq_title' ) ); ?></h2>
			<p class="nx-faq__text"><?php echo esc_html( nexora_option( 'faq_text' ) ); ?></p>
		</div>
		<div class="nx-faq__list">
			<?php foreach ( $nexora_items as $nexora_i => $nexora_item ) : ?>
				<details class="nx-faq__item"<?php echo 0 === $nexora_i ? ' open' : ''; ?>>
					<summary><?php echo esc_html( $nexora_item[0] ); ?> <span class="nx-faq__sign" aria-hidden="true"></span></summary>
					<p><?php echo esc_html( $nexora_item[1] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>
