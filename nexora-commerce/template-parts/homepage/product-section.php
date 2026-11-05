<?php
/**
 * Generic product section. Args: key, title, type, limit, link, link_label, eyebrow, include, columns, class.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! nexora_is_woocommerce_active() ) {
	return;
}

$nexora_a = wp_parse_args(
	$args ?? array(),
	array(
		'key'        => 'products',
		'title'      => '',
		'type'       => 'latest',
		'limit'      => 8,
		'link'       => '',
		'link_label' => '',
		'eyebrow'    => '',
		'include'    => array(),
		'columns'    => 4,
		'class'      => '',
	)
);

$nexora_ids = nexora_get_product_ids( $nexora_a['type'], $nexora_a['limit'], array( 'include' => $nexora_a['include'] ) );
if ( ! $nexora_ids ) {
	return;
}
// Show complete rows only (e.g. 5 featured products in a 4-column grid shows 4).
$nexora_cols = max( 1, (int) $nexora_a['columns'] );
if ( count( $nexora_ids ) > $nexora_cols ) {
	$nexora_ids = array_slice( $nexora_ids, 0, (int) floor( count( $nexora_ids ) / $nexora_cols ) * $nexora_cols );
}
$nexora_heading_id = 'nx-' . sanitize_html_class( $nexora_a['key'] ) . '-title';
?>
<section class="nx-section nx-products-section <?php echo esc_attr( $nexora_a['class'] ); ?>" aria-labelledby="<?php echo esc_attr( $nexora_heading_id ); ?>">
	<div class="nx-container">
		<?php nexora_section_header( $nexora_a['title'], $nexora_a['link'], $nexora_a['link_label'], $nexora_a['eyebrow'], $nexora_heading_id ); ?>
		<?php nexora_product_grid( $nexora_ids, array( 'columns' => $nexora_a['columns'], 'name' => 'nexora_' . $nexora_a['key'] ) ); ?>
	</div>
</section>
