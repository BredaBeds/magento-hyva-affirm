<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Hash of everything that decides a product's listing message, for the listing item block cache key.
 *
 * Covers the resolved promo id, the listing availability (so availability plugins and gateway settings take
 * effect), Affirm's promo and messaging settings and the store date (dated promo ids switch at midnight).
 * Other modules add settings that influence messages through the DI arguments `configPaths` (single paths)
 * and `configPathPrefixes` (configuration group paths such as `section/group/`).
 */
class PromoFingerprint
{
    private const VERSION = 'v1';

    private const BUILT_IN_GROUPS = [
        'affirm/affirm_mfp',
        'affirm/affirm_aslowas',
    ];

    private const BUILT_IN_PATHS = [
        'payment/affirm_gateway/active',
        'payment/affirm_gateway/mode',
        'payment/affirm_gateway/min_order_total',
        'payment/affirm_gateway/max_order_total',
        'payment/affirm_gateway/disable_for_backordered_items',
    ];

    /** @var array<int, string> */
    private array $scopeHashes = [];

    /**
     * @param string[] $configPaths additional single configuration paths
     * @param string[] $configPathPrefixes additional configuration group paths
     */
    public function __construct(
        private readonly PromoIdInterface $promoId,
        private readonly AvailabilityInterface $availability,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly array $configPaths = [],
        private readonly array $configPathPrefixes = []
    ) {
    }

    public function forProduct(ProductInterface $product): string
    {
        $available = $this->availability->isAvailable(Placement::LISTING, $product);
        // The promo id costs several queries; it only matters when a message can render.
        $promoId = $available ? $this->promoId->forProduct($product) : '';

        return sha1((string) json_encode([
            self::VERSION,
            (string) $product->getId(),
            $promoId,
            (int) $available,
            $this->getScopeHash(),
        ]));
    }

    /**
     * Configuration and date part, computed once per store and request.
     */
    private function getScopeHash(): string
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!isset($this->scopeHashes[$storeId])) {
            $values = [];
            foreach ($this->getGroups() as $group) {
                $values[$group . '/'] = $this->normalise(
                    $this->scopeConfig->getValue($group, ScopeInterface::SCOPE_STORE, $storeId)
                );
            }
            foreach ($this->getPaths() as $path) {
                $values[$path] = $this->normalise(
                    $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId)
                );
            }
            $values['date'] = $this->timezone->scopeDate($storeId)->format('Y-m-d');
            $this->scopeHashes[$storeId] = sha1((string) json_encode([$storeId, $values]));
        }

        return $this->scopeHashes[$storeId];
    }

    /**
     * @return string[]
     */
    private function getGroups(): array
    {
        $groups = array_map(
            static fn (string $prefix): string => rtrim($prefix, '/'),
            array_merge(self::BUILT_IN_GROUPS, $this->filterStrings($this->configPathPrefixes))
        );

        return $this->sortedUnique(array_filter($groups, static fn (string $group): bool => $group !== ''));
    }

    /**
     * @return string[]
     */
    private function getPaths(): array
    {
        return $this->sortedUnique(array_merge(self::BUILT_IN_PATHS, $this->filterStrings($this->configPaths)));
    }

    /**
     * @param array<mixed> $values
     * @return string[]
     */
    private function filterStrings(array $values): array
    {
        return array_values(array_filter(
            $values,
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * Stable representation of a config value or group (keys sorted, scalars as strings).
     */
    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(fn ($item) => $this->normalise($item), $value);
        }

        return $value === null ? null : (is_scalar($value) ? (string) $value : '');
    }
}
