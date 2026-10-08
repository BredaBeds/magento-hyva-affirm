<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Plugin\Hyva;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\AstoundAffirm\Plugin\Hyva\ProductListItemAsLowAs;
use Hyva\Theme\ViewModel\ProductListItem;
use Hyva\Theme\ViewModel\ProductPrice;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductListItemAsLowAsTest extends TestCase
{
    private AvailabilityInterface&MockObject $availability;

    private PromoIdInterface&MockObject $promoId;

    private ProductPrice&MockObject $productPrice;

    private LayoutInterface&MockObject $layout;

    private Template&MockObject $renderer;

    private LoggerInterface&MockObject $logger;

    private ProductListItemAsLowAs $plugin;

    protected function setUp(): void
    {
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->promoId = $this->createMock(PromoIdInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('getLimits')->willReturn(new Limits(null, null, 100.0));
        $this->productPrice = $this->createMock(ProductPrice::class);
        $this->renderer = $this->createMock(Template::class);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->plugin = new ProductListItemAsLowAs(
            $this->availability,
            $this->promoId,
            $config,
            new AmountRange(),
            $this->productPrice,
            $this->layout,
            $this->logger
        );
    }

    public function testUnavailableListingKeepsThePriceHtml(): void
    {
        $this->availability->method('isAvailable')->willReturn(false);
        $this->layout->expects($this->never())->method('createBlock');
        $this->promoId->expects($this->never())->method('forProduct');

        $this->assertSame('<price/>', $this->callPlugin($this->product(1)));
    }

    public function testAmountOutsideTheListingRangeKeepsThePriceHtml(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        // min_mpp is 100: the listing shows messages only above it.
        $this->productPrice->method('getPriceValue')->willReturn(100.0);
        $this->layout->expects($this->never())->method('createBlock');

        $this->assertSame('<price/>', $this->callPlugin($this->product(1)));
    }

    public function testAppendsTheMessageRenderedByTheSharedTemplate(): void
    {
        $product = $this->product(7);
        $this->availability->method('isAvailable')
            ->with(Placement::LISTING, $product)->willReturn(true);
        $this->productPrice->method('getPriceValue')->with('final_price', $product)->willReturn(1299.99);
        $this->promoId->method('forProduct')->with($product)->willReturn('PROMO');
        $this->layout->expects($this->once())->method('createBlock')
            ->with(Template::class)->willReturn($this->renderer);
        $this->renderer->expects($this->once())->method('setTemplate')
            ->with('Hyva_AstoundAffirm::promotion/as-low-as.phtml');
        $this->renderer->expects($this->exactly(2))->method('addData')->with([
            'placement' => 'listing',
            'amount_cents' => 129999,
            'promo_id' => 'PROMO',
            'product' => $product,
            'attributes' => [],
        ]);
        $this->renderer->method('toHtml')->willReturn('<p class="affirm-as-low-as"></p>');

        $this->assertSame('<price/><p class="affirm-as-low-as"></p>', $this->callPlugin($product));
        $this->assertSame('<price/><p class="affirm-as-low-as"></p>', $this->callPlugin($product));
    }

    public function testErrorsKeepThePriceHtml(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->productPrice->method('getPriceValue')->willThrowException(new \RuntimeException('price'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame('<price/>', $this->callPlugin($this->product(1)));
    }

    private function callPlugin(Product $product): string
    {
        return $this->plugin->afterGetProductPriceHtml(
            $this->createMock(ProductListItem::class),
            '<price/>',
            $product
        );
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
