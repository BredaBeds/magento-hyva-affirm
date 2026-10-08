<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Affirm;

use Astound\Affirm\Helper\AsLowAs;
use Astound\Affirm\Model\Config as AffirmConfig;
use Astound\Affirm\Model\Plugin\Category\View;
use Astound\Affirm\Model\Ui\ConfigProvider;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keeps Affirm's Luma listing widget initialisation (a RequireJS data-mage-init span) on Luma themes only.
 *
 * Affirm registers the same plugin class on the category view and the search result block; this preference
 * covers both registrations.
 */
class CategoryViewThemeAware extends View
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
     * @param mixed $productListHtml
     * @return mixed
     */
    public function afterGetProductListHtml($subject, $productListHtml)
    {
        if ($this->currentTheme->isHyva()) {
            return $productListHtml;
        }

        return parent::afterGetProductListHtml($subject, $productListHtml);
    }
}
