<?php
/**
 * Shop filters: a plain GET form (works without JavaScript) that relies on WooCommerce's
 * native query vars — min_price/max_price, rating_filter and filter_{attribute} — plus two
 * small additions: on_sale=1 and stock=instock.
 *
 * main.js progressively enhances the form with AJAX swapping and history updates.
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalise multi-select checkbox params (filter_color[]=a&filter_color[]=b) into WooCommerce's
 * comma format before WooCommerce reads them, so filters work without JavaScript.
 */
function nexora_normalize_filter_params() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only catalogue filters.
	foreach ( $_GET as $key => $value ) {
		if ( is_array( $value ) && ( 0 === strpos( $key, 'filter_' ) || 'rating_filter' === $key ) ) {
			$_GET[ $key ] = implode( ',', array_map( 'sanitize_title', wp_unslash( $value ) ) );
		}
	}
	// Drop empty filter params and the query_type flag when its filter is unused, keeping URLs tidy.
	foreach ( array_keys( $_GET ) as $key ) {
		if ( 0 === strpos( $key, 'query_type_' ) && empty( $_GET[ 'filter_' . substr( $key, 11 ) ] ) ) {
			unset( $_GET[ $key ] );
		}
	}
	// phpcs:enable
}
add_action( 'init', 'nexora_normalize_filter_params', 0 );

/**
 * Apply the extra filters to the main product query.
 *
 * @param WP_Query $q Query.
 */
function nexora_apply_extra_filters( $q ) {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only catalogue filters.
	if ( ! empty( $_GET['on_sale'] ) ) {
		$on_sale  = array_merge( array( 0 ), wc_get_product_ids_on_sale() );
		$existing = (array) $q->get( 'post__in' );
		$q->set( 'post__in', $existing ? array_values( array_intersect( $existing, $on_sale ) ) ?: array( 0 ) : $on_sale );
	}
	if ( isset( $_GET['stock'] ) && 'instock' === $_GET['stock'] ) {
		$tax_query   = (array) $q->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => array( 'outofstock' ),
			'operator' => 'NOT IN',
		);
		$q->set( 'tax_query', $tax_query );
	}
	// phpcs:enable
}
add_action( 'woocommerce_product_query', 'nexora_apply_extra_filters' );

/**
 * Base URL of the current product archive, without filter or paging args.
 *
 * @return string
 */
function nexora_archive_base_url() {
	if ( is_product_taxonomy() ) {
		$link = get_term_link( get_queried_object() );
		return is_wp_error( $link ) ? wc_get_page_permalink( 'shop' ) : $link;
	}
	return wc_get_page_permalink( 'shop' );
}

/**
 * Global min/max product prices for the price inputs (cached for 12 hours).
 *
 * @return array{min:float,max:float}
 */
function nexora_price_bounds() {
	$bounds = get_transient( 'nexora_price_bounds_' . get_option( 'nexora_products_cache_v', '1' ) );
	if ( false === $bounds ) {
		global $wpdb;
		$row    = $wpdb->get_row( "SELECT MIN(min_price) AS min_price, MAX(max_price) AS max_price FROM {$wpdb->wc_product_meta_lookup}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$bounds = array(
			'min' => $row ? (float) floor( (float) $row->min_price ) : 0.0,
			'max' => $row ? (float) ceil( (float) $row->max_price ) : 0.0,
		);
		set_transient( 'nexora_price_bounds_' . get_option( 'nexora_products_cache_v', '1' ), $bounds, 12 * HOUR_IN_SECONDS );
	}
	return $bounds;
}

/**
 * Attribute taxonomies offered as filters.
 *
 * @return array<string,string> taxonomy => label
 */
function nexora_filter_attributes() {
	$out = array();
	foreach ( wc_get_attribute_taxonomies() as $tax ) {
		$name = wc_attribute_taxonomy_name( $tax->attribute_name );
		if ( taxonomy_exists( $name ) ) {
			$out[ $name ] = $tax->attribute_label;
		}
	}
	return apply_filters( 'nexora_filter_attributes', $out );
}

/**
 * Render the filter form.
 */
