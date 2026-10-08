<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\AstoundAffirm\ViewModel\AsLowAs;
use Hyva\AstoundAffirm\ViewModel\CartMessage;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CartMessageTest extends TestCase
{
    private const RULE = [
        'min' => 1500.0,
        'max' => 5000.0,
        'promoInRange' => 'CART_SIZE',
        'promoOutOfRange' => 'DEFAULT',
        'higherPrecedenceApplies' => false,
    ];

    private AvailabilityInterface&MockObject $availability;

    private PromoIdInterface&MockObject $promoId;

    private AsLowAs&MockObject $asLowAs;

    private Config&MockObject $config;

    private Quote&MockObject $quote;

    private CheckoutSession&MockObject $checkoutSession;

    private LoggerInterface&MockObject $logger;

    private CartMessage $cartMessage;

    protected function setUp(): void
    {
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->promoId = $this->createMock(PromoIdInterface::class);
        $this->asLowAs = $this->createMock(AsLowAs::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLimits')->willReturn(new Limits(1000.0, 50000.0, 2000.0));
        $this->quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cartMessage = new CartMessage(
            $this->availability,
            $this->promoId,
            $this->asLowAs,
            new AmountRange(),
            $this->config,
            $this->checkoutSession,
            $this->logger
        );
    }

    public function testCartAvailabilityIsTheCartPlacement(): void
    {
        $this->availability->expects($this->once())->method('isAvailable')->with(Placement::CART)->willReturn(true);

        $this->assertTrue($this->cartMessage->isAvailable());
    }

    public function testMinicartAvailabilityIsTheMinicartPlacement(): void
    {
        $this->availability->expects($this->once())->method('isAvailable')
            ->with(Placement::MINICART)->willReturn(false);

        $this->assertFalse($this->cartMessage->isMinicartAvailable());
    }

    public function testAmountIsTheQuoteGrandTotalInCents(): void
    {
        $this->quote->setData('grand_total', '1234.5');
        $this->quote->setData('base_grand_total', 1.0);
        $this->assertSame(123450, $this->cartMessage->getAmountCents());

        $this->quote->setData('grand_total', 999.999);
        $this->assertSame(100000, $this->cartMessage->getAmountCents());
    }

    public function testAmountIsZeroWhenTheQuoteCannotBeRead(): void
    {
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willThrowException(new \RuntimeException('no session'));
        $this->logger->expects($this->once())->method('warning');
        $cartMessage = new CartMessage(
            $this->availability,
            $this->promoId,
            $this->asLowAs,
            new AmountRange(),
            $this->config,
            $checkoutSession,
            $this->logger
        );

        $this->assertSame(0, $cartMessage->getAmountCents());
    }

    public function testDisplayableIgnoresMinMppAndHonoursOrderTotals(): void
    {
        // min_mpp is 2,000.00, which the cart page ignores (Luma parity); min_order_total is 1,000.00.
        $this->assertTrue($this->cartMessage->isDisplayable(150000));
        $this->assertTrue($this->cartMessage->isDisplayable(100000));
        $this->assertFalse($this->cartMessage->isDisplayable(99999));
        $this->assertTrue($this->cartMessage->isDisplayable(1750000));
        $this->assertFalse($this->cartMessage->isDisplayable(1750001));
    }

    public function testPromoIdIsAffirmsCartPromoId(): void
    {
        $this->promoId->expects($this->once())->method('forCart')->willReturn('CART_PROMO');

        $this->assertSame('CART_PROMO', $this->cartMessage->getPromoId());
    }

    public function testComponentConfigCarriesBoundsAndTheObservedCartRule(): void
    {
        $this->asLowAs->expects($this->once())->method('getCartRule')->willReturn(self::RULE);

        $this->assertSame(
            ['limits' => ['min' => 100000, 'max' => 1750000, 'minMpp' => 200000], 'cartRule' => self::RULE],
            $this->cartMessage->getComponentConfig()
        );
    }

    public function testComponentConfigNeverSwitchesWhenTheRuleFails(): void
    {
        $this->asLowAs->method('getCartRule')->willThrowException(new \RuntimeException('observer failed'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            [
                'min' => null,
                'max' => null,
                'promoInRange' => '',
                'promoOutOfRange' => '',
                'higherPrecedenceApplies' => true,
            ],
            $this->cartMessage->getComponentConfig()['cartRule']
        );
    }
}
