# Built-in Store (no WooCommerce)

The toolkit can run the whole shop itself, without the WooCommerce plugin.

## Turning it on

**New site:** nothing to do. When the toolkit is activated on a site where WooCommerce is not running, it starts the built-in store and remembers that choice. WooCommerce is never installed, and the demo importer works without it. A site that already runs WooCommerce stays on WooCommerce until you switch.

**Existing WooCommerce site:** go to **Settings → Freshtastic Toolkit → Store engine → Use the built-in store**. WooCommerce is deactivated, not deleted. Then open **Store → Settings** and fill in your address, pickup points and payment details.

**Lock the engine:** to keep a site on the built-in store whatever happens to WooCommerce, add this to `wp-config.php`:

```php
define( 'NEXORA_STORE_ENGINE', 'native' );
```

To load the demo store, go to **Tools → Freshtastic Demo Content**. It adds products, pages, menus, reviews and the homepage, and can remove them again. Store settings are not changed; set those under Store → Settings. On the command line, `wp nexora demo import --content --reviews --homepage` does the same, and `wp nexora store seed` adds products only.

**Going back:** **Settings → Freshtastic Toolkit → Store engine → Use WooCommerce**. If the constant above is set, remove it first.

## What carries over when you switch

| Data | When you switch |
|---|---|
| Products, options, stock, images, categories, tags, reviews | Carried over, both ways |
| Coupons | Carried over, both ways |
| Customer accounts and saved addresses | Carried over, both ways |
| Orders | **Stay with the engine that took them.** They are not merged, and they come back when you switch back. |
| Promotions | Built-in store only |
| WooCommerce payment plugins and add-ons | Stop working on the built-in store |

## What it includes

- **Shop:** shop, category, search and product pages; filters and sorting; options such as pack sizes; scheduled sale prices; reviews; related products.
- **Cart:** mini cart, cart page, guest carts that merge into the account at login.
- **Wishlist:** for guests and customers.
- **Checkout:** store pickup and customer-booked courier with time slots, optional store delivery, guest checkout, account creation.
- **Order success page and order emails.**
- **Customer portal:** login, registration, dashboard, orders, address, profile, wishlist, quotations.
- **Coupons** (Store → Coupons): percent or fixed amount, expiry, minimum and maximum order, usage limits, category limits.
- **Promotions** (Store → Promotions): applied automatically between dates, with no code. If several qualify, the customer gets the biggest.
- **Free-item coupons and the first customers promotion:** see [REWARDS.md](REWARDS.md). They work with WooCommerce too.
- **Staff:** dashboard, orders with filters and CSV export, status changes, reschedule, notes, low stock list, Order Desk role.
- **Also works here:** market prices and price board, quotations, maintenance mode, privacy export and erase.

## Payments

The built-in methods are **manual**: Pay at pickup (cash), Bank transfer, and GCash/Maya sent by the customer with a reference number. You check the payment, then set the order to Processing.

**Automatic online payments: PayMongo is built in.** Turn it on under **Store → PayMongo** for GCash, Maya, cards, QR Ph and more. Payments are confirmed automatically and you can refund from the order screen. See [PAYMONGO.md](PAYMONGO.md). Other providers can be added through the `nexora_store_gateways` filter. WooCommerce payment plugins do not work with the built-in store.

## What it does not do

WooCommerce remains the better choice if you need any of these:

- automatic online payments or automatic refunds (Refunded only records the status);
- tax calculation (prices are shown as entered);
- subscriptions, memberships, or multi-currency;
- WooCommerce extensions;
- analytics events (the toolkit's GA4, Meta Pixel and Google Ads tracking runs only with WooCommerce);
- manually created orders in the admin;
- decimal quantities (quantities are whole numbers);
- product options with more than one attribute: these display, but the editor manages one option list.

## For developers

Filters: `nexora_store_gateways`, `nexora_store_shipping_options`, `nexora_store_schedule`, `nexora_store_purchasable`, `nexora_store_account_menu`, `nexora_store_email_logo`.
Actions: `nexora_store_order_placed`, `nexora_store_order_status`.
Templates: copy `templates/store/*.php` from the toolkit to `yourtheme/nexora-store/` to override them.
Orders use their own tables: `nx_orders`, `nx_order_items`, `nx_order_notes`, plus `nx_carts`.
