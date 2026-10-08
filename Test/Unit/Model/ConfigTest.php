<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    private string $currentCurrency = 'USD';

    private string $baseCurrency = 'USD';

    private ResolverInterface&MockObject $localeResolver;

    private Config $config;

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $storeId) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);

                return $this->values[$storeId === null ? $path : $storeId . '|' . $path] ?? null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            function (string $path, string $scope, $storeId): bool {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);

                return (bool) ($this->values[$storeId === null ? $path : $storeId . '|' . $path] ?? false);
            }
        );

        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturnCallback(fn () => $this->currentCurrency);
        $store->method('getBaseCurrencyCode')->willReturnCallback(fn () => $this->baseCurrency);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->localeResolver = $this->createMock(ResolverInterface::class);
        $this->config = new Config($scopeConfig, $storeManager, $this->localeResolver);
    }

    public function testPublicKeyFollowsModeAndCurrency(): void
    {
        $this->values = [
            'payment/affirm_gateway/mode' => 'sandbox',
            'payment/affirm_gateway/public_api_key_sandbox' => ' SANDBOX_US ',
            'payment/affirm_gateway/public_api_key_sandbox_ca' => 'SANDBOX_CA',
            'payment/affirm_gateway/public_api_key_production' => 'LIVE_US',
            'payment/affirm_gateway/public_api_key_production_ca' => 'LIVE_CA',
        ];
        $this->assertTrue($this->config->isSandbox());
        $this->assertSame('SANDBOX_US', $this->config->getPublicApiKey());
        $this->currentCurrency = 'CAD';
        $this->assertSame('SANDBOX_CA', $this->config->getPublicApiKey());

        $this->values['payment/affirm_gateway/mode'] = 'production';
        $this->assertFalse($this->config->isSandbox());
        $this->assertSame('LIVE_CA', $this->config->getPublicApiKey());
        $this->currentCurrency = 'USD';
        $this->assertSame('LIVE_US', $this->config->getPublicApiKey());
    }

    public function testScriptUrlFollowsMode(): void
    {
        $this->values = ['payment/affirm_gateway/mode' => 'sandbox'];
        $this->assertSame('https://cdn1-sandbox.affirm.com/js/v2/affirm.js', $this->config->getScriptUrl());
        $this->values = ['payment/affirm_gateway/mode' => 'production'];
        $this->assertSame('https://cdn1.affirm.com/js/v2/affirm.js', $this->config->getScriptUrl());
    }

    public function testLocaleAndCountryFollowCurrency(): void
    {
        $this->localeResolver->method('getLocale')->willReturnOnConsecutiveCalls('fr_CA', 'en_GB');
        $this->assertSame('en_US', $this->config->getLocale());
        $this->assertSame('USA', $this->config->getCountryCode());

        $this->currentCurrency = 'CAD';
        $this->assertSame('CAN', $this->config->getCountryCode());
        $this->assertSame('fr_CA', $this->config->getLocale());
        $this->assertSame('en_CA', $this->config->getLocale());

        $this->values = ['3|general/locale/code' => 'fr_CA'];
        $this->assertSame('fr_CA', $this->config->getLocale(3));
    }

    public function testCurrencyMustBeSupportedForBaseAndDisplay(): void
    {
        $this->assertTrue($this->config->isCurrencyValid());
        $this->currentCurrency = 'CAD';
        $this->baseCurrency = 'CAD';
        $this->assertTrue($this->config->isCurrencyValid());
        $this->currentCurrency = 'EUR';
        $this->assertFalse($this->config->isCurrencyValid());
        $this->currentCurrency = 'USD';
        $this->baseCurrency = 'EUR';
        $this->assertFalse($this->config->isCurrencyValid());
    }

    public function testAsLowAsFlagsPerPlacement(): void
    {
        $this->values = [
            'affirm/affirm_aslowas/enabled_pdp' => '1',
            'affirm/affirm_aslowas/enabled_plp' => '0',
            'affirm/affirm_aslowas/enabled_cc' => '1',
            'affirm/affirm_aslowas/enabled_mcc' => '1',
        ];
        $this->assertTrue($this->config->isAsLowAsEnabled(Placement::PRODUCT));
        $this->assertFalse($this->config->isAsLowAsEnabled(Placement::LISTING));
        $this->assertTrue($this->config->isAsLowAsEnabled(Placement::CART));
        $this->assertTrue($this->config->isAsLowAsEnabled(Placement::MINICART));
        $this->assertFalse($this->config->isAsLowAsEnabled(Placement::BANNER));
        $this->assertFalse($this->config->isAsLowAsEnabled(Placement::PIXEL));
    }

    public function testLogoColorDefaultsToBlue(): void
    {
        $this->assertSame('blue', $this->config->getLogoColor());
        $this->values = ['affirm/affirm_aslowas/logo' => 'black'];
        $this->assertSame('black', $this->config->getLogoColor());
        $this->values = ['affirm/affirm_aslowas/logo' => 'Default'];
        $this->assertSame('blue', $this->config->getLogoColor());
    }

    public function testPlacementPdp(): void
    {
        $this->assertSame(0, $this->config->getPlacementPdp());
        $this->values = ['affirm/affirm_aslowas_developer/placement' => '1'];
        $this->assertSame(1, $this->config->getPlacementPdp());
        $this->values = ['affirm/affirm_aslowas_developer/placement' => '2'];
        $this->assertSame(2, $this->config->getPlacementPdp());
        $this->values = ['affirm/affirm_aslowas_developer/placement' => '7'];
        $this->assertSame(0, $this->config->getPlacementPdp());
    }

    public function testLimitsIgnoreEmptyZeroAndInvalidValues(): void
    {
        $this->values = [
            'payment/affirm_gateway/min_order_total' => '1000',
            'payment/affirm_gateway/max_order_total' => '',
            'affirm/affirm_aslowas/min_mpp' => '0',
        ];
        $limits = $this->config->getLimits();
        $this->assertSame(1000.0, $limits->minOrderTotal);
        $this->assertNull($limits->maxOrderTotal);
        $this->assertNull($limits->minMpp);

        $this->values = ['affirm/affirm_aslowas/min_mpp' => 'abc', 'payment/affirm_gateway/max_order_total' => '50000'];
        $limits = $this->config->getLimits();
        $this->assertNull($limits->minMpp);
        $this->assertSame(50000.0, $limits->maxOrderTotal);
    }

    public function testCheckoutButtonSettings(): void
    {
        $this->values = [
            'payment/affirm_gateway/enable_checkout_button' => '1',
            'payment/affirm_gateway/checkout_button_code' => ' https://cdn-assets.affirm.com/images/buttons/x.png ',
            'payment/affirm_gateway/checkout_button_width' => '148',
        ];
        $this->assertTrue($this->config->isCheckoutButtonEnabled());
        $this->assertSame('https://cdn-assets.affirm.com/images/buttons/x.png', $this->config->getCheckoutButtonImage());
        $this->assertSame(148, $this->config->getCheckoutButtonWidth());
        $this->values['payment/affirm_gateway/checkout_button_width'] = '';
        $this->assertSame(0, $this->config->getCheckoutButtonWidth());
    }

    public function testFlags(): void
    {
        $this->assertFalse($this->config->isActive());
        $this->assertFalse($this->config->isPixelEnabled());
        $this->assertFalse($this->config->isPixelInSandboxForced());
        $this->assertFalse($this->config->isBackorderRuleEnabled());
        $this->assertFalse($this->config->isBannerEnabled());
        $this->assertFalse($this->config->isLearnMoreEnabled());

        $this->values = [
            'payment/affirm_gateway/active' => '1',
            'affirm/affirm_pixel/enabled' => '1',
            'affirm/hyva/pixel_in_sandbox' => '1',
            'payment/affirm_gateway/disable_for_backordered_items' => '1',
            'affirm/affirm_promo/enabled' => '1',
            'affirm/affirm_aslowas/learn_more' => '1',
        ];
        $this->assertTrue($this->config->isActive());
        $this->assertTrue($this->config->isPixelEnabled());
        $this->assertTrue($this->config->isPixelInSandboxForced());
        $this->assertTrue($this->config->isBackorderRuleEnabled());
        $this->assertTrue($this->config->isBannerEnabled());
        $this->assertTrue($this->config->isLearnMoreEnabled());
    }

    public function testStoresWithoutCurrencyDataAreRejected(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createMock(StoreInterface::class));
        $config = new Config($this->createMock(ScopeConfigInterface::class), $storeManager, $this->localeResolver);

        $this->expectException(\UnexpectedValueException::class);
        $config->isCurrencyValid();
    }

    public function testExplicitStoreIdIsPassedThrough(): void
    {
        $this->values = ['5|payment/affirm_gateway/active' => '1', '5|affirm/affirm_mfp' => ['a' => 'b']];
        $this->assertTrue($this->config->isActive(5));
        $this->assertFalse($this->config->isActive());
        $this->assertSame(['a' => 'b'], $this->config->getValue('affirm/affirm_mfp', 5));
    }
}
