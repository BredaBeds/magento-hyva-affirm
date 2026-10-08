<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Hyva;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoFingerprint;
use Hyva\Theme\ViewModel\ProductListItem;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\Data\Collection;
use Magento\Framework\View\Element\AbstractBlock;
use Psr\Log\LoggerInterface;

/**
 * Adds the Affirm promo fingerprint to the listing item block cache key, so promo id, availability, setting
 * and date changes render a fresh item instead of a cached message.
 *
 * Hyvä computes the key for every item, cache hit or not, and the fingerprint reads each product's stock item
 * (Affirm's backorder rule). The first item of a category, search or widget listing therefore loads the stock
 * items of the whole listing in one query.
 */
class ProductListItemCacheKey
{
    public const KEY_PREFIX = 'hyva_affirm:';

    private const UNAVAILABLE = 'unavailable';

    /**
     * Listing collections already handled in this request.
     *
     * @var \WeakMap<Collection, bool>
     */
    private \WeakMap $seenCollections;

    public function __construct(
        private readonly PromoFingerprint $fingerprint,
        private readonly Config $config,
        private readonly BackorderResolver $backorderResolver,
        private readonly LoggerInterface $logger
    ) {
        $this->seenCollections = new \WeakMap();
    }

    /**
     * @param ProductListItem $subject
     * @param array<mixed> $result
     * @param Product $product
     * @param AbstractBlock $block the item renderer block
     * @param string $viewMode
     * @param string $templateType
     * @return array<mixed>
     */
    public function afterGetItemCacheKeyInfo(
        ProductListItem $subject,
        array $result,
        Product $product,
        AbstractBlock $block,
        string $viewMode,
        string $templateType
    ): array {
        $this->preloadListingStock($block);
        try {
            $fingerprint = $this->fingerprint->forProduct($product);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: listing cache fingerprint failed.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );
            $fingerprint = self::UNAVAILABLE;
        }
        $result[] = self::KEY_PREFIX . $fingerprint;

        return $result;
    }

    private function preloadListingStock(AbstractBlock $itemRenderer): void
    {
        try {
            $collection = $this->findListingCollection($itemRenderer);
            if ($collection === null || isset($this->seenCollections[$collection])) {
                return;
            }
            $this->seenCollections[$collection] = true;
            if ($this->config->isActive() && $this->config->isAsLowAsEnabled(Placement::LISTING)) {
                $this->backorderResolver->preload($collection->getItems());
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: listing stock preload failed.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );
        }
    }

    /**
     * The loaded product collection of the block the item renders in: category and search results, catalog
     * widgets. Hyvä makes that block the renderer's layout parent while it renders an item. Sliders and other
     * parents that hold no loaded collection get none, and their items are resolved one by one as before.
     */
    private function findListingCollection(AbstractBlock $itemRenderer): ?Collection
    {
        $parent = $itemRenderer->getParentBlock();
        if (!$parent instanceof AbstractBlock) {
            return null;
        }
        $collection = $parent instanceof ListProduct
            ? $parent->getLoadedProductCollection()
            : $parent->getData('product_collection');

        return $collection instanceof Collection && $collection->isLoaded() ? $collection : null;
    }
}
