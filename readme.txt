=== Paradox CardPointe Gateway ===
Contributors: exgbrian
Tags: woocommerce, payment gateway, cardpointe, credit card, ach
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept credit cards and eChecks through CardPointe. Tokenized checkout, refunds, saved cards, Subscriptions, Pre-Orders and block checkout.

== Description ==

Paradox CardPointe Gateway, by [Paradox Solutions](https://paradoxsolutions.io), connects your WooCommerce store to the CardPointe (Fiserv / CardConnect) gateway.

**Easy install.** Upload the plugin, enter your API credentials, flip sandbox mode off when you are ready, and start taking payments.

**Secure payments.** Card and bank details are entered in CardPointe's [Hosted iFrame Tokenizer](https://developer.cardpointe.com/hosted-iframe-tokenizer) and tokenized by [CardSecure](https://developer.cardpointe.com/guides/cardsecure). The card number, expiry and CVV never touch your server, which keeps you in the smallest PCI scope (SAQ A).

**Authorize only or instant capture.** Charge immediately, or authorize at checkout and capture later from the order screen (full or partial), by changing the order status, or automatically.

**Enable card types.** Choose which brands you accept; others are rejected before any charge is attempted, using CardPointe's BIN service.

**Refund via dashboard.** Full or partial refunds from the WooCommerce order screen. Unsettled transactions are voided automatically instead of refunded.

**Gateway receipts.** Optionally request CardPointe receipt data and show it on the order received page, in My Account and in customer emails.

**Logging.** Redacted request/response logging to the WooCommerce log for easy debugging. Card numbers, CVVs and passwords are never written.

**WooCommerce Subscriptions.** Recurring payments with merchant-initiated card-on-file transactions, free trials, payment method changes, admin payment meta and multiple subscriptions per order.

**WooCommerce Pre-Orders.** Payment methods are verified and vaulted at checkout and charged automatically when the pre-order is released.

**eCheck via ACH.** A separate "eCheck" payment method tokenizes routing and account numbers in the hosted iframe and processes them through the ACH network.

**Save cards on file.** Customers can save cards and bank accounts for faster checkout. Details are stored in CardPointe profiles in the CardSecure vault, never on your site.

**Block checkout ready.** Works with the classic shortcode checkout and the WooCommerce Cart & Checkout blocks, and is compatible with High-Performance Order Storage.

= External services =

This plugin sends payment requests from your server to the CardPointe Gateway API at `https://<site>.cardconnect.com/cardconnect/rest/` (or `https://<site>-uat.cardconnect.com/` in sandbox mode) and embeds the CardPointe Hosted iFrame Tokenizer from the same host on checkout pages. Data sent includes the tokenized account, transaction amount, order reference and billing details. Use of the service is subject to the [Fiserv terms of service](https://www.fiserv.com/en/about-fiserv/legal.html) and [privacy policy](https://www.fiserv.com/en/about-fiserv/privacy-notice.html). You need a CardPointe merchant account and API credentials from Fiserv Integration Delivery.

== Installation ==

1. Upload the `paradox-cardpointe-gateway` folder to `/wp-content/plugins/` or install the ZIP from Plugins > Add New.
2. Activate the plugin.
3. Go to WooCommerce > Settings > Payments > CardPointe - Credit Card.
4. Enter your sandbox and production site name, merchant ID, API username and API password. Click "Test connection".
5. Enable the gateway and choose Charge or Authorize only.
6. Optionally enable CardPointe - eCheck (ACH) if your merchant ID supports ACH.
7. Test with sandbox mode on, then turn it off to go live. Production mode requires HTTPS at checkout.

== Frequently Asked Questions ==

= Where do I get credentials? =

From Fiserv Integration Delivery (integrationdelivery@fiserv.com) when you board with CardPointe. You receive a site name, a merchant ID and an API username/password for UAT (sandbox) and production.

= Which card can I use for testing? =

In sandbox mode use 4111 1111 1111 1111 with any future expiry and CVV. Amounts between $1000 and $1999 produce specific response codes (the last three digits of the whole-dollar amount). Use ABA 036001808 with any account number for ACH tests.

= Does my site need HTTPS? =

Yes. In production mode the payment methods are hidden until checkout is served over HTTPS.

= What is stored on my site? =

The CardPointe retrieval reference, authorization code, last four digits, card brand or account type, and when a method is saved, the CardPointe profile and account IDs. No card numbers, expiry dates, CVVs or full bank account numbers are stored.

= Why can't I partially refund an order today? =

CardPointe can only void unsettled transactions in full. Partial refunds work after settlement (usually the next business day). A full refund of an unsettled transaction is processed as a void.

= Can I set the API password outside the database? =

Yes. Define `PARADOX_CARDPOINTE_PRODUCTION_API_PASSWORD` and/or `PARADOX_CARDPOINTE_SANDBOX_API_PASSWORD` in wp-config.php.

== External services ==

This plugin connects your store to the CardPointe payment gateway, operated by Fiserv (CardConnect), in order to process payments. Using the plugin requires a CardPointe merchant account. Nothing is sent until you enter API credentials and enable a payment method.

Both endpoints below use the site name you configure in the plugin settings: `{site}.cardconnect.com` in production, or `{site}-uat.cardconnect.com` in sandbox mode.

**1. Hosted iFrame Tokenizer** (`https://{site}.cardconnect.com/itoke/ajax-tokenizer.html`)

Loaded in an iframe on the checkout page, in the customer's browser, whenever a customer views a checkout with one of this plugin's payment methods available. The customer types the card number, expiry date and security code, or the bank routing and account number, directly into that iframe. Those details go from the customer's browser to Fiserv, which returns a token. They never pass through, and are never stored on, your server.

**2. CardPointe Gateway REST API** (`https://{site}.cardconnect.com/cardconnect/rest/`)

Called from your server when: a payment is authorized, captured, voided or refunded; a saved payment method is created, looked up or deleted; a card brand is verified against the BIN service; a transaction's settlement status is checked; or you click "Test connection" on the settings screen.

These requests contain the token returned by the tokenizer, the payment amount and currency, your merchant ID, an order reference, and the billing name, company, address, phone number and email address from the order.

Fiserv terms of use: https://www.fiserv.com/en/about-fiserv/terms-of-use.html
Fiserv privacy notice: https://www.fiserv.com/en/about-fiserv/privacy-notice.html
CardPointe developer documentation: https://developer.cardpointe.com/

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
