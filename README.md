# Affirm promotional messaging for Hyvä (`Hyva_AstoundAffirm`)

Hyvä Theme compatibility for Affirm's official Magento 2 extension
([`affirm/magento2`](https://github.com/Affirm/Magento2_Affirm), module `Astound_Affirm`): as-low-as messages on
product pages, listings, the cart and the minicart, Affirm's promotional banners, the cart "Checkout with Affirm"
button and the order-confirmed pixel.

- Composer package: `bredabeds/magento-hyva-affirm` · Magento module: `Hyva_AstoundAffirm` · Licence: MIT
- Companion module for Hyvä Checkout: [`bredabeds/magento-hyva-checkout-affirm`](https://github.com/BredaBeds/magento-hyva-checkout-affirm)
  (`Hyva_CheckoutAffirm`). The two modules do not depend on each other.

Contents: [Why](#why) · [Requirements](#requirements-and-support-matrix) · [Installation](#installation) ·
[Configuration](#configuration) · [Placements](#placements) · [Loader contract](#the-windowhyvaaffirm-loader-contract-v1) ·
[Extension points](#extension-points) · [Cache and promotion timing](#cache-and-promotion-timing) · [CSP](#content-security-policy) ·
[Accessibility](#accessibility) · [Known upstream issues](#known-upstream-issues) · [Testing](#testing) ·
[Rollback](#rollback) · [Licence](#licence)

## Why

Affirm's FAQ states that its extension "is not natively compatible with Hyva". On a Hyvä theme, Affirm 4.0.11
produces no working storefront output: its product page blocks expect Luma's page structure, Hyvä discards the
output of its listing plugin, the minicart parts are Knockout templates and the pixel is a RequireJS widget.
Affirm's layout XML still applies on Hyvä, so its Luma blocks and `affirm-promo.css` reach the page anyway.

This module replaces Affirm's Luma storefront glue with Hyvä blocks, CSP-compatible Alpine.js components and one
small affirm.js loader. Everything else stays Affirm's: configuration, promo id resolution (Multiple Financing
Programs), availability checks, gateway and admin. The page carries Affirm's documented element
(`<p class="affirm-as-low-as" data-amount="…">`) and affirm.js renders the text, the "Learn more" link and the
modal; the module never writes a monthly amount, an APR or "0%" itself.

Scope:

- Hyvä store views only. All output is declared in `hyva_*` layout handles, Affirm's Luma plugins keep running on
  Luma themes, and the template overrides apply only on Hyvä themes. Luma store views are unchanged.
- No new admin settings and no database objects. One hidden developer flag exists for pixel tests.
- Not the checkout. Hyvä Checkout already lists Affirm as a payment method but cannot place an Affirm order: its
  default place-order service runs Affirm's authorization without a checkout token. Install `Hyva_CheckoutAffirm`
  for that.

## Requirements and support matrix

| | Declared support | Tested on 2026-10-08 |
|---|---|---|
| PHP | 8.1 to 8.5 | 8.5 (runtime); PHP 8.1 compatibility of all sources checked with PHPCompatibility |
| Magento Open Source | 2.4.6 to 2.4.9 | 2.4.9 (runtime); unit tests also against 2.4.7-p10 (the release PHP 8.1 resolves to) |
| Affirm extension | `affirm/magento2 ^4.0.11` | 4.0.11, sandbox mode, US keys, USD store |
| Hyvä theme module / default theme | `hyva-themes/magento2-theme-module ^1.3.11` (Tailwind 3 sources for 1.3.x, Tailwind 4 sources for 1.4+) | 1.5.2 with Tailwind 4, on a child theme of the default theme |
| Hyvä compat module fallback | `^1.1.2` | 1.1.4 |
| Hyvä Checkout (optional, cart button only) | any; detected at runtime | 1.3.14 |

Everything in the "Declared support" column that is not in the "Tested" column is untested so far. Not tested at
runtime: Magento 2.4.6 to 2.4.8, PHP 8.1 to 8.4, Hyvä 1.3.x and Tailwind 3, CAD stores, Luma store views in a
browser (covered by code review and unit tests only), and a strict storefront CSP (see [CSP](#content-security-policy)).

The minicart message needs the cart drawer of the Hyvä default theme 1.4 or later: its containers
`cart-drawer.totals.before` and `cart-drawer.bottom`, and the drawer component's `cart` and `open` data. On older
themes every other placement is unaffected and the minicart shows no message.

## Installation

```sh
composer require bredabeds/magento-hyva-affirm
bin/magento module:enable Hyva_AstoundAffirm
bin/magento setup:upgrade
bin/magento hyva:config:generate
```

If the package is not available from your Composer repositories, add the GitHub repository first:
`composer config repositories.magento-hyva-affirm vcs https://github.com/BredaBeds/magento-hyva-affirm`.

Then rebuild the CSS of every Hyvä theme so Tailwind picks up the module's templates (the module registers
Tailwind 3 and Tailwind 4 sources through `hyva-themes.json`):

```sh
cd app/design/frontend/<Vendor>/<theme>/web/tailwind && npm run build-prod
```

In production mode also run `bin/magento setup:di:compile` and `bin/magento setup:static-content:deploy`, then
`bin/magento cache:flush`. Without the theme rebuild the message may lose its reserved height (`min-h-[1.5rem]`).

Nothing else is required: the module honours the Affirm settings the store already has. Messages, banners and the
cart button stay off until they are switched on in Affirm's settings (Affirm's defaults have them off). The
order-confirmed pixel follows Affirm's own setting, which defaults to Yes (see [Pixel](#order-confirmed-pixel)).

## Configuration

### Requirements shared by every placement

Every placement, and the affirm.js loader itself, requires:

- Affirm enabled (`payment/affirm_gateway/active`);
- a public API key for the configured mode and the store's currency (`public_api_key_sandbox|production`, with the
  `_ca` keys for CAD);
- base currency **and** display currency USD or CAD.

### Affirm settings honoured

| Affirm setting (admin label) | Path | Used for |
|---|---|---|
| Enabled | `payment/affirm_gateway/active` | every placement (see above) |
| Mode | `payment/affirm_gateway/mode` | affirm.js URL (sandbox or production) and key; pixel suppressed in sandbox |
| Public API Key (US / CA) | `payment/affirm_gateway/public_api_key_*` | passed to affirm.js; chosen by mode and currency |
| Minimum / Maximum Order Total | `payment/affirm_gateway/min_order_total`, `max_order_total` | narrow the amount bounds of every message; cart button availability |
| Disable for backordered items | `payment/affirm_gateway/disable_for_backordered_items` | hides product, listing and product banner content for backordered products |
| Enabled Checkout Button, Checkout Button Code, Checkout Button Width | `payment/affirm_gateway/enable_checkout_button`, `checkout_button_code`, `checkout_button_width` | cart button |
| Enable for PDP / Category PLP / Shopping Cart / Mini Shopping Cart | `affirm/affirm_aslowas/enabled_pdp`, `enabled_plp`, `enabled_cc`, `enabled_mcc` | switch each message placement |
| Affirm logo | `affirm/affirm_aslowas/logo` | `data-affirm-color`; `blue` (Affirm's "Default") when unset |
| Minimum amount for displaying the promotional payment pricing | `affirm/affirm_aslowas/min_mpp` | per placement, see the table below |
| Enable Learn More | `affirm/affirm_aslowas/learn_more` | `data-learnmore-show` |
| Default Affirm Promo ID | `affirm/affirm_aslowas/promo_id_value_default` | promo ids, through Affirm's helper |
| PDP Placement options for As low as | `affirm/affirm_aslowas_developer/placement` | product page: 0 After Price, 1 Before Price, 2 End of Product Info |
| Multiple Financing Program | `affirm/affirm_mfp/*` | promo ids (dated promo, cart-size rule), through Affirm's helper |
| Product and category Affirm attributes | `affirm_*` EAV attributes | promo ids, through Affirm's helper |
| Banner Settings: Enabled | `affirm/affirm_promo/enabled` | banners |
| Display, Promo Size, Promo Position (home, category, product, cart) | `affirm/affirm_promo_{homepage,category,product,checkout_cart}/*` | banners |
| Developer containers | `affirm/affirm_developer/{homepage,category,product,checkout_cart}_container` | HTML around each banner |
| Enable Affirm Checkout Tracking Pixel | `affirm/affirm_pixel/enabled` | order-confirmed pixel (Affirm's default: Yes) |
| *(this module, hidden)* | `affirm/hyva/pixel_in_sandbox` | default 0; tests only, see [Pixel](#order-confirmed-pixel) |

### Rules per placement

Every message is hidden outside **$50.00 to $17,500.00** (inclusive; the bounds of Affirm's Luma JavaScript)
and outside Minimum/Maximum Order Total when those are set. CAD stores use the same bounds until Affirm confirms
CAD-specific ones. The element carries the amount in cents (`data-amount`).

| Placement | Switch | Amount | `min_mpp` | Further conditions |
|---|---|---|---|---|
| Product page | `enabled_pdp` | final price as displayed (tax display setting), followed live | hidden below it (equal shows), also for the live price | saleable; price visible (no MSRP "click for price"); not grouped; simple/virtual not backordered; configurables hide while the selection resolves to backordered children |
| Listings | `enabled_plp` | final price as displayed | shown only above it | price visible; simple/virtual not backordered |
| Cart page | `enabled_cc` | quote grand total, followed live | ignored | Affirm's payment method is available for the quote (includes Affirm's backorder rule) |
| Minicart | `enabled_mcc` | cart subtotal (including tax only when the cart subtotal is displayed including tax only) | at or below it the promo id is blank (Affirm's section data) | Affirm's `allow_affirm_quote_aslowas` (includes Minimum/Maximum Order Total on the base grand total) |
| Banners | `affirm/affirm_promo/enabled` and the page's Display flag | none | none | product page: product not backordered; cart: Affirm's `Helper\Payment::isAffirmAvailable()` |
| Cart button | `enable_checkout_button` | none | none | Affirm's payment method available for the quote; Minimum/Maximum Order Total against the base grand total |
| Pixel | `affirm/affirm_pixel/enabled` | base grand total | none | production mode, or the hidden developer flag |

### Behaviour compared with Affirm's Luma storefront

| Topic | Affirm on Luma | This module |
|---|---|---|
| Message colour | nothing renders until "Affirm logo" is saved (no default) | defaults to `blue`, Affirm's "Default" |
| Amount bounds | $50 to $17,500 and order totals, in the Luma widgets | same rule, applied on the server and in the browser |
| `min_mpp` on the product page | checked once against the first price; below it the block is not rendered | the element renders collapsed and appears when options or a selection raise the price to `min_mpp` |
| Cart message below Minimum Order Total | not rendered | rendered collapsed; a shipping estimate that brings the total into range reveals it |
| Cart message and the PDP placement setting | hidden unless the PDP placement is "After Price" (Affirm bug) | independent of the PDP placement |
| Pixel | needs only its own flag (and production mode) | also needs Affirm enabled with a public key and USD/CAD |
| Banner element | `<a href>` link | `<button type="button">`: affirm.js opens the modal without cancelling the click, so a link would leave the store |
| Banner promo ids | the session's financing program, for every page | home and category: Affirm's dated promo id or the default (never visitor data on cached pages); product: the product's promo id; cart: the cart's promo id |
| Home and category banners | shown without the gateway being enabled | need Affirm enabled with a public key and USD/CAD (affirm.js opens their modal) |
| Cart banner position `center-top` (Affirm's default, not an option value) | matches no position | means position 0, the "Center Top" it names |
| Developer container without `{container}` | the whole HTML is printed before and after the banner | ignored (no wrapper) |
| Product banner and backordered configurable children | hidden through Luma's swatch markup | static: hidden only when the product itself is backordered |
| Loading affirm.js | on page load | after the browser is idle, and only when a placement can show something; the minicart loads it when the drawer opens |

## Placements

### Product page

The message is attached to the product price block, so it also follows themes that move the price.
`Plugin\Catalog\Block\Product\PriceAsLowAs` (an `afterToHtml` plugin on `Magento\Catalog\Block\Product\Price`
and `Magento\Catalog\Block\Product\View`) renders the child block `hyva.affirm.aslowas.product` of
`product.info.price` before the price HTML (placement 1) or after it (placement 0). Placement 2 renders the block
`hyva.affirm.aslowas.product.end` at the end of `product.info.additional` instead. The Hyvä default theme renders
that container; themes that hide it show no message for placement 2.

- **Live amount.** The component `hyvaAffirmProductMessage` follows Hyvä's price events through a page-level
  price-state recorder (`window.hyvaAffirmPriceState`, see [below](#price-state-recorder-v1)). The amount is the
  latest `update-product-final-price`, else the configurable's selected price (`update-prices-<id>`), else the
  server-rendered final price, plus the prices of the **active** custom options. Quantity is not multiplied in, as
  in Affirm's Luma widget. Because the recorder keeps the latest payloads, a component that starts late
  (`x-defer`, a deferred parent, the cart "configure" page) or is re-initialised starts with the current price.
- **Configurable products:** the configurable's final price, then the selection's price; with "Disable for
  backordered items" on, the message hides while every product the selection can still resolve to is backordered.
- **Bundle products:** only the bundle summary carries a message (block `hyva.affirm.aslowas.bundle`, child of
  `product.info.bundle.price`): before the bundle price for placement 1, after it for placements 0 and 2. Bundle
  selections come with `update-product-final-price`; paid custom options are added once.
- **Grouped products:** no message, as on Luma. **Virtual products:** like simple products.
- Out of range, the element is rendered collapsed (`hidden`) and affirm.js is not requested; it appears when the
  price comes into range.

### Listings, search, sliders and widgets

`Plugin\Hyva\ProductListItemAsLowAs` appends a static message (page type `category`) to the price of every item
rendered through Hyvä's `ProductListItem`: category and search results, product sliders and widgets. The markup
holds no script and does not follow swatch selections (as on Luma). It is rendered only when the amount is
displayable. Listing items are block-cached; see [Cache and promotion timing](#cache-and-promotion-timing).

### Cart page

Block `hyva.affirm.aslowas.cart` in `checkout.cart.methods`, after Hyvä's "Proceed to Checkout" block. The server
renders the quote grand total and Affirm's cart promo id. The component `hyvaAffirmCartMessage` then follows:

- `update-totals`: the amount from `grand_total`, the amount bounds re-checked (estimated tax and shipping
  included);
- Affirm's cart-size rule: while the rule applies (cart-size promo id, minimum and maximum all set; `0` disables
  it), the promo id switches on `base_grand_total`, inclusive at both ends. It never switches when a product or
  category promo id applies, or when a plugin changed Affirm's final promo id;
- `cart-is-loading` and Hyvä's `#maincontent` replacement after quantity changes: the re-rendered block carries
  fresh server values and affirm.js re-renders.

The loader and the component script are page JS dependencies rendered outside `#maincontent`. They are registered
by the template-less block `hyva.affirm.aslowas.cart.scripts` in `content`, which renders on every cart page while
`enabled_cc` is on, including an empty cart (whose page Hyvä replaces with the full cart when the cart changes in
another tab). A message that first appears after a cart reload therefore still works.

**After every cart reload** (quantity change, coupon, reload after a change elsewhere) Hyvä replaces `#maincontent`,
and affirm.js does not notice new elements: it binds banners and renders static messages only when it becomes ready
and on refresh. The script `js/cart-refresh.phtml` therefore watches `private-content-loaded` and `cart-is-loading`
(`isLoading: false`) and, when the page holds static placements it has not seen yet (`.affirm-site-modal`,
`.affirm-product-modal`, `.affirm-as-low-as` without `data-hyva-affirm-lazy`: banners, CMS content, cross-sell
listing messages), calls `hyvaAffirm.load().then(() => hyvaAffirm.refresh())`. This does not depend on the cart
message: it also works while the message is out of range or switched off. Placements present when the page loads are
left to the loader's own load after idle. The script and the loader are page JS dependencies of the three cart
banner blocks, the message block and `hyva.affirm.aslowas.cart.scripts`, which register them even when they print
nothing, so a banner that first appears after a reload (for example once the cart reaches Affirm's minimum order
total) finds `window.hyvaAffirm`. Other blocks on the cart page whose HTML contains Affirm's classes add the script
through the same `blockOutputPatternMap` detection as [CMS content](#cms-content).

### Minicart

Block `hyva.affirm.aslowas.minicart` in `cart-drawer.totals.before` and its component script
`hyva.affirm.aslowas.minicart.js` in `cart-drawer.bottom` (both with `ifconfig` `enabled_mcc`). The drawer must be
enabled (`checkout/sidebar/display`). The markup is the same for every visitor, so it is safe on full-page cached
pages. The component reads the drawer's customer `cart` section:

| Key | Source |
|---|---|
| `affirm_amount` | this module: subtotal in cents (only while the minicart message is available) |
| `affirm_minicart` | this module: `{available: bool, limits: {min, max, minMpp}}` |
| `mfpValue`, `learnMore`, `allow_affirm_quote_aslowas` | Affirm's own section plugin |

affirm.js is requested when the drawer opens (`toggle-cart`), never on page load; the message re-applies after
`private-content-loaded`.

### Banners

Affirm's banner image (from Affirm's CDN, French images for `fr_CA`) inside a `<button type="button"
class="affirm-site-modal">` that opens Affirm's site modal, wrapped in the developer container HTML. Blocks are
named `hyva.affirm.banner.<section>.<position>` and use `ifconfig="affirm/affirm_promo/enabled"`.

| Page (`data-page-type`) | Position 0 | Position 1 | Position 2 | Position 3 |
|---|---|---|---|---|
| Home (`homepage`) | first in `content` | last in `content` | first in `sidebar.additional` | last in `sidebar.additional` |
| Category (`category`) | first in `top.container` | last in `content` | first in `sidebar.main` | last in `sidebar.additional` |
| Product (`product`) | first in `top.container` | last in `top.container` | last child of `product.info.addtocart` | none |
| Cart (`cart`) | first in `content` | last in `content` | first in `checkout.cart.methods` | none |

- Sidebar positions render only in page layouts with sidebars (a one-column home page shows nothing for 2 and 3).
  Where a theme orders the children of `sidebar.main` itself, category position 2 may follow the layered navigation.
- The developer container HTML is admin-trusted and printed unescaped, as Affirm does. It is used only when it
  contains `{container}`.
- Affirm's page layouts move `catalog.leftnav` next to Affirm's sidebar banner. This module removes that banner but
  does not move the layered navigation itself (Magento applies moves before removals), so the theme's own
  placement is kept.

### Cart button

Block `hyva.affirm.cart.button` in `checkout.cart.methods`, before `checkout.cart.shortcut.buttons`. A plain form
posts to `hyva_affirm/cart/start` with the form key; it shows Affirm's configured image (alt text "Checkout with
Affirm", configured width), or a text button when no image is configured.

`Controller\Cart\Start` accepts POST only (Magento validates the form key), re-checks availability, calls Affirm's
`CheckoutPaymentManagerInterface::initPayment()` to set Affirm on the quote and redirects to `checkout`. When Hyvä
Checkout is enabled and its active checkout has a `payment` step, the redirect adds `?step=payment` and Hyvä
Checkout preselects Affirm. On any failure the shopper returns to the cart with the message "Checkout with Affirm
is not available for this cart. Please choose another payment method."

### Order-confirmed pixel

Block `hyva.affirm.pixel` in `before.body.end` of the success page (declared before `page-js-dependencies`). It
replaces Affirm's RequireJS pixel and removes Affirm's Luma minicart cleanup block (refreshing the cart after an
Affirm order belongs to the checkout integration).

- Payload, as Affirm's Luma block builds it from the session's last order: `orderId` (increment id), `currency`
  (order currency), `total` (the **base** grand total in cents; a store whose display currency differs from its
  base currency gets the same mismatch as on Luma) and `paymentMethod` (any method, not only Affirm). The third
  argument is `true` when all four values are set.
- The script loads affirm.js at once (not after idle) and calls
  `affirm.analytics.trackOrderConfirmed(payload, null, strict)`.
- Suppressed in sandbox mode unless the hidden flag `affirm/hyva/pixel_in_sandbox` is 1. The flag has no admin
  field, so `bin/magento config:set` rejects it; set it in `app/etc/env.php` for test environments only:
  `'system' => ['default' => ['affirm' => ['hyva' => ['pixel_in_sandbox' => 1]]]]`.
- **Affirm's current affirm.js treats `trackOrderConfirmed` as deprecated:** in the sandbox and production builds
  examined on 2026-10-08, the function only logs "trackOrderConfirmed is deprecated" to the console. Affirm's Luma
  module calls the same function, so the behaviour matches Luma. Because Affirm's default for the pixel setting is
  Yes, every success page then loads affirm.js for no effect; consider setting "Enable Affirm Checkout Tracking
  Pixel" to No until Affirm says otherwise.

### CMS content

Affirm's documented markup pasted into CMS pages, CMS blocks or Page Builder content works: the loader is emitted
when any block's HTML matches `/\baffirm-(as-low-as|site-modal|product-modal)\b|data-hyva-affirm/` and starts by
itself after idle when the page has a static placement. Such messages are static; their `data-amount` is whatever
the content says. This uses the `blockOutputPatternMap` of Hyvä's `page-js-dependencies` block, which Hyvä marks
as internal and outside its backward-compatibility guarantee; the module's own blocks register the loader through
`BlockJsDependencies` instead.

### Luma template fallback

Through `hyva-themes/magento2-compat-module-fallback`, Affirm's six Luma storefront templates resolve to empty
overrides on Hyvä themes (`promotion/aslowasPDP.phtml`, `promotion/aslowasCart.phtml`, `promotion/bml.phtml`,
`onepage/button.phtml`, `onepage/clearcart.phtml`, `pixel/confirm.phtml` under `templates/Astound_Affirm/`). A theme
or module that adds one of Affirm's Luma blocks back therefore renders nothing instead of dead Luma markup.

### Element and styling hooks

Every message is Affirm's element, rendered by `promotion/as-low-as.phtml`:

```html
<p class="affirm-as-low-as hyva-affirm-message min-h-[1.5rem] invisible"
   data-page-type="product|category|cart" data-amount="<cents>" data-promo-id="<id, when set>"
   data-affirm-color="blue|black|white" data-learnmore-show="true|false"
   data-hyva-affirm-placement="product|listing|cart|minicart"></p>
```

The element reserves one line and stays `invisible` until affirm.js has rendered into it; its component collapses
it with `hidden` when out of range or when affirm.js cannot load (10 s). Listing messages are visible static markup
without a component, rendered only for displayable amounts; nothing collapses them, so when affirm.js cannot load
(blocked, Affirm outage, timeout) they keep their reserved, empty line.

Styling hooks: `.hyva-affirm-message`, `[data-hyva-affirm-placement="…"]`, `.hyva-affirm-cart-message`,
`.hyva-affirm-minicart-message`, `.hyva-affirm-banner`, `.hyva-affirm-cart-button`.

## The `window.hyvaAffirm` loader contract (v1)

Both this module and `Hyva_CheckoutAffirm` ship the same loader script (`js/sdk-loader.phtml`, identical apart from
the view model it reads its configuration from). v1 is frozen: a change needs a new version number, and a newer
loader replaces an older one, so later versions keep the v1 members.

```js
window.hyvaAffirm.version;       // 1
window.hyvaAffirm.load();        // Promise that resolves with window.affirm
window.hyvaAffirm.refresh();     // re-render Affirm's placements (no return value)
window.hyvaAffirm.whenIdle(fn);  // Promise that resolves with fn's result
window.dispatchEvent(new CustomEvent('hyva-affirm:load')); // request a load from anywhere
```

- The loader is emitted only when affirm.js can work (Affirm enabled, public key, USD/CAD). Its configuration is
  `window.hyvaAffirmConfig = {public_api_key, script, locale, country_code}` (Affirm's documented
  `_affirm_config`); the first definition on a page wins.
- It installs `window.hyvaAffirm` only when none exists or the existing one is older. With both modules on a page,
  the first copy defines it and the second does nothing.
- `load()` installs Affirm's queueing stub, sets `window._affirm_config`, injects affirm.js once
  (`script[data-hyva-affirm-sdk]`) and resolves with `window.affirm` on `affirm.ui.ready`. It rejects when the
  script fails or after 10 s; a rejected load may be retried.
- `refresh()` calls `affirm.ui.refresh()`, debounced by 100 ms, once a load has started.
- `whenIdle(fn)` resolves after `DOMContentLoaded` and an idle period (`requestIdleCallback`, 2 s timeout), then
  runs `fn` and resolves with its result.
- After idle, the loader loads affirm.js by itself when the page contains a static placement:
  `.affirm-as-low-as:not([data-hyva-affirm-lazy])`, `.affirm-site-modal` or `.affirm-product-modal`. Elements
  marked `data-hyva-affirm-lazy` are loaded by their component.
- affirm.js handles only the placements present when it becomes ready or refreshes. Code that inserts static
  placements later (AJAX listings, infinite scroll, replaced page regions) calls
  `window.hyvaAffirm.load().then(() => window.hyvaAffirm.refresh())` afterwards. The module does this itself on the
  cart page (see [Cart page](#cart-page)).

### Price-state recorder (v1)

`js/price-state-recorder.phtml` runs during parsing, before Alpine starts, and keeps the latest payload of Hyvä's
price events, because Hyvä does not replay them for components that start later:

```js
window.hyvaAffirmPriceState = {
    v: 1,
    global: {finalPrice, optionPrices, activeOptions: {[optionId]: bool}, bundleOptionPrices,
             configurableSelection: {[productId]: detail}},
    products: {[productId]: {prices, qty}},   // update-prices-<id>, update-qty-<id>
};
```

Product ids are read from `[data-hyva-affirm-product-id]`. After each change it dispatches
`hyva-affirm:price-state` (`detail: {type, productId}`; `productId` is null for page-global events). A script can
ask it to track another product id with `hyva-affirm:price-state-track` (`detail: {productId}`).

## Extension points

### Suppress any placement: `AvailabilityInterface`

`Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface::isAvailable(Placement $placement, ?ProductInterface $product = null): bool`
is the single decision point for every placement, including banners, the cart button and the pixel. The
implementation is non-final, so an `after` plugin works:

```xml
<!-- etc/frontend/di.xml of your module -->
<type name="Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface">
    <plugin name="vendor_module_affirm_availability" type="Vendor\Module\Plugin\AffirmAvailability"/>
</type>
```

```php
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Magento\Catalog\Api\Data\ProductInterface;

class AffirmAvailability
{
    public function __construct(private readonly CampaignInterface $campaign) // your own service
    {
    }

    public function afterIsAvailable(
        AvailabilityInterface $subject,
        bool $result,
        Placement $placement,
        ?ProductInterface $product = null
    ): bool {
        // Example: show Affirm content only while a campaign runs.
        return $result && $this->campaign->isRunning();
    }
}
```

`Placement` cases: `PRODUCT`, `LISTING`, `CART`, `MINICART`, `BANNER`, `CART_BUTTON`, `PIXEL`. The listing cache
key includes the listing decision, but full-page cached pages keep what they rendered (see
[Cache and promotion timing](#cache-and-promotion-timing)). The cart button controller applies the same rule.

### Change message attributes: event `hyva_affirm_as_low_as_data`

Dispatched for every message before it renders, with a `DataObject` `transport`:

| Key | Type | Meaning |
|---|---|---|
| `placement` | `Placement` | `PRODUCT`, `LISTING`, `CART` or `MINICART` |
| `attributes` | `array<string, string>` | the element's attributes; change, add or remove entries |
| `cart_rule` | `array` or `null` | cart only: `{min, max, promoInRange, promoOutOfRange, higherPrecedenceApplies}` (min and max in base currency) |
| `product` | `ProductInterface` or `null` | product page and listings |
| `render` | `bool` | set to `false` to render nothing |

```xml
<!-- etc/frontend/events.xml -->
<event name="hyva_affirm_as_low_as_data">
    <observer name="vendor_module_affirm_message" instance="Vendor\Module\Observer\AffirmMessage"/>
</event>
```

```php
public function execute(\Magento\Framework\Event\Observer $observer): void
{
    $transport = $observer->getData('transport');
    $attributes = $transport->getData('attributes');
    $attributes['data-affirm-type'] = 'logo';
    $transport->setData('attributes', $attributes);
}
```

Notes: the browser components overwrite `data-amount` (product, cart, minicart) and `data-promo-id` (cart-size
switching, minicart section data); change the cart promo ids through `cart_rule`. Listing output is block-cached
per product, settings and date: an observer whose decision depends on anything else must also feed the listing
fingerprint (next section). Promo ids themselves come from Affirm's `Astound\Affirm\Helper\AsLowAs` (and its parent
`FinancingProgram`); change them with plugins on its public methods, or for the cart through `cart_rule` above.

**Do not declare plugins on `PromoIdInterface`.** Magento applies them to its implementation `PromoId`, which is
final, so the generated interceptor cannot extend it: `setup:di:compile` fails with a fatal error (in developer
mode, every page that renders a message does). To replace promo-id resolution completely, declare your own
preference for `PromoIdInterface`.

### Listing cache fingerprint: `PromoFingerprint` arguments

Add configuration that influences messages to the listing item cache key:

```xml
<type name="Hyva\AstoundAffirm\Model\Messaging\PromoFingerprint">
    <arguments>
        <argument name="configPaths" xsi:type="array">
            <item name="vendor_messaging_flag" xsi:type="string">vendor_section/general/messaging_enabled</item>
        </argument>
        <argument name="configPathPrefixes" xsi:type="array">
            <item name="vendor_campaign" xsi:type="string">vendor_section/campaign/</item>
        </argument>
    </arguments>
</type>
```

`configPaths` are single paths; `configPathPrefixes` are configuration group paths (`section/group/`).

### Themes with other price blocks: `priceBlocks`

`PriceAsLowAs` maps a price block name to the name of its message child block. A DI array argument replaces the
default, so repeat the two defaults, and declare the child block in your layout:

```xml
<type name="Hyva\AstoundAffirm\Plugin\Catalog\Block\Product\PriceAsLowAs">
    <arguments>
        <argument name="priceBlocks" xsi:type="array">
            <item name="product.info.price" xsi:type="string">hyva.affirm.aslowas.product</item>
            <item name="product.info.bundle.price" xsi:type="string">hyva.affirm.aslowas.bundle</item>
            <item name="my.price.block" xsi:type="string">my.affirm.message</item>
        </argument>
    </arguments>
</type>
```

```xml
<referenceBlock name="my.price.block">
    <block name="my.affirm.message" template="Hyva_AstoundAffirm::promotion/as-low-as.phtml"
           ifconfig="affirm/affirm_aslowas/enabled_pdp"/>
</referenceBlock>
```

The plugin is declared on `Magento\Catalog\Block\Product\Price` and `Magento\Catalog\Block\Product\View`; a price
block of another class also needs the plugin declared on that class.

### Template overrides

Every template can be overridden in a Hyvä theme under `Hyva_AstoundAffirm/templates/…`:

| Template | Purpose |
|---|---|
| `promotion/as-low-as.phtml` | the message element of every placement (keep the `affirm-as-low-as` class and the `data-*` attributes) |
| `promotion/product-end.phtml` | End of Product Info wrapper |
| `promotion/banner.phtml` | banners |
| `cart/message.phtml`, `cart/affirm-button.phtml` | cart message wrapper, cart button |
| `minicart/message.phtml` | minicart message wrapper |
| `pixel/order-confirmed.phtml` | pixel data element |
| `js/price-state-recorder.phtml`, `js/product-message.phtml`, `js/cart-message.phtml`, `js/minicart-message.phtml`, `js/pixel.phtml` | scripts and Alpine components |
| `js/cart-refresh.phtml` | re-renders new static placements after cart reloads |
| `js/sdk-loader.phtml` | the loader; do not change it (shared contract) |

### Switching off component scripts

The product and cart scripts are attached as page JS dependencies (`hyva_js_template_dependencies`). A theme that
ships its own component under another template name can switch the module's script off with `false`; values
already on the block win:

```xml
<referenceBlock name="hyva.affirm.aslowas.product">
    <arguments>
        <argument name="hyva_js_template_dependencies" xsi:type="array">
            <item name="Hyva_AstoundAffirm::js/product-message.phtml" xsi:type="boolean">false</item>
        </argument>
    </arguments>
</referenceBlock>
```

The same works for `Hyva_AstoundAffirm::js/price-state-recorder.phtml` on the product message blocks
(`hyva.affirm.aslowas.product`, `hyva.affirm.aslowas.bundle`, `hyva.affirm.aslowas.product.end.message`) and for
`Hyva_AstoundAffirm::js/cart-message.phtml` on `hyva.affirm.aslowas.cart.scripts`. The minicart script is its own
block (`hyva.affirm.aslowas.minicart.js`); remove it in layout. The loader cannot be switched off this way: message
and banner templates register it themselves. The cart refresh script (`js/cart-refresh.phtml`) does nothing until
a cart reload brings new static placements; keep it unless your theme re-renders Affirm's placements itself.

## Cache and promotion timing

What is cached is the promo id and the amount in the HTML; affirm.js fetches the message text from Affirm when
the page is viewed.

- **Listing block cache.** Hyvä caches listing items. This module adds a promo fingerprint to each item's cache
  key (`hyva_affirm:<sha1>`): the product's resolved promo id (product and category attributes, dated and default
  promo ids), the listing availability decision (including plugins), the `affirm/affirm_mfp` and
  `affirm/affirm_aslowas` settings, Affirm's enabled, mode, order total and backorder settings, any paths added
  through DI, and the store date in the store's time zone. Changes to any of these produce new item keys at once;
  orphaned entries expire with the item's cache lifetime. Hyvä computes the key for every item, cache hit or not.
  While listing messages and Affirm's "Disable for backordered items" are on, the first item of a category, search
  or catalog widget listing therefore loads the stock items of the whole listing in one query instead of one query
  per item; slider items are read one by one, memoised per request. Promo ids are resolved per item and request; they are not
  cached across requests.
- **Listings inside self-cached blocks.** A listing rendered inside a block that caches its own output, for example
  the CMS "Catalog Products List" widget (Magento caches it for 24 hours), is not rendered again while that outer
  entry is cached, so its items never reach the fingerprint. Such listings change only when the outer entry is
  cleaned: rely on the `cache:clean block_html` flush described below.
- **Full-page cache, Varnish and CDN.** Product, category and home pages carry promo ids derived from product and
  category attributes and dated rules. Product saves invalidate their pages through Magento's cache tags.
  Category attribute edits, Affirm configuration changes and the start or end of a dated promotion do **not**
  invalidate cached pages. After any of these, run
  `bin/magento cache:clean block_html full_page`, make sure Varnish is purged when it is Magento's full-page
  cache, and purge any CDN that caches HTML.
- **Dated promotions:** schedule that flush at each boundary. Cron runs in the server's time zone; Affirm's dates
  use the store's.

  ```cron
  # Promotion from 2026-11-27 to 2026-11-30 inclusive (server time = store time zone): flush at both boundaries.
  1 0 27 11 * cd /path/to/magento && php bin/magento cache:clean block_html full_page
  1 0 1 12 *  cd /path/to/magento && php bin/magento cache:clean block_html full_page
  ```

- The cart page is not full-page cached. The minicart reads the customer section data, not cached HTML.
- Pages already open in a browser change on their next load.

## Content Security Policy

- Affirm's own `etc/csp_whitelist.xml` allows `*.affirm.com` and `*.affirm.ca` for `script-src`, `frame-src`,
  `connect-src` and `img-src`. That covers affirm.js, Affirm's modal, its API and the banner and default button
  images. A checkout button image on another host needs its own `img-src` entry.
- Every inline script is followed by `$hyvaCsp->registerInlineScript()`, so it gets a nonce or hash under Hyvä's
  strict CSP.
- The Alpine components are named `Alpine.data` components whose bindings reference only properties and methods,
  so they work with Hyvä's Alpine CSP build. The minicart component was verified with the CSP build.
- Verified on 2026-10-08: no CSP violations on product, listing and cart pages under an enforced storefront policy
  that still allowed `'unsafe-inline'`. A strict storefront policy (hashes and nonces only) has not been tested yet.

## Accessibility

- Product, cart and minicart messages reserve one line and stay `invisible` (not announced) until affirm.js has
  rendered text and a "Learn more" link into them; out of range, or when affirm.js cannot load, their components
  collapse them (`display: none`). Listing messages are static markup without a component: they are rendered only
  for displayable amounts and keep their reserved line when affirm.js cannot load (an empty element, so nothing is
  announced).
- Banners are buttons with `aria-haspopup="dialog"` and visually hidden text. affirm.js replaces the button's
  `aria-label` with its text plus " - Affirm Financing (opens in modal)", so the text is what screen readers hear.
  Enter opens the modal and focus returns to the button after Escape.
- The cart button is a native form button; its image has the alt text "Checkout with Affirm".
- Messages inherit the surrounding text colour; listing messages take the colour of the theme's price. If that
  fails contrast, style `.hyva-affirm-message`.
- Layout shift: one line is reserved. Where a theme squeezes the message into a narrow column (for example a row
  beside the price), Affirm's text wraps to two or three lines; consider reserving more height there with theme CSS.

Measured on 2026-10-08 with axe-core and layout-shift tracing: no violations on the module's markup; CLS 0 on
natural page loads at 390 px and 320 px viewport width, no horizontal scrolling. In a theme that places the message
beside the price in a narrow row, CLS reached 0.07 (390 px) and 0.09 (320 px) when that row was in view while
affirm.js rendered.

## Known upstream issues

### Affirm extension 4.0.11

| Issue | Status in this module |
|---|---|
| Cart as-low-as hidden when the product page placement is not "After Price" (`Block/Promotion/AslowasAbstract.php`) | worked around: the cart message is independent |
| `data-affirm-color` double-escaped in the product and cart templates | worked around: own templates |
| "Affirm logo" has no default, so messages stay off until it is saved | worked around: defaults to `blue` |
| Configuration defaults that match no option (cart banner position `center-top`) | worked around: treated as position 0 |
| `Helper\Payment::isAffirmAvailableForProduct()` and `getConfigurableProductBackordersOptions()` pass a product id where Inventory (MSI) expects a stock id and can throw `SkuIsNotAssignedToStockException` when "Disable for backordered items" is on; the Inventory dependency is undeclared | worked around: the backorder rule (backorders enabled and quantity below 1, as Affirm's own `payment_method_is_active` observer reads it) is applied without those methods |
| `cms_index_index.xml` sets `layout="1column"` for the home page | documented: it applies only when the page configuration has no layout; a CMS home page's own layout, or a later `cms_index_index` layout declaration, wins. This module sets none, so the theme's choice is kept |
| Checkout, gateway and admin issues (country code missing from the checkout object, POST-only confirmation controller, checkout tokens in logs, random idempotency keys, and others) | outside this module; see `Hyva_CheckoutAffirm` |

### Affirm's affirm.js (observed 2026-10-08)

- The `.affirm-site-modal` click handler does not cancel the default action; banners are therefore buttons.
- It rewrites the `aria-label` of site-modal elements from their text; banners therefore carry visually hidden text.
- It binds site-modal elements and renders static messages only when it becomes ready and on `affirm.ui.refresh()`;
  it does not watch for placements added later. On the cart page the module refreshes after every `#maincontent`
  replacement (see [Cart page](#cart-page)).
- `affirm.analytics.trackOrderConfirmed` only logs a deprecation warning (see [Pixel](#order-confirmed-pixel)).
- In sandbox mode, Affirm's promos API answers with CORS errors for store domains that are not allow-listed on the
  Affirm account; affirm.js then renders generic text instead of the promotional terms. This is an Affirm account
  setting, not a module problem.

### Hyvä and Magento

- **Fixed-price bundles (Hyvä default theme 1.5.2):** the bundle price box starts from a final price that already
  includes the required default selections and then adds the selected options again, so the shown price is too high
  by the default selections. The message follows the shown price (`update-product-final-price`) and is overstated
  by the same amount.
- **Dynamic-price bundles (Magento):** a custom option on the bundle product itself is shown on the product page
  and in the price index, and the message includes it, but the cart does not charge it.
- **Themes that dispatch custom option prices as objects** (`{final, regular}`): Hyvä's bundle price box cannot
  read them and leaves paid options out of the shown bundle price; the message includes them.

## Testing

| Suite | Command | Result on 2026-10-08 |
|---|---|---|
| PHP unit tests (PHPUnit 10.5) | on a Magento installation: `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist <package>/Test/Unit` | 243 tests pass |
| CI harness, no Magento installation needed | see [`Test/ci/README.md`](Test/ci/README.md) | all steps pass, reproduced locally with PHP 8.5 against Magento 2.4.9 and 2.4.7-p10 |
| JavaScript (Node 20+, `node:test`) | `node --test Test/js/*.test.mjs` (pass the files: newer Node releases read a directory argument as a module path) | 66 tests pass |
| Static analysis | php-cs-fixer (Magento rule set), PHPCS `Magento2` (errors fail, warnings reported), PHPStan level 6, PHPCompatibility for PHP 8.1+ | clean |

The `Test/` directory is not part of release archives (`export-ignore`); run the tests from a source checkout.

The **CI harness** (`Test/ci/`) installs Magento Open Source packages from the Mage-OS mirror (no credentials) and
`affirm/magento2` from Packagist, but no Hyvä packages: the Hyvä classes the module uses are MIT test doubles
written from their public signatures. `.github/workflows/ci.yml` runs `php -l` on PHP 8.1 to 8.5, the quality
jobs (php-cs-fixer, PHPCS, PHPStan, PHPUnit) on PHP 8.1 (resolves Magento 2.4.7) and 8.3 (resolves 2.4.9), and the
JavaScript tests on Node 20; as of 2026-10-08 its steps were reproduced locally, not yet run on GitHub. The
JavaScript tests run the pure functions extracted from the templates, and check that the price reducer and
recorder copies under `Test/js/` are identical to the template code.

**Browser acceptance** (Playwright against a sandbox store; not part of this repository), 2026-10-08, Magento
2.4.9, PHP 8.5, Hyvä 1.5.2 with Tailwind 4, Affirm 4.0.11 sandbox (US):

| Area | Result |
|---|---|
| Product page: one message per main price; live custom options; options preselected before a delayed component start; re-initialised component; placements 0, 1 and 2; affirm.js only after idle and only for a displayable amount; collapse when affirm.js is blocked | pass |
| Configurable (selection price, backordered child hides the message), bundles fixed and dynamic with a paid custom option (one summary message, option counted once), grouped (no message), virtual | pass (see [Hyvä and Magento](#hyvä-and-magento)) |
| Category and search listings; listing cache key changes with promo settings | pass |
| Cart page: quantity change, shipping estimate, amount bounds, cart-size rule entering and leaving the range with and without a higher-precedence promo id | pass |
| Cart button: POST lands on Hyvä Checkout 1.3.14's payment step with Affirm preselected | pass |
| Minicart: open, add, remove, warm full-page cache, no affirm.js until the drawer opens | pass |
| Banners at every section and position; modal opens without navigation; keyboard and focus return | pass |
| Pixel suppressed in sandbox; with the developer flag, exactly one `trackOrderConfirmed` call with the correct payload (against an affirm.js stub) | pass |
| Affirm markup in CMS page content and in a CMS block widget | pass |
| axe-core, layout shift, console errors from the module | pass (see [Accessibility](#accessibility)) |
| Strict storefront CSP, CAD store, Luma store view, stock default theme (not a child theme), Lighthouse request chain; cart banners and cross-sell messages after a cart reload (refresh script added after this run; covered by unit and JavaScript tests) | not tested |

## Rollback

- **Hide all Affirm storefront content** (this also stops new Affirm checkouts; invoices, captures, voids and
  refunds of existing orders keep working): `bin/magento config:set payment/affirm_gateway/active 0` at the scope
  where it is enabled.
- **Hide only the storefront content and keep Affirm checkout:** set `affirm/affirm_aslowas/enabled_pdp`,
  `enabled_plp`, `enabled_cc`, `enabled_mcc`, `affirm/affirm_promo/enabled`,
  `payment/affirm_gateway/enable_checkout_button` and `affirm/affirm_pixel/enabled` to 0.
- After either change: `bin/magento cache:clean config block_html full_page`, then purge Varnish and the CDN.
- **Remove the module:**

  ```sh
  bin/magento module:disable Hyva_AstoundAffirm
  composer remove bredabeds/magento-hyva-affirm
  bin/magento setup:upgrade
  bin/magento hyva:config:generate
  # rebuild the theme CSS; in production mode also setup:di:compile and setup:static-content:deploy
  bin/magento cache:flush
  ```

  The module created no database objects, so there is nothing to clean up. Hyvä store views then return to
  Affirm's unmodified behaviour (no working storefront output). `Hyva_CheckoutAffirm`, if installed, is not
  affected.

## Licence

MIT, see [`LICENSE`](LICENSE). The MIT grant does not cover the Affirm name or logo, nor Affirm's banners, buttons
and scripts, which are loaded from Affirm's servers; see [`TRADEMARKS.md`](TRADEMARKS.md). The package calls Affirm's
classes and copies none of Affirm's code; [`NOTICE`](NOTICE) carries Affirm's BSD-3-Clause notice for any fragment
adapted in future. This project is not affiliated with or endorsed by Affirm, Inc. or Hyvä Themes B.V.

Contributions: see [`CONTRIBUTING.md`](CONTRIBUTING.md). Changes: see [`CHANGELOG.md`](CHANGELOG.md).
