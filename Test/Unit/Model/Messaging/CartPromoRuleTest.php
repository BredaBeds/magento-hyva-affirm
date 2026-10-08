<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Hyva\AstoundAffirm\Model\Messaging\CartPromoRule;
use PHPUnit\Framework\TestCase;

class CartPromoRuleTest extends TestCase
{
    public function testToArray(): void
    {
        $rule = new CartPromoRule(100.0, 2000.5, 'IN', 'OUT', false);

        $this->assertSame(
            [
                'min' => 100.0,
                'max' => 2000.5,
                'promoInRange' => 'IN',
                'promoOutOfRange' => 'OUT',
                'higherPrecedenceApplies' => false,
            ],
            $rule->toArray()
        );
    }

    public function testFixedNeverSwitches(): void
    {
        $rule = CartPromoRule::fixed('PROMO');

        $this->assertNull($rule->min);
        $this->assertNull($rule->max);
        $this->assertSame('PROMO', $rule->promoInRange);
        $this->assertSame('PROMO', $rule->promoOutOfRange);
        $this->assertTrue($rule->higherPrecedenceApplies);
    }

    public function testFromArrayAcceptsObserverChanges(): void
    {
        $fallback = new CartPromoRule(100.0, 2000.0, 'IN', 'OUT', false);
        $rule = CartPromoRule::fromArray(
            ['promoInRange' => 'Q', 'promoOutOfRange' => 'Q', 'higherPrecedenceApplies' => 1, 'min' => '5'],
            $fallback
        );

        $this->assertSame(5.0, $rule->min);
        $this->assertSame(2000.0, $rule->max);
        $this->assertSame('Q', $rule->promoInRange);
        $this->assertSame('Q', $rule->promoOutOfRange);
        $this->assertTrue($rule->higherPrecedenceApplies);
    }

    public function testFromArrayFallsBackOnInvalidValues(): void
    {
        $fallback = new CartPromoRule(100.0, 2000.0, 'IN', 'OUT', false);
        $rule = CartPromoRule::fromArray(['promoInRange' => ['x'], 'max' => 'abc'], $fallback);

        $this->assertSame(100.0, $rule->min);
        $this->assertNull($rule->max);
        $this->assertSame('IN', $rule->promoInRange);
        $this->assertSame('OUT', $rule->promoOutOfRange);
        $this->assertFalse($rule->higherPrecedenceApplies);
    }
}
