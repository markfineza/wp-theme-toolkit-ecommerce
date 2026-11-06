# Terms, reCAPTCHA and email login codes

Everything is under **Settings → Freshtastic Security**. It works with WooCommerce and with the built-in store.

## Set it up in this order

1. **Legal pages.** Click **Create draft pages**, fill in every `[Your ...]` blank, have a lawyer or your Data Protection Officer review the text, then publish both pages. Until they are published, customers still see the required box but with no links to read, and the Dashboard shows a warning.
2. **reCAPTCHA.** In the [reCAPTCHA admin console](https://www.google.com/recaptcha/admin), create a v2 "I'm not a robot" Checkbox key for your domain. Save the site key and secret key. A rejected secret is reported when you save.
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

- Appears on the same forms as the consent box, and only after both keys are saved.
- If Google cannot be reached, the registration is refused with a request to try again. Sign-in never uses reCAPTCHA.
- Keep the secret out of the database by adding `define( 'NEXORA_RECAPTCHA_SECRET', '...' );` to `wp-config.php`.
- Accounts created in the WooCommerce checkout **block** do not show the box, because that checkout cannot carry it. Use the classic checkout under **Settings → Freshtastic Toolkit** if this matters to you.

## Login codes

- After a correct password, the customer or staff member gets a 6-digit code by email. No session starts until the code is entered.
- This covers wp-login.php, the WooCommerce forms and the built-in store forms, including sign-in at checkout.
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
