# Philippine store configuration

The importer's optional **Apply Philippine store settings** step sets most of this for you. Everything below can also be done by hand in **WooCommerce → Settings**.

## General

| Setting | Value |
|---|---|
| Store address → Country / State | Philippines — Metro Manila (or your province) |
| Selling location(s) | Sell to specific countries → Philippines |
| Shipping location(s) | Ship to all countries you sell to |
| Currency | Philippine peso (₱) |
| Currency position | Left (`₱2,499.00`) |
| Thousand / decimal separator | `,` / `.` |
| Number of decimals | 2 |

**Taxes:** VAT-registered sellers usually show VAT-inclusive prices. Enable taxes, set *Prices entered with tax: Yes*, and add a 12% standard rate for PH. Confirm the treatment with your accountant; the theme displays whatever WooCommerce calculates.

## Shipping

The demo zone **Philippines (Freshtastic demo)** contains:

1. **Store pickup** — two demo pickup points, customer picks a time slot.
2. **Courier delivery (customer books)** — the customer books and pays Lalamove, Grab Express or another courier; the rider collects at the chosen time.

Both are free by default and configurable per zone; see [CHECKOUT-AND-ORDER-DESK.md](CHECKOUT-AND-ORDER-DESK.md). Existing stores that already have the older demo zone keep their methods; re-running the settings step only adds the two new ones.

If you also deliver yourself, add *Flat rate* or *Free shipping* to the zone, or split zones by region (Metro Manila, Luzon, Visayas, Mindanao) using postcode or state matching.

**Free-shipping bar:** it only appears when a *Free shipping* method is enabled. Its threshold is **Customize → Freshtastic Theme → Shop and product pages → Free-shipping threshold** (display only — set it to the same amount as the method's minimum).

## Payments

The theme never fakes a payment method. The footer and product page list only gateways that are **enabled** in WooCommerce (or a manual list you choose).

| Method | How to enable |
|---|---|
| Cash on delivery | Built in. Enabled by the demo settings step. |
| Bank transfer (BACS) | Built in. Enter real account details before going live; the demo text says it is a demo. |
| GCash, Maya, cards, GrabPay, online banking | Install a gateway plugin from a payment provider that supports the Philippines (for example PayMongo, Xendit, Maya Business or 2C2P), create a merchant account, complete their verification, then enter API keys in that plugin's settings. Test in sandbox mode first. |

Gateway availability, fees and onboarding are set by each provider. Check their current documentation.

## Checkout

- New stores use the **Cart and Checkout blocks**; the theme styles both the blocks and the classic shortcodes.
- **Guest checkout:** WooCommerce → Settings → Accounts & Privacy.
- **Terms and privacy:** set your Terms page (WooCommerce → Settings → Advanced) and Privacy page (Settings → Privacy). The demo Terms page is a placeholder that must be replaced.
- Phone numbers: Philippine mobile numbers (`09XX XXX XXXX`) are accepted by the default phone field.

## Legal reminders

This is not legal advice. Review your policies against the Consumer Act (RA 7394), the E-Commerce Act (RA 8792), the Data Privacy Act (RA 10173) and current DTI guidance for online sellers.
