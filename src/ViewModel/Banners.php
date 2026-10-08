<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Astound\Affirm\Helper\Payment as AffirmPaymentHelper;
use Astound\Affirm\Model\Config as AffirmConfig;
use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm's promotional banners (Affirm Promos > Banner Settings) for the Hyvä placements.
 *
 * Applies the rules of Affirm's Block\Promotion\Banners without its Luma block: the global banner switch, each
 * section's Display flag, Position and Size (an image on Affirm's CDN; French images for fr_CA) and the
 * developer container HTML. Sections are 'home', 'category', 'product' and 'cart'; Affirm's own names
 * 'homepage' and 'checkout_cart' are accepted too.
 *
 * Differences from Luma, all deliberate:
 * - Promo ids never depend on the visitor on full-page cached pages: home and category banners use Affirm's
 *   dated or default promo id, product banners the product's promo id, cart banners the cart's (Luma used the
 *   session's financing program for every section).
 * - Banners require the gateway to be active with a public key, because affirm.js opens their modal.
 * - A configured position that is not a number (Affirm's cart default "center-top") means position 0, the
 *   centre top position it names.
 * - The container HTML is used only when it contains the {container} placeholder.
 */
class Banners implements ArgumentInterface
{
    public const SECTION_HOME = 'home';
    public const SECTION_CATEGORY = 'category';
    public const SECTION_PRODUCT = 'product';
    public const SECTION_CART = 'cart';

    public const CONTAINER_PLACEHOLDER = '{container}';

    /** This module's section names and Affirm's own, mapped to Affirm's configuration section. */
    private const SECTIONS = [
        self::SECTION_HOME => 'homepage',
        'homepage' => 'homepage',
        self::SECTION_CATEGORY => 'category',
        self::SECTION_PRODUCT => 'product',
        self::SECTION_CART => 'checkout_cart',
        'checkout_cart' => 'checkout_cart',
    ];

    /** The data-page-type of each Affirm section, as Affirm's Luma banners set it. */
    private const PAGE_TYPES = [
        'homepage' => 'homepage',
        'category' => 'category',
        'product' => 'product',
        'checkout_cart' => 'cart',
    ];

    /** Affirm's banner sizes are "<width>x<height>", optionally prefixed with "--" (admin option values). */
    private const SIZE_PATTERN = '/^([1-9]\d{0,3})x([1-9]\d{0,3})$/';

    /**
     * Affirm's payment helper loads the session quote in its constructor, so DI injects its proxy: only cart
     * banners use it, and home, category and product pages are full-page cached.
     */
    public function __construct(
        private readonly Config $config,
        private readonly AffirmConfig $affirmConfig,
        private readonly AvailabilityInterface $availability,
        private readonly PromoIdInterface $promoId,
        private readonly AffirmPaymentHelper $affirmPaymentHelper,
        private readonly CurrentProduct $currentProduct,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * True when the section's banner belongs at this position: Affirm's banner switch and the section's Display,
     * Position and Size settings, availability (gateway, key, currency, backorders on product pages) and, on the
     * cart, Affirm's own availability for the cart. Never throws.
     */
    public function isVisible(string $section, int|string $position): bool
    {
        $affirmSection = self::SECTIONS[$section] ?? null;
        if ($affirmSection === null || !is_numeric($position)) {
            return false;
        }

        try {
            if (!$this->config->getValue($this->path($affirmSection, 'display'))
                || $this->getConfiguredPosition($affirmSection) !== (int) $position
                || $this->getSize($section) === null
                || !$this->availability->isAvailable(Placement::BANNER, $this->getProduct($affirmSection))
            ) {
                return false;
            }

            // The cart page is not cached: Affirm's quote checks (currency, order total range, country) apply.
            return $affirmSection !== 'checkout_cart' || (bool) $this->affirmPaymentHelper->isAffirmAvailable();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: banner check failed, banner hidden.',
                ['section' => $affirmSection, 'error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * The section's banner size, or null when unset, a size-group heading or malformed.
     *
     * @return array{width: int, height: int}|null
     */
    public function getSize(string $section): ?array
    {
        $affirmSection = self::SECTIONS[$section] ?? null;
        if ($affirmSection === null) {
            return null;
        }
        $value = $this->config->getValue($this->path($affirmSection, 'size'));
        if (!is_scalar($value)
            || !preg_match(self::SIZE_PATTERN, str_replace('--', '', trim((string) $value)), $matches)
        ) {
            return null;
        }

        return ['width' => (int) $matches[1], 'height' => (int) $matches[2]];
    }

    /**
     * Affirm's banner image for the section's size ('' without a valid size). Affirm chooses the French image
     * set for the fr_CA locale.
     */
    public function getImageUrl(string $section): string
    {
        $size = $this->getSize($section);
        if ($size === null) {
            return '';
        }

        try {
            return $this->affirmConfig->getAffirmAssetsUrl() . $size['width'] . 'x' . $size['height'] . '.png';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: banner image URL unavailable.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return '';
        }
    }

    /**
     * The developer container HTML around the banner, split at its {container} placeholder (admin-trusted HTML,
     * rendered unescaped). Both parts are empty when no container with a placeholder is configured.
     *
     * @return array{start: string, end: string}
     */
    public function getContainer(string $section): array
    {
        $affirmSection = self::SECTIONS[$section] ?? null;
        $html = $affirmSection === null
            ? null
            : $this->config->getValue('affirm/affirm_developer/' . $affirmSection . '_container');
        if (!is_string($html) || !str_contains($html, self::CONTAINER_PLACEHOLDER)) {
            return ['start' => '', 'end' => ''];
        }
        // Like Affirm: the text before the first placeholder and after the last one.
        $parts = explode(self::CONTAINER_PLACEHOLDER, $html);

        return ['start' => (string) reset($parts), 'end' => (string) end($parts)];
    }

    /**
     * Promo id for the section's banner ('' for none): see the class description.
     */
    public function getPromoId(string $section): string
    {
        $affirmSection = self::SECTIONS[$section] ?? null;
        if ($affirmSection === null) {
            return '';
        }

        try {
            return $this->promoId->forBanner($affirmSection, $this->getProduct($affirmSection));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: banner promo id unavailable, using none.',
                ['section' => $affirmSection, 'error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return '';
        }
    }

    /**
     * The data-page-type affirm.js receives: homepage, category, product or cart ('' for unknown sections).
     */
    public function getPageType(string $section): string
    {
        $affirmSection = self::SECTIONS[$section] ?? null;

        return $affirmSection === null ? '' : self::PAGE_TYPES[$affirmSection];
    }

    /**
     * Configured position; values that are not numbers (Affirm's cart default "center-top") mean 0.
     */
    private function getConfiguredPosition(string $affirmSection): int
    {
        $position = $this->config->getValue($this->path($affirmSection, 'position'));

        return is_numeric($position) ? (int) $position : 0;
    }

    /**
     * The product of the product page (Hyvä's current product), for product banners only.
     */
    private function getProduct(string $affirmSection): ?ProductInterface
    {
        if ($affirmSection !== 'product' || !$this->currentProduct->exists()) {
            return null;
        }

        return $this->currentProduct->get();
    }

    private function path(string $affirmSection, string $field): string
    {
        return 'affirm/affirm_promo_' . $affirmSection . '/' . $field;
    }
}
