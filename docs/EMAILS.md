# Emails: sender, design and notification text

Everything is under **Settings → Freshtastic Emails** (toolkit 2.11.0). It works with the built-in store and with WooCommerce.

## Sender

| Setting | What it does |
|---|---|
| **From name** | The sender name customers see in their inbox. It replaces "WordPress" on every email the site sends. Empty uses your site title. |
| **From email** | Replaces `wordpress@yourdomain`. Use an address on your own domain, such as `noreply@freshtasticfoodhub.com.ph` or `orders@…`, or emails may land in spam. |
| **Reply-To** | Optional. Where replies go, for example your support inbox. Quotation emails keep their own Reply-To. |

If you use an SMTP plugin (WP Mail SMTP, FluentSMTP and so on) with its own sender name and address, the SMTP plugin's setting wins. Set the same values there, or turn off "force from" in that plugin.

## Design

Logo, header colour, button and link colour, background colour, text colour and footer (address, phone, hours; `{site_name}`, `{site_url}` and `{year}` work here). Empty colours follow the theme's Customizer colours, and an empty logo uses the site logo. Use a PNG or JPG logo, because some email apps do not show WebP or SVG.

**Apply to:**

- **WordPress's own emails and other plain-text emails.** Password reset, new user, password changed, email changed, and plugins that send plain text all get the same design, with clickable links. On by default.
- **WooCommerce emails.** WooCommerce's sender, header image, footer and colours follow these settings. On by default. A note on **WooCommerce → Settings → Emails** says so. The subject and text of each WooCommerce email are still edited there.

Click **Preview the design** after saving.

## Notifications

Each email has its own **Subject**, **Heading** and **Message**, grouped as *Emails to customers*, *Emails to the store* and *WordPress account emails*. Grey text shows the default. Leave a field empty to keep the default.

- **Placeholders** are listed under each email, for example `{first_name}`, `{order_number}`, `{customer_name}`, `{reference}`. `{site_name}`, `{site_url}` and `{year}` work everywhere. `{heading}` puts the heading in the subject.
- **Generated parts are always added after your message:** order details, bank details, the sign-in code, the Activate button, reward codes and quotation tables. Editing the text cannot remove them.
- **On/off:** most emails can be turned off. These are always sent because customers need them: sign-in code, account activation, password reset, your quotation, daily report (turned on or off under Analytics), and the admin note when someone resets a password.
- **WordPress account emails:** leave the message empty to keep WordPress's own text. If you write your own password reset or set-password message without `{link}`, the link is added at the end.
- **Basic formatting** is allowed in messages: bold, italics and links. Scripts are removed.
- **Preview** opens the email with sample details. **Send me a test** emails it to you.

| Group | Emails |
|---|---|
| Customers | Order received, Order being prepared, Order ready (store pickup / customer books a courier / delivery), Order complete, Order cancelled, Order refunded, Activate your account, Welcome, Sign-in code, First customers reward, Quotation request received, Your quotation |
| Store | New order, New quotation request, Daily report |
| WordPress | Password reset, New account: set your password, Your password was changed, Your email address was changed, New user registered (to the admin), Password reset by a user (to the admin) |

The order emails in the list are the built-in store's. On WooCommerce, order emails are WooCommerce's own, edited under **WooCommerce → Settings → Emails**, and they follow the sender and design here.

## For developers

- `Nexora_Toolkit_Emails::compose( $id, $vars )` returns the subject, heading and message HTML, or `null` when that email is turned off. `Nexora_Toolkit_Emails::send()` sends in the design.
- Add your own notification with the `nexora_email_notifications` filter.
