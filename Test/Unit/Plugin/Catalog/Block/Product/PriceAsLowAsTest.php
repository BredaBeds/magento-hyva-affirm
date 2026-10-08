<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Plugin\Catalog\Block\Product;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Plugin\Catalog\Block\Product\PriceAsLowAs;
use Hyva\AstoundAffirm\ViewModel\ProductMessage;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Catalog\Block\Product\Price;
use Magento\Catalog\Block\Product\View;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PriceAsLowAsTest extends TestCase
{
    private const PRICE_HTML = '<div class="price-box">$2,199.00</div>';
    private const MESSAGE_HTML = '<p class="affirm-as-low-as"></p>';

    private CurrentTheme&MockObject $currentTheme;

    private AvailabilityInterface&MockObject $availability;

    private Config&MockObject $config;

    private ProductMessage&MockObject $productMessage;

    private LoggerInterface&MockObject $logger;

    private Product&MockObject $product;

    private Template&MockObject $messageBlock;

    protected function setUp(): void
    {
        $this->currentTheme = $this->createMock(CurrentTheme::class);
        $this->currentTheme->method('isHyva')->willReturn(true);
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->productMessage = $this->createMock(ProductMessage::class);
        $this->productMessage->method('prepareMessageBlock')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->product = $this->createMock(Product::class);
        $this->messageBlock = $this->createMock(Template::class);
    }

    public function testRendersTheMessageAfterThePrice(): void
    {
        $this->availability->expects($this->once())->method('isAvailable')
            ->with(Placement::PRODUCT, $this->product)->willReturn(true);
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_AFTER_PRICE);
        $this->productMessage->expects($this->once())->method('prepareMessageBlock')
            ->with($this->messageBlock, $this->product)->willReturn(true);
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product');

        $this->assertSame(
            self::PRICE_HTML . self::MESSAGE_HTML,
            $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML)
        );
    }

    public function testRendersTheMessageBeforeThePrice(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_BEFORE_PRICE);
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product');

        $this->assertSame(
            self::MESSAGE_HTML . self::PRICE_HTML,
            $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML)
        );
    }

    public function testEndOfInfoPlacementLeavesThePriceAlone(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_END_OF_INFO);
        $this->productMessage->method('isEndOfInfoPlacement')->with($this->product)->willReturn(true);
        $this->productMessage->expects($this->never())->method('prepareMessageBlock');
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product', false);

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testBundleSummaryRendersAfterItsPriceForTheEndOfInfoPlacement(): void
    {
        // Bundles have no end-of-info message, so their summary message follows the price.
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_END_OF_INFO);
        $this->productMessage->method('isEndOfInfoPlacement')->willReturn(false);
        $bundlePrice = $this->createMock(View::class);
        $bundlePrice->method('getNameInLayout')->willReturn('product.info.bundle.price');
        $bundlePrice->method('getChildBlock')->with('hyva.affirm.aslowas.bundle')->willReturn($this->messageBlock);
        $bundlePrice->method('getProduct')->willReturn($this->product);
        $bundlePrice->expects($this->once())->method('getChildHtml')->with('hyva.affirm.aslowas.bundle')
            ->willReturn("\n" . self::MESSAGE_HTML . "\n");

        $this->assertSame(
            self::PRICE_HTML . self::MESSAGE_HTML,
            $this->plugin()->afterToHtml($bundlePrice, self::PRICE_HTML)
        );
    }

    public function testAcceptsStringablePriceHtml(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_AFTER_PRICE);
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product');
        $result = new class(self::PRICE_HTML) implements \Stringable {
            public function __construct(private readonly string $html)
            {
            }

            public function __toString(): string
            {
                return $this->html;
            }
        };

        $this->assertSame(self::PRICE_HTML . self::MESSAGE_HTML, $this->plugin()->afterToHtml($priceBlock, $result));
    }

    public function testIgnoresBlocksOutsideTheAllowList(): void
    {
        $this->currentTheme->expects($this->never())->method('isHyva');
        $this->availability->expects($this->never())->method('isAvailable');
        $block = $this->createMock(View::class);
        $block->method('getNameInLayout')->willReturn('product.info.form');
        $block->expects($this->never())->method('getChildBlock');

        $this->assertSame('<form/>', $this->plugin()->afterToHtml($block, '<form/>'));
    }

    public function testAllowListIsConfigurable(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_AFTER_PRICE);
        $priceBlock = $this->priceBlock('theme.product.price', 'theme.affirm.message');
        $plugin = $this->plugin(['theme.product.price' => 'theme.affirm.message']);

        $this->assertSame(self::PRICE_HTML . self::MESSAGE_HTML, $plugin->afterToHtml($priceBlock, self::PRICE_HTML));
        $this->assertSame(
            self::PRICE_HTML,
            $plugin->afterToHtml($this->priceBlock('product.info.price', 'x', false), self::PRICE_HTML)
        );
    }

    public function testLumaThemesKeepThePriceHtml(): void
    {
        $this->currentTheme = $this->createMock(CurrentTheme::class);
        $this->currentTheme->method('isHyva')->willReturn(false);
        $this->availability->expects($this->never())->method('isAvailable');
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product', false);

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testUnavailableProductKeepsThePriceHtml(): void
    {
        $this->availability->method('isAvailable')->willReturn(false);
        $this->productMessage->expects($this->never())->method('prepareMessageBlock');
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product', false);

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testMissingMessageBlockKeepsThePriceHtml(): void
    {
        // The block is not generated while affirm/affirm_aslowas/enabled_pdp is off.
        $this->availability->expects($this->never())->method('isAvailable');
        $priceBlock = $this->createMock(Price::class);
        $priceBlock->method('getNameInLayout')->willReturn('product.info.price');
        $priceBlock->method('getChildBlock')->willReturn(false);
        $priceBlock->expects($this->never())->method('getChildHtml');

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testMissingProductKeepsThePriceHtml(): void
    {
        $this->availability->expects($this->never())->method('isAvailable');
        $priceBlock = $this->createMock(Price::class);
        $priceBlock->method('getNameInLayout')->willReturn('product.info.price');
        $priceBlock->method('getChildBlock')->willReturn($this->messageBlock);
        $priceBlock->method('getProduct')->willReturn(null);

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testEmptyPriceHtmlGetsNoMessage(): void
    {
        $this->currentTheme->expects($this->never())->method('isHyva');
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product', false);

        $this->assertSame(" \n", $this->plugin()->afterToHtml($priceBlock, " \n"));
        $this->assertSame('', $this->plugin()->afterToHtml($priceBlock, null));
    }

    public function testMessageWithoutDataKeepsThePriceHtml(): void
    {
        $this->productMessage = $this->createMock(ProductMessage::class);
        $this->productMessage->method('prepareMessageBlock')->willReturn(false);
        $this->availability->method('isAvailable')->willReturn(true);
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product', false);

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testEmptyMessageKeepsThePriceHtml(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->config->expects($this->never())->method('getPlacementPdp');
        $priceBlock = $this->createMock(Price::class);
        $priceBlock->method('getNameInLayout')->willReturn('product.info.price');
        $priceBlock->method('getChildBlock')->willReturn($this->messageBlock);
        $priceBlock->method('getProduct')->willReturn($this->product);
        $priceBlock->method('getChildHtml')->willReturn("\n");

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    public function testErrorsKeepThePriceHtmlAndAreLogged(): void
    {
        $this->availability->method('isAvailable')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning')
            ->with('Hyva_AstoundAffirm: product message skipped.', $this->callback(
                static fn (array $context): bool => $context['block'] === 'product.info.price'
                    && $context['error'] === 'RuntimeException: boom'
            ));
        $priceBlock = $this->priceBlock('product.info.price', 'hyva.affirm.aslowas.product', false);

        $this->assertSame(self::PRICE_HTML, $this->plugin()->afterToHtml($priceBlock, self::PRICE_HTML));
    }

    /**
     * @param array<string, string>|null $priceBlocks
     */
    private function plugin(?array $priceBlocks = null): PriceAsLowAs
    {
        $arguments = [$this->currentTheme, $this->availability, $this->config, $this->productMessage, $this->logger];
        if ($priceBlocks !== null) {
            $arguments[] = $priceBlocks;
        }

        return new PriceAsLowAs(...$arguments);
    }

    private function priceBlock(string $name, string $messageBlockName, bool $rendersMessage = true): Price&MockObject
    {
        $priceBlock = $this->createMock(Price::class);
        $priceBlock->method('getNameInLayout')->willReturn($name);
        $priceBlock->method('getChildBlock')->with($messageBlockName)->willReturn($this->messageBlock);
        $priceBlock->method('getProduct')->willReturn($this->product);
        $priceBlock->expects($rendersMessage ? $this->once() : $this->never())->method('getChildHtml')
            ->with($messageBlockName)->willReturn(self::MESSAGE_HTML);

        return $priceBlock;
    }
}
