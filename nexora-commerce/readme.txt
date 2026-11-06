=== Freshtastic Food Hub ===
Contributors: nexoracommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A wet and dry market WooCommerce theme with a trading-floor design and request-for-quotation.

== Description ==

Freshtastic Food Hub (Market Edition) is a storefront theme for WooCommerce stores that sell rice, vegetables, fruits, meat, poultry, eggs, seafood and dry goods:

* Live price ticker and "Today's movers" board with ▲▼ changes from the store's real price history.
* Exchange-style price board with category tabs, trend sparklines, stock left and quick add.
* Prices with selling units ("₱280 / kg"), origins, and price-per-kg comparisons for packs and sacks.
* Request for Quotation everywhere an item is missing, out of stock or needed in bulk.
* Live "Market open / closed" status from your market hours.

* Customizer-driven homepage with 14 sections you can show, hide and reorder.
* Live product search, category mega menu, mobile drawer and mini-cart drawer.
* Shop filters (category, price, attributes, availability, sale, rating) that work without JavaScript and update in place with it.
* Product cards with hover image, accurate discount badges, ratings and quick add to cart.
* Product pages with sticky buy box, buy now, sticky mobile add-to-cart, trust lines, "frequently bought together" and recently viewed.
* Free-shipping progress in the mini-cart, cart (classic and block) and checkout.
* Styled Cart/Checkout blocks, classic templates and My Account.
* Accessible (WCAG 2.2 AA target), translation-ready, child-theme friendly, cache-friendly.

Pair it with the Freshtastic Food Hub Toolkit plugin (included in the package) for the demo importer, wishlist, newsletter providers and analytics events.

Full documentation (setup, Philippine store configuration, marketing, customization, testing) ships in the docs/ folder of the Freshtastic Food Hub package.

== Installation ==

1. Install and activate WooCommerce.
2. Appearance → Themes → Add New → Upload Theme → nexora-commerce.zip → Activate.
3. Optional: install the Freshtastic Food Hub Toolkit plugin and run Tools → Freshtastic Demo Content.

== Frequently Asked Questions ==

= Does it work without WooCommerce? =
Yes. Blog, pages and menus work; shop sections are hidden and administrators see a notice.

= Which WooCommerce templates are overridden? =
archive-product.php, content-product.php, loop/no-products-found.php, checkout/form-checkout.php, checkout/review-order.php, checkout/form-shipping.php, checkout/thankyou.php and myaccount/navigation.php, dashboard.php, form-login.php, form-edit-account.php, form-edit-address.php, my-address.php. All WooCommerce hooks are kept.

== Changelog ==

= 2.3.0 =
* Works with the toolkit's built-in store as well as WooCommerce; WooCommerce behaviour is unchanged.
* Fixed price board button contrast.

= 2.2.0 =
* Rebranded as Freshtastic Food Hub: bundled logo, browser icons, navy and orange colours from the logo.
* New customer dashboard, sign-in and registration pages. Registration collects name and mobile number.
* Fixed sideways scrolling in the account menu on phones.
* Removed the leftover free-delivery and same-day delivery wording from the hero and product pages.

= 2.1.0 =
* New step-by-step checkout with delivery option cards and a sticky order summary (classic checkout).
* Redesigned account pages: sign in / create account, account menu, profile and addresses, thank-you page.
* Free-shipping bar only shows when a Free shipping method exists.
* Small-phone header fix (no sideways scrolling at 360–400px).

= 2.0.0 =
* Market Edition: trading-floor design (ticker, market board, price board, change chips, sparklines), market colour palette, unit and origin display, price-per-kg tables, request-for-quotation entry points throughout the store, new market demo catalog and artwork.

= 1.0.0 =
* Initial release.

== Copyright ==

Freshtastic Food Hub, Copyright 2026 Freshtastic Food Hub Team.
Distributed under the terms of the GNU GPL v2 or later.

This theme bundles the following third-party resources:

Inter, Copyright (c) 2016 The Inter Project Authors (https://github.com/rsms/inter)
License: SIL Open Font License 1.1 — assets/fonts/OFL-Inter.txt

Manrope, Copyright 2018 The Manrope Project Authors (https://github.com/googlefonts/manrope)
License: SIL Open Font License 1.1 — assets/fonts/OFL-Manrope.txt

assets/images/hero-fallback.jpg and screenshot.png: original artwork created for this theme, CC0.
