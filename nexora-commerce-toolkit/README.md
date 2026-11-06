# Freshtastic Food Hub Toolkit

Companion plugin for the Freshtastic Food Hub theme. It holds the features that should keep working if you switch themes.

| Module | What it does | Settings |
|---|---|---|
| Market data | Selling unit, origin and pack contents on products; automatic price history; "₱/kg" price suffix; movers for the ticker | Product editor → General / Variations |
| Request for quotation | `[nexora_rfq_form]`, inbox under WooCommerce → Quotations, quote-by-email, My Account → Quotations, privacy export/erase | Settings → Freshtastic Toolkit |
| Pickup and courier delivery | Store pickup and "Courier delivery (customer books)" shipping methods with pickup points, lead time, closed days and time slots; validated schedule fields at checkout; "Ready for pickup" status and email; one-click switch to the classic checkout | WooCommerce → Settings → Shipping; Settings → Freshtastic Toolkit |
| Built-in store | A complete shop without WooCommerce: catalogue, cart, checkout, coupons, promotions, customer portal, orders, emails. Off unless you choose it | Settings → Freshtastic Toolkit → Store engine |
| Customer registration | Name and mobile number on the My Account form, saved to profile and billing; switches WooCommerce registration on once | WooCommerce → Settings → Accounts & Privacy |
| Order Desk | Modern order screens (KPI tiles, fulfilment column and filters, progress bar, reschedule) and an "Order Desk" role with a focused, branded admin | Settings → Freshtastic Toolkit → Orders and checkout |
| Maintenance mode | Whole-site or shop-only maintenance (HTTP 503 + Retry-After), purchases blocked, staff bypass, temporary access passes (link or code, expiring, revocable), auto-reopen; `wp nexora maintenance` | Settings → Freshtastic Maintenance |
| Dependency installer | Installs and activates WooCommerce automatically on activation; one-click notice fallback; `wp nexora install-dependencies` | Automatic |
| Demo importer | Imports and removes the 33-product demo store safely; WP-CLI `wp nexora demo` | Tools → Freshtastic Demo Content |
| Newsletter | Consent-based signups stored locally and forwarded server-side to Mailchimp, Brevo or Klaviyo; CSV export; privacy export/erase | Settings → Freshtastic Toolkit; Users → Subscribers |
| Analytics | GA4, GTM, Meta Pixel, Google Ads purchase conversion; ecommerce events; Consent Mode v2; duplicate-plugin detection | Settings → Freshtastic Toolkit |
| Wishlist | Guest (browser) and customer (account) wishlist, `[nexora_wishlist]` shortcode, My Account tab | Settings → Freshtastic Toolkit |
| Testimonials | Testimonial post type used by the theme's homepage | Testimonials menu |
| Sample labels | "Sample review" / "Demo product" labels on imported content | Settings → Freshtastic Toolkit |

Works with any theme. The wishlist page and account tab render with WooCommerce's `[products]` shortcode; the heart buttons and newsletter forms are provided by the Freshtastic theme.

## Security notes

- API keys are write-only in the admin and can be set as `wp-config.php` constants instead (`NEXORA_MAILCHIMP_API_KEY`, `NEXORA_BREVO_API_KEY`, `NEXORA_KLAVIYO_PRIVATE_KEY`). They are never sent to the browser.
- Every admin action checks capabilities and nonces. The importer requires `manage_options` and `manage_woocommerce`.
- The public newsletter endpoint uses a honeypot and per-IP rate limiting (salted hash), and requires consent.
- Declares compatibility with WooCommerce HPOS and Cart/Checkout blocks.

See the package `docs/` folder (`SETUP.md`, `MARKETING.md`) for full instructions.

License: GPL-2.0-or-later. Demo artwork in `demo/images/`: original, CC0.