function nexora_shop_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only catalogue filters.
	$base       = nexora_archive_base_url();
	$bounds     = nexora_price_bounds();
	$chosen     = WC_Query::get_layered_nav_chosen_attributes();
	$min_price  = isset( $_GET['min_price'] ) ? wc_clean( wp_unslash( $_GET['min_price'] ) ) : '';
	$max_price  = isset( $_GET['max_price'] ) ? wc_clean( wp_unslash( $_GET['max_price'] ) ) : '';
	$rating     = isset( $_GET['rating_filter'] ) ? array_map( 'absint', explode( ',', wc_clean( wp_unslash( $_GET['rating_filter'] ) ) ) ) : array();
	$on_sale    = ! empty( $_GET['on_sale'] );
	$in_stock   = isset( $_GET['stock'] ) && 'instock' === $_GET['stock'];
	$current    = is_product_category() ? get_queried_object_id() : 0;
	$categories = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'parent'     => 0,
			'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
		)
	);
	?>
	<form class="nx-filter-form" method="get" action="<?php echo esc_url( $base ); ?>" data-nexora-filters>
		<?php
		// Preserve sorting and product search across filter changes.
		foreach ( array( 'orderby', 's', 'post_type' ) as $keep ) {
			if ( isset( $_GET[ $keep ] ) && '' !== $_GET[ $keep ] ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $keep ), esc_attr( wc_clean( wp_unslash( $_GET[ $keep ] ) ) ) );
			}
		}
		?>

		<?php if ( ! is_wp_error( $categories ) && $categories ) : ?>
			<div class="nx-filter-group">
				<h3 class="nx-filter-title"><?php esc_html_e( 'Category', 'nexora-commerce' ); ?></h3>
				<ul class="nx-filter-cats">
					<li><a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"<?php echo ! $current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'All products', 'nexora-commerce' ); ?></a></li>
					<?php foreach ( $categories as $cat ) : ?>
						<?php $children = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => $cat->term_id, 'orderby' => 'menu_order' ) ); ?>
						<li>
							<a href="<?php echo esc_url( get_term_link( $cat ) ); ?>"<?php echo $current === $cat->term_id ? ' aria-current="page"' : ''; ?>>
								<?php echo esc_html( $cat->name ); ?> <span class="nx-count"><?php echo esc_html( (string) $cat->count ); ?></span>
							</a>
							<?php if ( ! is_wp_error( $children ) && $children ) : ?>
								<ul class="nx-filter-cats__sub">
									<?php foreach ( $children as $child ) : ?>
										<li>
											<a href="<?php echo esc_url( get_term_link( $child ) ); ?>"<?php echo $current === $child->term_id ? ' aria-current="page"' : ''; ?>>
												<?php echo esc_html( $child->name ); ?> <span class="nx-count"><?php echo esc_html( (string) $child->count ); ?></span>
											</a>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<fieldset class="nx-filter-group">
			<legend class="nx-filter-title"><?php esc_html_e( 'Price', 'nexora-commerce' ); ?></legend>
			<div class="nx-price-inputs">
				<label>
					<span><?php esc_html_e( 'Min', 'nexora-commerce' ); ?></span>
					<input type="number" inputmode="numeric" name="min_price" min="0" step="1" value="<?php echo esc_attr( $min_price ); ?>" placeholder="<?php echo esc_attr( (string) $bounds['min'] ); ?>">
				</label>
				<span aria-hidden="true">–</span>
				<label>
					<span><?php esc_html_e( 'Max', 'nexora-commerce' ); ?></span>
					<input type="number" inputmode="numeric" name="max_price" min="0" step="1" value="<?php echo esc_attr( $max_price ); ?>" placeholder="<?php echo esc_attr( (string) $bounds['max'] ); ?>">
				</label>
			</div>
		</fieldset>

		<fieldset class="nx-filter-group">
			<legend class="nx-filter-title"><?php esc_html_e( 'Availability', 'nexora-commerce' ); ?></legend>
			<label class="nx-check"><input type="checkbox" name="stock" value="instock" <?php checked( $in_stock ); ?>> <?php esc_html_e( 'In stock only', 'nexora-commerce' ); ?></label>
			<label class="nx-check"><input type="checkbox" name="on_sale" value="1" <?php checked( $on_sale ); ?>> <?php esc_html_e( 'On sale', 'nexora-commerce' ); ?></label>
		</fieldset>

		<?php
		foreach ( nexora_filter_attributes() as $taxonomy => $label ) :
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$param    = 'filter_' . wc_attribute_taxonomy_slug( $taxonomy );
			$selected = isset( $chosen[ $taxonomy ] ) ? $chosen[ $taxonomy ]['terms'] : array();
			?>
			<fieldset class="nx-filter-group">
				<legend class="nx-filter-title"><?php echo esc_html( $label ); ?></legend>
				<input type="hidden" name="query_type_<?php echo esc_attr( wc_attribute_taxonomy_slug( $taxonomy ) ); ?>" value="or">
				<div class="nx-chip-options">
					<?php foreach ( $terms as $term ) : ?>
						<label class="nx-option-chip">
							<input type="checkbox" name="<?php echo esc_attr( $param ); ?>[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $selected, true ) ); ?>>
							<span><?php echo esc_html( $term->name ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php endforeach; ?>

		<fieldset class="nx-filter-group">
			<legend class="nx-filter-title"><?php esc_html_e( 'Rating', 'nexora-commerce' ); ?></legend>
			<?php for ( $stars = 5; $stars >= 3; $stars-- ) : ?>
				<label class="nx-check">
					<input type="checkbox" name="rating_filter[]" value="<?php echo esc_attr( (string) $stars ); ?>" <?php checked( in_array( $stars, $rating, true ) ); ?>>
					<?php
					/* translators: %d: star rating */
					echo esc_html( sprintf( _n( '%d star', '%d stars', $stars, 'nexora-commerce' ), $stars ) );
					?>
				</label>
			<?php endfor; ?>
		</fieldset>

		<div class="nx-filter-actions">
			<button type="submit" class="nx-btn nx-btn--primary nx-btn--block"><?php esc_html_e( 'Apply filters', 'nexora-commerce' ); ?></button>
		</div>
	</form>
	<?php
	// phpcs:enable
}

