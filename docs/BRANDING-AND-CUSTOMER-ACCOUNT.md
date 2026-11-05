# Branding and Customer Accounts

Version 2.2 rebrands the theme as **Freshtastic Food Hub** and redesigns the customer dashboard, sign-in and registration.

## Brand

| Item | Value | Where to change it |
|---|---|---|
| Theme name | Freshtastic Food Hub | `style.css` |
| Plugin name | Freshtastic Food Hub Toolkit | `nexora-commerce-toolkit.php` |
| Primary colour | Navy `#12306E` | Customize → Freshtastic Theme → Brand |
| Secondary colour | Orange `#F0762B` | Customize → Freshtastic Theme → Brand |
| Dark sections | Deep navy `#0A1A40` | Customize → Freshtastic Theme → Brand |
| Logo | `assets/images/brand/` | Customize → Site Identity → Logo |
| Browser icon | `assets/images/brand/` | Customize → Site Identity → Site Icon |

- The bundled logo appears in the header and footer, on the sign-in page, on `wp-login.php` and in WooCommerce emails. A logo set in Site Identity replaces it everywhere except emails, which have their own header image setting (WooCommerce → Settings → Emails).
- On navy sections, primary buttons turn orange so they stay visible.
- Green stays only as the "price up" and "in stock" signal.
- On update, colours saved in the Customizer are reset to the new brand only if they still equal the old defaults. A colour you chose yourself is kept.
- Set the shop's name in **Settings → General → Site Title**. The theme doesn't change it.

### Folder name

The installed folders stay `nexora-commerce` and `nexora-commerce-toolkit`, and code keeps its `nexora_` prefixes. Customers never see these. Renaming them would make WordPress treat the theme as new, and the Customizer settings would be lost.

### Demo text saved before this version

The name is updated in the code, but text already saved on a site doesn't change on its own. If you ran the demo importer earlier, check:

- the shipping zone name and pickup points (WooCommerce → Settings → Shipping);
- the announcement bar, footer text and hero (Customize);
- the "Delivery and Returns" page.

Running the importer again doesn't overwrite these.

## Sign-in and registration

The page is `/my-account/`. `/my-account/?action=register` opens the *Create account* tab directly, which is useful for links in posts or messages.

**Registration fields:** first name, last name, mobile number, email and password. The name and mobile number are saved to the customer's profile and billing details, so the first checkout is already filled in. The mobile number is checked on the server; accounts created during checkout don't need these fields.

**Registration is switched on automatically once.** WooCommerce ships with it off, so the form wouldn't appear. The first admin page load after updating the toolkit turns it on, with customers choosing their own password. To change this afterwards, go to WooCommerce → Settings → Accounts & Privacy; the toolkit won't switch it back.

## Customer dashboard

`woocommerce/myaccount/dashboard.php` shows:

- a greeting with *Shop the market* and *Request a quote* buttons;
- the next pickup or rider collection, with order progress. It changes to "Ready to collect" or "Packed: book your rider now" when staff mark the order ready;
- counts for orders in progress, total orders, quotations and saved items;
- recent orders with status, or a friendly empty state for new customers;
- the customer's contact details and address;
- total spent, and *Buy again* for in-stock items from recent orders.

The next-collection card, quotation count and saved-item count come from the toolkit. Without the plugin, those parts are simply left out.

The *Downloads* menu item is hidden for customers with nothing to download.
