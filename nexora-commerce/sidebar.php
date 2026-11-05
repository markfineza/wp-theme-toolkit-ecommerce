<?php
/**
 * Blog sidebar.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_active_sidebar( 'sidebar-blog' ) ) {
	return;
}
?>
<aside class="nx-sidebar widget-area" aria-label="<?php esc_attr_e( 'Blog sidebar', 'nexora-commerce' ); ?>">
	<?php dynamic_sidebar( 'sidebar-blog' ); ?>
</aside>
