# Free-item coupons and the first customers promotion

Both work with WooCommerce and with the built-in store. They use normal coupons, so they survive switching between the two.

## Free-item coupon

Give a free product to customers who meet conditions you choose.

1. Open **Coupons** and add a coupon. Its title is the code customers type, for example `FREESALT`.
   - WooCommerce: Marketing → Coupons
   - Built-in store: Store → Coupons
2. In the **Free item** box, tick **This coupon gives a free item**.
3. Choose the product (each pack size is listed separately) and how many are free.
4. Set any conditions. An empty condition is skipped.

| Condition | Where it is set |
|---|---|
| Minimum number of items bought | Free item box |
| Must buy certain products or categories | Free item box |
| First order only | Free item box |
| Minimum order amount, expiry date, total uses | The coupon's normal fields |
| Only certain customers, by email | The coupon's normal fields: "Allowed emails" in WooCommerce, "Only these customers" in the built-in store |

**How it behaves**

- The customer must be signed in, and each account can use the coupon once. This is always on.
- The free item is added when the coupon is applied. The customer cannot change its quantity.
- If the conditions stop being met, the free item leaves the cart. The built-in store keeps the coupon and shows the reason. WooCommerce removes the coupon, so the customer has to apply it again.
- Stock is reduced for the free item. If it is out of stock, the coupon is refused.
- Saving clears any discount amount on the coupon, because the free item is the whole reward.
- A cancelled order gives the use back.

## First customers promotion

Reward the first customers who register, for example the first 100.

**Open it:** WooCommerce → First customers, or Store → First customers in the built-in store.

| Setting | What it does |
|---|---|
| Run this promotion | On or off |
| Name customers see | Used on the card, the banner and the email |
| Number of customers | How many spots there are |
| Starts on, Ends on | Optional dates |
| Reward | Percent off, fixed amount off, no delivery or pickup fee, or a free item |
| Minimum order | Optional |
| Only for these categories | Optional |
| Days to use it | Counted from registration. 0 means no expiry |
| Registration page | Shows the offer and the spots left on the registration form |

**How it behaves**

- Each customer who registers while spots are left gets a personal code such as `WELCOME-4DF65B5D`. It arrives by email and appears on their account page.
- The code works only on that customer's account, and only once. The cart and checkout offer a **Use on this order** button.
- A spot is taken at registration, whether or not the customer uses the reward. The limit holds even when many people register at once.
- Accounts created by staff or by import tools do not count. Only accounts with the Customer role count, so a default WordPress registration (Subscriber role) does not.
- Changing the settings affects only customers who register afterwards. Coupons already given keep their terms.
- The screen lists who has a spot, their code and whether it has been used (up to the latest 200).
- **Start a new round** resets the count to zero. Rewards already given stay valid, and customers who already have a spot do not get a second one.

## Limits

- **Fake accounts can take spots.** WordPress does not verify email addresses, so one person with several email addresses can register several times. Keep the reward modest, or add an email verification plugin, especially if the reward has real cost.
- **"Once per customer" means once per account.** A person with a second account can use a free-item coupon again. Use total uses or allowed emails to limit this.
- **The personal codes appear in the coupon list.** With 100 customers, that adds 100 coupons. The Reward column shows who each one is for.

## Showing a coupon on the homepage

Tick **Show this code on the homepage** in the coupon's **Homepage** box. The homepage coupons band lists it until it expires or reaches its usage limit, and the band stays hidden when no coupon is ticked. Personal first-customer codes and coupons limited to certain emails never appear.
