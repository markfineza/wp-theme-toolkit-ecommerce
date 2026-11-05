# WooCommerce configuration checklist

Work through this before launching. Items marked ⚙️ are set by the demo importer's optional settings step.

## Store basics
- [ ] ⚙️ Store address, currency (₱) and selling locations — see [PHILIPPINES-STORE-SETUP.md](PHILIPPINES-STORE-SETUP.md)
- [ ] Site title, tagline, logo (Customize → Site Identity) and favicon (Site Icon)
- [ ] Permalinks: *Post name*; product base left as default
- [ ] **WooCommerce → Settings → Site visibility → Live** when ready

## Products
- [ ] Real products added; demo content removed (Tools → Freshtastic Demo Content)
- [ ] Every product has a main image, alt text, SKU, price and stock settings
- [ ] Variable products have every variation priced and in stock or marked out of stock
- [ ] Featured products ticked (star icon in Products list) — shown in *Featured products*
- [ ] Cross-sells set (Linked Products tab) — shown as *Frequently bought together* and in the cart
- [ ] Product catalog layout: Customize → WooCommerce → Product Catalog (products per row and rows per page)
- [ ] *Hide out of stock items* decided (WooCommerce → Settings → Products → Inventory)
- [ ] Low-stock threshold set in both WooCommerce (notifications) and Customize → Freshtastic Theme (storefront "Only N left")

## Shipping, tax, payments
- [ ] ⚙️ Shipping zones and methods; free-shipping minimum matches the theme's progress bar
- [ ] Taxes configured (or deliberately off)
- [ ] ⚙️ At least one payment method enabled; real gateway tested in sandbox mode
- [ ] Bank transfer details replaced with real ones (if used)

## Checkout and accounts
- [ ] Guest checkout and account creation options chosen
- [ ] Terms and Privacy pages published and linked
- [ ] Test order placed end to end, with a coupon, and the order confirmation email received
- [ ] Customer emails branded (WooCommerce → Settings → Emails)

## Reviews
- [ ] Reviews enabled; *Show "verified owner" label* on; *Reviews can only be left by verified owners* considered
- [ ] Review moderation (Settings → Discussion)

## Performance and operations
- [ ] Page cache configured to **exclude** cart, checkout and my-account, and to bypass when the `woocommerce_items_in_cart` cookie is set (most WooCommerce-aware caches do this automatically)
- [ ] Object cache (Redis/Memcached) if your host offers it
- [ ] Backups and uptime monitoring
- [ ] Action Scheduler running (WooCommerce → Status → Scheduled Actions shows no large backlog)
- [ ] HTTPS enforced
