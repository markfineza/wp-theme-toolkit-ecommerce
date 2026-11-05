<?php
/**
 * Newsletter form. Submissions are handled by the Freshtastic Food Hub Toolkit plugin, which keeps
 * provider API keys on the server. Consent is never pre-ticked.
 *
 * Args: id (string), compact (bool).
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_args = wp_parse_args( $args ?? array(), array( 'id' => 'nl', 'compact' => false ) );
$nexora_uid  = 'nx-nl-' . sanitize_html_class( $nexora_args['id'] );

if ( ! apply_filters( 'nexora_newsletter_enabled', false ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		echo '<p class="nx-admin-hint">' . esc_html__( 'Admin note: activate the Freshtastic Food Hub Toolkit plugin to enable newsletter signups. Visitors do not see this message.', 'nexora-commerce' ) . '</p>';
	}
	return;
}

$nexora_consent = apply_filters( 'nexora_newsletter_consent_text', __( 'I agree to receive marketing emails and accept the privacy policy. I can unsubscribe at any time.', 'nexora-commerce' ) );
?>
<form class="nx-newsletter-form<?php echo $nexora_args['compact'] ? ' is-compact' : ''; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nexora-newsletter novalidate>
	<input type="hidden" name="action" value="nexora_subscribe">
	<input type="hidden" name="source" value="<?php echo esc_attr( $nexora_args['id'] ); ?>">
	<div class="nx-hp" aria-hidden="true">
		<label for="<?php echo esc_attr( $nexora_uid ); ?>-website"><?php esc_html_e( 'Leave this field empty', 'nexora-commerce' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $nexora_uid ); ?>-website" name="website" tabindex="-1" autocomplete="off">
	</div>
	<div class="nx-newsletter-form__row">
		<label class="screen-reader-text" for="<?php echo esc_attr( $nexora_uid ); ?>-email"><?php esc_html_e( 'Email address', 'nexora-commerce' ); ?></label>
		<input type="email" id="<?php echo esc_attr( $nexora_uid ); ?>-email" name="email" required autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'nexora-commerce' ); ?>" aria-describedby="<?php echo esc_attr( $nexora_uid ); ?>-msg">
		<button type="submit" class="nx-btn nx-btn--primary"><?php esc_html_e( 'Subscribe', 'nexora-commerce' ); ?></button>
	</div>
	<label class="nx-check nx-newsletter-form__consent">
		<input type="checkbox" name="consent" value="1" required>
		<span>
			<?php echo esc_html( $nexora_consent ); ?>
			<?php if ( get_privacy_policy_url() ) : ?>
				<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'Privacy policy', 'nexora-commerce' ); ?></a>
			<?php endif; ?>
		</span>
	</label>
	<p class="nx-newsletter-form__msg" id="<?php echo esc_attr( $nexora_uid ); ?>-msg" role="status" aria-live="polite"></p>
</form>
