<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

use Hyva\AstoundAffirm\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryPreloader;

/**
 * Affirm's "disable for backordered items" rule for storefront messages.
 *
 * Affirm 4.0.11's product-level helpers (Helper\Payment::isAffirmAvailableForProduct() and
 * getConfigurableProductBackordersOptions()) pass the product id as an MSI stock id and throw on most
 * products, so the rule is evaluated here instead, the way Affirm's own payment_method_is_active observer
 * reads stock: through the legacy stock registry, an item counts as backordered when backorders are enabled
 * and its quantity is below 1. Listings preload the stock items of all their products in one query.
 */
class BackorderResolver
{
    /** @var array<int, bool> */
    private array $backorderedByProductId = [];

    public function __construct(
        private readonly Config $config,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockRegistryPreloader $stockRegistryPreloader
    ) {
    }

    /**
     * payment/affirm_gateway/disable_for_backordered_items.
     */
    public function isRuleEnabled(): bool
    {
        return $this->config->isBackorderRuleEnabled();
    }

    /**
     * Whether a simple (non-composite) product is backordered while the rule is enabled.
     *
     * Composite products (configurable, bundle, grouped) return false: their children are resolved with
     * backorderedChildIds() because the shopper's selection decides.
     */
    public function isBackordered(ProductInterface $product): bool
    {
        $productId = (int) $product->getId();
        if ($productId <= 0 || !$this->isRuleEnabled() || $this->isComposite($product)) {
            return false;
        }

        return $this->isProductIdBackordered($productId);
    }

    /**
     * Child product ids (configurable children, bundle selections, grouped items) that are backordered while
     * the rule is enabled.
     *
     * @return int[]
     */
    public function backorderedChildIds(ProductInterface $product): array
    {
        $productId = (int) $product->getId();
        if ($productId <= 0 || !$product instanceof Product || !$this->isRuleEnabled()
            || !$this->isComposite($product)
        ) {
            return [];
        }

        $childIds = [];
        foreach ($product->getTypeInstance()->getChildrenIds($productId, false) as $group) {
            foreach ((array) $group as $childId) {
                $childIds[(int) $childId] = true;
            }
        }
        unset($childIds[0]);

        $backordered = [];
        foreach (array_keys($childIds) as $childId) {
            if ($this->isProductIdBackordered($childId)) {
                $backordered[] = $childId;
            }
        }
        sort($backordered);

        return $backordered;
    }

    /**
     * Loads the stock items of many products in one query while the rule is enabled, so that isBackordered()
     * answers for them without a query each. Composite products are skipped (their own stock is not read), and so
     * are products already resolved. Products without a stock item are left to the per-product lookup.
     *
     * @param iterable<mixed> $products
     */
    public function preload(iterable $products): void
    {
        if (!$this->isRuleEnabled()) {
            return;
        }
        $productIds = [];
        foreach ($products as $product) {
            if (!$product instanceof ProductInterface || $this->isComposite($product)) {
                continue;
            }
            $productId = (int) $product->getId();
            if ($productId > 0 && !array_key_exists($productId, $this->backorderedByProductId)) {
                $productIds[$productId] = $productId;
            }
        }
        if ($productIds === []) {
            return;
        }

        // The preloader also fills the stock registry's storage, which StockRegistryInterface::getStockItem() reads.
        foreach ($this->stockRegistryPreloader->preloadStockItems(array_values($productIds)) as $stockItem) {
            $productId = (int) $stockItem->getProductId();
            if (isset($productIds[$productId])) {
                $this->backorderedByProductId[$productId] = $this->isStockItemBackordered($stockItem);
            }
        }
    }

    private function isComposite(ProductInterface $product): bool
    {
        return $product instanceof Product && $product->isComposite();
    }

    private function isProductIdBackordered(int $productId): bool
    {
        if (!array_key_exists($productId, $this->backorderedByProductId)) {
            $this->backorderedByProductId[$productId] = $this->isStockItemBackordered(
                $this->stockRegistry->getStockItem($productId)
            );
        }

        return $this->backorderedByProductId[$productId];
    }

    private function isStockItemBackordered(StockItemInterface $stockItem): bool
    {
        return (int) $stockItem->getBackorders() !== 0 && (float) $stockItem->getQty() < 1;
    }
}
