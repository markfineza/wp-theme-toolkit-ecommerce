# Packages and the promo pop-up

Both work with WooCommerce and with the built-in store.

## Packages

A package is a product with its own price that contains other products, for example **Package A: 2 × Tilapia + 1 × Iodized Salt**.

### Create one

1. Add a product and name it, for example `Package A`. Give it a **price** and a photo. Choose the one-price product type, not several options.
2. In the **Package** box, tick **This product is a package**.
3. Choose each product and its quantity. Options such as "5 kg" can be chosen directly.
4. Publish. The product also appears in your shop, so you can give it a "Packages" category.

Packages are listed by name, so Package A comes before Package B. On WooCommerce you can change this with **Product data → Advanced → Menu order**.

### What customers see

- **Homepage:** a "Choose your package" section. It stays hidden until a package exists. Edit its text under **Appearance → Customize → Freshtastic Homepage → Packages**, and move it under **Sections and order**.
- **Package page:** what's inside, and how much is saved compared with buying separately.
- **Product pages:** products that are in a package link to it under "Also in these packages".
- **Cart, checkout, emails and order screens:** the package with its contents listed, so staff know what to pack.

### Stock

- A package has no stock of its own. Its stock fields are switched off on saving.
- Availability comes from the contents. With 5 Tilapia and 2 in each package, 2 packages can be sold. If something inside is out of stock or unpublished, the package shows as out of stock.
- Buying a package takes stock from each product inside. Cancelling gives it back.
- Each order keeps its own copy of the contents, so editing a package later does not change existing orders.
- If a cart's packages and single items together need more than is in stock, the customer is stopped before paying.

### Limits

- A package cannot contain another package, and a product inside a package cannot itself become one.
- Customers cannot swap items inside a package. Create another package for a different mix.
- Quantities inside a package are whole numbers.
- On WooCommerce, shop filters and "hide out of stock" do not see a package as out of stock when its contents run out. The package page and the buttons do show it, and it cannot be bought.
- On WooCommerce, two customers buying the last stock at the same instant could take a product below zero, because WooCommerce's stock hold does not cover package contents. The built-in store prevents this.

## Promo pop-up

Go to **Settings → Freshtastic Promo Pop-up**.

| Setting | Options |
|---|---|
| Where | Homepage only, or all pages. Cart, checkout and account pages never show it. |
| Who sees it | Everyone, visitors who are not signed in, or signed-in customers |
| How often | Once per visit, once a day, once a week, or every page load |
| Wait before showing | 0 to 60 seconds |
| Banners | Up to 3, each with an image, a description, a link and optional dates |

- **Preview saved banners** shows them to you even while the pop-up is off, and ignores dates and frequency.
- Changing the banners shows the new promo again to people who closed the old one.
- Banner dates use your site's time zone. A banner shows from the start of its first date until the end of its last.
- Always fill in **What the banner says**, because screen readers cannot read text inside an image.
- The image loads only when the pop-up opens, so it does not slow down the page.
- Use it sparingly. Search engines can penalise pop-ups that cover a phone screen as soon as the page opens, so keep a delay and a modest frequency.
