<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Affirm;

use Astound\Affirm\Model\Config as AffirmConfig;
use Astound\Affirm\Model\Plugin\MiniCart\AsLowAs\ConfigPlugin;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Checkout\Block\Cart\Sidebar;

/**
 * Keeps Affirm's Knockout minicart configuration on Luma themes only. Stock Hyvä never renders the Luma
 * sidebar block; this covers themes that re-declare it.
 */
class MinicartConfigThemeAware extends ConfigPlugin
{
    public function __construct(
        AffirmConfig $configProvider,
        private readonly CurrentTheme $currentTheme
    ) {
        parent::__construct($configProvider);
    }

    /**
     * @param Sidebar $subject
     * @param array<mixed> $result
     * @return array<mixed>
     */
    public function afterGetConfig(Sidebar $subject, array $result): array
    {
        if ($this->currentTheme->isHyva()) {
            return $result;
        }

        return parent::afterGetConfig($subject, $result);
    }
}
