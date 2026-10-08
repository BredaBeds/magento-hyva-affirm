<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

use Astound\Affirm\Model\Ui\ConfigProvider;
use Hyva\AstoundAffirm\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm's availability rules per storefront placement, in one place.
 *
 * Every placement requires the gateway to be active, a public key for the mode and currency, and a supported
 * currency. Product and listing decisions use configuration and catalog data only, because those pages are
 * full-page cached; cart decisions ask Affirm's payment method about the session quote (the cart page is not
 * cached). Never throws: any error makes the placement unavailable.
 */
class Availability implements AvailabilityInterface
{
    private const MSRP_PRICE_CODE = 'msrp_price';
    private const TYPE_GROUPED = 'grouped';

    private ?MethodInterface $paymentMethod = null;

    public function __construct(
        private readonly Config $config,
        private readonly BackorderResolver $backorderResolver,
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentHelper $paymentHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isAvailable(Placement $placement, ?ProductInterface $product = null): bool
    {
        try {
            if (!$this->isConfigured()) {
                return false;
            }

            return match ($placement) {
                Placement::PRODUCT => $this->isProductMessageAvailable($product),
                Placement::LISTING => $this->isListingMessageAvailable($product),
                Placement::CART => $this->config->isAsLowAsEnabled(Placement::CART) && $this->isQuoteAvailable(),
                Placement::MINICART => $this->config->isAsLowAsEnabled(Placement::MINICART),
                Placement::BANNER => $this->config->isBannerEnabled()
                    && ($product === null || !$this->backorderResolver->isBackordered($product)),
                Placement::CART_BUTTON => $this->config->isCheckoutButtonEnabled() && $this->isQuoteAvailable(),
                Placement::PIXEL => $this->config->isPixelEnabled()
                    && (!$this->config->isSandbox() || $this->config->isPixelInSandboxForced()),
            };
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: availability check failed, placement hidden.',
                ['placement' => $placement->value, 'error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * Requirements shared by every placement.
     */
    private function isConfigured(): bool
    {
        return $this->config->isActive()
            && $this->config->getPublicApiKey() !== ''
            && $this->config->isCurrencyValid();
    }

    private function isProductMessageAvailable(?ProductInterface $product): bool
    {
        if (!$product instanceof Product || !$product->getId()
            || !$this->config->isAsLowAsEnabled(Placement::PRODUCT)
        ) {
            return false;
        }

        // Grouped products have no price block; Affirm shows no product-page message for them either.
        return $product->getTypeId() !== self::TYPE_GROUPED
            && $product->isSaleable()
            && $this->isPriceVisible($product)
            && !$this->backorderResolver->isBackordered($product);
    }

    private function isListingMessageAvailable(?ProductInterface $product): bool
    {
        if (!$product instanceof Product || !$product->getId()
            || !$this->config->isAsLowAsEnabled(Placement::LISTING)
        ) {
            return false;
        }

        return $this->isPriceVisible($product) && !$this->backorderResolver->isBackordered($product);
    }

    /**
     * False when the price is hidden: "can show price" off, or MSRP "click for price" (the same test Hyvä's
     * product price template uses).
     */
    private function isPriceVisible(Product $product): bool
    {
        if ($product->getData('can_show_price') === false) {
            return false;
        }
        $prices = $product->getPriceInfo()->getPrices();
        if ($prices instanceof \Traversable) {
            $prices = iterator_to_array($prices);
        }
        $msrpPrice = is_array($prices) ? $prices[self::MSRP_PRICE_CODE] ?? null : null;
        if ($msrpPrice === null
            || !method_exists($msrpPrice, 'canApplyMsrp')
            || !method_exists($msrpPrice, 'isMinimalPriceLessMsrp')
        ) {
            return true;
        }

        return !($msrpPrice->canApplyMsrp($product) && $msrpPrice->isMinimalPriceLessMsrp($product));
    }

    /**
     * Affirm's payment method decides for the session quote; its availability event includes Affirm's own
     * backorder rule over the quote items.
     */
    private function isQuoteAvailable(): bool
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote->getId() || !$quote->hasItems()) {
            return false;
        }

        return (bool) $this->getPaymentMethod()->isAvailable($quote);
    }

    private function getPaymentMethod(): MethodInterface
    {
        if ($this->paymentMethod === null) {
            $this->paymentMethod = $this->paymentHelper->getMethodInstance(ConfigProvider::CODE);
        }

        return $this->paymentMethod;
    }
}
