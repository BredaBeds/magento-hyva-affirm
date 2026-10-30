<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model;

use Astound\Affirm\Model\Config as AffirmConfig;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Every Affirm setting the storefront module reads, at store scope.
 *
 * Affirm's admin fields keep their meaning; values are read with store scope so website and default values
 * are inherited. Keys, country and locale follow the store's current currency exactly like Affirm's own
 * Model\Config. Every getter takes an optional store id (null = current store).
 */
class Config
{
    public const PATH_GATEWAY = 'payment/affirm_gateway/';
    public const PATH_ASLOWAS = 'affirm/affirm_aslowas/';
    public const PATH_PLACEMENT_PDP = 'affirm/affirm_aslowas_developer/placement';
    public const PATH_PROMO_ENABLED = 'affirm/affirm_promo/enabled';
    public const PATH_PIXEL_ENABLED = 'affirm/affirm_pixel/enabled';
    public const PATH_PIXEL_IN_SANDBOX = 'affirm/hyva/pixel_in_sandbox';

    public const MODE_SANDBOX = 'sandbox';

    public const PLACEMENT_PDP_AFTER_PRICE = 0;
    public const PLACEMENT_PDP_BEFORE_PRICE = 1;
    public const PLACEMENT_PDP_END_OF_INFO = 2;

    public const DEFAULT_LOGO_COLOR = 'blue';

    private const LOGO_COLORS = ['blue', 'black', 'white'];
    private const CURRENCY_CAD = 'CAD';
    private const VALID_CURRENCIES = ['USD', 'CAD'];
    private const CANADIAN_LOCALES = ['en_CA', 'fr_CA'];
    private const ASLOWAS_FLAGS = [
        'product' => 'enabled_pdp',
        'listing' => 'enabled_plp',
        'cart' => 'enabled_cc',
        'minicart' => 'enabled_mcc',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResolverInterface $localeResolver
    ) {
    }

    /**
     * Raw store-scoped value of any configuration path (a group path returns the group's values as an array).
     */
    public function getValue(string $path, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether affirm.js can work at all: Affirm enabled, a public key for the mode and currency, USD or CAD.
     */
    public function canLoadAffirmJs(?int $storeId = null): bool
    {
        return $this->isActive($storeId)
            && $this->isCurrencyValid($storeId)
            && $this->getPublicApiKey($storeId) !== '';
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_GATEWAY . 'active', $storeId);
    }

    public function getMode(?int $storeId = null): string
    {
        return $this->getString(self::PATH_GATEWAY . 'mode', $storeId);
    }

    public function isSandbox(?int $storeId = null): bool
    {
        return $this->getMode($storeId) === self::MODE_SANDBOX;
    }

    /**
     * Public key for the mode and the store's current currency ('_ca' keys for CAD), as Affirm selects it.
     */
    public function getPublicApiKey(?int $storeId = null): string
    {
        $field = 'public_api_key_' . ($this->isSandbox($storeId) ? 'sandbox' : 'production')
            . ($this->getCurrency($storeId) === self::CURRENCY_CAD ? '_ca' : '');

        return $this->getString(self::PATH_GATEWAY . $field, $storeId);
    }

    /**
     * URL of affirm.js for the configured mode.
     */
    public function getScriptUrl(?int $storeId = null): string
    {
        return $this->isSandbox($storeId) ? AffirmConfig::JS_URL_SANDBOX : AffirmConfig::JS_URL_PRODUCTION;
    }

    /**
     * Locale for affirm.js: CAD stores use their en_CA/fr_CA locale (en_CA otherwise), all others en_US.
     */
    public function getLocale(?int $storeId = null): string
    {
        if ($this->getCurrency($storeId) !== self::CURRENCY_CAD) {
            return 'en_US';
        }
        $locale = $storeId === null
            ? (string) $this->localeResolver->getLocale()
            : $this->getString('general/locale/code', $storeId);

        return in_array($locale, self::CANADIAN_LOCALES, true) ? $locale : 'en_CA';
    }

    /**
     * Affirm country code: 'CAN' for CAD stores, 'USA' otherwise.
     */
    public function getCountryCode(?int $storeId = null): string
    {
        return $this->getCurrency($storeId) === self::CURRENCY_CAD ? 'CAN' : 'USA';
    }

    /**
     * The store's current (display) currency code.
     */
    public function getCurrency(?int $storeId = null): string
    {
        return (string) $this->getStore($storeId)->getCurrentCurrencyCode();
    }

