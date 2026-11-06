# Testing checklist, results and known limitations

## Test environment

- WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.4.7, MySQL 9.3. Cart and Checkout **blocks** (WooCommerce's default for new stores).
- Browser automation: headless Chrome driven over the DevTools Protocol, plus axe-core 4.10 and Lighthouse 12.2.
- Viewports: 1440×1000 desktop, 1200×900, and 390×844 / 412×823 mobile.
- Two sites: a development site (theme symlinked), and a **fresh** WordPress where both ZIPs were installed with `wp theme install` / `wp plugin install`.

## Acceptance criteria

| # | Criterion | Result | How it was verified |
|---|---|---|---|
| 1a | Toolkit installs WooCommerce automatically | ✅ | Fresh site with no WooCommerce: uploaded the toolkit ZIP in wp-admin → Activate → "Installed and activated WooCommerce" (11.2.1). Notice button and `wp nexora install-dependencies` also tested. |
| 1 | Theme installs and activates without fatal errors | ✅ | Installed from `dist/*.zip` on a fresh site; `debug.log` clean (WP_DEBUG on). |
| 2 | Usable when WooCommerce is inactive | ✅ | Home, journal, posts, pages, search, 404 and REST search return 200; shop sections hidden; admin notice shown. (A fatal in the testimonials rating was found and fixed with a non-WooCommerce fallback.) |
| 3 | Homepage loads real WordPress/WooCommerce content | ✅ | Featured flag, newest, `total_sales`, top-rated queries; categories with counts; posts; reviews. |
| 4 | Categories, search, sorting, filters work | ✅ | Indigo color filter → 3 results with chip; + On sale → empty state; *Clear all* → 34; sort by price → ₱349 first; live search "bottle" → Trek Travel Bottle; AJAX updates URL; works without JS (GET form). |
| 5 | Product variations update correctly | ✅ | Selecting *Lunar White* swaps the image, shows ₱2,499 and "11 in stock", enables Add to cart / Buy now. |
| 6 | Add to cart, quantity change, removal | ✅ | AJAX add from card (count, toast, mini-cart fragment); variable add through the product form; quantities and removal in the Cart block. |
| 7 | Coupons validated by WooCommerce | ✅ | `DEMO10` applied in the Checkout block: −₱129.90 on ₱1,299. |
| 8 | Checkout with a test gateway | ✅ | Cash on delivery order #430, total ₱1,319.10 (incl. ₱150 shipping); order-received page. |
| 9 | Order confirmation and account pages | ✅ | Thank-you page; My Account dashboard tiles; Orders; Wishlist tab (`/my-account/wishlist/`). |
| 10 | Marketing integrations: no exposed credentials, no duplicate events | ✅ | Keys write-only and never sent to the browser; `purchase` fired once for two thank-you renders (order meta guard); real total, currency, tax, shipping, coupon; AJAX add-to-cart sent client-side only; known tracking plugins auto-detected. |
| 11 | Importer safely re-runnable | ✅ | Re-run → "Skipped" ×33; counts unchanged (33 products, 88 images, 13 reviews). Owner pages with the same slug are kept. |
| 12 | Cleanup does not delete unrelated content | ✅ | After cleanup: owner product, page and post kept; orders #427 and #430 kept with item names; coupon and subscriber kept; 14 settings restored. |
| 13 | Mobile navigation and shopping usable | ✅ | No horizontal overflow at 390px (home, shop, product). Menu drawer: focus moves in, Escape closes and returns focus. Filter drawer, sticky add-to-cart, mini-cart drawer. |
| 14 | Accessibility, security, performance checks | ✅ / ⚠️ | See below. |
| 15 | WooCommerce hooks/extension compatibility | ✅ | Only 3 template overrides; all loop/single hooks still fire; HPOS and blocks compatibility declared by the toolkit. |

## Market Edition (2.0) checks

| Area | Result | How it was verified |
|---|---|---|
| Market import | ✅ | 36 products (8 variable, 28 simple), 9 nested categories (Wet/Dry Market), 89 images, units, origins, pack measures, seeded price history; re-run safe; cleanup removes all of it. |
| Price history and arrows | ✅ | Movers computed from history; pack products compared per kg so pack sizes don't skew results (a bug that showed Pork Liempo at −45.8% was found and fixed: now +2.8% per kg). |
| Price per kg table | ✅ | Sinandomeng: 50 kg sack ₱49/kg highlighted as best value; 25 kg ₱50; 5 kg ₱51; 1 kg ₱52. |
| Ticker, market status, hero board, price board | ✅ | Rendered on desktop and 390px mobile with no horizontal overflow; status shows "Market open · closes 8:00 PM" from Customizer hours; board collapses to cards on mobile. |
| RFQ — empty search | ✅ | Searching "durian" shows the inline form prefilled with "durian". |
| RFQ — out of stock | ✅ | Suahe Shrimp shows "Currently unavailable → Request a quotation" (also in cards, price board and sticky bar). |
| RFQ — validation | ✅ | Empty submit lists 5 field errors with `aria-invalid`, focus moves to the first error. |
| RFQ — submission | ✅ | Prefilled from `?rfq_item=Native%20garlic`; reference RFQ-01013 created with all fields and consent time; store notification and customer confirmation emails sent (captured by a test mail logger). |
| RFQ — staff quote | ✅ | In WooCommerce → Quotations, entering ₱205/kg, total ₱10,370, validity and a message with "Email this quotation" sent the quote email, set status to *Quoted* and logged the send. |
| RFQ — customer account | ✅ | My Account → Quotations lists RFQ-01013 · Native garlic · 50 kg · Quoted · ₱205.00 / kg. |
| Toolkit auto-installs WooCommerce | ✅ | See 1a above. |
| Maintenance — whole site | ✅ | Visitors get 503 + `Retry-After` + `X-Robots-Tag: noindex` + no-cache; Store API cart returns 503; `?wc-api=` callbacks and wp-login are not blocked. |
| Maintenance — shop only | ✅ | Home, journal and About 200 with a "Shop temporarily closed" notice; shop, product, cart, checkout and account 503; no add-to-cart buttons for the public. |
| Maintenance — staff | ✅ | Logged-in admin browses the closed shop; red "Maintenance ON" in the admin bar. |
| Access passes | ✅ | Link sets a signed HttpOnly cookie and redirects to a clean URL; pass holders get 200 + "Preview mode" bar; code typed as `rf5vlx8ywp` (lowercase, no dash) accepted; wrong code shows an accessible error; forged signature and edited expiry rejected (503); revoked pass stops working for both link and existing cookie; "End preview" returns to the maintenance page; admin UI shows the code once only. |
| Auto-reopen | ✅ | Return time in the past with auto-off → inactive; future return time → `Retry-After` equals seconds remaining. |

## Checkout, fulfilment and Order Desk (2.1) checks

| Check | Result | Notes |
|---|---|---|
| Classic checkout switch | ✅ | Block checkout content backed up to post meta and replaced with `[woocommerce_checkout]`; restore available. |
| Step layout | ✅ | Details → Delivery or pickup → Review and pay; summary sticky on desktop, collapsed `<details>` with live total on mobile; no horizontal scroll at 360/390px. |
| Courier order | ✅ | Courier list and day-grouped slots from method settings; missing confirmation tick rejected server-side; order saved with courier, date, slot, rider pickup address and contact; "Courier:" row in totals; Pay at pickup hidden for courier. |
| Pickup order | ✅ | Two pickup points selectable; billing address hidden and not required; Pay at pickup offered; order saved with point, address and hours. |
| Ready for pickup | ✅ | Row action and bulk action available; status change stores the ready time and sends the customer email (pickup and courier wording). |
| Order screens | ✅ | KPI tiles (sales tile hidden from desk staff), Fulfilment column, fulfilment/schedule filters, progress bar, Fulfilment box with reschedule. HPOS tested; legacy storage hooks included. |
| Order Desk role | ✅ | Lands on Orders after login, Dashboard redirects to Orders, menu limited to Orders / Quotations / Profile, other admin pages refused. |
| Account pages | ✅ | Sign in, account menu, Account details, Addresses, view order with fulfilment card. |
| axe-core | ✅ | 0 violations on sign in, checkout (pickup and courier states), Account details, Addresses, edit billing address, view order (fixed during testing: "Free" badge contrast, password toggle target size, unlabelled WooCommerce state select). |

## Branding and accounts (2.2) checks

| Check | Result | Notes |
|---|---|---|
| Logo and colours | ✅ | Logo in header, footer, sign-in page and wp-login; navy and orange across the storefront and Order Desk; buttons on navy sections are orange. |
| Registration | ✅ | Empty required fields blocked by the browser; invalid mobile rejected by the server with the tab and typed values kept; valid signup saves name and mobile to the profile and billing details and logs the customer in. Registration switched on once by the first admin page load. |
| Sign-in | ✅ | Tabs, "Create an account" / "Sign in" links, `?action=register`, login to the dashboard. |
| Dashboard | ✅ | Empty state for new customers; with orders: ready/next collection card, counts, recent orders, details, buy again. |
| Phones | ✅ | No sideways scrolling on the dashboard and registration pages at 390px; account menu scrolls inside itself. |
| axe-core | ✅ | 0 violations on home, shop, checkout, sign-in, registration and dashboard (desktop); registration and dashboard also on a phone. |

## Built-in store (2.3) checks

| Check | Result | Notes |
|---|---|---|
| WooCommerce mode unchanged | ✅ | Shop, checkout, orders and order screens work; the built-in store's scripts are not loaded; no PHP errors. |
| Switching | ✅ | WooCommerce → built-in → WooCommerce → built-in. Products, categories and coupons carried over; a product created in the built-in store opened in WooCommerce with its options; each engine kept its own orders. |
| Fresh site | ✅ | `NEXORA_STORE_ENGINE` set: WooCommerce never installed; 36 demo products seeded; order placed with an account created at checkout. |
| Purchase | ✅ | Add to cart (button, link, options), mini cart, cart, coupon plus promotion, pickup and courier checkout, success page, emails. |
| Stock race | ✅ | 6 simultaneous orders for the last unit: 1 succeeded, 5 refused; cancelling restored the stock. Asking for more than is in stock caps the quantity. |
| Access control | ✅ | Wrong order key, another customer's order and customers opening wp-admin are all blocked; Order Desk staff are refused settings and export; cross-site cart requests get 403. |
| Maintenance mode | ✅ | Shop scope: store pages and cart requests return 503, other pages stay open. |
| axe-core | ✅ | 0 violations on home, shop, product, cart, checkout, success page, sign in, registration and account pages. |
| Not tested | — | The built-in store with a theme other than this one; a real SMTP mail server (emails were captured, not delivered); a large catalogue under load. |

## Accessibility

- **axe-core (WCAG 2.0/2.1/2.2 A and AA rules): 0 violations** on home (desktop and mobile), shop, product, cart, journal and a post. Issues found and fixed during testing: WooCommerce's breadcrumb grey contrast, and the add-to-cart button contrast before a variation is chosen.
- **Lighthouse Accessibility: 100** on home, shop and product.
- Implemented: skip link; visible focus rings; landmark regions; labelled icon buttons; ARIA combobox/listbox live search with arrow-key support; dialogs with focus trap, Escape and focus return; `aria-expanded` and `aria-pressed` states; live regions for cart toasts, filter results and newsletter messages; `prefers-reduced-motion`; 44px touch targets; ratings exposed as text.
- Manual checks to repeat after customizing colors: keyboard-only shopping flow, a screen reader pass (VoiceOver/NVDA) through variation selection and checkout.

## Security review

- All admin actions (importer steps, cleanup, CSV export, notice dismissal, testimonial meta) check capabilities **and** nonces. The importer requires `manage_options` + `manage_woocommerce` and an explicit confirmation.
- Input is sanitized (`sanitize_*`, `absint`, `wc_clean`, strict regexes for tracking IDs); output is escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Only one direct SQL query (price bounds), with no user input. Everything else goes through WP/WooCommerce APIs; products are created via the WooCommerce CRUD.
- Public REST endpoints are read-only (search, product cards, wishlist render) or rate-limited with consent and a honeypot (newsletter). The wishlist write endpoint requires login plus the core `wp_rest` nonce.
- No payment data is ever handled by the theme or toolkit.

## Performance

**Lighthouse (mobile, simulated slow 4G), development server**

| Page | Performance | Accessibility | Best practices | SEO | CLS | TBT |
|---|---|---|---|---|---|---|
| Home | 71 | 100 | 100 | 100 | 0 | 0 ms |
| Shop | 68 | 100 | 100 | 91 | 0 | 90 ms |
| Product | 58 | 100 | 100 | 100 | 0 | 300 ms |

The performance numbers come from PHP's built-in development server, which sends **no gzip/brotli compression** (Lighthouse: "525 KiB savings") and **no cache headers** for static files. Expect markedly better FCP/LCP on a production host with compression, HTTP/2, caching and a CDN. Most of the remaining unused JS/CSS is WooCommerce core (jQuery, blocks, cart fragments).

Fixed during testing:
- Product page CLS went from 0.37 to 0. WooCommerce's gallery slider briefly stacks or over-widens slides; the theme now caps and hides them until they are sized, and disables `smoothHeight`.
- The homepage meta description now falls back to the hero copy when the site tagline is empty.
- Non-descriptive "Read more" links on out-of-stock cards became "View product".

Theme-side optimizations: local variable fonts (2 files, preloaded, `font-display: swap`), hero image preloaded with `fetchpriority="high"` and never lazy-loaded, WebP sub-sizes, lazy-loaded below-the-fold images, deferred vanilla JS (~27 KB unminified, no framework), WooCommerce stylesheet only on WooCommerce pages, cached homepage product queries (invalidated on product changes), and cache-safe personalization (`localStorage` + public REST).

**Production recommendations:** enable gzip/brotli, long cache TTLs for `/wp-content/`, a page cache that excludes cart/checkout/account, an object cache (Redis), and optionally an asset minifier.

## Known limitations

- **Payment gateways:** only Cash on delivery and Bank transfer are configured by the demo. GCash, Maya and cards need a third-party gateway plugin and merchant account (see [PHILIPPINES-STORE-SETUP.md](PHILIPPINES-STORE-SETUP.md)).
- **Email providers:** Mailchimp, Brevo and Klaviyo requests follow their published APIs but could not be tested against live accounts here. Verify with a test list. Failures are logged to WooCommerce → Status → Logs (`nexora-newsletter`) and the local subscriber record is kept.
- **Tracking pixels** were verified at the event-payload level; confirm in GA4 DebugView, Tag Assistant and Meta Events Manager with your real IDs.
- **Add-to-cart events from the Cart/Checkout blocks** (Store API) are queued and sent on the next full page load, not instantly.
- **Free-shipping threshold** is display-only: keep it equal to the *Free shipping* method's minimum. The demo zone lists free shipping first so it becomes the default once a cart qualifies, but WooCommerce keeps a customer's earlier manual choice.
- **Frequently bought together** shows cross-sells without a bundle discount; real bundles need an extension.
- **Quick view, product comparison, exit-intent, loyalty, referrals, A/B testing and abandoned-cart emails** are not built in (see [MARKETING.md](MARKETING.md) for plugin options).
- **WooCommerce "Coming soon" mode** is WooCommerce's default for new stores; switch to Live manually.
- **Recently viewed** history is per browser (`localStorage`), not per account.
- Not yet tested: RTL languages, multisite, and multi-currency plugins.
- The demo artwork is illustrative vector art, not product photography. Replace it with real photos before launch ([IMAGES.md](IMAGES.md)).
