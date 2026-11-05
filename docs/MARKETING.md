# Marketing integrations

What the theme and toolkit do natively, and what needs a third-party plugin or account.

| Area | Native (theme/toolkit) | Needs a plugin, service or account |
|---|---|---|
| SEO | Semantic markup, breadcrumbs, heading structure, alt text, fast pages, fallback meta description | Open Graph, canonical, sitemaps, schema extras (Yoast SEO, Rank Math, AIOSEO, SEOPress) |
| Email | Consent-based signup forms, local subscriber list with consent records, CSV export, privacy export/erase | Mailchimp, Brevo or Klaviyo account; abandoned-cart and post-purchase flows |
| Analytics | GA4, GTM, Meta Pixel, Google Ads purchase conversion; ecommerce events; Consent Mode v2 | The accounts themselves; a consent banner plugin |
| Promotions | Coupon fields, sale badges with accurate %, scheduled campaign banner, real-deadline countdown | Advanced rules (BOGO, tiered) need a discount plugin |
| Social and feeds | Clean product data, social profile links | Meta for WooCommerce, Google for WooCommerce (Merchant Center), product feed plugins |
| Conversion | Sticky add-to-cart, buy now, free-shipping progress, inventory-based low stock, recently viewed, related, wishlist | Exit intent, comparison, A/B testing, loyalty, referrals |

## SEO

- The theme outputs **no** Open Graph tags or extra schema. WooCommerce outputs Product structured data, and your SEO plugin handles social and canonical metadata, so there are no duplicates.
- Breadcrumbs: if Yoast breadcrumbs are enabled, or Rank Math is active, the theme uses theirs; otherwise WooCommerce's.
- Without an SEO plugin, the theme prints a basic `<meta name="description">` (disable with `add_filter( 'nexora_output_meta_description', '__return_false' );`).
- If Yoast or Rank Math is active during import, demo products get meta descriptions.
- Sitemaps: WordPress core sitemaps (`/wp-sitemap.xml`) or your SEO plugin's. Submit to Google Search Console.

## Email marketing

**Settings → Freshtastic Toolkit → Newsletter**

1. Choose a provider: *Store on this site only*, *Mailchimp*, *Brevo* or *Klaviyo*.
2. Add keys. **Recommended:** put them in `wp-config.php`, which keeps them out of the database. The settings screen shows them as locked:
   ```php
   define( 'NEXORA_MAILCHIMP_API_KEY', 'xxxx-us21' );
   define( 'NEXORA_BREVO_API_KEY', 'xkeysib-…' );
   define( 'NEXORA_KLAVIYO_PRIVATE_KEY', 'pk_…' );
   ```
   Keys saved in the screen are write-only (never displayed again) and are never sent to the browser.
3. Add the list/audience ID.

| Provider | What the toolkit sends | Notes |
|---|---|---|
| Mailchimp | `PUT /lists/{id}/members/{hash}` with `status_if_new: pending` (double opt-in on by default) | Mailchimp emails the confirmation. |
| Brevo | `POST /v3/contacts` with `listIds` and `updateEnabled` | Use a Brevo double opt-in form or automation if required. |
| Klaviyo | Profile subscription bulk-create job for the list, with marketing consent | API revision is filterable: `nexora_klaviyo_revision`. |

Every signup is also stored locally under **Users → Subscribers** with a timestamp, the consent wording and the provider result, so nothing is lost if a provider call fails. **Export CSV** is on that screen. WordPress's *Export/Erase Personal Data* tools include these records.

**Spam protection:** a honeypot field and 5 attempts per IP per 10 minutes (stored only as a salted hash). The form is nonce-free on purpose, because cached pages would otherwise reject genuine signups.

**Welcome emails, abandoned cart, post-purchase and segmentation:** configure these in your provider (Mailchimp Customer Journeys, Brevo Automations, Klaviyo Flows). For abandoned cart and purchase-based segments, install the provider's official WooCommerce plugin (*Mailchimp for WooCommerce*, *Brevo – WooCommerce*, *Klaviyo*). It syncs orders and carts, and the Freshtastic forms keep working alongside it.

## Analytics and conversion tracking

**Settings → Freshtastic Toolkit → Analytics**