    /**
     * Affirm supports USD and CAD. Both the base currency (what Affirm checks) and the displayed currency
     * (what messages show and what selects the keys) must be supported.
     */
    public function isCurrencyValid(?int $storeId = null): bool
    {
        $store = $this->getStore($storeId);

        return in_array((string) $store->getBaseCurrencyCode(), self::VALID_CURRENCIES, true)
            && in_array((string) $store->getCurrentCurrencyCode(), self::VALID_CURRENCIES, true);
    }

    /**
     * Affirm's per-surface as-low-as switch (enabled_pdp, enabled_plp, enabled_cc, enabled_mcc); false for
     * placements that are not messages.
     */
    public function isAsLowAsEnabled(Placement $placement, ?int $storeId = null): bool
    {
        $field = self::ASLOWAS_FLAGS[$placement->value] ?? null;

        return $field !== null && $this->isFlag(self::PATH_ASLOWAS . $field, $storeId);
    }

    /**
     * Message colour from affirm/affirm_aslowas/logo; Affirm's primary logo colour when unset or unknown.
     */
    public function getLogoColor(?int $storeId = null): string
    {
        $logo = $this->getString(self::PATH_ASLOWAS . 'logo', $storeId);

        return in_array($logo, self::LOGO_COLORS, true) ? $logo : self::DEFAULT_LOGO_COLOR;
    }

    public function isLearnMoreEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_ASLOWAS . 'learn_more', $storeId);
    }

    /**
     * Product page placement: 0 after the price, 1 before the price, 2 end of product info.
     */
    public function getPlacementPdp(?int $storeId = null): int
    {
        $value = $this->getValue(self::PATH_PLACEMENT_PDP, $storeId);
        $placement = is_numeric($value) ? (int) $value : self::PLACEMENT_PDP_AFTER_PRICE;

        return in_array(
            $placement,
            [self::PLACEMENT_PDP_AFTER_PRICE, self::PLACEMENT_PDP_BEFORE_PRICE, self::PLACEMENT_PDP_END_OF_INFO],
            true
        ) ? $placement : self::PLACEMENT_PDP_AFTER_PRICE;
    }

    public function getLimits(?int $storeId = null): Limits
    {
        return new Limits(
            $this->getPositiveFloat(self::PATH_GATEWAY . 'min_order_total', $storeId),
            $this->getPositiveFloat(self::PATH_GATEWAY . 'max_order_total', $storeId),
            $this->getPositiveFloat(self::PATH_ASLOWAS . 'min_mpp', $storeId)
        );
    }

    public function isCheckoutButtonEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_GATEWAY . 'enable_checkout_button', $storeId);
    }

    /**
     * Image URL of the cart "Checkout with Affirm" button (admin field checkout_button_code).
     */
    public function getCheckoutButtonImage(?int $storeId = null): string
    {
        return $this->getString(self::PATH_GATEWAY . 'checkout_button_code', $storeId);
    }

    /**
     * Button width in pixels, or 0 when unset or invalid.
     */
    public function getCheckoutButtonWidth(?int $storeId = null): int
    {
        $width = $this->getValue(self::PATH_GATEWAY . 'checkout_button_width', $storeId);

        return is_numeric($width) && (int) $width > 0 ? (int) $width : 0;
    }

    /**
     * Global switch for Affirm's promotional banners (affirm/affirm_promo/enabled).
     */
    public function isBannerEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_PROMO_ENABLED, $storeId);
    }

    public function isPixelEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_PIXEL_ENABLED, $storeId);
    }

    /**
     * Hidden developer flag: emit the order-confirmed pixel in sandbox mode too (for tests only).
     */
    public function isPixelInSandboxForced(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_PIXEL_IN_SANDBOX, $storeId);
    }

    /**
     * payment/affirm_gateway/disable_for_backordered_items.
     */
    public function isBackorderRuleEnabled(?int $storeId = null): bool
    {
        return $this->isFlag(self::PATH_GATEWAY . 'disable_for_backordered_items', $storeId);
    }

    private function getStore(?int $storeId): Store
    {
        $store = $this->storeManager->getStore($storeId);
        if (!$store instanceof Store) {
            throw new \UnexpectedValueException('The store does not provide currency information.');
        }

        return $store;
    }

    private function isFlag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function getString(string $path, ?int $storeId): string
    {
        $value = $this->getValue($path, $storeId);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function getPositiveFloat(string $path, ?int $storeId): ?float
    {
        $value = $this->getValue($path, $storeId);
        if (!is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
