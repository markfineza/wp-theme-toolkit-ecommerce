# Freshtastic Food Hub Toolkit

Companion plugin for the Freshtastic Food Hub theme. It holds the features that should keep working if you switch themes.

| Module | What it does | Settings |
|---|---|---|
| Market data | Selling unit, origin and pack contents on products; automatic price history; "₱/kg" price suffix; movers for the ticker | Product editor → General / Variations |
| Request for quotation | `[nexora_rfq_form]`, inbox under WooCommerce → Quotations, quote-by-email, My Account → Quotations, privacy export/erase | Settings → Freshtastic Toolkit |
| Pickup and courier delivery | Store pickup and "Courier delivery (customer books)" shipping methods with pickup points, lead time, closed days and time slots; validated schedule fields at checkout; "Ready for pickup" status and email; one-click switch to the classic checkout | WooCommerce → Settings → Shipping; Settings → Freshtastic Toolkit |
| Built-in store | A complete shop without WooCommerce: catalogue, cart, checkout, coupons, promotions, customer portal, orders, emails. Off unless you choose it | Settings → Freshtastic Toolkit → Store engine |
| Analytics | Sales, Products, Reviews and Daily report screens with period comparison, CSV download and print, plus a daily report by email. Works on both engines. See `docs/ANALYTICS.md` | Store → Analytics or WooCommerce → Freshtastic Analytics |
| Coupons and points | Customers claim codes and listed offers, with a claim history, plus a Points page ready for a future earning feature. See `docs/COUPONS-AND-POINTS.md` | My account → Coupons and Points |
| Packages | Products sold together at one price (Package A, Package B). Availability and stock follow the products inside. See `docs/PACKAGES-AND-PROMO-POPUP.md` | Product screen → Package |
| Promo pop-up | Modal with up to 3 banner images, with dates, audience, frequency and delay | Settings → Freshtastic Promo Pop-up |
| Security | Required Terms and Privacy consent with a time-stamped record, optional marketing choice, draft Philippine legal pages, reCAPTCHA v2 Checkbox or v3 (score) on every registration form, email activation for new customer accounts (no automatic sign-in), email login codes for staff and customers. See `docs/SECURITY-AND-CONSENT.md` | Settings → Freshtastic Security |
| Emails | Sender name and address (replaces "WordPress"), Reply-To, logo, colours and footer for every email; subject, heading, message and on/off for each notification, with preview and test send; WordPress's plain-text emails in the same design; WooCommerce emails can follow. See `docs/EMAILS.md` | Settings → Freshtastic Emails |
| PayMongo | GCash, Maya, cards, QR Ph, GrabPay, ShopeePay, BillEase and online banking at the built-in store's checkout through PayMongo Checkout; signed webhook confirmation, unpaid orders cancelled after a time limit, refunds from the order screen. See `docs/PAYMONGO.md` | Store → PayMongo |
| Facebook and Instagram (Meta) | Catalog sync to Commerce Manager (products, options, prices, stock, removals) through the Catalog Batch API, a private CSV data feed, and automatic Facebook Page and Instagram posts for new products. Works on both engines. See `docs/META.md` | Settings → Freshtastic Meta |
| Rewards | Free-item coupons with conditions, one use per signed-in customer; first customers promotion with personal one-use coupons. Works with WooCommerce and the built-in store | Coupon screen → Free item; Store or WooCommerce → First customers |
| Customer registration | Name and mobile number on the My Account form, saved to profile and billing; switches WooCommerce registration on once | WooCommerce → Settings → Accounts & Privacy |
| Order Desk | Modern order screens (KPI tiles, fulfilment column and filters, progress bar, reschedule) and an "Order Desk" role with a focused, branded admin | Settings → Freshtastic Toolkit → Orders and checkout |
| Maintenance mode | Whole-site or shop-only maintenance (HTTP 503 + Retry-After), purchases blocked, staff bypass, temporary access passes (link or code, expiring, revocable), auto-reopen; `wp nexora maintenance` | Settings → Freshtastic Maintenance |
| Dependency installer | A new site without WooCommerce starts on the built-in store and installs nothing. When the WooCommerce engine is chosen, installs and activates WooCommerce; one-click notice fallback; `wp nexora install-dependencies` | Automatic |
| Demo importer | Imports and removes the 36-product demo store safely, with WooCommerce or the built-in store; WP-CLI `wp nexora demo` | Tools → Freshtastic Demo Content |
| Newsletter | Consent-based signups stored locally and forwarded server-side to Mailchimp, Brevo or Klaviyo; CSV export; privacy export/erase | Settings → Freshtastic Toolkit; Users → Subscribers |
| Analytics | GA4, GTM, Meta Pixel, Google Ads purchase conversion; ecommerce events; Consent Mode v2; duplicate-plugin detection | Settings → Freshtastic Toolkit |
| Wishlist | Guest (browser) and customer (account) wishlist, `[nexora_wishlist]` shortcode, My Account tab | Settings → Freshtastic Toolkit |
| Testimonials | Testimonial post type used by the theme's homepage | Testimonials menu |
| Sample labels | "Sample review" / "Demo product" labels on imported content | Settings → Freshtastic Toolkit |

Works with any theme. The wishlist page and account tab render with WooCommerce's `[products]` shortcode; the heart buttons and newsletter forms are provided by the Freshtastic theme.

## Security notes

- API keys are write-only in the admin and can be set as `wp-config.php` constants instead (`NEXORA_MAILCHIMP_API_KEY`, `NEXORA_BREVO_API_KEY`, `NEXORA_KLAVIYO_PRIVATE_KEY`, `NEXORA_META_ACCESS_TOKEN`, `NEXORA_META_APP_SECRET`, `NEXORA_PAYMONGO_LIVE_SECRET`, `NEXORA_PAYMONGO_LIVE_WEBHOOK_SECRET` and the test equivalents). They are never sent to the browser.
- Every admin action checks capabilities and nonces. The importer requires `manage_options` and `manage_woocommerce`.
- The public newsletter endpoint uses a honeypot and per-IP rate limiting (salted hash), and requires consent.
- Declares compatibility with WooCommerce HPOS and Cart/Checkout blocks.

See the package `docs/` folder (`SETUP.md`, `MARKETING.md`) for full instructions.

License: GPL-2.0-or-later. Demo artwork in `demo/images/`: original, CC0.
