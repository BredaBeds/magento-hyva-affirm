# Affirm messaging for Hyvä (`Hyva_AstoundAffirm`)

Makes Affirm's official Magento 2 extension ([`affirm/magento2`](https://github.com/Affirm/Magento2_Affirm), module
`Astound_Affirm`) work on the Hyvä theme. It adds:

- "As low as" messages on product pages, category and search listings, the cart and the minicart
- Affirm's promotional banners
- the "Checkout with Affirm" button on the cart page
- the order-confirmed pixel on the success page

| | |
|---|---|
| Composer package | `bredabeds/magento-hyva-affirm` |
| Magento module | `Hyva_AstoundAffirm` |
| Licence | MIT (the Affirm name and logo are not covered, see [Licence](#licence)) |
| Checkout | Use [`bredabeds/magento-hyva-checkout-affirm`](https://github.com/BredaBeds/magento-hyva-checkout-affirm) to pay with Affirm in Hyvä Checkout. The two modules work alone or together. |

## Why this module exists

Affirm's FAQ says its extension "is not natively compatible with Hyva". On a Hyvä theme, Affirm 4.0.11 shows no
working messages: its templates expect Luma's page structure, Knockout and RequireJS, none of which Hyvä has.

This module replaces Affirm's Luma storefront code with Hyvä templates, small Alpine.js components and one affirm.js
loader. Everything else stays Affirm's: settings, promo ids, availability rules, the payment gateway and the admin.
Luma store views are not changed.

The page carries Affirm's documented element (`<p class="affirm-as-low-as" data-amount="…">`). affirm.js writes
the text, the "Learn more" link and the modal. The module never writes a monthly amount or an APR itself.

## Requirements

| | Supported | Tested |
|---|---|---|
| PHP | 8.1 – 8.5 | 8.5 |
| Magento Open Source | 2.4.6 – 2.4.9 | 2.4.9 (unit tests also on 2.4.7) |
| Affirm extension | `affirm/magento2` ^4.0.11 | 4.0.11, US sandbox, USD |
| Hyvä theme module | ^1.3.11 (Tailwind 3 or 4) | 1.5.x with Tailwind 4 |
| Hyvä Checkout | optional, only used by the cart button | 1.3.15; 3.0 checked against its source |

Not tested yet in a browser: Magento 2.4.6 – 2.4.8, PHP 8.1 – 8.4, Tailwind 3, CAD stores, Luma store views and a
strict (nonce-only) storefront CSP.

The minicart message needs the cart drawer of the Hyvä default theme 1.4 or later. On older themes everything else
works and the minicart shows no message.

## Installation

```sh
composer require bredabeds/magento-hyva-affirm
bin/magento module:enable Hyva_AstoundAffirm
bin/magento setup:upgrade
bin/magento hyva:config:generate
```

Then rebuild the CSS of each Hyvä theme, so Tailwind picks up the module's templates:

```sh
cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm run build-prod
```

In production mode, also run `bin/magento setup:di:compile`, `bin/magento setup:static-content:deploy` and
`bin/magento cache:flush`.

If Composer cannot find the package, add the repository first:
`composer config repositories.magento-hyva-affirm vcs https://github.com/BredaBeds/magento-hyva-affirm`.

## Configuration

The module has no settings of its own. It uses the Affirm settings you already have
(Stores > Configuration > Sales > Payment Methods > Affirm, and Affirm's promotional settings).

Nothing shows until you switch it on in Affirm's settings. Every placement also needs:

- Affirm enabled (`payment/affirm_gateway/active`)
- a public API key for the current mode (sandbox or production; the `_ca` keys for CAD)
- USD or CAD as both the base and the display currency

| Affirm setting | Used for |
|---|---|
| Enabled, Mode, Public API Key | every placement; the affirm.js URL and key |
| Minimum / Maximum Order Total | hides messages and the cart button outside these amounts |
| Disable for backordered items | hides product, listing and product-banner content for backordered products |
| Enable for PDP / Category PLP / Shopping Cart / Mini Shopping Cart | switches each message on or off |
| Affirm logo, Enable Learn More | the message's look (the logo defaults to blue when unset) |
| Default Affirm Promo ID, Multiple Financing Programs, product and category Affirm attributes | which promo id each message uses (resolved by Affirm's own helper) |
| PDP Placement options for As low as | product page: after the price, before the price, or at the end of the product info |
| Banner settings and developer containers | which banners show, where, and the HTML around them |
| Enabled Checkout Button, Button Code, Button Width | the cart button |
| Enable Affirm Checkout Tracking Pixel | the order-confirmed pixel (production mode only) |

Messages show only between **$50 and $17,500** (Affirm's own limits), narrowed by Minimum/Maximum Order Total.

## Where things appear

| Placement | Where | Notes |
|---|---|---|
| Product page | next to the product price | Follows the live price: custom options, configurable selections and bundle selections. Not shown for grouped products. |
| Listings | under each product price in category, search, sliders and widgets | Static; does not follow swatch selections (as on Luma). |
| Cart | below "Proceed to Checkout" | Follows the cart total, including shipping estimates and quantity changes. Applies Affirm's cart-size promo rule. |
| Minicart | in the Hyvä cart drawer | affirm.js loads only when the drawer opens. |
| Banners | home, category, product and cart pages, at Affirm's configured positions | Rendered as buttons that open Affirm's modal. |
| Cart button | cart page, next to the other checkout buttons | Selects Affirm and opens the checkout at the payment step. |
| Pixel | success page | Suppressed in sandbox mode. |
| CMS content | any CMS page or block | Affirm's documented markup works; the module loads affirm.js when it finds it. |

affirm.js loads after the page becomes idle, and only when the page has something Affirm can show. Messages
reserve one line of height to avoid layout shift, and collapse when they are out of range or affirm.js cannot load.

### How this differs from Affirm on Luma

| Topic | Affirm on Luma | This module |
|---|---|---|
| Affirm logo not saved | no message renders | uses blue, Affirm's default |
| Product price below the "minimum amount for promotional pricing" | message never renders | appears once options raise the price above it |
| Cart message and the product placement setting | hidden unless the product placement is "After Price" (Affirm bug) | independent |
| Banner element | a link, which leaves the store when clicked | a button that opens Affirm's modal |
| Home and category banner promo ids | the visitor's financing program, also on cached pages | the dated or default promo id (never visitor data on cached pages) |
| Loading affirm.js | on page load | after the page is idle, only when needed |
| Pixel | needs only its own setting | also needs Affirm enabled with a key and USD/CAD |

## Caching

affirm.js fetches the message text from Affirm each time the page is viewed. What gets cached is the promo id and
the amount in the HTML.

- **Listings:** each listing item's cache key includes its promo id, Affirm's settings and the store date, so changes
  show up at once.
- **Full-page cache, Varnish and CDN:** product saves refresh their pages. Category attribute changes, Affirm
  setting changes and the start or end of a dated promotion do **not**. After any of these, run
  `bin/magento cache:clean block_html full_page` and purge Varnish and any CDN that caches HTML. For dated
  promotions, schedule that flush for the start and end dates (in the store's time zone).
- The cart page and the minicart are never served from the full-page cache.

## Content Security Policy and accessibility

- Affirm's own CSP whitelist covers `*.affirm.com` and `*.affirm.ca`. A cart-button image on another host needs its
  own `img-src` entry.
- Every inline script is registered with Hyvä's CSP helper, and the Alpine components work with Hyvä's CSP build.
- Messages stay hidden from screen readers until affirm.js has filled them. Banners are buttons with
  `aria-haspopup="dialog"`, and focus returns to the banner when Affirm's modal closes.
- Messages use the surrounding text colour. If that fails contrast in your theme, style `.hyva-affirm-message`.

## Known issues

In Affirm's extension 4.0.11 (this module works around each one unless noted):

- The cart message is hidden when the product placement is not "After Price".
- `data-affirm-color` is escaped twice in Affirm's templates.
- "Affirm logo" has no default value, so messages stay off until it is saved.
- The cart banner's default position matches no option.
- Affirm's backorder helpers can throw on stores with Inventory (MSI). This module applies the same rule without them.
- `affirm.analytics.trackOrderConfirmed` (the pixel) only logs "deprecated" in current affirm.js. Luma behaves the
  same. Consider turning the pixel off until Affirm says otherwise. *Not worked around.*

In affirm.js:

- It does not notice messages or banners added after it loads. The module refreshes it after Hyvä reloads the cart.
- In the sandbox, Affirm's promos API refuses store domains that are not allow-listed on the Affirm account, so
  messages show generic text. This is an Affirm account setting.

Mixed currencies: Minimum/Maximum Order Total are base-currency amounts. Messages compare them with the displayed
price, as Affirm's Luma code does. A store whose display currency differs from its base currency can therefore show a
message for a cart the checkout then refuses.

## For developers

[`docs/reference.md`](docs/reference.md) covers the internals: each placement in detail, the `window.hyvaAffirm`
loader contract, the price-state recorder, the extension points (availability plugin, message event, cache
fingerprint, price blocks, template overrides) and the cart button's preselection contract with
`Hyva_CheckoutAffirm`.

The most common customisation is hiding Affirm content under your own rule. Add an `after` plugin on
`Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface::isAvailable()`. It decides every placement, including
banners, the cart button and the pixel.

## Testing

| Suite | Command |
|---|---|
| PHP unit tests on a Magento install | `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/bredabeds/magento-hyva-affirm/Test/Unit` |
| Without a Magento install (what CI runs) | see [`Test/ci/README.md`](Test/ci/README.md) |
| JavaScript (Node 20+) | `node --test Test/js/*.test.mjs` |

GitHub Actions runs PHP syntax checks on 8.1 – 8.5, then php-cs-fixer, PHPCS, PHPStan level 6 and PHPUnit on
PHP 8.1 and 8.3, and the JavaScript tests. The `Test/` folder is left out of release archives.

Browser tests (Playwright on a sandbox store, not in this repository) cover every placement listed above, the cart
button with Hyvä Checkout, accessibility (axe-core) and layout shift.

## Turning it off

- **Hide all Affirm content and stop new Affirm checkouts:** set `payment/affirm_gateway/active` to 0.
  Existing orders can still be invoiced, refunded and voided.
- **Hide only the storefront content:** switch off the four message settings, the banners, the checkout button and
  the pixel in Affirm's settings.
- After either change, run `bin/magento cache:clean config block_html full_page` and purge Varnish and the CDN.
- **Remove the module:**

  ```sh
  bin/magento module:disable Hyva_AstoundAffirm
  composer remove bredabeds/magento-hyva-affirm
  bin/magento setup:upgrade
  bin/magento hyva:config:generate
  # rebuild the theme CSS; in production mode also setup:di:compile and setup:static-content:deploy
  bin/magento cache:flush
  ```

  The module creates no database tables, so there is nothing to clean up.

## Licence

MIT, see [`LICENSE`](LICENSE). The licence does not cover the Affirm name or logo, or Affirm's banners, buttons and
scripts, which load from Affirm's servers; see [`TRADEMARKS.md`](TRADEMARKS.md). The package calls Affirm's classes
and copies none of Affirm's code; [`NOTICE`](NOTICE) carries Affirm's BSD-3-Clause notice in case a fragment is
adapted in future. This project is not affiliated with or endorsed by Affirm, Inc. or Hyvä Themes B.V.

Contributing: see [`CONTRIBUTING.md`](CONTRIBUTING.md). Changes: see [`CHANGELOG.md`](CHANGELOG.md).
