<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Affirm;

use Astound\Affirm\Helper\AsLowAs;
use Astound\Affirm\Model\Config as AffirmConfig;
use Astound\Affirm\Model\Plugin\Product\ListProduct;
use Astound\Affirm\Model\Ui\ConfigProvider;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keeps Affirm's Luma listing message on Luma themes only.
 *
 * Hyvä discards the output of ListProduct::getProductPrice() but still calls it for every listing item, so on
 * Hyvä the original price is passed through without Affirm's promo-id queries; the Hyvä listing message comes
 * from Plugin\Hyva\ProductListItemAsLowAs.
 */
class ListProductThemeAware extends ListProduct
{
    /**
     * @param StoreManagerInterface $storeManagerInterface
     * @param ConfigProvider $configProvider
     * @param AffirmConfig $configAffirm
     * @param AsLowAs $asLowAs
     * @param CollectionFactory $productCollectionFactory
     * @param CurrentTheme $currentTheme
     */
    public function __construct(
        StoreManagerInterface $storeManagerInterface,
        ConfigProvider $configProvider,
        AffirmConfig $configAffirm,
        AsLowAs $asLowAs,
        CollectionFactory $productCollectionFactory,
        private readonly CurrentTheme $currentTheme
    ) {
        parent::__construct(
            $storeManagerInterface,
            $configProvider,
            $configAffirm,
            $asLowAs,
            $productCollectionFactory
        );
    }

    /**
     * @param mixed $subject
     * @param callable $procede
     * @param Product $product
     * @return mixed
     */
    public function aroundGetProductPrice($subject, $procede, Product $product)
    {
        if ($this->currentTheme->isHyva()) {
            return $procede($product);
        }

        return parent::aroundGetProductPrice($subject, $procede, $product);
    }
}
