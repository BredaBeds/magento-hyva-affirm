<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

/**
 * Amount rules for as-low-as messages, mirroring Affirm's Luma widgets.
 *
 * Luma (view/frontend/web/js/model/aslowas.js, updateAffirmAsLowAs) hides a message when the amount is below
 * 50.00 or above 17,500.00, or outside min_order_total/max_order_total when those are set. min_mpp is applied
 * per surface: the product page hides below it (equal shows), listings show only above it, the cart page and
 * the minicart ignore it (Affirm's section data only blanks the minicart promo id).
 */
final class AmountRange // phpcs:ignore Magento2.PHP.FinalImplementation.FoundFinal -- pure calculator
{
    /** Lowest amount (cents) Affirm's Luma widgets show a message for, inclusive. */
    public const MIN_CENTS = 5000;

    /** Highest amount (cents) Affirm's Luma widgets show a message for, inclusive. */
    public const MAX_CENTS = 1750000;

    /** Amounts beyond this are treated as invalid rather than overflowing the integer conversion. */
    private const MAX_ABS_AMOUNT = 1.0e13;

    /**
     * Converts a money amount to integer cents, rounding to two decimals first.
     */
    public function toCents(float $amount): int
    {
        if (!is_finite($amount) || abs($amount) > self::MAX_ABS_AMOUNT) {
            return 0;
        }

        return (int) round(round($amount, 2) * 100);
    }

    /**
     * Whether a message for this amount may be shown at this placement.
     *
     * Placements without an amount (banner, pixel) are always displayable; the cart button follows
     * the gateway's minimum and maximum order total only.
     */
    public function isDisplayable(int $cents, Placement $placement, Limits $limits): bool
    {
        if ($placement === Placement::BANNER || $placement === Placement::PIXEL) {
            return true;
        }
        if ($placement === Placement::CART_BUTTON) {
            return $this->isWithinOrderTotals($cents, $limits);
        }
        if ($cents < self::MIN_CENTS || $cents > self::MAX_CENTS) {
            return false;
        }

        return $this->isWithinOrderTotals($cents, $limits) && $this->passesMinMpp($cents, $placement, $limits);
    }

    /**
     * The min_mpp rule alone, which Luma applies when it renders a message (not on later price changes).
     */
    public function passesMinMpp(int $cents, Placement $placement, Limits $limits): bool
    {
        $minMppCents = $limits->minMpp === null ? 0 : $this->toCents($limits->minMpp);

        return match ($placement) {
            Placement::PRODUCT => $cents >= $minMppCents,
            Placement::LISTING => $cents > $minMppCents,
            default => true,
        };
    }

    /**
     * Bounds for components that re-evaluate the amount in the browser.
     *
     * 'min' and 'max' are the effective inclusive bounds in cents: Luma's fixed bounds narrowed by
     * min_order_total and max_order_total. 'minMpp' is min_mpp in cents, or null when unset.
     *
     * @return array{min: int|null, max: int|null, minMpp: int|null}
     */
    public function toClientConfig(Limits $limits): array
    {
        $min = self::MIN_CENTS;
        if ($limits->minOrderTotal !== null) {
            $min = max($min, $this->toCents($limits->minOrderTotal));
        }
        $max = self::MAX_CENTS;
        if ($limits->maxOrderTotal !== null) {
            $max = min($max, $this->toCents($limits->maxOrderTotal));
        }

        return [
            'min' => $min,
            'max' => $max,
            'minMpp' => $limits->minMpp === null ? null : $this->toCents($limits->minMpp),
        ];
    }

    private function isWithinOrderTotals(int $cents, Limits $limits): bool
    {
        if ($limits->minOrderTotal !== null && $cents < $this->toCents($limits->minOrderTotal)) {
            return false;
        }

        return $limits->maxOrderTotal === null || $cents <= $this->toCents($limits->maxOrderTotal);
    }
}
