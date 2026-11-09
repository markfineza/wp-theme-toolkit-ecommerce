# Setup and installation

## 1. Requirements

| | Minimum | Tested |
|---|---|---|
| WordPress | 6.5 | 7.1.3 |
| WooCommerce | 8.5 | 11.2.1 |
| PHP | 7.4 | 8.4 |
| Server | GD or Imagick (image sizes); WebP support recommended | |

## 2. Install

1. **Appearance → Themes → Add New → Upload Theme** → choose `nexora-commerce.zip` → **Install** → **Activate**.
2. **Plugins → Add New → Upload Plugin** → choose `nexora-commerce-toolkit.zip` → **Install** → **Activate Plugin**.
   - **Site without WooCommerce:** the toolkit starts its **built-in store**. WooCommerce is not installed and not needed, including for the demo import. Fill in your address, pickup points and payment details under **Store → Settings**. See [BUILT-IN-STORE.md](BUILT-IN-STORE.md).
   - **Site already running WooCommerce:** WooCommerce keeps running the shop. You can switch later under **Settings → Freshtastic Toolkit → Store engine**.
   - **Want WooCommerce on a new site?** Choose **Settings → Freshtastic Toolkit → Store engine → Use WooCommerce**. The toolkit then downloads, installs and activates it from WordPress.org. If your host does not allow automatic plugin installs (it asks for FTP details, `DISALLOW_FILE_MODS` is set, or it's a multisite), a notice with an **Install** button appears instead. With WP-CLI: `wp nexora install-dependencies`. Developers can add plugins to that list with the `nexora_toolkit_required_plugins` filter.
3. **Settings → Permalinks**: choose *Post name* and save (needed for clean shop and wishlist URLs).

The theme works without the toolkit. You lose the demo importer, wishlist, newsletter, analytics events and testimonials, and the matching UI hides itself. The theme also works without WooCommerce: blog, pages and menus render, shop sections hide, and admins see a notice.

## 3. Import the demo store

**Tools → Freshtastic Demo Content**

First choose **which store should run the demo**. The demo content is the same for both:

| Store | What happens |
|---|---|
| **Without WooCommerce: built-in store** *(recommended)* | The Freshtastic Toolkit runs the shop, cart, checkout, pickup and courier, orders and accounts. No other plugin is installed. |
| **With WooCommerce** | For WooCommerce payment gateways or add-ons. WooCommerce is activated, or downloaded from WordPress.org first. |

If you pick the store that is not running, the importer switches the shop to it, reloads the page and continues the import by itself. Switching is reversible under **Settings → Freshtastic Toolkit → Store engine**; products, categories, coupons and customers carry over, and each store keeps its own orders. If WooCommerce cannot be installed (for example the host blocks plugin downloads), the shop stays on the store it had and the page says why.

| Option | What it does |
|---|---|
| Products, categories, attributes, images *(always)* | 36 wet and dry market products in *Wet Market* (Vegetables, Fruits, Meat, Poultry and Eggs, Fish and Seafood) and *Dry Market* (Rice and Grains, Dry Goods and Condiments), with units, origins, pack sizes, illustrative price history, 89 images and cross-sells. |
| Pages, journal posts and menus | About, **Request a Quote**, Contact, Delivery and Returns, FAQ, Terms, Wishlist, Market Journal; 5 market guides; 5 menus (only fills **empty** menu locations). |
| Sample reviews and testimonials | 13 reviews and 3 testimonials, all visibly labelled "Sample". |
| Static homepage and banner images | Creates a "Home" page as the front page and sets hero/campaign/CTA images. |
| Philippine store settings *(WooCommerce only, off by default; the built-in store already uses ₱)* | ₱ currency, Metro Manila base, PH-only selling, demo delivery zone with *Store pickup* and *Courier delivery (customer books)*, Pay at pickup (cash) and Bank transfer, required mobile number. Then click **Use the Freshtastic checkout** in the admin notice — see [CHECKOUT-AND-ORDER-DESK.md](CHECKOUT-AND-ORDER-DESK.md). |

Tick **I understand…** and click **Import demo content**. A progress bar and log show each step. The import takes about a minute; if it is interrupted, run it again and it continues where it stopped.

**Re-running is safe.** Products are matched by SKU and everything else by a stored key, so nothing is duplicated. Pages whose slug already exists on your site are left alone.

**WP-CLI:**

```bash
wp nexora demo import --content --reviews --homepage                    # into the running store
wp nexora demo import --engine=native --content --reviews --homepage    # built-in store; switches first, then run it again
wp nexora demo import --engine=woocommerce --content --reviews --homepage --settings
wp nexora demo status
wp nexora demo cleanup --yes
```

> **WooCommerce "Coming soon" mode.** New WooCommerce stores start in *Coming soon* mode, so logged-out visitors see a placeholder instead of shop pages. Switch to *Live* under **WooCommerce → Settings → Site visibility** when ready. The importer tells you if this mode is on.

## 4. Remove the demo

**Tools → Freshtastic Demo Content → Remove demo content.**

- Deletes only what the importer created: products and variations, images, posts, pages, menus, sample reviews, testimonials, the demo shipping zone, and categories, tags and attributes it created *if nothing else uses them*.
- Restores settings it changed (currency, front page, payment settings and so on), but **only** if you have not changed them since.
- **Never** deletes orders, customers, users, coupons, newsletter subscribers or your own content. Past orders keep their own copy of product names and prices.

## 5. Replace demo products with your inventory

1. Add real products (Products → Add New, or **Products → Import** for CSV).
2. Keep or rename the demo categories, or create your own. Set category images under Products → Categories.
3. Replace homepage text and images in **Appearance → Customize → Freshtastic Homepage**.
4. Run **Remove demo content**.
5. In **Settings → Freshtastic Toolkit**, untick *Label sample content* once no demo content remains.

## 6. Next steps

- Configure payments and shipping: [PHILIPPINES-STORE-SETUP.md](PHILIPPINES-STORE-SETUP.md).
- Go through [WOOCOMMERCE-CHECKLIST.md](WOOCOMMERCE-CHECKLIST.md) before launch.
- Connect email and analytics: [MARKETING.md](MARKETING.md).
