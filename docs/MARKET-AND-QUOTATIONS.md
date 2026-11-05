# Market features and quotation requests

Freshtastic Food Hub 2.0 turns the storefront into a wet and dry market with a trading-floor look: a live price ticker, a price board, price-change arrows and per-kilo comparisons. Shoppers can **request a quotation** for anything that isn't posted, is out of stock, or is needed in bulk.

These features need the **Freshtastic Food Hub Toolkit** plugin (2.0+). Without it the theme still works as a normal store; the market and quotation UI simply hides.

## 1. Setting up a market product

Edit a product → **Product data → General**:

| Field | What it does | Example |
|---|---|---|
| **Selling unit** | Shown after the price: "₱280 / kg". The cart quantity counts this unit. | per kg, per pack, per tray, per bundle |
| **Origin** | Shown on cards, the price board and the product page. | Benguet |
| **Compare price per** | Turns on the *price per kg / L* table and "From ₱49/kg" lines. | kg |
| **Pack contents** | For simple products sold in packs: how many kg/L are in one. | 2 (for a 2 kg pack) |

For **variable products** (e.g. 1 kg / 5 kg / 25 kg sack), open each variation and fill **Pack contents (kg or L)**. The product page then shows a table like this, with the best value highlighted:

| Pack | Price | Per kg |
|---|---|---|
| 50 kg sack | ₱2,450 | ₱49.00 ✓ Best value |
| 25 kg sack | ₱1,250 | ₱50.00 |
| 1 kg | ₱52 | ₱52.00 |

**Per-kilo items:** set *Selling unit = per kg* and price the product per kilo. A customer ordering quantity 3 buys 3 kg. Explain in your delivery policy how you handle natural weight differences (the demo says short weights are refunded).

## 2. Price history, arrows and the ticker

- Every time a product's selling price changes (manual edit, sale start or end, import), the toolkit **records the new price with a date**. For pack products, it records the lowest price per kg or litre, so different pack sizes compare like for like.
- **▲ / ▼ arrows** show the change since that product's **previous recorded price**. The tooltip shows the date of the latest update. Products with only one recorded price show no arrow.
- The **ticker** under the header and the **"Today's movers" board** in the hero list the products with the biggest recent changes, falling back to best sellers.
- The **price board** (homepage section "Today's price board", anchor `#price-board`) lists movers and best sellers with price, change, a trend sparkline, stock left and a quick action: *Add*, *Options*, or *Quote* when out of stock. Category tabs filter the board.
- The demo importer seeds one illustrative previous price for 14 demo products, so the arrows have something to show. Real products build their own history as you update prices.

These are **your store's selling prices**, not official price monitoring. The board text says so; keep it that way unless you publish verified market data.

**Market hours:** Customize → Freshtastic Theme → Header → *Market opens / closes* (24-hour time, site time zone). The ticker and hero board show *Market open · closes 8:00 PM* or *Market closed · opens 4:00 AM*, computed in the browser so it stays correct on cached pages. Leave the fields empty to hide the status.

## 3. Request for Quotation (RFQ)

### Where shoppers see it

| Place | Behaviour |
|---|---|
| Header | **Request a Quote** button (icon-only on small screens). |
| Search with no results / empty category | The quotation form appears inline, prefilled with the search term. |
| Out-of-stock product | The add-to-cart area becomes *Currently unavailable → Request a quotation* (also in the sticky bar). |
| In-stock product | "Buying in bulk or for your business? Request a volume quotation." |
| Product cards and the price board | Out-of-stock items show **Request quote** instead of a dead end. |
| Homepage | "Can't find it on the board?" banner with a 3-step explainer, and a *Not on the list?* tile in Market sections. |
| Page | **Request a Quote** (`/request-a-quote/`) with the full form (`[nexora_rfq_form]`). |

### The form

Item, category, quantity and unit (kg, 25/50 kg sack, tray, pack, piece, bundle, box, liter), frequency (one-time/weekly/monthly), optional target price per unit, needed-by date, delivery city, name, business name, email, mobile number, notes and a required consent checkbox. Links can prefill it: `/request-a-quote/?rfq_item=Native%20garlic`.

Spam protection uses a honeypot field and a limit of 4 requests per IP per 10 minutes (stored as a salted hash).

### What happens next

1. The request is saved privately with a reference number (**RFQ-00123**).
2. The store gets an email (set the address in **Settings → Freshtastic Toolkit → Send quotation requests to**; defaults to the admin email). Replying to that email replies to the customer.
3. The customer gets a confirmation email with the reference and their details.
4. Logged-in customers can follow requests under **My Account → Quotations**.

### Replying with a quote

**WooCommerce → Quotations** → open a request:

- Set the **Status**: New, Sourcing, Quoted, Accepted or Closed.
- Enter **Quoted price per unit**, **Quoted total incl. delivery**, **Valid until** and a **Message**.
- Tick **Email this quotation to the customer on save** and click **Update**. The customer receives a branded email, the status moves to *Quoted*, and the send is logged on the request.

When the customer accepts, create the order in **WooCommerce → Orders → Add order** (or add the item as a product so they can check out), then set the request to *Accepted*.

**Privacy:** requests are included in **Tools → Export/Erase Personal Data**, and consent is timestamped on each request.

## 4. Customizer settings added in 2.0

| Where | Setting |
|---|---|
| Freshtastic Theme → Header | Show "Request a Quote" button · Show price ticker · Ticker items · Market opens / closes |
| Freshtastic Homepage → Hero | Market board title |
| Freshtastic Homepage → Product sections | Price board title and number of items |
| Freshtastic Homepage → Benefits, newsletter and banners | Quotation banner title and copy |
| Freshtastic Homepage → Sections and order | New sections: **Price board**, **Request a quotation banner** |

## 5. Developer reference

| Filter / action | Purpose |
|---|---|
| `nexora_market_data( $data, $product )` | Unit, origin, change, history, per-kg data used by the theme. |
| `nexora_unit_prices( $rows, $product )` | Rows for the price-per-kg table. |
| `nexora_market_movers( $list, $limit )` | Ticker and board products. |
| `nexora_market_units` | Selling units offered in the product editor. |
| `nexora_rfq_units` | Quantity units on the quotation form. |
| `nexora_rfq_enabled`, `nexora_rfq_url( $url, $item )` | Theme integration. |
| `nexora_rfq_submitted( $id, $data )` (action) | Push requests to a CRM, Slack and so on. |
| JS event `nexora:rfq_submitted` | Fires after a successful request; the toolkit sends `generate_lead` to analytics. |

Meta keys: product `_nexora_unit`, `_nexora_origin`, `_nexora_measure_unit`, `_nexora_measure`, `_nexora_price_history` (array of `{t, p}`); requests use post type `nexora_rfq` with `_rfq_*` meta.
