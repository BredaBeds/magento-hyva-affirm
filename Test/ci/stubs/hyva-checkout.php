<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test double written from the public API for CI only; not the original code; MIT.
 *
 * Stands in for the one class of hyva-themes/magento2-hyva-checkout (public signature as of 1.3.14) that this
 * module looks up when Hyvä Checkout is installed (Controller\Cart\Start), so its tests run instead of being
 * skipped. The module does not depend on Hyvä Checkout. The method needs a Magento runtime and throws: unit
 * tests mock it.
 */
declare(strict_types=1);

namespace Hyva\Checkout\Model;

class Config
{
    public function getActiveCheckoutData(): array
    {
        throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
    }
}
