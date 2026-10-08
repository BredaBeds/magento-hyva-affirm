<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

/**
 * Storefront places where Affirm content can appear. Used as the key of every availability decision.
 */
enum Placement: string
{
    case PRODUCT = 'product';
    case LISTING = 'listing';
    case CART = 'cart';
    case MINICART = 'minicart';
    case BANNER = 'banner';
    case CART_BUTTON = 'cart_button';
    case PIXEL = 'pixel';

    /**
     * The data-page-type affirm.js expects for an as-low-as message, or null for placements without a message.
     */
    public function pageType(): ?string
    {
        return match ($this) {
            self::PRODUCT => 'product',
            self::LISTING => 'category',
            self::CART, self::MINICART => 'cart',
            default => null,
        };
    }
}
