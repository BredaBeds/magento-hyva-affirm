<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\Availability;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AvailabilityTest extends TestCase
{
    /** @var array<string, mixed> Config method => return value */
    private array $settings = [];

    private BackorderResolver&MockObject $backorders;

    private Quote&MockObject $quote;

    private MethodInterface&MockObject $method;

    private PaymentHelper&MockObject $paymentHelper;

    private LoggerInterface&MockObject $logger;

    private Availability $availability;

    protected function setUp(): void
    {
        $this->settings = [
            'isActive' => true,
            'getPublicApiKey' => 'PUBLIC',
            'isCurrencyValid' => true,
            'isAsLowAsEnabled' => true,
            'isBannerEnabled' => true,
            'isCheckoutButtonEnabled' => true,
            'isPixelEnabled' => true,
            'isSandbox' => false,
            'isPixelInSandboxForced' => false,
        ];
        $config = $this->createMock(Config::class);
        foreach (array_keys($this->settings) as $methodName) {
            $config->method($methodName)->willReturnCallback(fn () => $this->settings[$methodName]);
        }

        $this->backorders = $this->createMock(BackorderResolver::class);
        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getId')->willReturn(42);
        $this->quote->method('hasItems')->willReturn(true);
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($this->quote);
        $this->method = $this->createMock(MethodInterface::class);
        $this->paymentHelper = $this->createMock(PaymentHelper::class);
        $this->paymentHelper->method('getMethodInstance')->with('affirm_gateway')->willReturn($this->method);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->availability = new Availability(
            $config,
            $this->backorders,
            $session,
            $this->paymentHelper,
            $this->logger
        );
    }

    public function testEveryPlacementNeedsActiveGatewayKeyAndCurrency(): void
    {
        $this->method->method('isAvailable')->willReturn(true);
        foreach (['isActive' => false, 'getPublicApiKey' => '', 'isCurrencyValid' => false] as $setting => $value) {
            $original = $this->settings[$setting];
            $this->settings[$setting] = $value;
            foreach (Placement::cases() as $placement) {
                $this->assertFalse(
                    $this->availability->isAvailable($placement, $this->product()),
                    $setting . ' / ' . $placement->value
                );
            }
            $this->settings[$setting] = $original;
        }
    }

    public function testProductMessage(): void
    {
        $this->assertTrue($this->availability->isAvailable(Placement::PRODUCT, $this->product()));
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT));
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $this->product(typeId: 'grouped')));
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $this->product(saleable: false)));
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $this->product(canShowPrice: false)));
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $this->product(msrpApplies: true)));
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $this->product(id: null)));
        $this->assertFalse(
            $this->availability->isAvailable(Placement::PRODUCT, $this->createMock(ProductInterface::class))
        );

        $this->settings['isAsLowAsEnabled'] = false;
        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $this->product()));
    }

    public function testProductMessageHonoursBackorders(): void
    {
        $product = $this->product();
        $this->backorders->method('isBackordered')->with($product)->willReturn(true);

        $this->assertFalse($this->availability->isAvailable(Placement::PRODUCT, $product));
        $this->assertFalse($this->availability->isAvailable(Placement::LISTING, $product));
    }

    public function testListingMessage(): void
    {
        $this->assertTrue($this->availability->isAvailable(Placement::LISTING, $this->product()));
        // Luma shows listing messages regardless of saleability and product type.
        $this->assertTrue($this->availability->isAvailable(Placement::LISTING, $this->product(saleable: false)));
        $this->assertTrue($this->availability->isAvailable(Placement::LISTING, $this->product(typeId: 'grouped')));
        $this->assertFalse($this->availability->isAvailable(Placement::LISTING, $this->product(msrpApplies: true)));
        $this->assertFalse($this->availability->isAvailable(Placement::LISTING));
    }

    public function testCartMessageAsksAffirmsPaymentMethod(): void
    {
        $this->method->expects($this->exactly(2))->method('isAvailable')->with($this->quote)
            ->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($this->availability->isAvailable(Placement::CART));
        $this->assertFalse($this->availability->isAvailable(Placement::CART));

        $this->settings['isAsLowAsEnabled'] = false;
        $this->assertFalse($this->availability->isAvailable(Placement::CART));
    }

    public function testCartPlacementsNeedAQuoteWithItems(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(null);
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($quote);
        $config = $this->createMock(Config::class);
        $config->method('isActive')->willReturn(true);
        $config->method('getPublicApiKey')->willReturn('PUBLIC');
        $config->method('isCurrencyValid')->willReturn(true);
        $config->method('isAsLowAsEnabled')->willReturn(true);
        $config->method('isCheckoutButtonEnabled')->willReturn(true);
        $this->paymentHelper->expects($this->never())->method('getMethodInstance');
        $availability = new Availability($config, $this->backorders, $session, $this->paymentHelper, $this->logger);

        $this->assertFalse($availability->isAvailable(Placement::CART));
        $this->assertFalse($availability->isAvailable(Placement::CART_BUTTON));
    }

    public function testMinicartIsConfigurationOnly(): void
    {
        $this->paymentHelper->expects($this->never())->method('getMethodInstance');

        $this->assertTrue($this->availability->isAvailable(Placement::MINICART));
        $this->settings['isAsLowAsEnabled'] = false;
        $this->assertFalse($this->availability->isAvailable(Placement::MINICART));
    }

    public function testBanner(): void
    {
        $product = $this->product();
        $this->backorders->method('isBackordered')->willReturnOnConsecutiveCalls(false, true);

        $this->assertTrue($this->availability->isAvailable(Placement::BANNER));
        $this->assertTrue($this->availability->isAvailable(Placement::BANNER, $product));
        $this->assertFalse($this->availability->isAvailable(Placement::BANNER, $product));
        $this->settings['isBannerEnabled'] = false;
        $this->assertFalse($this->availability->isAvailable(Placement::BANNER));
    }

    public function testCartButton(): void
    {
        $this->method->method('isAvailable')->willReturn(true);

        $this->assertTrue($this->availability->isAvailable(Placement::CART_BUTTON));
        // The cart button does not depend on the cart message switch.
        $this->settings['isAsLowAsEnabled'] = false;
        $this->assertTrue($this->availability->isAvailable(Placement::CART_BUTTON));
        $this->settings['isCheckoutButtonEnabled'] = false;
        $this->assertFalse($this->availability->isAvailable(Placement::CART_BUTTON));
    }

    public function testPixelIsSuppressedInSandboxUnlessForced(): void
    {
        $this->assertTrue($this->availability->isAvailable(Placement::PIXEL));
        $this->settings['isSandbox'] = true;
        $this->assertFalse($this->availability->isAvailable(Placement::PIXEL));
        $this->settings['isPixelInSandboxForced'] = true;
        $this->assertTrue($this->availability->isAvailable(Placement::PIXEL));
        $this->settings['isPixelEnabled'] = false;
        $this->assertFalse($this->availability->isAvailable(Placement::PIXEL));
    }

    public function testErrorsFailClosedAndAreLogged(): void
    {
        $this->method->method('isAvailable')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('availability'), $this->callback(
                static fn (array $context): bool => $context['placement'] === 'cart'
                    && str_contains($context['error'], 'boom')
            ));

        $this->assertFalse($this->availability->isAvailable(Placement::CART));
    }

    private function product(
        ?int $id = 5,
        string $typeId = 'simple',
        bool $saleable = true,
        ?bool $canShowPrice = null,
        bool $msrpApplies = false
    ): Product&MockObject {
        $msrp = new class($msrpApplies) {
            public function __construct(private readonly bool $applies)
            {
            }

            public function canApplyMsrp(): bool
            {
                return $this->applies;
            }

            public function isMinimalPriceLessMsrp(): bool
            {
                return true;
            }
        };
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrices')->willReturn(new \ArrayIterator(['msrp_price' => $msrp]));

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('isSaleable')->willReturn($saleable);
        $product->method('getData')->willReturnMap([['can_show_price', null, $canShowPrice]]);
        $product->method('getPriceInfo')->willReturn($priceInfo);

        return $product;
    }
}
