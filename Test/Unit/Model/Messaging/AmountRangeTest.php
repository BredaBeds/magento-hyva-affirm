<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use PHPUnit\Framework\TestCase;

class AmountRangeTest extends TestCase
{
    private AmountRange $range;

    protected function setUp(): void
    {
        $this->range = new AmountRange();
    }

    public function testToCentsRoundsToTwoDecimalsFirst(): void
    {
        $this->assertSame(1999, $this->range->toCents(19.99));
        $this->assertSame(30, $this->range->toCents(0.1 + 0.2));
        $this->assertSame(100000, $this->range->toCents(1000.0));
        $this->assertSame(123457, $this->range->toCents(1234.5678));
        $this->assertSame(-500, $this->range->toCents(-5.0));
    }

    public function testToCentsRejectsNonFiniteAndHugeAmounts(): void
    {
        $this->assertSame(0, $this->range->toCents(NAN));
        $this->assertSame(0, $this->range->toCents(INF));
        $this->assertSame(0, $this->range->toCents(1.0e20));
    }

    public function testLumaBoundsAreInclusive(): void
    {
        $limits = new Limits(null, null, null);
        foreach ([Placement::PRODUCT, Placement::LISTING, Placement::CART, Placement::MINICART] as $placement) {
            $this->assertFalse($this->range->isDisplayable(4999, $placement, $limits), $placement->value);
            $this->assertTrue($this->range->isDisplayable(5000, $placement, $limits), $placement->value);
            $this->assertTrue($this->range->isDisplayable(1750000, $placement, $limits), $placement->value);
            $this->assertFalse($this->range->isDisplayable(1750001, $placement, $limits), $placement->value);
        }
    }

    public function testOrderTotalsNarrowTheRangeInclusively(): void
    {
        $limits = new Limits(1000.0, 2000.0, null);
        $this->assertFalse($this->range->isDisplayable(99999, Placement::CART, $limits));
        $this->assertTrue($this->range->isDisplayable(100000, Placement::CART, $limits));
        $this->assertTrue($this->range->isDisplayable(200000, Placement::CART, $limits));
        $this->assertFalse($this->range->isDisplayable(200001, Placement::CART, $limits));
    }

    public function testMinMppDiffersPerPlacementLikeLuma(): void
    {
        $limits = new Limits(null, null, 100.0);
        // Product page: hidden below min_mpp, shown when equal.
        $this->assertFalse($this->range->isDisplayable(9999, Placement::PRODUCT, $limits));
        $this->assertTrue($this->range->isDisplayable(10000, Placement::PRODUCT, $limits));
        // Listing: shown only above min_mpp.
        $this->assertFalse($this->range->isDisplayable(10000, Placement::LISTING, $limits));
        $this->assertTrue($this->range->isDisplayable(10001, Placement::LISTING, $limits));
        // Cart page and minicart ignore min_mpp.
        $this->assertTrue($this->range->isDisplayable(5000, Placement::CART, $limits));
        $this->assertTrue($this->range->isDisplayable(5000, Placement::MINICART, $limits));
    }

    public function testPassesMinMppAlone(): void
    {
        $limits = new Limits(null, null, 100.0);
        $this->assertTrue($this->range->passesMinMpp(10000, Placement::PRODUCT, $limits));
        $this->assertFalse($this->range->passesMinMpp(10000, Placement::LISTING, $limits));
        $this->assertTrue($this->range->passesMinMpp(1, Placement::CART, $limits));
        $this->assertTrue($this->range->passesMinMpp(1, Placement::PRODUCT, new Limits(null, null, null)));
        $this->assertFalse($this->range->passesMinMpp(0, Placement::LISTING, new Limits(null, null, null)));
    }

    public function testPlacementsWithoutMessages(): void
    {
        $limits = new Limits(1000.0, 2000.0, 100.0);
        $this->assertTrue($this->range->isDisplayable(0, Placement::BANNER, $limits));
        $this->assertTrue($this->range->isDisplayable(0, Placement::PIXEL, $limits));
        // The cart button follows the order totals only, not Luma's message bounds.
        $this->assertFalse($this->range->isDisplayable(99999, Placement::CART_BUTTON, $limits));
        $this->assertTrue($this->range->isDisplayable(150000, Placement::CART_BUTTON, $limits));
        $this->assertTrue($this->range->isDisplayable(100, Placement::CART_BUTTON, new Limits(null, null, null)));
    }

    public function testClientConfigFoldsOrderTotalsIntoTheBounds(): void
    {
        $this->assertSame(
            ['min' => 5000, 'max' => 1750000, 'minMpp' => null],
            $this->range->toClientConfig(new Limits(null, null, null))
        );
        $this->assertSame(
            ['min' => 100000, 'max' => 1750000, 'minMpp' => 9950],
            $this->range->toClientConfig(new Limits(1000.0, 50000.0, 99.5))
        );
        $this->assertSame(
            ['min' => 5000, 'max' => 10000, 'minMpp' => null],
            $this->range->toClientConfig(new Limits(10.0, 100.0, null))
        );
    }
}
