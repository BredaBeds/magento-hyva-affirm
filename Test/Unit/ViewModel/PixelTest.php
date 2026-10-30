<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Astound\Affirm\Gateway\Helper\Util;
use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\Availability;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\ViewModel\Pixel;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Math\Random;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PixelTest extends TestCase
{
    private AvailabilityInterface&MockObject $availability;

    private CheckoutSession&MockObject $checkoutSession;

    private LoggerInterface&MockObject $logger;

    private Pixel $pixel;

    protected function setUp(): void
    {
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        // Affirm's own cents formatter, as the Luma pixel uses it.
        $this->pixel = new Pixel($this->availability, $this->checkoutSession, new Util(new Random()), $this->logger);
    }

    public function testPayloadMatchesAffirmsPixel(): void
    {
        $this->available(true);
        $this->checkoutSession->expects($this->once())->method('getLastRealOrder')
            ->willReturn($this->order('100000123', 'USD', '2824.00', 'affirm_gateway'));

        $this->assertSame(
            ['orderId' => '100000123', 'currency' => 'USD', 'total' => 282400, 'paymentMethod' => 'affirm_gateway'],
            $this->pixel->getPayload()
        );
        $this->assertTrue($this->pixel->isStrict());
        // Memoised: the session order is read once.
        $this->assertSame(282400, $this->pixel->getPayload()['total'] ?? null);
    }

    public function testTotalIsTheBaseGrandTotalInCents(): void
    {
        $this->available(true);
        $this->checkoutSession->method('getLastRealOrder')
            ->willReturn($this->order('100000124', 'CAD', 1234.5, 'checkmo'));

        $payload = $this->pixel->getPayload();

        $this->assertSame(123450, $payload['total'] ?? null);
        $this->assertSame('CAD', $payload['currency'] ?? null);
        $this->assertSame('checkmo', $payload['paymentMethod'] ?? null);
    }

    public function testNotStrictWhenAValueIsEmpty(): void
    {
        $this->available(true);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($this->order('100000125', 'USD', 0, 'free'));

        $this->assertSame(0, $this->pixel->getPayload()['total'] ?? null);
        $this->assertFalse($this->pixel->isStrict());
    }

    public function testNothingWhenThePixelIsNotAvailable(): void
    {
        // Covers the pixel switch and sandbox suppression, both decided by Availability (PIXEL).
        $this->available(false);
        $this->checkoutSession->expects($this->never())->method('getLastRealOrder');

        $this->assertNull($this->pixel->getPayload());
        $this->assertFalse($this->pixel->isStrict());
    }

    public function testSandboxSuppressionAndDeveloperFlagThroughAvailability(): void
    {
        // [pixel enabled, sandbox mode, affirm/hyva/pixel_in_sandbox] => pixel sent
        $cases = [
            [true, false, false, true],
            [true, true, false, false],
            [true, true, true, true],
            [false, false, false, false],
            [false, true, true, false],
        ];
        foreach ($cases as [$enabled, $sandbox, $forced, $expected]) {
            $config = $this->configMock();
            $config->method('isActive')->willReturn(true);
            $config->method('getPublicApiKey')->willReturn('public-key');
            $config->method('isCurrencyValid')->willReturn(true);
            $config->method('isPixelEnabled')->willReturn($enabled);
            $config->method('isSandbox')->willReturn($sandbox);
            $config->method('isPixelInSandboxForced')->willReturn($forced);
            $checkoutSession = $this->createMock(CheckoutSession::class);
            $checkoutSession->method('getLastRealOrder')
                ->willReturn($this->order('100000126', 'USD', '1500.00', 'affirm_gateway'));
            $availability = new Availability(
                $config,
                $this->createMock(BackorderResolver::class),
                $checkoutSession,
                $this->createMock(PaymentHelper::class),
                $this->logger
            );
            $pixel = new Pixel($availability, $checkoutSession, new Util(new Random()), $this->logger);

            $this->assertSame(
                $expected,
                $pixel->getPayload() !== null,
                sprintf('enabled=%d sandbox=%d forced=%d', (int) $enabled, (int) $sandbox, (int) $forced)
            );
        }
    }

    public function testNothingWithoutAnOrder(): void
    {
        $this->available(true);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);
        $order->method('getPayment')->willReturn(null);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);

        $this->assertNull($this->pixel->getPayload());
    }

    public function testNothingWithoutAPayment(): void
    {
        $this->available(true);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn('42');
        $order->method('getPayment')->willReturn(null);
        $this->checkoutSession->method('getLastRealOrder')->willReturn($order);

        $this->assertNull($this->pixel->getPayload());
    }

    public function testFailuresSkipThePixelAndAreLogged(): void
    {
        $this->available(true);
        $this->checkoutSession->method('getLastRealOrder')->willThrowException(new \RuntimeException('session'));
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('pixel'), $this->arrayHasKey('error'));

        $this->assertNull($this->pixel->getPayload());
    }

    private function available(bool $available): void
    {
        $this->availability->method('isAvailable')->with(Placement::PIXEL)->willReturn($available);
    }

    private function order(string $incrementId, string $currency, float|int|string $baseTotal, string $method): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn('42');
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        $order->method('getBaseGrandTotal')->willReturn($baseTotal);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    /**
     * Config mock whose canLoadAffirmJs() stays real: it combines the stubbed isActive, key and currency checks.
     *
     * @return Config&MockObject
     */
    private function configMock(): Config
    {
        $stubbed = array_diff(get_class_methods(Config::class), ['__construct', 'canLoadAffirmJs']);

        return $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_values($stubbed))
            ->getMock();
    }
}
