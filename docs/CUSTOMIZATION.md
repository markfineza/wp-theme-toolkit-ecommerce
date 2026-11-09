# Theme customization

## Where things are edited

| What | Where |
|---|---|
| Logo, site icon (favicon), title | Appearance → Customize → Site Identity |
| Brand colors, fonts, corner radius | Customize → **Freshtastic Theme → Brand colors and typography** |
| Announcement bar (text, link, real countdown, dismissible) | Freshtastic Theme → Announcement bar |
| Sticky / transparent header, search, mega menu | Freshtastic Theme → Header |
| Free-shipping threshold, card style, hover image, ratings, quick add, badges, low-stock threshold, sticky add-to-cart, buy now, product trust lines | Freshtastic Theme → Shop and product pages |
| Phone, email, address, hours, social links | Freshtastic Theme → Store info and social links |
| Footer intro, payment badges, copyright | Freshtastic Theme → Footer |
| WebP sub-sizes, bundled fonts on/off | Freshtastic Theme → Performance |
| **Homepage sections: show/hide and order** | Customize → **Freshtastic Homepage → Sections and order** |
| Hero banners: up to 3 that slide sideways, each with text, button, coupon code, dates and image. A banner with no image shows only the navy and orange background. Clear a banner's headline to remove it | Freshtastic Homepage → Hero promotional banner |
| Product section titles, limits, sources, categories | Freshtastic Homepage → Product sections |
| Campaign banner (image, copy, schedule) | Freshtastic Homepage → Campaign banner |
| Benefits, newsletter copy, journal count, final banner | Freshtastic Homepage → Benefits, newsletter and banners |
| Products per row / rows per page, shop page display | Customize → WooCommerce → Product Catalog |
| Menus (primary, mobile, 3 footer columns, legal) | Appearance → Menus |
| Extra widgets (blog sidebar, shop filters, footer) | Appearance → Widgets |
| Custom CSS | Customize → Additional CSS |
| Block editor colors, font sizes | `theme.json` (Primary, Secondary, Dark, Sale and so on are available as block colors) |

Product data (prices, stock, attributes, images, featured, cross-sells) always lives in **WooCommerce**. Editorial content lives in Posts and Pages. The Home page's own block content can be shown with the *Front page editor content* section.

### Homepage sections

Hero · Shop by category · Featured products · Trending now · Campaign banner · New arrivals · Best sellers · Brand benefits · Personalized discovery · Reviews and testimonials · Newsletter · Editorial / journal · Front page editor content · Final conversion banner.

- **Featured** uses WooCommerce's *Featured* flag. **New arrivals** uses the newest products. **Best sellers** uses WooCommerce's `total_sales` counter (seeded with sample numbers for demo products only). **Trending** can be top rated, best selling, on sale or a manual list of IDs.
- Grids show complete rows only (5 featured products in a 4-column grid shows 4).
- **Personalized discovery** shows top-rated picks at first, then switches to products related to the last product the visitor viewed. "Recently viewed" history is stored only in the visitor's browser, so it works with full-page caching.

## Design tokens

All colors and spacing are CSS custom properties on `:root` in `assets/css/main.css`. Customizer colors override the brand set:

```css
--nx-primary  --nx-secondary  --nx-text  --nx-bg  --nx-dark  --nx-sale  --nx-radius
--nx-font-heading  --nx-font-body
```

Derived tokens (`--nx-primary-ink`, `--nx-primary-soft`, `--nx-sale-ink` and so on) keep text contrast at WCAG AA. If you pick a very light primary color, check contrast for white button text.

## Child theme

```php
// wp-content/themes/nexora-child/style.css
/*
Theme Name: Freshtastic Child
Template:   nexora-commerce
*/

// wp-content/themes/nexora-child/functions.php
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'nexora-child', get_stylesheet_uri(), array( 'nexora-main' ), '1.0' );
}, 20 );
```

Copy any template (for example `template-parts/homepage/hero.php` or `woocommerce/content-product.php`) into the child theme at the same path to override it.

## Hooks and filters

| Hook | Type | Use |
|---|---|---|
| `nexora_home_sections` | filter | Add or remove homepage sections. Add a `template-parts/homepage/{key}.php` (underscores → hyphens). |
| `nexora_before_home_section` / `nexora_after_home_section` | action | Output around any section (receives the key). |
| `nexora_settings_schema` | filter | Add or change Customizer settings declaratively. |
| `nexora_option_{key}` | filter | Override any theme option in code. |
| `nexora_product_badges` | filter | Add or alter product badges (`array( 'class' => '', 'label' => '' )`). |
| `nexora_product_section_query_args` | filter | Change homepage product queries. |
| `nexora_filter_attributes` | filter | Choose which attributes appear as shop filters. |
| `nexora_script_data` | filter | Add data for front-end scripts (no secrets). |
| `nexora_wishlist_enabled`, `nexora_wishlist_url` | filter | Wishlist integration (set by the toolkit). |
| `nexora_newsletter_enabled`, `nexora_newsletter_consent_text` | filter | Newsletter integration and consent wording. |
| `nexora_is_sample_content` | filter | Mark content as sample (shows a "Sample content" tag). |
| `nexora_seo_plugin_active`, `nexora_output_meta_description` | filter | SEO behaviour. |
| `nexora_use_storefront_home` | filter | Return false to use the blog index as front page when "Your latest posts" is set. |

**JavaScript events** on `document`: `nexora:search`, `nexora:newsletter_signup`, `nexora:wishlist`, `nexora:content-updated` (after AJAX shop updates and injected product cards).

**Toolkit filters:** `nexora_tracking_channels`, `nexora_tracking_detected_plugins`, `nexora_tracking_skip_user`, `nexora_klaviyo_revision`; action `nexora_newsletter_subscribed( $email, $source, $status )`.

## WooCommerce template overrides

Kept deliberately small. Each file notes the WooCommerce template version it is based on. After a WooCommerce update, check **WooCommerce → Status → Templates** for outdated overrides.

| File | Based on | Why |
|---|---|---|
| `woocommerce/archive-product.php` | 8.6.0 | Filter sidebar/drawer, toolbar (count, view toggle, sorting), active chips. All core hooks kept. |
| `woocommerce/content-product.php` | 9.4.0 | Product card markup. All loop hooks still fire. |
| `woocommerce/loop/no-products-found.php` | 7.8.0 | Helpful empty state with "clear filters". |
| `woocommerce/single-product/nexora-sticky-atc.php` | — | Theme-only template (not an override). |

Everything else (single product, cart, checkout, my account, emails) uses WooCommerce's own templates, styled with CSS and extended through hooks.

## Shop filters

The filter form is a normal GET form that uses WooCommerce's own query parameters (`min_price`, `max_price`, `rating_filter`, `filter_{attribute}`) plus `on_sale=1` and `stock=instock`. It works without JavaScript. With JavaScript, results update in place, the URL updates, and Back/Forward work. Every global attribute (Products → Attributes) automatically becomes a filter group.

## Translation

Text domains `nexora-commerce` (theme) and `nexora-commerce-toolkit` (plugin). Templates are in each `languages/` folder (`.pot`). Translate with Loco Translate or Poedit and place `.mo` files in `wp-content/languages/themes/` and `wp-content/languages/plugins/`.
