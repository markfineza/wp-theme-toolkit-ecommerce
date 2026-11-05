<?php
/**
 * Search form.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

$nexora_sf_id = wp_unique_id( 'nx-sf-' );
?>
<form role="search" method="get" class="search-form nx-inline-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr( $nexora_sf_id ); ?>"><?php esc_html_e( 'Search for:', 'nexora-commerce' ); ?></label>
	<input type="search" id="<?php echo esc_attr( $nexora_sf_id ); ?>" class="search-field" placeholder="<?php esc_attr_e( 'Search…', 'nexora-commerce' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s">
	<button type="submit" class="nx-btn nx-btn--primary"><?php esc_html_e( 'Search', 'nexora-commerce' ); ?></button>
</form>
