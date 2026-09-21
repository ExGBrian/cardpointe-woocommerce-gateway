=== Paradox CardPointe Gateway for WooCommerce ===
Contributors: exgbrian
Tags: woocommerce, payment gateway, cardpointe, credit card, ach
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept credit cards and eChecks through CardPointe. Tokenized checkout, refunds, saved cards, Subscriptions, Pre-Orders and block checkout.

== Description ==

Paradox CardPointe Gateway for WooCommerce, by [Paradox Solutions](https://paradoxsolutions.io), connects your WooCommerce store to the CardPointe (Fiserv / CardConnect) gateway.

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

**Apple Pay.** Express buttons on single product pages, the cart and the top of the checkout, plus a button inside the credit card box, each of which you can switch on or off. Works on classic pages, the Cart and Checkout blocks and CheckoutWC. In Safari the shopper pays with Touch ID or Face ID; in Chrome, Edge and Firefox they scan a code with their iPhone. Express buttons collect the address in the Apple Pay sheet and show shipping and tax as they are chosen. The encrypted Apple Pay token is decrypted by CardSecure, never on your server.

**Save cards on file.** Customers can save cards and bank accounts for faster checkout. Details are stored in CardPointe profiles in the CardSecure vault, never on your site.

**Block checkout ready.** Works with the classic shortcode checkout and the WooCommerce Cart & Checkout blocks, and is compatible with High-Performance Order Storage.

= External services =

This plugin sends payment requests from your server to the CardPointe Gateway API at `https://<site>.cardconnect.com/cardconnect/rest/` (or `https://<site>-uat.cardconnect.com/` in sandbox mode) and embeds the CardPointe Hosted iFrame Tokenizer from the same host on checkout pages. Data sent includes the tokenized account, transaction amount, order reference and billing details. Use of the service is subject to the [Fiserv terms of service](https://www.fiserv.com/en/about-fiserv/legal.html) and [privacy policy](https://www.fiserv.com/en/about-fiserv/privacy-notice.html). You need a CardPointe merchant account and API credentials from Fiserv Integration Delivery.

== Installation ==

1. Upload the `paradox-cardpointe-gateway-for-woocommerce` folder to `/wp-content/plugins/` or install the ZIP from Plugins > Add New.
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

= Where does the Apple Pay button appear? =

Wherever you allow it under "Allow Apple Pay on": single products (simple and variable), the cart, the top of the checkout, and inside the credit card box. The first three are express buttons: the Apple Pay sheet collects the contact details and address, shipping methods and tax update as the shopper chooses, and the order is placed straight from the sheet. On a product page the item is added to the cart first, so anything already in the cart is part of the order and is listed in the sheet; if the shopper cancels, the cart is put back as it was. The button inside the credit card box uses the address already entered on the checkout form.

Apple Pay is not offered when the order has to keep a card on file (subscriptions, pre-orders charged on release), and express buttons are hidden from signed-out shoppers on stores that do not allow guest checkout.

= Does Apple Pay work in Chrome? =

Yes, with "Other Browsers" switched on. Outside Safari, Apple shows a code that the shopper scans with an iPhone running iOS 18 or later and approves there. This uses Apple's JavaScript SDK, which is then loaded from Apple on the pages that show a button. With the setting off, the button only appears in Safari and nothing is loaded from Apple.

= How do I set up Apple Pay? =

1. Email integrationdelivery@fiserv.com and ask for an Apple Pay Payment Processing Certificate CSR for your merchant ID (allow up to 5 business days). In the Apple Developer portal, create a Merchant ID, create a Payment Processing Certificate from that CSR, and send the resulting .cer file back to your Fiserv representative. This is what lets CardSecure decrypt Apple Pay payments.
2. Under the same Merchant ID, create a Merchant Identity Certificate. Put the certificate and its private key into one PEM file with no passphrase. From a .p12 exported out of Keychain Access: `openssl pkcs12 -in merchant_id.p12 -out certificates.pem -nodes`.
3. Register your store's domain under the Merchant ID, upload the verification file Apple gives you to the `.well-known` folder in your web root, and click Verify in the Apple portal.
4. In WooCommerce > Settings > Payments > CardPointe - Credit Card, open the Apple Pay tab, tick Accept Apple Pay and click Upload PEM file. The Apple Merchant ID is read from the certificate. You can instead place the file on the server yourself and enter its path; the screen shows your web root for reference. Save.
5. Click Test Apple Pay setup. The plugin asks Apple for a merchant session with your Merchant ID, certificate and domain, and says exactly what is wrong if Apple refuses. Once it passes, a test Apple Pay button appears; in Safari it opens the real Apple Pay sheet without charging anything.

The PEM contains a private key, so an uploaded file is stored one level above the web root when the server allows it, and otherwise in a protected uploads folder under a random name. The upload is checked first and refused with a clear reason if the key is missing or passphrase-protected, if it is the Payment Processing Certificate by mistake, if it has expired, or if the key does not belong to the certificate.

Apple Pay needs HTTPS even in sandbox mode. To test payments in the sandbox, use an Apple sandbox tester account with Apple's test cards.

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

**3. Apple Pay merchant validation** (`https://apple-pay-gateway.apple.com/paymentservices/…` and Apple's regional equivalents)

When a shopper taps the Apple Pay button, and when an administrator runs the setup check on the settings screen. Safari (or, for the setup check, Apple's standard endpoint) gives the store a one-time validation URL and your server calls it to prove the store's identity to Apple, sending your Apple merchant identifier, the store name shown on the payment sheet and this site's domain, authenticated with your merchant identity certificate. No cart, customer or card data is included. The Apple Pay sheet itself is part of Safari; this plugin loads no script from Apple. The encrypted token Apple returns is sent to CardSecure (service 2 above) to be decrypted and tokenized.

**4. Apple Pay JS SDK** (`https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js`)

Only when Apple Pay is enabled with "Other Browsers" switched on. The script is loaded by the shopper's browser from Apple on pages that show an Apple Pay button (single products, cart and checkout, according to your settings) and on the plugin's settings screen. It draws the Apple Pay button and, in browsers other than Safari, shows the code the shopper scans with their iPhone and talks to Apple to complete that hand-off. As with any script loaded from a third party, Apple receives the shopper's IP address and browser details. The plugin itself sends Apple nothing beyond the merchant validation described above.

Apple Pay on the web terms: https://developer.apple.com/apple-pay/acceptable-use-guidelines-for-websites/
Apple privacy policy: https://www.apple.com/legal/privacy/

== Changelog ==

= 1.2.1 =
* Fixed the Apple Pay button showing as an empty box in Chrome, Edge and Firefox. Apple's script registers its button element shortly after it starts running; the button is now created as that element whenever "Other Browsers" is on, instead of only when it was already registered, and falls back to Safari's own button if Apple's script never loads.
* Fixed an empty error box appearing under express buttons on themes and page builders that style WooCommerce notices in a way that overrides the hidden state.
* Fixed the express button missing from CheckoutWC. CheckoutWC runs WooCommerce's own checkout hook and discards what it prints before running its express checkout hook, which used up the plugin's "print once" guard. Each hook is now tracked separately, and the gateway registers itself with CheckoutWC as providing express checkout.

= 1.2.0 =
* Apple Pay express buttons on single product pages (simple and variable products), the cart and the top of the checkout. The Apple Pay sheet collects the contact details and address, and shipping methods, shipping cost and tax update live as the shopper chooses. Runs on the WooCommerce Store API, so it works the same on classic pages, the Cart and Checkout blocks and CheckoutWC (in its express area).
* Apple Pay on the Checkout block: an express button in the block's express area, and a button inside the credit card form that uses the address already entered.
* Apple Pay in Chrome, Edge and Firefox: with "Other Browsers" on, Apple's JS SDK is loaded and shoppers pay by scanning a code with an iPhone (iOS 18 or later). Buttons are drawn with Apple's own button element.
* New "Allow Apple Pay on" setting to switch each placement on or off (single products, cart, checkout express, credit card box), and an "Other Browsers" setting.
* The amount approved in the Apple Pay sheet is sent with the payment and the order is refused, uncharged, if its total differs.
* Merchant validation accepts the Store API nonce, so express buttons keep working on product pages served from a page cache.
* The setup check's test button on the settings screen is now the real Apple Pay button in every browser when "Other Browsers" is on.

= 1.1.1 =
* Apple Pay settings now have their own tab, laid out as Apple Pay and Connection Settings.
* The Merchant Identity Certificate is a single PEM file (certificate plus private key, no passphrase). The separate private key, passphrase and domain verification file settings were removed; existing values are cleaned up on upgrade.
* Added an Upload PEM file button. The file is checked before it is accepted (missing or passphrase-protected key, Payment Processing Certificate used by mistake, expired, key not matching) and stored above the web root where the server allows it, so the private key cannot be downloaded. The Apple Merchant ID is read from the certificate.
* The settings show the site's web root path for reference.
* Added a setup check that asks Apple for a merchant session and explains any refusal, and a test Apple Pay button that appears once Apple has accepted the setup. In Safari it opens the real payment sheet without charging anything.

= 1.1.0 =
* Added Apple Pay. An Apple Pay button appears in the credit card box for shoppers using Safari with Apple Pay set up, on the classic checkout, CheckoutWC and order-pay pages. The encrypted Apple token is tokenized by CardSecure and charged like any card payment, with the usual authorize/capture, refund and receipt handling.
* New Apple Pay section in the card gateway settings: merchant identifier, merchant identity certificate and key (paths or wp-config constants), domain verification file served from WordPress, button style and label, and a status row that lists anything still missing.
* Apple Pay is not offered for orders that need a saved payment method (subscriptions, charge-on-release pre-orders), on the My Account payment method screens, or on the Cart & Checkout blocks yet.

= 1.0.2 =
* Fixed the hosted tokenizer iframe sometimes stalling the first time the payment form was shown, when reloading the page would display it correctly.
* Fixed the payment form never loading on multi-step checkouts such as CheckoutWC, where the payment step is revealed client-side after the page has loaded. The form now mounts the moment its container is laid out, and no longer depends on the payment method radio button, which such checkouts manage with their own markup.
* The frame container now carries a data-state attribute (and optional console tracing with ?paradox-cardpointe-debug=1) so a stuck form can be diagnosed from the inspector.
* The iframe is no longer torn down and rebuilt each time WooCommerce refreshes the checkout, which restarted the request to CardPointe.
* The iframe is now marked as eagerly loaded, so optimisation plugins that add lazy loading cannot defer it indefinitely.
* Added a connection hint for the tokenizer host on checkout pages so the first load does not wait on a DNS lookup and TLS handshake.
* If the form still does not appear, it is now retried automatically once and then offers a "Reload the payment form" link instead of waiting forever.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
Fixes the Apple Pay button not drawing in Chrome and other non-Safari browsers, and the express button missing from CheckoutWC.

= 1.2.0 =
Apple Pay express buttons for product pages, cart and checkout, Checkout block support, and Apple Pay in Chrome. New buttons are on by default; choose where they appear under Apple Pay > Allow Apple Pay on.

= 1.1.1 =
Simpler Apple Pay setup: one PEM file with an upload button, a setup check against Apple and a test button. If you used a separate private key file, combine it with the certificate into one PEM and upload it.

= 1.1.0 =
Adds Apple Pay for the classic checkout, CheckoutWC and order-pay pages. Configure it in the card gateway settings.

= 1.0.2 =
Fixes the secure payment form occasionally failing to appear until the checkout page was reloaded.

= 1.0.0 =
Initial release.
