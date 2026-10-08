<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Plugin\Affirm;

use Astound\Affirm\Helper\AsLowAs;
use Astound\Affirm\Model\Config as AffirmConfig;
use Astound\Affirm\Model\Ui\ConfigProvider;
use Hyva\AstoundAffirm\Plugin\Affirm\CategoryViewThemeAware;
use Hyva\AstoundAffirm\Plugin\Affirm\ListProductThemeAware;
use Hyva\AstoundAffirm\Plugin\Affirm\MinicartConfigThemeAware;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Checkout\Block\Cart\Sidebar;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ThemeAwarePluginsTest extends TestCase
{
    private AffirmConfig&MockObject $affirmConfig;

    private AsLowAs&MockObject $asLowAs;

    private CollectionFactory&MockObject $collectionFactory;

    private StoreManagerInterface&MockObject $storeManager;

    protected function setUp(): void
    {
        $this->affirmConfig = $this->createMock(AffirmConfig::class);
        $this->asLowAs = $this->createMock(AsLowAs::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    public function testListingPluginPassesThroughOnHyva(): void
    {
        $this->affirmConfig->expects($this->never())->method('isAsLowAsEnabled');
        $this->collectionFactory->expects($this->never())->method('create');
        $plugin = $this->listProductPlugin(true);

        $result = $plugin->aroundGetProductPrice(new \stdClass(), static fn () => '<price/>', $this->product());

        $this->assertSame('<price/>', $result);
    }

    public function testListingPluginKeepsAffirmsLumaMessage(): void
    {
        $this->affirmConfig->method('isAsLowAsEnabled')->with('plp')->willReturn(1);
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);
        $this->asLowAs->method('getFinancingProgramValueALS')->willReturn('PROMO');
        $this->asLowAs->method('formatPrice')->willReturn(150000);
        $plugin = $this->listProductPlugin(false);

        $result = $plugin->aroundGetProductPrice(new \stdClass(), static fn () => '<price/>', $this->product());

        $this->assertStringStartsWith('<price/>', $result);
        $this->assertStringContainsString('id="as_low_as_plp_5"', $result);
        $this->assertStringContainsString('data-promo-id="PROMO"', $result);
    }

    public function testCategoryPluginPassesThroughOnHyva(): void
    {
        $this->affirmConfig->expects($this->never())->method('isAsLowAsEnabled');
        $plugin = new CategoryViewThemeAware(
            $this->storeManager,
            $this->createMock(ConfigProvider::class),
            $this->affirmConfig,
            $this->asLowAs,
            $this->collectionFactory,
            $this->theme(true)
        );

        $this->assertSame('<list/>', $plugin->afterGetProductListHtml(new \stdClass(), '<list/>'));
    }

    public function testCategoryPluginKeepsAffirmsLumaWidget(): void
    {
        $this->affirmConfig->method('isAsLowAsEnabled')->with('plp')->willReturn(1);
        $plugin = new CategoryViewThemeAware(
            $this->storeManager,
            $this->createMock(ConfigProvider::class),
            $this->affirmConfig,
            $this->asLowAs,
            $this->collectionFactory,
            $this->theme(false)
        );

        $result = $plugin->afterGetProductListHtml(new \stdClass(), '<list/>');

        $this->assertStringStartsWith('<list/>', $result);
        $this->assertStringContainsString('Astound_Affirm/js/aslowasPLP', $result);
    }

    public function testMinicartPluginPassesThroughOnHyva(): void
    {
        $this->affirmConfig->expects($this->never())->method('getAllAsLowAsConfig');
        $plugin = new MinicartConfigThemeAware($this->affirmConfig, $this->theme(true));

        $this->assertSame(['a' => 1], $plugin->afterGetConfig($this->createMock(Sidebar::class), ['a' => 1]));
    }

    public function testMinicartPluginKeepsAffirmsLumaConfig(): void
    {
        $this->affirmConfig->method('getAllAsLowAsConfig')->willReturn(['logo' => 'black']);
        $plugin = new MinicartConfigThemeAware($this->affirmConfig, $this->theme(false));

        $result = $plugin->afterGetConfig($this->createMock(Sidebar::class), ['a' => 1]);

        $this->assertSame(1, $result['a']);
        $this->assertSame('als_mcc', $result['element_id']);
        $this->assertSame('black', $result['color_id']);
    }

    private function listProductPlugin(bool $hyva): ListProductThemeAware
    {
        return new ListProductThemeAware(
            $this->storeManager,
            $this->createMock(ConfigProvider::class),
            $this->affirmConfig,
            $this->asLowAs,
            $this->collectionFactory,
            $this->theme($hyva)
        );
    }

    private function theme(bool $hyva): CurrentTheme&MockObject
    {
        $theme = $this->createMock(CurrentTheme::class);
        $theme->method('isHyva')->willReturn($hyva);

        return $theme;
    }

    private function product(): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(5);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getFinalPrice')->willReturn(1500.0);

        return $product;
    }
}
