<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\StockRegistryPreloader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BackorderResolverTest extends TestCase
{
    private Config&MockObject $config;

    private StockRegistryInterface&MockObject $stockRegistry;

    private StockRegistryPreloader&MockObject $preloader;

    /** @var array<int, array{int, float|null}> product id => [backorders, qty] */
    private array $stock = [];

    private BackorderResolver $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->stockRegistry = $this->createMock(StockRegistryInterface::class);
        $this->stockRegistry->method('getStockItem')->willReturnCallback(function ($productId) {
            [$backorders, $qty] = $this->stock[(int) $productId] ?? [0, 0.0];

            return $this->stockItem((int) $productId, $backorders, $qty);
        });
        $this->preloader = $this->createMock(StockRegistryPreloader::class);
        $this->resolver = new BackorderResolver($this->config, $this->stockRegistry, $this->preloader);
    }

    public function testRuleDisabledNeverReportsBackorders(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(false);
        $this->stock = [7 => [1, 0.0]];
        $this->stockRegistry->expects($this->never())->method('getStockItem');

        $this->assertFalse($this->resolver->isRuleEnabled());
        $this->assertFalse($this->resolver->isBackordered($this->product(7, false)));
        $this->assertSame([], $this->resolver->backorderedChildIds($this->product(8, true, [[1 => 1]])));
    }

    public function testSimpleProductRuleMatchesAffirm(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->stock = [
            1 => [1, 0.0],   // backorders on, nothing in stock
            2 => [1, 5.0],   // backorders on, in stock
            3 => [0, 0.0],   // backorders off
            4 => [2, 0.5],   // backorders "notify", below one unit
            5 => [1, null],  // no quantity recorded
        ];

        $this->assertTrue($this->resolver->isBackordered($this->product(1, false)));
        $this->assertFalse($this->resolver->isBackordered($this->product(2, false)));
        $this->assertFalse($this->resolver->isBackordered($this->product(3, false)));
        $this->assertTrue($this->resolver->isBackordered($this->product(4, false)));
        $this->assertTrue($this->resolver->isBackordered($this->product(5, false)));
        $this->assertFalse($this->resolver->isBackordered($this->product(0, false)));
    }

    public function testCompositeProductsAreDecidedPerChild(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->stock = [10 => [1, 0.0], 12 => [1, 0.0], 11 => [1, 3.0], 13 => [0, 0.0]];
        $configurable = $this->product(10, true, [[11 => '11', 12 => '12', 13 => '13']]);

        $this->assertFalse($this->resolver->isBackordered($configurable));
        $this->assertSame([12], $this->resolver->backorderedChildIds($configurable));
    }

    public function testBundleSelectionsAcrossOptions(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->stock = [21 => [1, 0.0], 23 => [1, 0.0]];
        $bundle = $this->product(20, true, [1 => [23 => 23, 21 => 21], 2 => [22 => 22, 21 => 21]]);

        $this->assertSame([21, 23], $this->resolver->backorderedChildIds($bundle));
    }

    public function testSimpleProductHasNoChildren(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);

        $this->assertSame([], $this->resolver->backorderedChildIds($this->product(1, false)));
    }

    public function testStockLookupsAreMemoised(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->stock = [1 => [1, 0.0]];
        $this->stockRegistry->expects($this->once())->method('getStockItem');

        $this->resolver->isBackordered($this->product(1, false));
        $this->resolver->isBackordered($this->product(1, false));
    }

    public function testPreloadReadsTheStockOfAListingInOneQuery(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->preloader->expects($this->once())->method('preloadStockItems')
            ->with([1, 2, 4])
            ->willReturn([
                $this->stockItem(1, 1, 0.0),
                $this->stockItem(2, 1, 4.0),
                $this->stockItem(99, 1, 0.0),
            ]);
        $this->stockRegistry->expects($this->once())->method('getStockItem')->with(4);
        $this->stock = [4 => [1, 0.0]];

        $this->resolver->preload([
            $this->product(1, false),
            $this->product(2, false),
            $this->product(3, true),
            $this->product(0, false),
            $this->product(1, false),
            'not a product',
            $this->product(4, false),
        ]);

        $this->assertTrue($this->resolver->isBackordered($this->product(1, false)));
        $this->assertFalse($this->resolver->isBackordered($this->product(2, false)));
        // No stock item came back for 4: the per-product lookup still decides.
        $this->assertTrue($this->resolver->isBackordered($this->product(4, false)));
    }

    public function testPreloadSkipsProductsAlreadyResolved(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->resolver->isBackordered($this->product(1, false));
        $this->preloader->expects($this->once())->method('preloadStockItems')->with([2])
            ->willReturn([$this->stockItem(2, 0, 0.0)]);

        $this->resolver->preload(new \ArrayIterator([$this->product(1, false), $this->product(2, false)]));
        $this->resolver->preload([$this->product(1, false), $this->product(2, false)]);
    }

    public function testPreloadDoesNothingWhileTheRuleIsOffOrWithoutProducts(): void
    {
        $this->config->method('isBackorderRuleEnabled')->willReturn(false);
        $this->preloader->expects($this->never())->method('preloadStockItems');

        $this->resolver->preload([$this->product(1, false)]);

        $this->setUp();
        $this->config->method('isBackorderRuleEnabled')->willReturn(true);
        $this->preloader->expects($this->never())->method('preloadStockItems');
        $this->resolver->preload([]);
        $this->resolver->preload([$this->product(5, true)]);
    }

    private function stockItem(int $productId, int $backorders, ?float $qty): StockItemInterface&MockObject
    {
        $item = $this->createMock(StockItemInterface::class);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getBackorders')->willReturn($backorders);
        $item->method('getQty')->willReturn($qty);

        return $item;
    }

    /**
     * @param array<int|string, array<int|string, int|string>> $childrenIds
     */
    private function product(int $id, bool $composite, array $childrenIds = []): Product&MockObject
    {
        $type = $this->createMock(AbstractType::class);
        $type->method('getChildrenIds')->with($id, false)->willReturn($childrenIds);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id ?: null);
        $product->method('isComposite')->willReturn($composite);
        $product->method('getTypeInstance')->willReturn($type);

        return $product;
    }
}
