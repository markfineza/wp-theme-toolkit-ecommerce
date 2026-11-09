# Maintenance mode and temporary access

Close the store for updates — restocking, price changes, a redesign — while staff and invited people keep working on and previewing the live site. Requires the **Freshtastic Food Hub Toolkit** plugin; works with any theme.

**Settings → Freshtastic Maintenance**

## Turning it on

1. (Optional) Fill in the maintenance page: **Headline**, **Message**, **Expected back** time and a **Contact line**.
2. Choose the **Scope**:
   - **Whole storefront** — every front-end page shows the maintenance page.
   - **Shop only** — shop, product, cart, checkout and account pages are closed; the homepage, journal and info pages stay up with a "Shop temporarily closed" notice, and quotation and newsletter forms keep working.
3. Click **Turn maintenance on** (and confirm). Click **Turn maintenance off — open the store** when you are done.

Tick **Open the store automatically at this time** to end maintenance at the *Expected back* time without logging in.

### Background

Choose the **Background** of the maintenance page:

- **Market artwork** *(default)*: rice, vegetables, fish and eggs over a price chart, in the theme's style. It ships with the toolkit.
- **Homepage banner image from the theme**: the *Fallback banner image* under **Appearance → Customize → Freshtastic Homepage**. If none is set, the market artwork is used.
- **My own image**: click **Choose image**. A landscape image at least 1600 pixels wide works best.
- **Plain dark background**: the earlier look.

The image is tinted with the theme's primary colour, and the text sits on a frosted card so it stays easy to read. **Overlay strength** (30 to 90%, default 60%) sets how dark the tint is. Raise it for busy photos. Click **Preview the maintenance page** after saving to see it without turning maintenance on.

WP-CLI: `wp nexora maintenance on [--scope=shop]`, `wp nexora maintenance off`, `wp nexora maintenance status`.

## What visitors get

- A branded maintenance page (your logo, a market background in the theme's colours, headline, message, expected return time, contact line and a staff login link).
- HTTP **503 Service Unavailable** with **Retry-After** (seconds until your expected return) and **noindex** — the correct signals for search engines, so rankings are not affected by a short closure.
- **No purchases**: products are not purchasable and the WooCommerce Store API (used by the Cart and Checkout blocks) answers 503, so nothing can be ordered through any route.

Never blocked: **wp-admin**, the **login page**, **payment gateway callbacks** (`?wc-api=…`, so pending payments still complete), cron and WP-CLI.

## Who still sees the site

- **Staff roles** — administrators always; shop managers by default; choose others under *Roles that always see the site*. Staff see a red **Maintenance ON** badge in the admin bar.
- **Holders of a temporary access pass** (below).

## Temporary access passes

For clients, suppliers, testers or investors who need to see the site while it is closed.

1. Under **Temporary access**, enter who it is for (e.g. "Client review") and how long it is valid (1 hour – 30 days), then **Create access pass**.
2. Copy the **access link** (e.g. `https://yourstore.ph/?nexora_access=YZXC7-6RQLL`) or the **access code** (`YZXC7-6RQLL`). **They are shown only once** — the site keeps only a hashed copy.
3. Send it. The person either opens the link, or types the code in the *Have a preview access code?* box on the maintenance page (case and dashes don't matter).

Pass holders browse the whole site (they can also test the cart and checkout) with a **Preview mode** bar showing when their access ends and an **End preview** button. Access is stored in a signed, HttpOnly cookie that expires with the pass; editing the cookie does not extend it.

The pass list shows when each pass was used and how often. **Revoke** stops a pass immediately — even for someone already browsing. Expired passes are cleaned up after 30 days.

WP-CLI: `wp nexora maintenance grant --label="Client review" --hours=24`, `wp nexora maintenance revoke <id>`.

**Security:** codes are 10 random characters (≈50 bits), stored as HMAC hashes; code attempts are limited to 8 per IP per 10 minutes; the code form carries a nonce.

## Caching and CDNs

When you switch maintenance on or off the toolkit purges WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache and the object cache when present. If you use another cache or a CDN:

- purge it after switching maintenance on or off;
- exclude the **`nexora_preview`** cookie from page caching (treat it like a login cookie), so pass holders aren't served the cached maintenance page — or the public served cached shop pages.

## WooCommerce "Coming soon" mode

WooCommerce has its own *Coming soon* setting (WooCommerce → Settings → Site visibility) intended for stores that have never launched. Use Freshtastic maintenance for a live store that closes temporarily; don't enable both.

## Developer notes

- Option `nexora_maintenance` (settings) and `nexora_maintenance_grants` (passes).
- Action `nexora_maintenance_changed( $settings )` fires after settings change (hook your own cache purge here).
- `Nexora_Toolkit_Maintenance::is_active()` and `::can_bypass()` are public for custom integrations.
