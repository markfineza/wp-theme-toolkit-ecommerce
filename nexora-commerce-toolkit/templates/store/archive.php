<?php
/**
 * Shop, category and product search pages. Override by copying to yourtheme/nexora-store/archive.php.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

get_header();

global $wp_query;
$nx_term  = is_tax() ? get_queried_object() : null;
$nx_title = $nx_term ? $nx_term->name : ( is_search() ? sprintf( /* translators: %s: search term */ __( 'Results for “%s”', 'nexora-commerce-toolkit' ), get_search_query() ) : __( 'Shop', 'nexora-commerce-toolkit' ) );
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only filters.
$nx_order = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
$nx_sale  = ! empty( $_GET['on_sale'] );
$nx_stock = ! empty( $_GET['in_stock'] );
// phpcs:enable
$nx_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => 0 ) );
$nx_keep = is_search() ? '<input type="hidden" name="s" value="' . esc_attr( get_search_query() ) . '"><input type="hidden" name="post_type" value="product">' : '';
?>
<main id="primary" class="site-main nx-wc-page">
	<div class="nx-container woocommerce">
		<?php
		if ( function_exists( 'nexora_breadcrumbs' ) ) {
			nexora_breadcrumbs();
		}
		echo Nexora_Store_Cart::notices_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		?>
		<header class="nx-page-header is-compact">
			<h1 class="nx-page-title"><?php echo esc_html( $nx_title ); ?></h1>
			<?php if ( $nx_term && $nx_term->description ) : ?><p class="nx-store-lead"><?php echo esc_html( $nx_term->description ); ?></p><?php endif; ?>
		</header>
		<?php if ( ! is_wp_error( $nx_cats ) && $nx_cats ) : ?>
			<nav class="nx-store-catnav" aria-label="<?php esc_attr_e( 'Product categories', 'nexora-commerce-toolkit' ); ?>">
				<a href="<?php echo esc_url( Nexora_Store::shop_url() ); ?>" <?php echo ! $nx_term ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'nexora-commerce-toolkit' ); ?></a>
				<?php foreach ( $nx_cats as $nx_c ) : ?>
					<a href="<?php echo esc_url( get_term_link( $nx_c ) ); ?>" <?php echo $nx_term && (int) $nx_term->term_id === (int) $nx_c->term_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $nx_c->name ); ?></a>
					<?php foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => $nx_c->term_id ) ) as $nx_sub ) : ?>
						<a href="<?php echo esc_url( get_term_link( $nx_sub ) ); ?>" <?php echo $nx_term && (int) $nx_term->term_id === (int) $nx_sub->term_id ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $nx_sub->name ); ?></a>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
		<form class="nx-store-toolbar" method="get">
			<?php echo $nx_keep; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			<?php /* translators: %d: products */ ?>
			<p class="nx-store-toolbar__count"><?php echo esc_html( sprintf( _n( '%d product', '%d products', (int) $wp_query->found_posts, 'nexora-commerce-toolkit' ), (int) $wp_query->found_posts ) ); ?></p>
			<label><input type="checkbox" name="on_sale" value="1" <?php checked( $nx_sale ); ?>> <?php esc_html_e( 'On sale', 'nexora-commerce-toolkit' ); ?></label>
			<label><input type="checkbox" name="in_stock" value="1" <?php checked( $nx_stock ); ?>> <?php esc_html_e( 'In stock', 'nexora-commerce-toolkit' ); ?></label>
			<label for="nx-orderby" class="screen-reader-text"><?php esc_html_e( 'Sort by', 'nexora-commerce-toolkit' ); ?></label>
			<select name="orderby" id="nx-orderby" class="orderby">
				<option value="" <?php selected( $nx_order, '' ); ?>><?php esc_html_e( 'Default sorting', 'nexora-commerce-toolkit' ); ?></option>
				<option value="popularity" <?php selected( $nx_order, 'popularity' ); ?>><?php esc_html_e( 'Best sellers', 'nexora-commerce-toolkit' ); ?></option>
				<option value="rating" <?php selected( $nx_order, 'rating' ); ?>><?php esc_html_e( 'Top rated', 'nexora-commerce-toolkit' ); ?></option>
				<option value="date" <?php selected( $nx_order, 'date' ); ?>><?php esc_html_e( 'Newest', 'nexora-commerce-toolkit' ); ?></option>
				<option value="price" <?php selected( $nx_order, 'price' ); ?>><?php esc_html_e( 'Price: low to high', 'nexora-commerce-toolkit' ); ?></option>
				<option value="price-desc" <?php selected( $nx_order, 'price-desc' ); ?>><?php esc_html_e( 'Price: high to low', 'nexora-commerce-toolkit' ); ?></option>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'nexora-commerce-toolkit' ); ?></button>
		</form>
		<?php if ( have_posts() ) : ?>
			<?php Nexora_Store_Catalog::grid( wp_list_pluck( $wp_query->posts, 'ID' ), 4, array( 'heading' => 'h2' ) ); ?>
			<nav class="woocommerce-pagination" aria-label="<?php esc_attr_e( 'Pages', 'nexora-commerce-toolkit' ); ?>">
				<?php echo wp_kses_post( (string) paginate_links( array( 'type' => 'list', 'prev_text' => '←', 'next_text' => '→' ) ) ); ?>
			</nav>
		<?php else : ?>
			<div class="nx-empty">
				<h2><?php esc_html_e( 'No products found', 'nexora-commerce-toolkit' ); ?></h2>
				<?php if ( class_exists( 'Nexora_Toolkit_RFQ' ) && Nexora_Toolkit_Settings::get( 'rfq_enabled' ) ) : ?>
					<p><?php esc_html_e( 'Not on the list? Tell us what you need and we will send a quote.', 'nexora-commerce-toolkit' ); ?></p>
					<?php echo do_shortcode( '[nexora_rfq_form compact="1" item="' . esc_attr( str_replace( array( '[', ']', '"' ), '', get_search_query() ) ) . '"]' ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
