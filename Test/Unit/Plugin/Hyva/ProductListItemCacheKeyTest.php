<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Plugin\Hyva;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoFingerprint;
use Hyva\AstoundAffirm\Plugin\Hyva\ProductListItemCacheKey;
use Hyva\Theme\ViewModel\ProductListItem;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\Data\Collection;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductListItemCacheKeyTest extends TestCase
{
    private PromoFingerprint&MockObject $fingerprint;

    private Config&MockObject $config;

    private BackorderResolver&MockObject $backorderResolver;

    private LoggerInterface&MockObject $logger;

    private ProductListItemCacheKey $plugin;

    protected function setUp(): void
    {
        $this->fingerprint = $this->createMock(PromoFingerprint::class);
        $this->fingerprint->method('forProduct')->willReturn('abc');
        $this->config = $this->createMock(Config::class);
        $this->backorderResolver = $this->createMock(BackorderResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new ProductListItemCacheKey(
            $this->fingerprint,
            $this->config,
            $this->backorderResolver,
            $this->logger
        );
    }

    public function testAppendsTheFingerprint(): void
    {
        $fingerprint = $this->createMock(PromoFingerprint::class);
        $product = $this->createMock(Product::class);
        $fingerprint->expects($this->once())->method('forProduct')->with($product)->willReturn('def');
        $plugin = new ProductListItemCacheKey($fingerprint, $this->config, $this->backorderResolver, $this->logger);

        $this->assertSame([1, 'grid', 'hyva_affirm:def'], $this->callPlugin($product, $this->renderer(false), $plugin));
    }

    public function testFailureUsesAConstantMarker(): void
    {
        $fingerprint = $this->createMock(PromoFingerprint::class);
        $fingerprint->method('forProduct')->willThrowException(new \RuntimeException('config'));
        $this->logger->expects($this->once())->method('warning');
        $plugin = new ProductListItemCacheKey($fingerprint, $this->config, $this->backorderResolver, $this->logger);

        $this->assertSame(
            [1, 'grid', 'hyva_affirm:unavailable'],
            $this->callPlugin($this->createMock(Product::class), $this->renderer(false), $plugin)
        );
    }

    public function testPreloadsTheStockOfACategoryListingOnceForAllItsItems(): void
    {
        $this->givenListingMessagesEnabled(true);
        $items = [7 => $this->createMock(Product::class), 8 => $this->createMock(Product::class)];
        $parent = $this->createMock(ListProduct::class);
        $parent->method('getLoadedProductCollection')->willReturn($this->collection($items, true));
        $renderer = $this->renderer($parent);
        $this->backorderResolver->expects($this->once())->method('preload')->with($items);

        $this->callPlugin($items[7], $renderer);
        $this->callPlugin($items[8], $renderer);
    }

    public function testEachListingCollectionIsPreloadedOnce(): void
    {
        $this->givenListingMessagesEnabled(true);
        $first = $this->collection([1 => $this->createMock(Product::class)], true);
        $second = $this->collection([2 => $this->createMock(Product::class)], true);
        $preloaded = [];
        $this->backorderResolver->method('preload')->willReturnCallback(
            static function (iterable $items) use (&$preloaded): void {
                $preloaded[] = array_keys(is_array($items) ? $items : iterator_to_array($items));
            }
        );

        foreach ([$first, $second, $first, $second] as $collection) {
            $this->callPlugin($this->createMock(Product::class), $this->renderer($this->widget($collection)));
        }

        $this->assertSame([[1], [2]], $preloaded);
    }

    public function testCatalogWidgetCollectionIsPreloaded(): void
    {
        $this->givenListingMessagesEnabled(true);
        $items = [3 => $this->createMock(Product::class)];
        $this->backorderResolver->expects($this->once())->method('preload')->with($items);

        $this->callPlugin($items[3], $this->renderer($this->widget($this->collection($items, true))));
    }

    public function testNothingIsPreloadedWithoutALoadedListingCollection(): void
    {
        $this->givenListingMessagesEnabled(true);
        $this->backorderResolver->expects($this->never())->method('preload');
        $slider = $this->createMock(Template::class);
        $slider->method('getData')->willReturn(null);

        $product = $this->createMock(Product::class);
        $this->assertSame([1, 'grid', 'hyva_affirm:abc'], $this->callPlugin($product, $this->renderer(false)));
        $this->callPlugin($product, $this->renderer($slider));
        $this->callPlugin($product, $this->renderer($this->widget($this->collection([], false))));
    }

    public function testNothingIsPreloadedWhileListingMessagesAreOff(): void
    {
        $collection = $this->collection([1 => $this->createMock(Product::class)], true);
        $this->backorderResolver->expects($this->never())->method('preload');

        $this->givenListingMessagesEnabled(false);
        $this->callPlugin($this->createMock(Product::class), $this->renderer($this->widget($collection)));

        $this->setUp();
        $this->config->method('isActive')->willReturn(false);
        $this->config->method('isAsLowAsEnabled')->willReturn(true);
        $this->backorderResolver->expects($this->never())->method('preload');
        $this->callPlugin($this->createMock(Product::class), $this->renderer($this->widget($collection)));
    }

    public function testPreloadFailureIsLoggedAndTheKeyStillGetsTheFingerprint(): void
    {
        $this->givenListingMessagesEnabled(true);
        $this->backorderResolver->method('preload')->willThrowException(new \RuntimeException('db'));
        $this->logger->expects($this->once())->method('warning')
            ->with('Hyva_AstoundAffirm: listing stock preload failed.');

        $this->assertSame(
            [1, 'grid', 'hyva_affirm:abc'],
            $this->callPlugin(
                $this->createMock(Product::class),
                $this->renderer($this->widget($this->collection([1 => $this->createMock(Product::class)], true)))
            )
        );
    }

    private function givenListingMessagesEnabled(bool $enabled): void
    {
        $this->config->method('isActive')->willReturn(true);
        $this->config->method('isAsLowAsEnabled')->willReturnCallback(
            static fn (Placement $placement): bool => $enabled && $placement === Placement::LISTING
        );
    }

    /**
     * @param array<int, Product> $items
     */
    private function collection(array $items, bool $loaded): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('isLoaded')->willReturn($loaded);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }

    private function widget(Collection $collection): Template&MockObject
    {
        $widget = $this->createMock(Template::class);
        $widget->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'product_collection' ? $collection : null
        );

        return $widget;
    }

    private function renderer(AbstractBlock|false $parent): AbstractBlock&MockObject
    {
        $renderer = $this->createMock(AbstractBlock::class);
        $renderer->method('getParentBlock')->willReturn($parent);

        return $renderer;
    }

    /**
     * @return array<mixed>
     */
    private function callPlugin(
        Product $product,
        AbstractBlock $renderer,
        ?ProductListItemCacheKey $plugin = null
    ): array {
        return ($plugin ?? $this->plugin)->afterGetItemCacheKeyInfo(
            $this->createMock(ProductListItem::class),
            [1, 'grid'],
            $product,
            $renderer,
            'grid',
            'default'
        );
    }
}
