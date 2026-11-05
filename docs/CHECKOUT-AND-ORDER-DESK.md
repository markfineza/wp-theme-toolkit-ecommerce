# Checkout, Pickup/Courier Delivery and the Order Desk

Version 2.1 of the Freshtastic Food Hub theme and the Freshtastic Food Hub Toolkit adds four things:

1. **A new checkout layout**: numbered step cards and a sticky order summary, replacing WooCommerce's standard two-column form.
2. **Two delivery options**: *Courier delivery (customer books the rider)* and *Store pickup*. Each lets the shopper choose a time.
3. **A "Ready for pickup" order status**: setting it emails the customer.
4. **Modern order screens for staff**: an "Order Desk" mode for people who only handle orders.

The account pages (sign in, profile, addresses and the account menu) were redesigned too.

---

## 1. Turn on the Freshtastic checkout (one click)

WooCommerce creates the checkout page with the **Checkout block**. The Freshtastic layout and the pickup/courier time fields need WooCommerce's **classic checkout**.

After you update the toolkit, a notice appears on the Dashboard, the Plugins screen and the WooCommerce screens:

> **Freshtastic checkout is not active yet** → **Use the Freshtastic checkout**

Clicking the button saves a backup of the current page and replaces its content with `[woocommerce_checkout]`.

- **Status and switching back:** **Settings → Freshtastic Toolkit → Checkout page** shows which checkout is active. Use **Restore the block checkout** there to go back.
- **From the command line:** call `Nexora_Toolkit_Fulfilment::set_checkout_mode( 'classic' )` or `( 'block' )` with `wp eval`.

If you keep the block checkout, both delivery options still appear, but customers cannot pick a time or a courier.

**Payment gateways:** PayMongo, Xendit, Maya, PayPal and Stripe all support the classic checkout.

## 2. Delivery options

Add them in **WooCommerce → Settings → Shipping → (your zone) → Add shipping method**.

### Courier delivery (customer books)

The store does not deliver. The customer books and pays a courier (Lalamove, Grab Express, Transportify, and so on), and the rider collects the order from you at the time the customer chose.

| Setting | What it does |
|---|---|
| Name at checkout | Default: "Courier delivery — you book the rider" |
| Couriers customers can choose | One per line. "Other / not sure yet" is always added. |
| Rider pickup address | Where riders collect. Empty = the store address in WooCommerce → Settings → General. |
| Store contact number for riders | Shown at checkout, on the order page and in emails. |
| Fee (₱) | Optional store charge (for example packing). Default 0. |
| Minimum order | Hides the option below this subtotal. |
| Preparation time (hours) | The earliest slot offered starts this many hours after ordering. |
| Days customers can book ahead | Default 6. |
| Closed on | Weekdays with no slots. |
| Time slots | One per line, 24-hour: `08:00-10:00` |
| Instructions at checkout | Optional note (gate, parking, and so on). |

**At checkout the customer sees:**

- the four steps of the process;
- the rider pickup address and contact number;
- a **Courier** dropdown;
- a **Rider pickup time** dropdown, grouped by day;
- a required tick box: *"I will book and pay for the courier myself. The store does not deliver this order."*

The customer's courier choice is remembered for their next order.

### Store pickup

| Setting | What it does |
|---|---|
| Pickup points | One per line: `Name \| Address \| Opening hours`. With more than one, customers choose. |
| Fee, minimum, preparation time, days ahead, closed days, slots, instructions | Same as above. |

**For pickup orders:**

- the customer chooses a pickup point and time;
- the billing address fields are hidden and not required;
- "Deliver to a different address" is hidden.

### How the time is checked

The time slot list is built on the server from the method's settings, and it is checked again when the order is placed. If a slot has expired while the customer was on the page, they get *"That time is no longer available. Please choose another."*

### Saved on the order

The choice is saved on the order as `_nexora_fulfilment`: type, date, slot, courier or pickup point, address, contact. Two extra meta fields, `_nexora_fulfilment_type` and `_nexora_fulfilment_date`, make the admin filters fast.

The choice appears:

- on the thank-you page and in **My Account → Orders**, as a card that says what happens next;
- in every WooCommerce email, as a "Pickup:" or "Courier:" row under Shipping;
- on the admin order screen.

### Payment suggestions

The demo importer enables **Pay at pickup (cash)**, which is WooCommerce's Cash on delivery limited to *Store pickup*, plus Bank transfer.

