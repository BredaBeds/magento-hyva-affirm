# Affirm messaging for Hyvä (`Hyva_AstoundAffirm`)

Makes Affirm's official Magento 2 extension ([`affirm/magento2`](https://github.com/Affirm/Magento2_Affirm), module
`Astound_Affirm`) work on the Hyvä theme: "as low as" messages, Affirm's banners, the cart's "Checkout with Affirm"
button and the order-confirmed pixel.

Affirm's extension is built for Luma and shows nothing on Hyvä. This module replaces its storefront templates with
Hyvä templates, small Alpine.js components and one affirm.js loader. Settings, promo ids, availability rules, the
payment gateway and the admin stay Affirm's, and Luma store views are not changed. affirm.js writes every amount
and rate; the module never does.

To pay with Affirm in Hyvä Checkout, add
[`bredabeds/magento-hyva-checkout-affirm`](https://github.com/BredaBeds/magento-hyva-checkout-affirm). Each module
works without the other.

## Requirements

| | Supported | Tested |
|---|---|---|
| PHP | 8.1 – 8.5 | 8.5 |
| Magento Open Source | 2.4.6 – 2.4.9 | 2.4.9 |
| Affirm extension | `affirm/magento2` ^4.0.11 | 4.0.11, US sandbox, USD |
| Hyvä theme module | ^1.3.11 (Tailwind 3 or 4) | 1.5.x with Tailwind 4 |
| Hyvä Checkout | optional (cart button only) | 1.3.15 |

The minicart message needs the cart drawer of the Hyvä default theme 1.4 or later. Not yet tested in a browser:
older Magento and PHP versions, Tailwind 3, CAD stores and a nonce-only storefront CSP.

## Installation

```sh
composer require bredabeds/magento-hyva-affirm
bin/magento module:enable Hyva_AstoundAffirm
bin/magento setup:upgrade
bin/magento hyva:config:generate
```

Then rebuild the CSS of each Hyvä theme (`npm run build-prod` in its `web/tailwind` folder). In production mode
also run `setup:di:compile`, `setup:static-content:deploy` and `cache:flush`.

If Composer cannot find the package:
`composer config repositories.magento-hyva-affirm vcs https://github.com/BredaBeds/magento-hyva-affirm`.

## Configuration

The module has no settings of its own. It uses Affirm's
(Stores > Configuration > Sales > Payment Methods > Affirm, and Affirm's promotional settings), and nothing shows
until you switch it on there. Every placement needs:

- Affirm enabled, with a public API key for the current mode (the `_ca` keys for CAD)
- USD or CAD as both the base and the display currency

Messages show between $50 and $17,500 (Affirm's limits), narrowed by Minimum / Maximum Order Total. Which setting
drives what: [`docs/reference.md`](docs/reference.md#affirm-settings-honoured).

## What appears where

| Placement | Where | Notes |
|---|---|---|
| Product page | next to the price | follows options and configurable or bundle selections; not for grouped products |
| Listings | under each price in category, search, sliders and widgets | static |
| Cart | below "Proceed to Checkout" | follows the cart total |
| Minicart | in the Hyvä cart drawer | affirm.js loads when the drawer opens |
| Banners | home, category, product and cart pages | buttons that open Affirm's modal |
| Cart button | next to the other checkout buttons | selects Affirm and opens the checkout's payment step |
| Pixel | success page | off in sandbox mode |
| CMS content | any page or block | Affirm's documented markup works |

affirm.js loads after the page is idle, and only on pages that have something to show. Messages reserve their
height, so nothing shifts when they fill in.

## After changing settings or promotions

affirm.js fetches the message text on every view, but the promo id and amount are part of the cached HTML. Listing
items update by themselves; full-page caches do not. After changing Affirm settings or category Affirm attributes,
and at the start and end of a dated promotion, run `bin/magento cache:clean block_html full_page` and purge Varnish
and any CDN that caches HTML. Details: [Cache and promotion timing](docs/reference.md#cache-and-promotion-timing).

## For developers

- To hide Affirm under your own rule, add an `after` plugin on
  `Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface::isAvailable()`. It decides every placement.
- [`docs/reference.md`](docs/reference.md) has the rest: each placement, the `window.hyvaAffirm` loader, the other
  extension points, [how the module differs from Affirm on Luma](docs/reference.md#differences-from-affirm-on-luma),
  [known Affirm issues](docs/reference.md#known-affirm-issues) and
  [CSP and accessibility](docs/reference.md#content-security-policy-and-accessibility).
- Tests: `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/bredabeds/magento-hyva-affirm/Test/Unit` on a
  Magento install, `node --test Test/js/*.test.mjs` for the JavaScript, and [`Test/ci/README.md`](Test/ci/README.md)
  for what CI runs without one.

## Turning it off

- **Everything, including new Affirm checkouts:** set `payment/affirm_gateway/active` to 0. Existing orders can
  still be invoiced, refunded and voided.
- **Storefront content only:** switch off the message, banner, checkout button and pixel settings.
- Then run `bin/magento cache:clean config block_html full_page` and purge Varnish and the CDN.
- **Remove the module:** `module:disable Hyva_AstoundAffirm`, `composer remove bredabeds/magento-hyva-affirm`,
  `setup:upgrade`, `hyva:config:generate`, rebuild the theme CSS. It creates no database tables.

## Licence

MIT, see [`LICENSE`](LICENSE). The licence does not cover the Affirm name, logo, banners, buttons or scripts; see
[`TRADEMARKS.md`](TRADEMARKS.md) and [`NOTICE`](NOTICE). Not affiliated with or endorsed by Affirm, Inc. or Hyvä
Themes B.V.

[Contributing](CONTRIBUTING.md) · [Changelog](CHANGELOG.md)
