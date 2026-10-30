<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

use Astound\Affirm\Helper\AsLowAs;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Promo ids from Affirm's as-low-as helper, memoised for the request.
 *
 * Products use Affirm's product chain (product attributes, then the product's categories, then the dated
 * config value, then the default). The cart uses Affirm's quote chain, which adds the cart-size rule. Plugins
 * on the helper's public methods therefore apply here too.
 */
class PromoId implements PromoIdInterface
{
    /** Attributes Affirm selects for its product promo-id collection. */
    private const PRODUCT_ATTRIBUTES = [
        'affirm_product_promo_id',
        'affirm_product_mfp_type',
        'affirm_product_mfp_priority',
        'affirm_product_mfp_start_date',
        'affirm_product_mfp_end_date',
    ];

    private const SECTION_PRODUCT = 'product';
    private const CART_SECTIONS = ['cart', 'checkout_cart'];

    /** @var array<string, string> */
    private array $productPromoIds = [];

    private ?string $cartPromoId = null;

    private ?CartPromoRule $cartRule = null;

    /** @var array<int, string> */
    private array $datedOrDefault = [];

    public function __construct(
        private readonly AsLowAs $asLowAs,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function forProduct(ProductInterface $product): string
    {
        $productId = (int) $product->getId();
        if ($productId <= 0) {
            return '';
        }

        try {
            $key = $this->getStoreId() . ':' . $productId;
            if (!isset($this->productPromoIds[$key])) {
                // A fresh one-product collection per call, exactly as Affirm's own blocks build it.
                $collection = $this->productCollectionFactory->create()
                    ->addAttributeToSelect(self::PRODUCT_ATTRIBUTES)
                    ->addAttributeToFilter('entity_id', ['eq' => $productId]);
                $this->productPromoIds[$key] = $this->toString(
                    $this->asLowAs->getFinancingProgramValueALS($collection)
                );
            }

            return $this->productPromoIds[$key];
        } catch (\Throwable $e) {
            $this->logFailure('product', $e);

            return '';
        }
    }

    public function forCart(): string
    {
        if ($this->cartPromoId === null) {
            try {
                $this->cartPromoId = $this->toString($this->asLowAs->getFinancingProgramValue());
            } catch (\Throwable $e) {
                $this->logFailure('cart', $e);

                return '';
            }
        }

        return $this->cartPromoId;
    }

    /**
     * Affirm's cart-size rule (FinancingProgram::getFinancingProgramFromCartSize) applies when the cart-size
     * promo id, minimum and maximum are all non-empty, comparing the quote base grand total inclusively. It
     * ranks below product and category promo ids. When a higher-precedence promo id applies, or a plugin on
     * the helper changed the final value (it no longer equals Affirm's own chain), the cart must not switch:
     * both values are then the final promo id.
     */
    public function cartRule(): CartPromoRule
    {
        if ($this->cartRule !== null) {
            return $this->cartRule;
        }

        $final = $this->forCart();
        try {
            $customer = $this->toString($this->asLowAs->getCustomerFinancingProgram());
            $products = $this->toString($this->asLowAs->getFinancingProgramFromProducts());
            $categories = $this->toString($this->asLowAs->getFinancingProgramFromCategories());
            $cartSize = $this->toString($this->asLowAs->getFinancingProgramFromCartSize());
            $outOfRange = $this->datedOrDefault();
            $natural = $this->firstNonEmpty([$customer, $products, $categories, $cartSize]) ?? $outOfRange;

            $inRange = $this->toString($this->asLowAs->getFinancingProgramCartSizeValue());
            $min = $this->asLowAs->getFinancingProgramCartSizeMinOrderTotal();
            $max = $this->asLowAs->getFinancingProgramCartSizeMaxOrderTotal();
            // Same truthiness as Affirm: '0' disables the rule.
            $ruleApplies = (bool) $inRange && !empty($min) && !empty($max) && is_numeric($min) && is_numeric($max);
            $higherPrecedence = $customer || $products || $categories || $final !== $natural;

            if (!$ruleApplies) {
                $this->cartRule = CartPromoRule::fixed($final);
            } elseif ($higherPrecedence) {
                $this->cartRule = new CartPromoRule((float) $min, (float) $max, $final, $final, true);
            } else {
                $this->cartRule = new CartPromoRule((float) $min, (float) $max, $inRange, $outOfRange, false);
            }
        } catch (\Throwable $e) {
            $this->logFailure('cart rule', $e);
            $this->cartRule = CartPromoRule::fixed($final);
        }

        return $this->cartRule;
    }

    public function forBanner(string $section, ?ProductInterface $product = null): string
    {
        if ($section === self::SECTION_PRODUCT && $product !== null) {
            return $this->forProduct($product);
        }
        if (in_array($section, self::CART_SECTIONS, true)) {
            return $this->forCart();
        }

        try {
            return $this->datedOrDefault();
        } catch (\Throwable $e) {
            $this->logFailure('banner', $e);

            return '';
        }
    }

    /**
     * Affirm's configured promo id for the current date range, otherwise its default (no visitor data).
     */
    private function datedOrDefault(): string
    {
        $storeId = $this->getStoreId();
        if (!isset($this->datedOrDefault[$storeId])) {
            $this->datedOrDefault[$storeId] = $this->asLowAs->isFinancingProgramValidCurrentDate()
                ? $this->toString($this->asLowAs->getFinancingProgramDateRange())
                : $this->toString($this->asLowAs->getFinancingProgramDefault());
        }

        return $this->datedOrDefault[$storeId];
    }

    /**
     * First value Affirm's chain would accept (PHP truthiness, so '0' is skipped like in Affirm's helper).
     *
     * @param string[] $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value) {
                return $value;
            }
        }

        return null;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function getStoreId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }

    private function logFailure(string $context, \Throwable $e): void
    {
        $this->logger->warning(
            'Hyva_AstoundAffirm: promo id resolution failed (' . $context . '), using none.',
            ['error' => get_class($e) . ': ' . $e->getMessage()]
        );
    }
}
