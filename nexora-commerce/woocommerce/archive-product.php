<?php
/**
 * Product archive (shop, categories, tags, product search).
 *
 * Override of woocommerce/templates/archive-product.php (@version 8.6.0). All core hooks are kept;
 * result count and ordering are moved into the toolbar. See docs/CUSTOMIZATION.md → Template overrides.
 *
 * @package NexoraCommerce
 * @version 8.6.0
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'woocommerce_shop_loop_header', 'woocommerce_product_taxonomy_archive_header' );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );

get_header( 'shop' );

do_action( 'woocommerce_before_main_content' );

$nexora_term     = is_product_taxonomy() ? get_queried_object() : null;
$nexora_children = $nexora_term instanceof WP_Term && is_taxonomy_hierarchical( $nexora_term->taxonomy ) ? get_terms( array( 'taxonomy' => $nexora_term->taxonomy, 'parent' => $nexora_term->term_id, 'hide_empty' => true ) ) : array();
wc_set_loop_prop( 'nexora_heading', 'h2' );
?>
<header class="nx-shop-header">
	<div>
		<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
			<h1 class="nx-page-title"><?php woocommerce_page_title(); ?></h1>
		<?php endif; ?>
		<?php do_action( 'woocommerce_archive_description' ); ?>
	</div>
	<?php do_action( 'woocommerce_shop_loop_header' ); ?>
	<?php if ( ! is_wp_error( $nexora_children ) && $nexora_children ) : ?>
		<ul class="nx-subcats">
			<?php foreach ( $nexora_children as $nexora_child ) : ?>
				<li><a href="<?php echo esc_url( get_term_link( $nexora_child ) ); ?>"><?php echo esc_html( $nexora_child->name ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</header>

<div class="nx-shop-layout">
	<div class="nx-filters-drawer" id="nx-filters" data-nexora-filters-drawer>
		<div class="nx-drawer__backdrop" data-nexora-close></div>
		<aside class="nx-filters" aria-labelledby="nx-filters-title">
			<div class="nx-filters__head">
				<h2 class="nx-filters__title" id="nx-filters-title"><?php esc_html_e( 'Filters', 'nexora-commerce' ); ?></h2>
				<button type="button" class="nx-icon-btn nx-filters__close" data-nexora-close>
					<?php echo nexora_icon( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="screen-reader-text"><?php esc_html_e( 'Close filters', 'nexora-commerce' ); ?></span>
				</button>
			</div>
			<?php nexora_shop_filters(); ?>
			<?php if ( is_active_sidebar( 'sidebar-shop' ) ) : ?>
				<div class="nx-filters__widgets"><?php dynamic_sidebar( 'sidebar-shop' ); ?></div>
			<?php endif; ?>
		</aside>
	</div>

	<div class="nx-shop-results" id="nx-shop-results" data-nexora-results>
		<div class="nx-toolbar">
			<button type="button" class="nx-btn nx-btn--ghost nx-btn--sm nx-filters-toggle" aria-controls="nx-filters" aria-expanded="false" data-nexora-open="nx-filters">
				<?php echo nexora_icon( 'filter', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Filters', 'nexora-commerce' ); ?>
			</button>
			<?php woocommerce_result_count(); ?>
			<div class="nx-toolbar__end">
				<div class="nx-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Product layout', 'nexora-commerce' ); ?>">
					<button type="button" class="nx-icon-btn" data-nexora-view="grid" aria-pressed="true"><?php echo nexora_icon( 'grid', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'Grid view', 'nexora-commerce' ); ?></span></button>
					<button type="button" class="nx-icon-btn" data-nexora-view="list" aria-pressed="false"><?php echo nexora_icon( 'list', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'List view', 'nexora-commerce' ); ?></span></button>
				</div>
				<?php woocommerce_catalog_ordering(); ?>
			</div>
		</div>
		<?php nexora_active_filter_chips(); ?>

		<?php
		if ( woocommerce_product_loop() ) {
			do_action( 'woocommerce_before_shop_loop' );

			woocommerce_product_loop_start();

			if ( wc_get_loop_prop( 'total' ) ) {
				while ( have_posts() ) {
					the_post();
					do_action( 'woocommerce_shop_loop' );
					wc_get_template_part( 'content', 'product' );
				}
			}

			woocommerce_product_loop_end();

			do_action( 'woocommerce_after_shop_loop' );
		} else {
			do_action( 'woocommerce_no_products_found' );
		}
		?>
	</div>
</div>
<?php
do_action( 'woocommerce_after_main_content' );

do_action( 'woocommerce_sidebar' );

get_footer( 'shop' );