/**
 * Active filter chips with individual remove links and "Clear all".
 */
function nexora_active_filter_chips() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only catalogue filters.
	$url   = remove_query_arg( 'paged', add_query_arg( null, null ) );
	$url   = preg_replace( '#/page/\d+/?#', '/', $url );
	$chips = array();

	if ( isset( $_GET['min_price'] ) && '' !== $_GET['min_price'] ) {
		/* translators: %s: price */
		$chips[] = array( sprintf( __( 'Min %s', 'nexora-commerce' ), wp_strip_all_tags( wc_price( (float) $_GET['min_price'], array( 'decimals' => 0 ) ) ) ), remove_query_arg( 'min_price', $url ) );
	}
	if ( isset( $_GET['max_price'] ) && '' !== $_GET['max_price'] ) {
		/* translators: %s: price */
		$chips[] = array( sprintf( __( 'Max %s', 'nexora-commerce' ), wp_strip_all_tags( wc_price( (float) $_GET['max_price'], array( 'decimals' => 0 ) ) ) ), remove_query_arg( 'max_price', $url ) );
	}
	if ( ! empty( $_GET['on_sale'] ) ) {
		$chips[] = array( __( 'On sale', 'nexora-commerce' ), remove_query_arg( 'on_sale', $url ) );
	}
	if ( isset( $_GET['stock'] ) && 'instock' === $_GET['stock'] ) {
		$chips[] = array( __( 'In stock', 'nexora-commerce' ), remove_query_arg( 'stock', $url ) );
	}
	if ( ! empty( $_GET['rating_filter'] ) ) {
		$ratings = array_filter( array_map( 'absint', explode( ',', wc_clean( wp_unslash( $_GET['rating_filter'] ) ) ) ) );
		foreach ( $ratings as $r ) {
			$rest    = array_diff( $ratings, array( $r ) );
			/* translators: %d: star rating */
			$chips[] = array( sprintf( _n( '%d star', '%d stars', $r, 'nexora-commerce' ), $r ), $rest ? add_query_arg( 'rating_filter', implode( ',', $rest ), $url ) : remove_query_arg( 'rating_filter', $url ) );
		}
	}
	foreach ( WC_Query::get_layered_nav_chosen_attributes() as $taxonomy => $data ) {
		$param = 'filter_' . wc_attribute_taxonomy_slug( $taxonomy );
		foreach ( $data['terms'] as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term ) {
				continue;
			}
			$rest    = array_diff( $data['terms'], array( $slug ) );
			$chips[] = array( $term->name, $rest ? add_query_arg( $param, implode( ',', $rest ), $url ) : remove_query_arg( array( $param, 'query_type_' . wc_attribute_taxonomy_slug( $taxonomy ) ), $url ) );
		}
	}
	// phpcs:enable

	if ( ! $chips ) {
		return;
	}

	$clear = nexora_archive_base_url();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	foreach ( array( 'orderby', 's', 'post_type' ) as $keep ) {
		if ( isset( $_GET[ $keep ] ) && '' !== $_GET[ $keep ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$clear = add_query_arg( $keep, rawurlencode( wc_clean( wp_unslash( $_GET[ $keep ] ) ) ), $clear ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	echo '<ul class="nx-active-filters" aria-label="' . esc_attr__( 'Active filters', 'nexora-commerce' ) . '">';
	foreach ( $chips as $chip ) {
		printf(
			'<li><a class="nx-active-chip" href="%1$s" data-nexora-ajax>%2$s %3$s<span class="screen-reader-text">%4$s</span></a></li>',
			esc_url( $chip[1] ),
			esc_html( $chip[0] ),
			nexora_icon( 'close', 14 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( '(remove filter)', 'nexora-commerce' )
		);
	}
	printf( '<li><a class="nx-clear-filters" href="%1$s" data-nexora-ajax>%2$s</a></li>', esc_url( $clear ), esc_html__( 'Clear all', 'nexora-commerce' ) );
	echo '</ul>';
}
