# Facebook and Instagram (Meta)

**Settings → Freshtastic Meta** (toolkit 2.12.0) connects your shop to Meta. It works with the built-in store and with WooCommerce, and it does not need the Meta for WooCommerce plugin.

| Feature | What it does |
|---|---|
| **Catalog sync** | Sends your products to a Meta catalog in Commerce Manager, where Facebook and Instagram Shops, product tags and ads use them. Products are sent when you save them, when they sell out or come back in stock (orders included), and they are removed when you unpublish, hide or delete them. |
| **Auto-post** | When a product is published with **Post on Facebook Page + Instagram when published** ticked, it is posted once on your Facebook Page (photo and text) and on Instagram. |
| **Data feed** | A private CSV address that Commerce Manager can read on a schedule. It works without a token, so you can use it if you only want the catalog. |

## What gets sent

- **One item per product.** A product with options (for example 5 kg and 25 kg) is sent as one item per option, grouped together.
- **Item ID:** the SKU, or the product number when there is no SKU. It is the same ID the analytics events use, so Meta ads can match visits and sales to catalog items. If you change a SKU, the old item is removed and a new one is added.
- **Fields:** title, description (short description, else the full one, as plain text), price in `125.00 PHP` format, sale price and sale dates, availability (in stock / out of stock), condition, brand (your site title unless you set one), product page link, main image, up to 10 gallery images, and the first category.
- **Left out:** drafts, private and password-protected products, products hidden from the shop, products without an image (Meta requires one), imported demo products (you can change this), and any product where you untick **Show in the Facebook & Instagram catalog**.
- **Maintenance mode** does not remove products from Meta.

