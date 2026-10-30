# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- `Model\Messaging\PromoId` is no longer `final`, so plugins on `PromoIdInterface` work instead of breaking
  `setup:di:compile`.
- The "can affirm.js load" check (Affirm enabled, public key, USD/CAD) lives in one place, `Config::canLoadAffirmJs()`,
  used by the availability rules and the loader view model.
- README rewritten in plain language; the technical detail moved to `docs/reference.md`.

## [0.1.0] - 2026-10-08

### Added

- Product page as-low-as message attached to the product price block, before or after the price or at the end of
  the product info (Affirm's "PDP Placement options for As low as"). It follows custom options, configurable
  selections and bundle selections live through a page-level price-state recorder (`window.hyvaAffirmPriceState`),
  so components that start late or are re-initialised begin with the current price.
- Bundle products: one message in the bundle summary. Grouped products: no message. Configurable products: the
  message hides while the selection resolves to backordered children ("Disable for backordered items").
- Static listing message on every Hyvä `ProductListItem` (category, search, sliders, widgets), with a promo
  fingerprint in the item block cache key (promo id, availability, Affirm settings, store date).
- Cart page message that follows Hyvä's `update-totals` event (amount, amount bounds, Affirm's cart-size promo rule)
  and cart reloads.
- Minicart message in the Hyvä 1.4+ cart drawer, fed from the customer `cart` section (`affirm_amount`,
  `affirm_minicart`); affirm.js loads only when the drawer opens.
- Affirm's promotional banners on home, category, product and cart pages at Affirm's positions, inside the developer
  container HTML, as buttons that open Affirm's site modal.
- Cart "Checkout with Affirm" button: a form post to `hyva_affirm/cart/start` that selects Affirm on the quote and
  continues to the checkout (Hyvä Checkout's payment step when available). It also records the choice in the
  checkout session (`hyva_affirm_preselect_quote_id`, the quote id; removed on failure) so a checkout integration
  can re-apply it after the checkout's own default method, such as Hyvä Checkout's "Enable first available payment
  method" (`Hyva_CheckoutAffirm` does).
- Order-confirmed pixel on the success page with Affirm's Luma payload; suppressed in sandbox unless the hidden
  developer flag `affirm/hyva/pixel_in_sandbox` is set.
- Detection of Affirm's documented markup in CMS pages, CMS blocks and Page Builder content.
- `window.hyvaAffirm` loader contract v1, shared with `Hyva_CheckoutAffirm`: lazy affirm.js loading after idle,
  only for placements that can show something.
- Extension points: `AvailabilityInterface` (one seam for every placement), the `hyva_affirm_as_low_as_data` event,
  `PromoFingerprint` and `PriceAsLowAs` DI arguments, overridable templates.
- Honours Affirm's existing settings; the message colour defaults to Affirm's blue logo when "Affirm logo" is unset.
- Affirm's backorder rule applied without Affirm's Inventory (MSI) helper calls that can throw on MSI stores.
- Theme-aware preferences for Affirm's Luma listing, category and minicart plugins (Luma store views unchanged);
  removal of Affirm's Luma blocks and `affirm-promo.css` on Hyvä; empty fallback overrides for Affirm's six Luma
  storefront templates.
- CSP-compatible Alpine.js components and registered inline scripts; Tailwind CSS 3 and 4 sources.
- `en_US` and `fr_CA` translations.
- Unit tests, JavaScript tests, a CI harness that needs no Hyvä packages and no repository credentials (Mage-OS
  mirror, MIT test doubles) and a GitHub Actions workflow; licence files.

### Changed

- Cart page: the loader and the cart message's component script are registered by the template-less block
  `hyva.affirm.aslowas.cart.scripts` in `content`, which renders on empty carts too, so a message that appears
  when Hyvä replaces an empty cart page with a full one finds its scripts. Themes that switch off
  `js/cart-message.phtml` do so on that block instead of `hyva.affirm.aslowas.cart`.
- `LICENSE` is the unmodified MIT licence text, so licence detectors recognise it; the statement that the MIT
  licence does not cover Affirm's trademarks moved to `NOTICE` (and stays in `TRADEMARKS.md` and the README).

### Fixed

- Cart button: with Hyvä Checkout's "Enable first available payment method" on, a shopper on a new session (for
  example a guest) reached the payment step with the first available method checked instead of Affirm, because
  Hyvä Checkout replaced the method on the session's first checkout page load. The button now records its choice
  for the checkout integration (see Added and the README's cart button section).
- Cart page: banners, CMS placements and cross-sell listing messages stayed dead (banner buttons without a click
  handler, empty message lines) after every `#maincontent` replacement unless the cart message was in range. The
  new page script `js/cart-refresh.phtml` refreshes affirm.js whenever a cart reload brings static placements it
  has not seen, independent of the cart message; it and the loader are page JS dependencies of the cart banner and
  message blocks, so a banner that first appears after a reload finds `window.hyvaAffirm`.
- Listings: the item cache key read each product's stock item with its own query on every render, cache hit or
  not. The first item of a category, search or catalog widget listing now loads the stock items of the whole
  listing in one query (`BackorderResolver::preload()`, through Magento's `StockRegistryPreloader`), only while
  listing messages are enabled and memoised per request.
- README: plugins on `PromoIdInterface` are not ignored but break compilation (`PromoId` is final; use a
  preference, plugins on Affirm's helpers or the `hyva_affirm_as_low_as_data` event); listings inside self-cached
  blocks such as the CMS "Catalog Products List" widget rely on the documented `block_html` flush; listing messages
  are static and keep their reserved line when affirm.js cannot load (only product, cart and minicart messages
  collapse); the cart refresh behaviour and the rule for code that inserts placements later are documented.
