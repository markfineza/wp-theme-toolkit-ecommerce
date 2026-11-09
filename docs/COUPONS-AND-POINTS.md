# Customer coupons and points

Toolkit 2.9 with theme 2.8. Both work on the built-in store and WooCommerce.

## Coupons (My account → Coupons)

Customers can:

- **Claim a code** by typing it, here or in the "Your rewards" box on the dashboard.
- **Claim an offer** you have listed. To list one, edit the coupon and tick **List as an offer customers can claim** in the **Customer accounts** box. The code stays hidden until they claim it.
- See what is **ready to use**, copy the code, or apply it to their cart in one click.
- See their **claim history**: code, reward, date, where it came from, and status (Ready to use, Used, Expired, Fully used, or No longer available).

The first-customers welcome reward appears in the wallet by itself.

### Good to know

- Claiming saves a coupon to the account. It does **not** reserve a use, so a coupon with a usage limit can still run out after being claimed.
- A customer can still type a code at the cart without claiming it. The coupon's own rules are checked either way.
- A coupon for another customer or email address reads as "not valid", so this form never reveals it exists.
- 10 wrong codes in 15 minutes pauses claiming for that customer, so codes cannot be guessed here.
- Each coupon's screen shows **Claimed by**, a count of customers.

## Points (My account → Points)

This is the customer-facing page only. **Nothing awards points yet**, so customers see a balance of 0 and "Ways to earn and use your points are coming soon."

To hide the page until points go live, untick **Points page in customer accounts** under **Settings → Freshtastic Toolkit**. It is on by default.

For the future feature, call this from code. It updates the balance and history, and refuses to go below zero:

```php
Nexora_Toolkit_Points::add( $user_id, 50, 'Order #1024' );  // Add points. The note is shown to the customer.
Nexora_Toolkit_Points::add( $user_id, -20, 'Used on order #1030' );  // Remove points.
Nexora_Toolkit_Points::balance( $user_id );
```

Balances are stored per customer without database locking. Add locking before using points as money, so two orders at once cannot spend the same points.
