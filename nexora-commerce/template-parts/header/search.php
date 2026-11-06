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
			<?php endif; ?>
			<button type="submit" class="nx-btn nx-btn--primary nx-btn--sm"><?php esc_html_e( 'Search', 'nexora-commerce' ); ?></button>
		</form>
		<div class="nx-search__results" id="nx-search-results" role="listbox" aria-label="<?php esc_attr_e( 'Search suggestions', 'nexora-commerce' ); ?>" hidden></div>
		<p class="screen-reader-text" aria-live="polite" data-nexora-search-status></p>
	</div>
</div>
