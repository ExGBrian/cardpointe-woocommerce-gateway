# CardPointe Payment Gateway for WooCommerce

WooCommerce payment gateway for [CardPointe](https://cardpointe.com) (Fiserv / CardConnect) by [Paradox Solutions](https://paradoxsolutions.io).

Card and bank account details are entered in CardPointe's Hosted iFrame Tokenizer and tokenized by CardSecure, so no card data ever touches your server.

## Features

- Charge immediately or authorize now and capture later (order screen, order actions, or automatic on status change)
- Accepted card type enforcement via the CardPointe BIN service
- Full and partial refunds from the WooCommerce order screen (voids unsettled transactions automatically)
- Optional gateway receipts on order pages and emails
- Redacted request/response logging
- Saved cards and bank accounts backed by CardPointe profiles
- WooCommerce Subscriptions and WooCommerce Pre-Orders support
- eCheck (ACH) as a separate payment method
- Classic checkout and Cart/Checkout Blocks, HPOS compatible
- Separate sandbox and production credentials with a "Test connection" button

## Requirements

- WordPress 6.6+, PHP 7.4+
- WooCommerce 9.0+
- A CardPointe merchant account with API credentials (site name, merchant ID, API username/password)

## Installation

1. Download this repository as a ZIP (or clone it into `wp-content/plugins/paradox-cardpointe-gateway`).
2. Activate **CardPointe Payment Gateway for WooCommerce** under Plugins.
3. Go to WooCommerce → Settings → Payments → **CardPointe - Credit Card**, enter your credentials, and click **Test connection**.
4. Enable the gateway. Turn off sandbox mode when you are ready to go live (production requires HTTPS at checkout).
5. Optionally enable **CardPointe - eCheck (ACH)** if your merchant ID supports ACH.

See `readme.txt` for the full WordPress.org style documentation and FAQ.

## Development notes

- No build step: the Checkout Block integration is plain JavaScript using `wp.element`.
- PHP is namespaced under `ParadoxSolutions\CardPointe` with a simple autoloader (`includes/`).
- Templates in `templates/` can be overridden from a theme under `paradox-cardpointe-gateway/`.
- Regenerate translations with `wp i18n make-pot . languages/paradox-cardpointe-gateway.pot`.

## Releasing

Publishing to the WordPress.org plugin directory is automated from tags. See
[RELEASING.md](RELEASING.md) for the checklist and the required repository secrets.

```sh
git tag v1.0.1 && git push origin v1.0.1
```

Continuous integration runs PHP syntax checks on 7.4, 8.1 and 8.3, PHP_CodeSniffer
against the WordPress standards, and the official Plugin Check tool that the review
team uses.

## License

GPL-3.0-or-later. See `LICENSE`.