The **Facebook & Instagram** box on each product shows what happened: *Sent to the catalog*, *Not in the catalog* (with the reason), or *Problem* (with Meta's message, for example an image Meta could not download). The **Status** section on the settings page lists the last 30 updates and posts.

Updates are sent in the background, a few seconds after you save (through WordPress's scheduled tasks). If Meta cannot be reached, they are retried after 15 minutes. **Send all products now** sends everything at once, for example after you connect a new catalog.

## Set up Meta (once, about 15 minutes)

The same steps, with these links, are on the settings page under **How to connect: step by step**. The links ask you to sign in to Facebook first. Meta sometimes renames its menus, but the links still lead to the right place.

| # | Step | Where |
|---|---|---|
| 1 | **Get ready.** You need a Facebook Page and a Meta Business portfolio that owns it. For Instagram, switch your Instagram to a professional (business) account and link it to the Page. | [business.facebook.com/overview](https://business.facebook.com/overview) |
| 2 | **Create a catalog.** **Add catalog** → type **E-commerce** → **Upload product info** → choose your business. Open the catalog → **Settings** and copy the **Catalog ID**. | [Commerce Manager](https://business.facebook.com/commerce) |
| 3 | **Create a Meta app.** **Create app** → **Other** → type **Business**. Name it (for example "Freshtastic Shop") and connect it to your business portfolio. You do not need to publish it or send it for review. | [Meta for Developers → My Apps](https://developers.facebook.com/apps/) |
| 4 | **Add a system user.** **Add**, name it (for example "Website"), role **Admin**. Then **Assign assets** and give it **full control** of your app, catalog, Facebook Page and Instagram account. | [Business Settings → System users](https://business.facebook.com/settings/system-users) |
| 5 | **Generate the token.** On the same system user: **Generate token** → your app → **Token expiration: Never** → tick `catalog_management`, `business_management`, `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`. Copy the token right away, because Meta shows it only once. | Same page |
| 6 | **Find your Page ID.** Select your Page and copy its **ID**. On Facebook you can also find it under your Page → **About** → **Page transparency**. | [Business Settings → Pages](https://business.facebook.com/settings/pages) |
| 7 | **Save and test.** In WordPress, open **Settings → Freshtastic Meta**. Paste the token, Catalog ID and Page ID, tick what you want under *Post new products automatically*, then click **Save Changes** and **Test connection**. Every line should get a green tick. Finally click **Send all products now**. | Your site |

**You do not need to look up the Instagram account ID.** Leave it empty: **Test connection** finds the Instagram account linked to your Page and saves its ID. Then tick **Post products on Instagram** and save.

Leave out the Page ID if you do not want to post on Facebook.

**If something fails:** paste the token into Meta's [Access Token Debugger](https://developers.facebook.com/tools/debug/accesstoken/) to see which permissions and assets it has. Meta's developer guide to [system users](https://developers.facebook.com/docs/marketing-api/system-users/) explains them in more detail. Common causes:

| Test connection says | Fix |
|---|---|
| (#190) Invalid OAuth access token | The token was copied incompletely or revoked. Generate a new one (step 5). |
| (#200) or (#10) permission error on the catalog | The system user does not have the catalog under **Assign assets**, or `catalog_management` was not ticked. |
| The token cannot post to this Page | Give the system user the Page with full control and tick `pages_manage_posts`, then generate a new token. |
| No Instagram line | The Instagram account is not a professional account, or it is not linked to the Page (Page settings → Linked accounts). |

**Keep the token safe.** It can post on your Page. It is never shown again after saving and is only sent to graph.facebook.com. It is safer to put it in `wp-config.php` instead:

```php
define( 'NEXORA_META_ACCESS_TOKEN', 'your-token' );
define( 'NEXORA_META_APP_SECRET', 'your-app-secret' ); // only if the app has "Require app secret" switched on
```

If the token is ever shared by mistake, revoke it in Business Settings → System users and generate a new one.

## Auto-posting

- Tick **Facebook Page** and/or **Instagram** under *Post new products automatically*.
- New products then have **Post on … when published** ticked (untick **New products** if you would rather tick it yourself each time). Publishing, or a scheduled product going live, posts it within about a minute. Reload the product to see **View** links to the posts.
- For a product that is already published, tick the box and click **Update** to post it. Each product is posted only once. Use **Post again when I click Update** to post it again.
- **Imported products are never posted** (demo import, CSV or other plugins), so an import cannot flood your Page.
- **Post text:** edit the template on the settings page. Placeholders: `{title}`, `{price}` (for example `₱150.00 / kg`, or `from ₱300.00` for products with options), `{short_description}`, `{link}`, `{category}`, `{site_name}`. A line with `{price}` is left out when a product has no price.
- **Facebook** gets a photo post with the text. A product without an image is posted as a link post.
- **Instagram** needs a **JPEG** product image, and links in captions are not clickable there (people use the link in your bio). If Instagram refuses a post, the product box shows why.

## Data feed (no token)

If you only want the catalog, or as a backup, use the feed:

1. Copy the **Feed address** from the settings page.
2. In [Commerce Manager](https://business.facebook.com/commerce) → your catalog → **Data sources → Add items → Data feed → Scheduled feed**, paste the address and choose an hourly or daily schedule. Currency is PHP.

The address contains a secret key. Anyone without it gets "not found". Tick **Make a new address** to change it, and then update it in Commerce Manager.

Use either the feed or the token sync for one catalog, not both. Both work, but two sources for the same items make problems harder to trace.

## Images

Meta downloads images from your site, so the site must be public: catalog items from a local or password-protected site are rejected. Use JPG or PNG product images. Instagram does not accept WebP, and Meta's support for WebP in catalogs is limited.

## Good to know

- **Meta for WooCommerce:** if it is also active, the settings page warns you. Use one of the two for the catalog.
- **Meta Pixel:** the Pixel ID is under Settings → Freshtastic Toolkit (WooCommerce shops). With matching item IDs, Meta can use the catalog for dynamic ads.
- **Graph API version:** v25.0 by default. Meta retires old versions after about two years. When Meta emails you about a version upgrade, enter the newer version (for example `v26.0`) and click **Test connection**.
- **Developers:** filters `nexora_meta_item` (catalog fields), `nexora_meta_retailer_id` (item ID) and `nexora_meta_post_message` (post text). The built-in store fires `nexora_store_stock_changed` when an order changes stock.
