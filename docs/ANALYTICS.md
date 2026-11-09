# Analytics and daily reports

Toolkit 2.8. Find it under **Store → Analytics** on the built-in store, or **WooCommerce → Freshtastic Analytics** with WooCommerce. Administrators and shop managers can open it. Order Desk staff cannot, because it shows the store's money.

## What each tab shows

| Tab | Shows |
|---|---|
| **Sales** | Total and net sales, orders, average order, items sold, customers, discounts and refunds, each compared with the previous period. A chart over time, payment methods, pickup and delivery, and orders by status. |
| **Products** | Units, orders, sales, rating and stock per product. Sales by category, low and sold-out stock, and published products with no sales. |
| **Reviews** | New reviews, average rating, reviews waiting for approval and low ratings. Star breakdown, products by rating and the latest reviews, with Approve and Edit links. |
| **Daily report** | One day compared with the day before and the same day last week. Sales by hour, top products, stock, a list of the last 14 days and the email settings. |

Each tab has **Download CSV** and **Print**. Periods run from today to a full year, or any custom dates up to 366 days.

## How the figures work

- **Sales** count every order except pending payment, cancelled, failed and refunded. This is the same rule WooCommerce Analytics uses by default.
- Cash on delivery and bank transfer orders count before the money arrives, so sales are not the same as cash received.
- **Total sales** include delivery fees and tax. **Net sales** leave them out. Both are after discounts and refunds.
- Orders and refunds count on the date the order was **placed**, in the timezone under **Settings → General**. Set this to Manila, or days will split at the wrong hour.
- A running period is compared with the same amount of time in the earlier period, so a part-finished day is not measured against a whole one.
- **Product sales** are before order-wide discounts, so they can add up to more than net sales. A package counts as one product.
- **Stock** is always today's stock, whatever dates are chosen.
- Reports are worked out from live orders. If an order is cancelled later, past reports change to match.

The Store dashboard's "Placed today" and "Last 30 days" tiles include pending payment orders, so they can read higher than Analytics.

## Daily email

On the **Daily report** tab, tick **Send yesterday's report by email each morning**, add up to 10 addresses and choose a time. The email has totals and product names, with no customer details.

- WordPress sends it on the first visit after the set time, so a quiet site can send it late. For an exact time, set up a real cron job with your host.
- If the email fails, it tries again every hour and shows the problem on the screen.
- If WordPress misses several days, only the latest day is sent. **Email the report now** sends any day by hand.