- For courier orders, use prepaid methods (GCash, Maya, cards or bank transfer). The rider shouldn't carry cash for you.
- To change this, edit **WooCommerce → Settings → Payments → Cash on delivery → Enable for shipping methods**.

## 3. "Ready for pickup" status and email

1. When an order is packed, set it to **Ready for pickup**. You can do this:
   - from the edit screen;
   - with the green shop icon in the order list;
   - or with the bulk action *Change status to ready for pickup*.
2. The customer receives **Order ready for pickup**. You can edit or turn it off in **WooCommerce → Settings → Emails**.
   - Pickup orders: "ready to collect, bring your order number".
   - Courier orders: "book your rider now", with the pickup address and contact.
3. When the customer or rider has collected the order, click **Complete** (✓). The usual *Completed order* email is sent.

"Ready for pickup" counts as a paid status in reports and analytics. To change the email design, copy `nexora-commerce-toolkit/templates/emails/customer-order-ready.php` to `yourtheme/woocommerce/emails/`.

## 4. Order screens

These apply to **WooCommerce → Orders**, with HPOS or legacy order storage. Turn them on or off in **Settings → Freshtastic Toolkit → Orders and checkout → Modern order screens**.

- **Summary tiles** (refreshed every 2 minutes, and whenever an order changes):

  | Tile | What it counts |
  |---|---|
  | To pack | Processing orders |
  | Ready for pickup | Orders with that status |
  | Scheduled today | Orders whose pickup or rider time is today |
  | On hold | On-hold orders |
  | Pending payment | Pending orders |
  | Sales today | Paid orders, net of refunds. Shown only to managers. |

  Each tile opens the filtered list.
- **Fulfilment column**: a Pickup or Courier badge with the time, plus the pickup point or courier. Past times that still haven't been collected are marked **overdue** in red.
- **Filters**:
  - *All fulfilment / Store pickup / Courier*;
  - *Scheduled today / tomorrow / Overdue*.
- **Status pills, segmented status tabs and card-style tables.** Press **/** to jump to search.
- **Edit order**:
  - an **order progress bar** (Placed → Paid → Ready for pickup → Completed, with times);
  - a **Fulfilment** box with the customer's mobile number and a **Reschedule** form (date, slot, courier). Rescheduling adds an order note.

### Order Desk mode (staff who only handle orders)

1. Create the user in **Users → Add New** with **Role: Order Desk**. The role can view and edit orders and quotations, and nothing else.
2. When they log in:
   - they go straight to the order list, and the Dashboard redirects there too;
   - the menu shows only **Orders**, **Quotations** and **Profile**, under your *Order Desk name*;
   - the admin has a dark, branded look, without the WordPress logo, update notices or version number;
   - sales totals are hidden.
3. Turn it off, or rename it, in **Settings → Freshtastic Toolkit → Orders and checkout**.

Shop Managers and Administrators keep the full admin, with the modern order screens.

## 5. Account pages (theme)

| Page | New design |
|---|---|
| Sign in / Create account | Brand panel plus a card. When registration is enabled, *Sign in* and *Create account* become keyboard-accessible tabs. Without JavaScript, both forms are shown. |
| Account menu | Profile card (initials avatar, name, email) and icon links. Scrolls sideways on phones. |
| Account details | Profile header ("Member since …"), then *Personal details* and *Password* sections. Password fields have show/hide buttons. |
| Addresses | One card per address with Add/Edit; the edit form has *All addresses* and *Cancel* links. |
| Thank-you page | Confirmation header, order facts, the pickup/courier card, and *Track this order*. |

**Templates overridden by the theme:**

- `woocommerce/checkout/`: `form-checkout.php`, `review-order.php`, `form-shipping.php`, `thankyou.php`
- `woocommerce/myaccount/`: `navigation.php`, `form-login.php`, `form-edit-account.php`, `form-edit-address.php`, `my-address.php`

Every WooCommerce hook is kept, so payment, analytics and checkout-field plugins keep working.

**Other theme changes:**

- Mobile number and email now sit next to each other at the top of checkout.
- The phone label is "Mobile number".
- The importer makes the mobile number **required**, because riders and pickup staff need to call.

## 6. Free-shipping bar

The bar now appears only when a **Free shipping** method is enabled in a shipping zone. With pickup and customer-booked couriers only, it stays hidden, so customers never see a free-delivery promise you don't offer.

The default announcement text changed to *"Order online, then pick up at the market or book your own rider · Choose your time at checkout"*. If you saved your own text in the Customizer, update it there.
