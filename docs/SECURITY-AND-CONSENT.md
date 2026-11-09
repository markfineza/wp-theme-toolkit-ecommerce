# Terms, reCAPTCHA, account activation and email login codes

Everything is under **Settings → Freshtastic Security**. It works with WooCommerce and with the built-in store.

## Set it up in this order

1. **Legal pages.** Click **Create draft pages**, fill in every `[Your ...]` blank, have a lawyer or your Data Protection Officer review the text, then publish both pages. Until they are published, customers still see the required box but with no links to read, and the Dashboard shows a warning.
2. **reCAPTCHA.** In the [reCAPTCHA admin console](https://www.google.com/recaptcha/admin), create a key for your domain: v2 "I'm not a robot" Checkbox, or v3. Choose the same **Key type** in the settings, then save the site key and secret key. A rejected secret is reported when you save.
3. **Email test.** Click **Send test code** and enter the code you receive. This unlocks the login code switches. Use an SMTP plugin if the code does not arrive.
4. **Login codes.** Tick **Administrators and staff** and **Customers**, then save. Sign in once in a private window to confirm, and keep your current window open while you do.

## Terms and consent

- Every way to create an account asks for a required, unticked box: My Account, checkout, and wp-login.php. Guest checkout is not affected.
- The default wording covers the Terms, the Privacy Policy, being 18 or older, and consent to data processing. You can change it; `{terms}` and `{privacy}` become links.
- **Marketing emails** are a separate, optional, unticked box. Ticking it adds the customer to your newsletter list. Customers can change this under **Account details**.
- Each decision is saved with the date, document version, IP address and place. See it at the bottom of the customer's **Users** screen. It is also included in **Tools → Export Personal Data**.
- When the terms really change, raise **Version of these documents**. Customers then see an **Accept** card on their account dashboard. The same card appears for customers who registered before this feature and for accounts created in the WooCommerce checkout block. It does not block them from using the site.

### What this does not do

The software collects and records consent. It cannot make a business legally compliant. The business still needs to check, with its lawyer or DPO:

- the final wording of both documents
- its obligations under the Data Privacy Act (Republic Act No. 10173), including a Data Protection Officer and any National Privacy Commission registration
- its obligations under the Internet Transactions Act (Republic Act No. 11967) and the Consumer Act (Republic Act No. 7394), including business details shown to shoppers
- how long records are kept

Turning marketing off removes the customer from the local newsletter list. If you use Mailchimp, Brevo or Klaviyo, also rely on the unsubscribe link in those emails, because the site does not remove people from those lists.

## reCAPTCHA

- Choose the **Key type** that matches your Google key. Google fixes the type when the key is created, and shows **"ERROR for site owner: Invalid key type"** when they do not match. The reCAPTCHA admin console shows it as *reCAPTCHA type*.
  - **v2 "I'm not a robot" Checkbox:** customers tick a box.
  - **v3 (score based):** nothing to tick. Google scores each sign-up from 0.0 (likely a bot) to 1.0 (likely a person), and sign-ups below **v3 minimum score** (default 0.5) are refused with "We could not confirm this sign-up". Lower the score if real customers are refused. Google shows its small reCAPTCHA badge in the corner of pages with the form.
- When switching type, paste **both** new keys. A blank secret keeps the old one, which will not match.
- Appears on the same forms as the consent box, and only after both keys are saved.
- If Google cannot be reached, the registration is refused with a request to try again. Sign-in never uses reCAPTCHA.
- Keep the secret out of the database by adding `define( 'NEXORA_RECAPTCHA_SECRET', '...' );` to `wp-config.php`.
- Accounts created in the WooCommerce checkout **block** do not show the box, because that checkout cannot carry it. Use the classic checkout under **Settings → Freshtastic Toolkit** if this matters to you.

## Account activation by email

On by default. Switch it under **Settings → Freshtastic Security → Account activation by email**.

- A new account from **My Account** registration or from **checkout** is **not signed in**. The customer sees "We sent an activation link to a\*\*\*@example.com" and gets an **Activate your account** email in place of the welcome email.
- The link works **once** and lasts **3 days**. Clicking it activates the account and opens the sign-in form with "Your account is now active. Please sign in." It does not sign the customer in by itself.
- Signing in before activating is refused with "Please activate your account first". With the right password, a **new link** is emailed, at most once every 5 minutes, and the old link stops working. A wrong password gets the normal error, so nothing is revealed to someone guessing.
- **Checkout:** the order still goes through and the receipt shows (by its order key, like a guest). The receipt tells the customer to check their email.
- **Resetting the password** through the emailed reset link also activates the account, since it proves the email address works.
- **By hand:** under **Users → Edit**, a pending account shows **Account activation** with **Activate now** and **Email a new activation link**. Activated accounts show the date.
- Not affected: accounts that existed before this feature, accounts created by administrators, and wp-login.php registration (WordPress already emails a set-password link there).
- On WooCommerce, WooCommerce's own "account created" email is still sent too. Only the on-screen message is replaced.
- Make sure your site can send email first (**Test email delivery** on the same screen). If emails do not arrive, new customers cannot sign in until you activate them by hand or switch this off.

## Login codes

- After a correct password, the customer or staff member gets a 6-digit code by email. No session starts until the code is entered.
- This covers wp-login.php, the WooCommerce forms and the built-in store forms, including sign-in at checkout.
- The code is entered on its own storefront page, `/verify-login/`, not on wp-login.php or in wp-admin. The theme file is `login-code.php`. Other themes, and the small "session expired" box in wp-admin, get a plain version of the page. On plain permalinks the address is `/?nx_login_code=1`.
- The page is not indexed or cached. During maintenance mode, someone part-way through signing in can still reach it. Other visitors get the maintenance page, unless maintenance covers only the shop.
- The code lasts 10 minutes by default (3 to 30), works once, and only works in the browser that entered the password.
- Limits: 5 wrong codes per sign-in, one new code per minute, 5 emails per sign-in, 10 emails per account per hour.
- Staff means every role except Customer and Subscriber.
- "Remember me" still works, so a remembered customer is not asked again until that session ends.
- Password logins over XML-RPC are refused while codes are on, because they cannot enter a code. Application passwords keep working.

### If you are locked out

Add this to `wp-config.php` with your hosting file manager:

```php
define( 'NEXORA_DISABLE_LOGIN_OTP', true );
```

Sign in, fix your email, then remove the line.

### Worth knowing

If the site cannot send email, people with codes turned on cannot sign in. Use a reliable SMTP service. A code by email protects against a stolen password, but not against someone who also controls the person's mailbox.
