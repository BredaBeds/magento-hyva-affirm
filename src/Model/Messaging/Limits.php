<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

/**
 * Affirm amount settings in the store's currency; null means "not set".
 *
 * minOrderTotal/maxOrderTotal: payment/affirm_gateway/min_order_total and max_order_total.
 * minMpp: affirm/affirm_aslowas/min_mpp (minimum price for showing the monthly payment).
 */
final class Limits // phpcs:ignore Magento2.PHP.FinalImplementation.FoundFinal -- immutable value object
{
    public function __construct(
        public readonly ?float $minOrderTotal,
        public readonly ?float $maxOrderTotal,
        public readonly ?float $minMpp
    ) {
    }
}
