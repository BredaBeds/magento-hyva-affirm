<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoFingerprint;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PromoFingerprintTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [];

    private string $promoId = 'PROMO';

    private bool $available = true;

    private string $date = '2026-10-08';

    private int $configReads = 0;

    private PromoIdInterface&MockObject $promoIds;

    protected function setUp(): void
    {
        $this->config = [
            'affirm/affirm_mfp' => ['promo_id_value' => 'A', 'start_date_mfp' => '2026-10-01'],
            'affirm/affirm_aslowas' => ['enabled_plp' => '1', 'min_mpp' => '100'],
            'payment/affirm_gateway/active' => '1',
            'payment/affirm_gateway/mode' => 'sandbox',
        ];
        $this->promoIds = $this->createMock(PromoIdInterface::class);
        $this->promoIds->method('forProduct')->willReturnCallback(fn () => $this->promoId);
    }

    public function testSameInputsGiveTheSameFingerprint(): void
    {
        $product = $this->product(1);

        $this->assertSame($this->fingerprint()->forProduct($product), $this->fingerprint()->forProduct($product));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->fingerprint()->forProduct($product));
    }

    public function testGroupKeyOrderDoesNotMatter(): void
    {
        $before = $this->fingerprint()->forProduct($this->product(1));
        $this->config['affirm/affirm_mfp'] = ['start_date_mfp' => '2026-10-01', 'promo_id_value' => 'A'];

        $this->assertSame($before, $this->fingerprint()->forProduct($this->product(1)));
    }

    public function testChangesWithEveryInput(): void
    {
        $product = $this->product(1);
        $base = $this->fingerprint()->forProduct($product);
        $changes = [
            'promo id' => fn () => $this->promoId = 'OTHER',
            'availability' => fn () => $this->available = false,
            'mfp group' => fn () => $this->config['affirm/affirm_mfp']['promo_id_value'] = 'B',
            'aslowas group' => fn () => $this->config['affirm/affirm_aslowas']['min_mpp'] = '200',
            'gateway path' => fn () => $this->config['payment/affirm_gateway/mode'] = 'production',
            'backorder path' => fn () => $this->config['payment/affirm_gateway/disable_for_backordered_items'] = '1',
            'date' => fn () => $this->date = '2026-10-09',
        ];
        foreach ($changes as $label => $change) {
            $saved = [$this->config, $this->promoId, $this->available, $this->date];
            $change();
            $this->assertNotSame($base, $this->fingerprint()->forProduct($product), $label);
            [$this->config, $this->promoId, $this->available, $this->date] = $saved;
        }
        $this->assertSame($base, $this->fingerprint()->forProduct($product));
        $this->assertNotSame($base, $this->fingerprint()->forProduct($this->product(2)));
    }

    public function testPromoIdIsNotResolvedWhenNoMessageCanRender(): void
    {
        $this->available = false;
        $this->promoIds->expects($this->never())->method('forProduct');

        $this->fingerprint()->forProduct($this->product(1));
    }

    public function testDiArgumentsAddPathsAndGroups(): void
    {
        $product = $this->product(1);
        $base = $this->fingerprint(['vendor/promo/financing'], ['vendor/affirm/'])->forProduct($product);

        $this->config['vendor/promo/financing'] = 'affirm:x';
        $withPath = $this->fingerprint(['vendor/promo/financing'], ['vendor/affirm/'])->forProduct($product);
        $this->assertNotSame($base, $withPath);

        $this->config['vendor/affirm'] = ['enabled' => '1'];
        $this->assertNotSame(
            $withPath,
            $this->fingerprint(['vendor/promo/financing'], ['vendor/affirm/'])->forProduct($product)
        );
        // Invalid DI values are ignored.
        $this->assertSame(
            $this->fingerprint()->forProduct($product),
            $this->fingerprint([' ', 5], [''])->forProduct($product)
        );
    }

    public function testConfigurationIsReadOncePerRequest(): void
    {
        $fingerprint = $this->fingerprint();
        $fingerprint->forProduct($this->product(1));
        $reads = $this->configReads;
        $fingerprint->forProduct($this->product(2));

        $this->assertGreaterThan(0, $reads);
        $this->assertSame($reads, $this->configReads);
    }

    /**
     * @param array<mixed> $paths
     * @param array<mixed> $prefixes
     */
    private function fingerprint(array $paths = [], array $prefixes = []): PromoFingerprint
    {
        $availability = $this->createMock(AvailabilityInterface::class);
        $availability->method('isAvailable')->willReturnCallback(
            fn (Placement $placement) => $placement === Placement::LISTING && $this->available
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function (string $path) {
            $this->configReads++;

            return $this->config[$path] ?? null;
        });
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('scopeDate')->with(1)->willReturnCallback(fn () => new \DateTime($this->date));

        return new PromoFingerprint(
            $this->promoIds,
            $availability,
            $scopeConfig,
            $storeManager,
            $timezone,
            $paths,
            $prefixes
        );
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
