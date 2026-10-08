<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Hyva\AstoundAffirm\Model\Messaging\Placement;
use PHPUnit\Framework\TestCase;

class PlacementTest extends TestCase
{
    public function testValuesAreTheContract(): void
    {
        $this->assertSame(
            ['product', 'listing', 'cart', 'minicart', 'banner', 'cart_button', 'pixel'],
            array_map(static fn (Placement $placement): string => $placement->value, Placement::cases())
        );
    }

    public function testPageTypes(): void
    {
        $this->assertSame('product', Placement::PRODUCT->pageType());
        $this->assertSame('category', Placement::LISTING->pageType());
        $this->assertSame('cart', Placement::CART->pageType());
        $this->assertSame('cart', Placement::MINICART->pageType());
        $this->assertNull(Placement::BANNER->pageType());
        $this->assertNull(Placement::CART_BUTTON->pageType());
        $this->assertNull(Placement::PIXEL->pageType());
    }
}
