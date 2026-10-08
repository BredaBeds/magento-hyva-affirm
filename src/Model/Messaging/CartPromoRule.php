<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

/**
 * Affirm's cart-size promo rule, prepared for the cart page component.
 *
 * The component compares the quote BASE grand total with min and max (inclusive) and uses promoInRange or
 * promoOutOfRange. When both promo ids are equal there is nothing to switch.
 */
final class CartPromoRule // phpcs:ignore Magento2.PHP.FinalImplementation.FoundFinal -- immutable value object
{
    public function __construct(
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly string $promoInRange,
        public readonly string $promoOutOfRange,
        public readonly bool $higherPrecedenceApplies
    ) {
    }

    /**
     * A rule that never switches: the given promo id applies at every cart total.
     */
    public static function fixed(string $promoId): self
    {
        return new self(null, null, $promoId, $promoId, true);
    }

    /**
     * Rebuilds a rule from toArray() output (for example after observers changed it); missing or invalid
     * values fall back to the given rule.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, self $fallback): self
    {
        return new self(
            array_key_exists('min', $data) ? self::toFloatOrNull($data['min']) : $fallback->min,
            array_key_exists('max', $data) ? self::toFloatOrNull($data['max']) : $fallback->max,
            isset($data['promoInRange']) && is_scalar($data['promoInRange'])
                ? (string) $data['promoInRange'] : $fallback->promoInRange,
            isset($data['promoOutOfRange']) && is_scalar($data['promoOutOfRange'])
                ? (string) $data['promoOutOfRange'] : $fallback->promoOutOfRange,
            array_key_exists('higherPrecedenceApplies', $data)
                ? (bool) $data['higherPrecedenceApplies'] : $fallback->higherPrecedenceApplies
        );
    }

    /**
     * @return array{min: float|null, max: float|null, promoInRange: string, promoOutOfRange: string,
     *     higherPrecedenceApplies: bool}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'max' => $this->max,
            'promoInRange' => $this->promoInRange,
            'promoOutOfRange' => $this->promoOutOfRange,
            'higherPrecedenceApplies' => $this->higherPrecedenceApplies,
        ];
    }

    private static function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
