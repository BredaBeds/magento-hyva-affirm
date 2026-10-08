<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Plugin\Checkout\CustomerData;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Plugin\Checkout\CustomerData\CartAffirmAmount;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CartAffirmAmountTest extends TestCase
{
    private const SECTION = ['summary_count' => 1, 'subtotalAmount' => 1234.5, 'mfpValue' => 'PROMO'];

    private CheckoutSession&MockObject $checkoutSession;

    private Quote&MockObject $quote;

    private TaxConfig&MockObject $taxConfig;

    private AvailabilityInterface&MockObject $availability;

    private CurrentTheme&MockObject $currentTheme;

    private LoggerInterface&MockObject $logger;

    private Cart&MockObject $subject;

    private CartAffirmAmount $plugin;

    protected function setUp(): void
    {
        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getStoreId')->willReturn(3);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);
        $this->taxConfig = $this->createMock(TaxConfig::class);
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $config = $this->createMock(Config::class);
        $config->method('getLimits')->willReturn(new Limits(1000.0, 30000.0, 500.0));
        $this->currentTheme = $this->createMock(CurrentTheme::class);
        $this->currentTheme->method('isHyva')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(Cart::class);

        $this->plugin = new CartAffirmAmount(
            $this->checkoutSession,
            $this->taxConfig,
            $this->availability,
            $config,
            new AmountRange(),
            $this->currentTheme,
            $this->logger
        );
    }

    public function testLumaStoreViewsAreLeftUntouched(): void
    {
        $currentTheme = $this->createMock(CurrentTheme::class);
        $currentTheme->method('isHyva')->willReturn(false);
        $this->availability->expects($this->never())->method('isAvailable');
        $plugin = new CartAffirmAmount(
            $this->checkoutSession,
            $this->taxConfig,
            $this->availability,
            $this->createMock(Config::class),
            new AmountRange(),
            $currentTheme,
            $this->logger
        );

        $this->assertSame(self::SECTION, $plugin->afterGetSectionData($this->subject, self::SECTION));
    }

    public function testUnavailableMinicartAddsOnlyTheFlagAndLimits(): void
    {
        $this->availability->method('isAvailable')->with(Placement::MINICART)->willReturn(false);
        $this->checkoutSession->expects($this->never())->method('getQuote');

        $result = $this->plugin->afterGetSectionData($this->subject, self::SECTION);

        $this->assertArrayNotHasKey('affirm_amount', $result);
        $this->assertSame(
            ['available' => false, 'limits' => ['min' => 100000, 'max' => 1750000, 'minMpp' => 50000]],
            $result['affirm_minicart']
        );
        $this->assertSame('PROMO', $result['mfpValue']);
    }

    public function testSubtotalExcludingTaxByDefault(): void
    {
        $this->givenAvailableWithSubtotal(['value' => 1000.0, 'value_incl_tax' => 1080.0, 'value_excl_tax' => 1000.0]);
        $this->givenTaxDisplay(false, true);

        $result = $this->plugin->afterGetSectionData($this->subject, self::SECTION);

        $this->assertSame(100000, $result['affirm_amount']);
        $this->assertTrue($result['affirm_minicart']['available']);
        $this->assertSame(1, $result['summary_count']);
    }

    public function testSubtotalIncludingTaxWhenOnlyIncludingTaxIsDisplayed(): void
    {
        $this->givenAvailableWithSubtotal(
            ['value' => 1000.0, 'value_incl_tax' => 1080.555, 'value_excl_tax' => 1000.0]
        );
        $this->givenTaxDisplay(true, false);

        $this->assertSame(108056, $this->plugin->afterGetSectionData($this->subject, [])['affirm_amount']);
    }

    public function testBothTaxDisplaysUseTheAmountExcludingTax(): void
    {
        $this->givenAvailableWithSubtotal(['value' => 999.99, 'value_incl_tax' => 1079.99, 'value_excl_tax' => 999.99]);
        $this->givenTaxDisplay(true, true);

        $this->assertSame(99999, $this->plugin->afterGetSectionData($this->subject, [])['affirm_amount']);
    }

    public function testFallsBackToTheSubtotalValueWithoutTaxAmounts(): void
    {
        $this->givenAvailableWithSubtotal(['value' => 1500.25]);
        $this->givenTaxDisplay(true, false);

        $this->assertSame(150025, $this->plugin->afterGetSectionData($this->subject, [])['affirm_amount']);
    }

    public function testMissingSubtotalGivesZero(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->quote->method('getTotals')->willReturn([]);

        $this->assertSame(0, $this->plugin->afterGetSectionData($this->subject, [])['affirm_amount']);
    }

    public function testErrorsKeepTheSectionDataAndAreLogged(): void
    {
        $this->availability->method('isAvailable')->willReturn(true);
        $this->quote->method('getTotals')->willThrowException(new \RuntimeException('totals failed'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(self::SECTION, $this->plugin->afterGetSectionData($this->subject, self::SECTION));
    }

    /**
     * @param array<string, float> $subtotal
     */
    private function givenAvailableWithSubtotal(array $subtotal): void
    {
        $this->availability->method('isAvailable')->with(Placement::MINICART)->willReturn(true);
        $this->quote->method('getTotals')->willReturn(['subtotal' => new DataObject($subtotal)]);
    }

    private function givenTaxDisplay(bool $inclTax, bool $exclTax): void
    {
        $this->taxConfig->method('displayCartSubtotalInclTax')->with(3)->willReturn($inclTax);
        $this->taxConfig->method('displayCartSubtotalExclTax')->with(3)->willReturn($exclTax);
    }
}