| Field | Example |
|---|---|
| Google Tag Manager | `GTM-ABC1234`. Events go to `dataLayer` in GA4 ecommerce format. |
| GA4 measurement ID | `G-XXXXXXX`. Leave empty if GA4 is configured inside GTM. |
| Google Ads ID + purchase label | `AW-123456789` + label. Sends a conversion with value, currency and transaction ID. |
| Meta Pixel ID | Numeric. Purchase uses `eventID = order_{number}` for deduplication with a Conversions API setup. |
| Search Console | Verification content value (skip if your SEO plugin verifies). |

**Events**

| Event | When | Source |
|---|---|---|
| `view_item` | Product page | Server |
| `search` | Search results page | Server |
| `add_to_cart` | AJAX buttons (client) / product form & Store API (queued, sent on next page) | Both, never both for one action |
| `remove_from_cart` | Mini-cart (client) / cart page (queued) | Both |
| `begin_checkout` | Checkout page with items | Server |
| `purchase` | Order received page, **once per order** (order meta flag), real total, tax, shipping, coupon and currency | Server |
| `generate_lead` | Newsletter signup | Client |

Meta Pixel names: ViewContent, AddToCart, InitiateCheckout, Purchase, Search, Lead.

**Avoiding duplicates:** the toolkit detects *Google for WooCommerce*, *Google Analytics for WooCommerce*, *Meta for WooCommerce*, *GTM4WP*, *PixelYourSite* and *Site Kit*, and skips the channels they already handle. Override with *Send events anyway* only after disabling their tracking. Logged-in shop managers are not tracked (filter: `nexora_tracking_skip_user`).

**Consent:** the default is Google Consent Mode v2 with everything *denied* until your banner grants it, and Meta Pixel starts revoked. Your consent banner must call:

```js
window.nexoraConsent.update( { analytics: true, marketing: true } );
```

Or choose *WP Consent API* and use a compatible consent plugin (for example Complianz or CookieYes). For a PH-only store, consult the Data Privacy Act; choose *No consent gating* only where legally permitted.

## Promotions and coupons

WooCommerce remains the source of truth for every discount (**Marketing → Coupons**): percentage, fixed cart, fixed product, free shipping, minimum spend, category restrictions, usage limits, expiry and first-order (via *Allowed emails* or a plugin).

- **Sale badges** show the real percentage. For variable products with different discounts it says *Up to −X%*.
- **Campaign banner** (Customize → Freshtastic Homepage → Campaign banner) has optional *show from* and *hide after* dates.
- **Announcement countdown** appears only when you set a real end date, and the bar hides itself after it.
- **Scheduled sales:** use WooCommerce's sale price schedule on each product.

## Social media and feeds

- **Facebook and Instagram Shops:** install *Meta for WooCommerce*, connect your Business Manager, and sync the catalog. (Freshtastic then skips its own Pixel to avoid double counting.)
- **Google Merchant Center:** install *Google for WooCommerce* (free listings and Performance Max), or a feed plugin such as *Product Feed PRO*. Make sure every product has a GTIN or brand if required for your category.
- **Campaign links:** use UTM parameters (`?utm_source=instagram&utm_medium=social&utm_campaign=street-edit`). GA4 reads them automatically. For affiliate or influencer programs, use an affiliate plugin (for example AffiliateWP or SliceWP).
- **Landing pages:** create a page in the block editor, or a product category page. Category descriptions appear at the top of the category archive.

Nothing is published to external marketplaces unless you install and configure one of these integrations.

## Conversion features that need plugins

| Feature | Suggested approach |
|---|---|
| Abandoned cart recovery | Your email provider's WooCommerce plugin, or *Cart Abandonment Recovery* |
| Review request emails | *Customer Reviews for WooCommerce*, or your email provider's post-purchase flow |
| Loyalty and referrals | *WooCommerce Points and Rewards*, *Advanced Coupons*, *ReferralCandy* |
| Exit-intent offers | *OptinMonster*, *Popup Maker* (avoid fake urgency) |
| Product comparison | *YITH WooCommerce Compare* |
| Quick view | *YITH Quick View*, or similar — check keyboard/focus support |
| A/B testing | *Nelio A/B Testing*, *VWO*, *Optimizely* |
| Bundles / true "frequently bought together" discounts | *Product Bundles* or *Frequently Bought Together* extensions. The theme shows cross-sells without a discount. |
