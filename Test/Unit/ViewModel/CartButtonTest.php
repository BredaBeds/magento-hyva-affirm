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
use Hyva\AstoundAffirm\ViewModel\CartButton;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CartButtonTest extends TestCase
{
    private AvailabilityInterface&MockObject $availability;

    private Config&MockObject $config;

    private Quote&MockObject $quote;

    private CheckoutSession&MockObject $checkoutSession;

    private UrlInterface&MockObject $urlBuilder;

    private FormKey&MockObject $formKey;

    private LoggerInterface&MockObject $logger;

    private CartButton $cartButton;

    protected function setUp(): void
    {
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLimits')->willReturn(new Limits(1000.0, 50000.0, 2000.0));
        $this->quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->formKey = $this->createMock(FormKey::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cartButton = new CartButton(
            $this->availability,
            new AmountRange(),
            $this->config,
            $this->checkoutSession,
            $this->urlBuilder,
            $this->formKey,
            $this->logger
        );
    }

    public function testUnavailableWithoutTheCartButtonPlacement(): void
    {
        $this->availability->expects($this->once())->method('isAvailable')
            ->with(Placement::CART_BUTTON)->willReturn(false);
        $this->checkoutSession->expects($this->never())->method('getQuote');

        $this->assertFalse($this->cartButton->isAvailable());
    }

    public function testOrderTotalsApplyToTheBaseGrandTotal(): void
    {
        $this->availability->method('isAvailable')->with(Placement::CART_BUTTON)->willReturn(true);
        // The displayed grand total is irrelevant: Magento compares min/max order totals with the base total.
        $this->quote->setData('grand_total', 5.0);

        $this->quote->setData('base_grand_total', 1000.0);
        $this->assertTrue($this->cartButton->isAvailable());

        $this->quote->setData('base_grand_total', 999.99);
        $this->assertFalse($this->cartButton->isAvailable());

        $this->quote->setData('base_grand_total', 50000.0);
        $this->assertTrue($this->cartButton->isAvailable());

        $this->quote->setData('base_grand_total', 50000.01);
        $this->assertFalse($this->cartButton->isAvailable());
    }

    public function testErrorsHideTheButton(): void
    {
        $this->availability->method('isAvailable')->willThrowException(new \RuntimeException('broken plugin'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertFalse($this->cartButton->isAvailable());
    }

    public function testImageAndWidthComeFromAffirmsSettings(): void
    {
        $this->config->method('getCheckoutButtonImage')->willReturn('https://cdn.example/button.png');
        $this->config->method('getCheckoutButtonWidth')->willReturn(148);

        $this->assertSame('https://cdn.example/button.png', $this->cartButton->getImageUrl());
        $this->assertSame(148, $this->cartButton->getImageWidth());
    }

    public function testFormTargetsTheStartControllerWithTheFormKey(): void
    {
        $this->urlBuilder->expects($this->once())->method('getUrl')
            ->with('hyva_affirm/cart/start', ['_secure' => true])
            ->willReturn('https://shop.example/hyva_affirm/cart/start/');
        $this->formKey->method('getFormKey')->willReturn('FORMKEY123');

        $this->assertSame('https://shop.example/hyva_affirm/cart/start/', $this->cartButton->getActionUrl());
        $this->assertSame('FORMKEY123', $this->cartButton->getFormKey());
    }
}
