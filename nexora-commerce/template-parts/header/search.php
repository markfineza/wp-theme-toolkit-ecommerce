<?php
/**
 * Header search with live product suggestions (ARIA combobox pattern).
 *
 * @package NexoraCommerce
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="nx-search" data-nexora-search>
	<button type="button" class="nx-icon-btn nx-search__toggle" aria-expanded="false" aria-controls="nx-search-panel">
		<?php echo nexora_icon( 'search', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span class="screen-reader-text"><?php esc_html_e( 'Open search', 'nexora-commerce' ); ?></span>
	</button>
	<div class="nx-search__panel" id="nx-search-panel">
		<form role="search" method="get" class="nx-search__form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="nx-search-input"><?php esc_html_e( 'Search products', 'nexora-commerce' ); ?></label>
			<?php echo nexora_icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="search" id="nx-search-input" name="s" value="<?php echo esc_attr( get_search_query() ); ?>"
				placeholder="<?php esc_attr_e( 'Search rice, bangus, pechay, eggs…', 'nexora-commerce' ); ?>"
				autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="nx-search-results">
			<?php if ( nexora_has_store() ) : ?>
				<input type="hidden" name="post_type" value="product">
				<?php
				$nexora_search_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'exclude' => array( (int) get_option( 'default_product_cat' ) ), 'orderby' => 'name' ) );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only pre-selects the search filter.
				$nexora_search_cat = isset( $_GET['product_cat'] ) ? sanitize_title( wp_unslash( $_GET['product_cat'] ) ) : '';
				?>
				<?php if ( ! is_wp_error( $nexora_search_cats ) && $nexora_search_cats ) : ?>
					<label class="screen-reader-text" for="nx-search-cat"><?php esc_html_e( 'Search in category', 'nexora-commerce' ); ?></label>
					<select id="nx-search-cat" class="nx-search__cat" name="product_cat" data-nexora-search-cat>
						<option value=""><?php esc_html_e( 'All categories', 'nexora-commerce' ); ?></option>
						<?php foreach ( $nexora_search_cats as $nexora_search_term ) : ?>
							<option value="<?php echo esc_attr( $nexora_search_term->slug ); ?>" <?php selected( $nexora_search_cat, $nexora_search_term->slug ); ?>><?php echo esc_html( $nexora_search_term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			<?php endif; ?>
			<button type="submit" class="nx-btn nx-btn--primary nx-btn--sm nx-search__submit"><?php echo nexora_icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="nx-search__submit-label"><?php esc_html_e( 'Search', 'nexora-commerce' ); ?></span></button>
		</form>
		<div class="nx-search__results" id="nx-search-results" role="listbox" aria-label="<?php esc_attr_e( 'Search suggestions', 'nexora-commerce' ); ?>" hidden></div>
		<p class="screen-reader-text" aria-live="polite" data-nexora-search-status></p>
	</div>
</div>
