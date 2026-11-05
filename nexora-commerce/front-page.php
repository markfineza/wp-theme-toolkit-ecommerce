<?php
/**
 * Homepage: renders the sections enabled under Customizer → Freshtastic Homepage → Sections and order.
 *
 * Child themes can add sections with the `nexora_home_sections` filter and a matching
 * template-parts/homepage/{key}.php file (underscores become hyphens).
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( 'posts' === get_option( 'show_on_front' ) && ! apply_filters( 'nexora_use_storefront_home', true ) ) {
	get_template_part( 'home' );
	return;
}

get_header();
?>
<main id="primary" class="site-main nx-home">
	<?php
	foreach ( nexora_enabled_home_sections() as $nexora_section ) {
		do_action( 'nexora_before_home_section', $nexora_section );
		get_template_part( 'template-parts/homepage/' . str_replace( '_', '-', $nexora_section ) );
		do_action( 'nexora_after_home_section', $nexora_section );
	}
	?>
</main>
<?php
get_footer();
