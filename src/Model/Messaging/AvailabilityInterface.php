<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Decides whether Affirm content may appear at a placement.
 *
 * This is the module's single extension seam: an `afterIsAvailable` plugin returning false suppresses the
 * placement (messages, banners, cart button and pixel alike). Implementations never throw.
 */
interface AvailabilityInterface
{
    /**
     * Single extension seam: plug `afterIsAvailable` to suppress any placement.
     */
    public function isAvailable(Placement $placement, ?ProductInterface $product = null): bool;
}
