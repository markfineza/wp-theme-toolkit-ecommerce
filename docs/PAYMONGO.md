# Online payments with PayMongo (built-in store)

**Store → PayMongo** (toolkit 2.13.0) lets customers pay online at checkout with **GCash, Maya, credit or debit cards, QR Ph**, and optionally GrabPay, ShopeePay, BillEase and online banking (BPI, UnionBank, BDO, Landbank, Metrobank). It works with the built-in store, without WooCommerce. If you use the WooCommerce engine, use PayMongo's own WooCommerce plugin instead.

## How it works for the customer

1. At checkout the customer chooses the PayMongo option (shown as **GCash, Maya, card or QR Ph** unless you rename it) and clicks **Place order**.
2. They are sent to PayMongo's secure payment page, pay there, and come back to your order page, which shows **Payment received**.
3. They get the "Order being prepared" email, and you get the "New order" email. No email is sent before they pay.

If they leave the payment page without paying, the order page says **Payment not completed yet**, with a **Pay ₱… now** button and the time until which the items are kept for them. After that time (60 minutes by default) the order is cancelled and the stock goes back.

Card details never touch your site. PayMongo handles them.

## How the payment is confirmed

- **Webhook (main way).** PayMongo calls your site when the payment succeeds. The call is checked against the webhook's secret signature, and the payment is then read again from PayMongo with your secret key before the order is marked paid. This works even if the customer closes the tab after paying.
- **Customer returns.** When the customer comes back, the site asks PayMongo directly. A forged "return" link cannot mark an order paid.
- **Check payment now.** This button on the order screen asks PayMongo for the payment status by hand.
- **Before cancelling an unpaid order,** the site checks PayMongo once more.

When an order is paid, it moves to **Processing**, the PayMongo payment ID (`pay_…`) is saved as the payment reference, and the method appears in the order (for example "GCash" or "Visa •••• 4242"). If the amount paid does not match the order total, the order goes **On hold** with a note.

## Set up (step by step)

The same steps, with links, are on the **Store → PayMongo** page.

1. **Sign up** at [dashboard.paymongo.com](https://dashboard.paymongo.com/signup) and complete the business verification. PayMongo must approve it before live payments work.
2. In the [PayMongo dashboard → Developers](https://dashboard.paymongo.com/developers), copy the **test secret key** (`sk_test_…`).
3. In WordPress go to **Store → PayMongo**:
   - Tick **Enable**, choose **Test**, and paste the test secret key.
   - Tick the payment methods your PayMongo account has activated (dashboard → Settings → Payment methods). If you tick a method that is not activated, checkout shows an error.
   - Save.
4. **On your live site (https)**, click **Connect webhook (test mode)**. It creates the webhook in PayMongo for `checkout_session.payment.paid` and `payment.refunded`, and saves its secret. PayMongo does not send webhooks to `http://` or local addresses.
   - **Webhook already exists:** if PayMongo says this, open it in the dashboard (Developers → Webhooks), copy its secret key (`whsk_…`) and paste it into **Test webhook secret**.
5. **Test.** Place an order and pay on PayMongo's test page (it offers test GCash/Maya flows and test cards). The order should turn **Processing**. Then try a refund from the order screen.
6. **Go live.** When PayMongo activates your account:
   - Paste the **live secret key** (`sk_live_…`), choose **Live** and save.
   - Click **Connect webhook (live mode)**. Live mode has its own webhook and secret.

For extra safety, put the keys in `wp-config.php` instead of saving them in the database:

```php
define( 'NEXORA_PAYMONGO_LIVE_SECRET', 'sk_live_…' );
define( 'NEXORA_PAYMONGO_LIVE_WEBHOOK_SECRET', 'whsk_…' );
// Test keys: NEXORA_PAYMONGO_TEST_SECRET, NEXORA_PAYMONGO_TEST_WEBHOOK_SECRET
```

Saved keys are never shown again on the settings page and are only sent to api.paymongo.com. Never share your secret key. If it leaks, roll it in the PayMongo dashboard.

## Settings

| Setting | What it does |
|---|---|
| Name at checkout | The option's name, for example "GCash, Maya, card or QR Ph". |
| Payment methods | Which methods PayMongo's page offers. |
| Time to pay | Minutes an unpaid order keeps its items (default 60, 0 = until you cancel it). |
| Receipt | PayMongo also emails its own payment receipt to the customer. |

## Refunds

On a paid order, **Refund through PayMongo** (on the order screen) sends money back to the customer: the full amount or part of it, with a reason. Only store managers (not the Order Desk role) see it.

- **Full refund:** with the box ticked, the order is marked **Refunded**, the stock goes back and the customer gets the refund email.
- **Partial refund:** it is recorded in the order history and the order status stays as it is.
- **Refunds made in the PayMongo dashboard** are added to the order history automatically, through the webhook.

Changing the status to **Refunded** by hand does not send money. Use the refund form for that.

## Good to know

- **Minimum amount:** PayMongo has a minimum charge per payment method (see PayMongo's pricing and docs). If an order is below it, the customer sees "We could not open the payment page", and the reason is written in the order history.
- **Fees:** PayMongo's fees differ by method and are deducted from your payouts. Check your PayMongo pricing.
- **Scheduled tasks:** cancelling unpaid orders runs on WordPress's scheduled tasks. On a quiet site, set up a real server cron job so orders are cancelled on time.
- **Developers:** `nexora_paymongo_session` filters the checkout session sent to PayMongo. `nexora_paymongo_paid` fires when an order is paid.
