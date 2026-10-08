<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\AstoundAffirm\ViewModel\ProductMessage;
use Hyva\Theme\ViewModel\ProductPrice;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductMessageTest extends TestCase
{
    private const DEPENDENCIES = 'hyva_js_template_dependencies';

    private Config&MockObject $config;

    private AvailabilityInterface&MockObject $availability;

    private PromoIdInterface&MockObject $promoId;

    private BackorderResolver&MockObject $backorderResolver;

    private ProductPrice&MockObject $productPrice;

    private LoggerInterface&MockObject $logger;

    private ProductMessage $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLimits')->willReturn(new Limits(1000.0, 50000.0, null));
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->promoId = $this->createMock(PromoIdInterface::class);
        $this->promoId->method('forProduct')->willReturn('PROMO-1');
        $this->backorderResolver = $this->createMock(BackorderResolver::class);
        $this->productPrice = $this->createMock(ProductPrice::class);
        $this->productPrice->method('displayPriceIncludingTax')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->rebuildViewModel();
    }

    public function testMessageDataForADisplayableSimpleProduct(): void
    {
        $product = $this->product(7, 'simple');
        $this->productPrice->expects($this->once())->method('getPriceValue')
            ->with('final_price', $product)->willReturn(2199.999);
        $this->backorderResolver->expects($this->never())->method('backorderedChildIds');

        $data = $this->viewModel->getMessageData($product);

        $this->assertNotNull($data);
        $this->assertSame('product', $data['placement']);
        $this->assertSame(220000, $data['amount_cents']);
        $this->assertSame('PROMO-1', $data['promo_id']);
        $this->assertSame($product, $data['product']);
        $this->assertSame(
            [
                'x-data' => 'hyvaAffirmProductMessage',
                'data-hyva-affirm-product-id' => '7',
                'data-hyva-affirm-config' => '{"min":100000,"max":1750000,"minMpp":null,'
                    . '"backordered":[],"priceKey":"finalPrice"}',
                'data-hyva-affirm-lazy' => true,
            ],
            $data['attributes']
        );
    }

    public function testAmountOutsideTheRangeRendersCollapsed(): void
    {
        $this->productPrice->method('getPriceValue')->willReturn(999.99);

        $data = $this->viewModel->getMessageData($this->product(7, 'simple'));

        $this->assertNotNull($data);
        $this->assertSame(99999, $data['amount_cents']);
        $this->assertSame('hidden', $data['attributes']['class']);
    }

    public function testMinMppHidesBelowAndShowsAtTheMinimum(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLimits')->willReturn(new Limits(null, null, 1500.0));
        $this->rebuildViewModel();
        $this->productPrice->method('getPriceValue')->willReturnOnConsecutiveCalls(1499.99, 1500.0);

        $below = $this->viewModel->getMessageData($this->product(7, 'simple'));
        $equal = $this->viewModel->getMessageData($this->product(7, 'simple'));

        $this->assertSame('hidden', $below['attributes']['class'] ?? null);
        $this->assertArrayNotHasKey('class', $equal['attributes'] ?? []);
        $this->assertStringContainsString(
            '"minMpp":150000',
            (string) ($equal['attributes']['data-hyva-affirm-config'] ?? '')
        );
    }

    public function testConfigurableCarriesItsBackorderedChildrenAndTheExclTaxKey(): void
    {
        $this->productPrice = $this->createMock(ProductPrice::class);
        $this->productPrice->method('displayPriceIncludingTax')->willReturn(false);
        $this->productPrice->method('getPriceValue')->willReturn(1500.0);
        $this->rebuildViewModel();
        $product = $this->product(12, 'configurable');
        $this->backorderResolver->expects($this->once())->method('backorderedChildIds')
            ->with($product)->willReturn([31, 33]);

        $data = $this->viewModel->getMessageData($product);

        $this->assertNotNull($data);
        $config = json_decode((string) $data['attributes']['data-hyva-affirm-config'], true);
        $this->assertSame([31, 33], $config['backordered']);
        $this->assertSame('basePrice', $config['priceKey']);
    }

    public function testBackorderErrorsHideTheMessage(): void
    {
        $this->productPrice->method('getPriceValue')->willReturn(1500.0);
        $this->backorderResolver->method('backorderedChildIds')
            ->willThrowException(new \RuntimeException('stock'));
        $this->logger->expects($this->once())->method('warning')
            ->with('Hyva_AstoundAffirm: product message skipped.', ['error' => 'RuntimeException: stock']);

        $this->assertNull($this->viewModel->getMessageData($this->product(12, 'configurable')));
    }

    public function testPriceErrorsHideTheMessage(): void
    {
        $this->productPrice->method('getPriceValue')->willThrowException(new \LogicException('price'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->viewModel->getMessageData($this->product(7, 'simple')));
    }

    public function testProductsWithoutModelOrIdHaveNoMessage(): void
    {
        $this->productPrice->expects($this->never())->method('getPriceValue');

        $this->assertNull($this->viewModel->getMessageData($this->createMock(ProductInterface::class)));
        $this->assertNull($this->viewModel->getMessageData($this->product(0, 'simple')));
    }

    public function testPrepareMessageBlockAddsTheDataAndThePageScripts(): void
    {
        $this->productPrice->method('getPriceValue')->willReturn(2199.0);
        $product = $this->product(7, 'simple');
        $block = $this->createMock(Template::class);
        $block->method('getData')->with(self::DEPENDENCIES)
            ->willReturn(['Hyva_AstoundAffirm::js/product-message.phtml' => false, 'Theme::js/x.phtml' => 20]);
        $block->expects($this->once())->method('addData')->with($this->callback(
            function (array $data) use ($product): bool {
                $this->assertSame('product', $data['placement']);
                $this->assertSame(219900, $data['amount_cents']);
                $this->assertSame($product, $data['product']);
                $this->assertSame(
                    [
                        'Hyva_AstoundAffirm::js/product-message.phtml' => false,
                        'Theme::js/x.phtml' => 20,
                        'Hyva_AstoundAffirm::js/price-state-recorder.phtml' => 3,
                    ],
                    $data[self::DEPENDENCIES]
                );

                return true;
            }
        ));

        $this->assertTrue($this->viewModel->prepareMessageBlock($block, $product));
    }

    public function testPrepareMessageBlockRegistersBothScriptsOnAFreshBlock(): void
    {
        $this->productPrice->method('getPriceValue')->willReturn(2199.0);
        $block = $this->createMock(Template::class);
        $block->method('getData')->willReturn(null);
        $block->expects($this->once())->method('addData')->with($this->callback(
            fn (array $data): bool => $data[self::DEPENDENCIES] === [
                ProductMessage::RECORDER_TEMPLATE => ProductMessage::RECORDER_PRIORITY,
                ProductMessage::COMPONENT_TEMPLATE => ProductMessage::COMPONENT_PRIORITY,
            ]
        ));

        $this->assertTrue($this->viewModel->prepareMessageBlock($block, $this->product(7, 'simple')));
    }

    public function testPrepareMessageBlockLeavesTheBlockAloneWithoutData(): void
    {
        $block = $this->createMock(Template::class);
        $block->expects($this->never())->method('addData');

        $this->assertFalse($this->viewModel->prepareMessageBlock($block, $this->product(0, 'simple')));
    }

    public function testEndOfInfoPlacementExcludesGroupedAndBundleProducts(): void
    {
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_END_OF_INFO);

        $this->assertTrue($this->viewModel->isEndOfInfoPlacement($this->product(7, 'simple')));
        $this->assertTrue($this->viewModel->isEndOfInfoPlacement($this->product(12, 'configurable')));
        $this->assertFalse($this->viewModel->isEndOfInfoPlacement($this->product(9, 'bundle')));
        $this->assertFalse($this->viewModel->isEndOfInfoPlacement($this->product(5, 'grouped')));
    }

    public function testOtherPlacementsAreNotEndOfInfo(): void
    {
        $this->config->method('getPlacementPdp')->willReturnOnConsecutiveCalls(
            Config::PLACEMENT_PDP_AFTER_PRICE,
            Config::PLACEMENT_PDP_BEFORE_PRICE
        );

        $this->assertFalse($this->viewModel->isEndOfInfoPlacement($this->product(7, 'simple')));
        $this->assertFalse($this->viewModel->isEndOfInfoPlacement($this->product(7, 'simple')));
    }

    public function testEndOfInfoNeedsThePriceBlockAndAvailability(): void
    {
        $this->config->method('getPlacementPdp')->willReturn(Config::PLACEMENT_PDP_END_OF_INFO);
        $product = $this->product(7, 'simple');
        $this->availability->method('isAvailable')->with(Placement::PRODUCT, $product)
            ->willReturnOnConsecutiveCalls(true, false);
        $withPrice = $this->createMock(LayoutInterface::class);
        $withPrice->method('getBlock')->with('product.info.price')
            ->willReturn($this->createMock(Template::class));
        $withoutPrice = $this->createMock(LayoutInterface::class);
        $withoutPrice->method('getBlock')->willReturn(false);

        $this->assertTrue($this->viewModel->canRenderEndOfInfo($product, $withPrice));
        $this->assertFalse($this->viewModel->canRenderEndOfInfo($product, $withPrice));
        $this->assertFalse($this->viewModel->canRenderEndOfInfo($product, $withoutPrice));
        $this->assertFalse($this->viewModel->canRenderEndOfInfo($product, null));
    }

    public function testEndOfInfoErrorsHideTheMessage(): void
    {
        $this->config->method('getPlacementPdp')->willThrowException(new \RuntimeException('config'));
        $this->logger->expects($this->once())->method('warning')
            ->with('Hyva_AstoundAffirm: end-of-info product message skipped.', ['error' => 'RuntimeException: config']);

        $this->assertFalse(
            $this->viewModel->canRenderEndOfInfo($this->product(7, 'simple'), $this->createMock(LayoutInterface::class))
        );
    }

    /**
     * (Re)creates the view model with the current collaborator mocks.
     */
    private function rebuildViewModel(): void
    {
        $this->viewModel = new ProductMessage(
            $this->config,
            $this->availability,
            $this->promoId,
            $this->backorderResolver,
            new AmountRange(),
            $this->productPrice,
            $this->logger
        );
    }

    private function product(int $id, string $typeId): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id === 0 ? null : (string) $id);
        $product->method('getTypeId')->willReturn($typeId);

        return $product;
    }
}
