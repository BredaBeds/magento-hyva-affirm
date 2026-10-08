<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Model\Messaging;

use Astound\Affirm\Helper\AsLowAs;
use Hyva\AstoundAffirm\Model\Messaging\PromoId;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PromoIdTest extends TestCase
{
    /** @var array<string, mixed> AsLowAs method => return value */
    private array $helper = [];

    private AsLowAs&MockObject $asLowAs;

    private CollectionFactory&MockObject $collectionFactory;

    private LoggerInterface&MockObject $logger;

    private PromoId $promoId;

    protected function setUp(): void
    {
        $this->helper = [
            'getFinancingProgramValue' => 'FINAL',
            'getCustomerFinancingProgram' => '',
            'getFinancingProgramFromProducts' => '',
            'getFinancingProgramFromCategories' => '',
            'getFinancingProgramFromCartSize' => '',
            'getFinancingProgramCartSizeValue' => '',
            'getFinancingProgramCartSizeMinOrderTotal' => null,
            'getFinancingProgramCartSizeMaxOrderTotal' => null,
            'isFinancingProgramValidCurrentDate' => false,
            'getFinancingProgramDateRange' => 'DATED',
            'getFinancingProgramDefault' => 'DEFAULT',
        ];
        $this->asLowAs = $this->getMockBuilder(AsLowAs::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array_merge(array_keys($this->helper), ['getFinancingProgramValueALS']))
            ->getMock();
        foreach (array_keys($this->helper) as $methodName) {
            $this->asLowAs->method($methodName)->willReturnCallback(fn () => $this->helper[$methodName]);
        }

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->promoId = new PromoId($this->asLowAs, $this->collectionFactory, $storeManager, $this->logger);
    }

    public function testProductPromoIdUsesAFreshCollectionAndIsMemoised(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('addAttributeToSelect')
            ->with($this->callback(
                static fn (array $attributes): bool => in_array('affirm_product_promo_id', $attributes, true)
                    && in_array('affirm_product_mfp_end_date', $attributes, true)
            ))->willReturnSelf();
        $collection->expects($this->once())->method('addAttributeToFilter')
            ->with('entity_id', ['eq' => 7])->willReturnSelf();
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);
        $this->asLowAs->expects($this->once())->method('getFinancingProgramValueALS')
            ->with($collection)->willReturn('PRODUCT_PROMO');

        $this->assertSame('PRODUCT_PROMO', $this->promoId->forProduct($this->product(7)));
        $this->assertSame('PRODUCT_PROMO', $this->promoId->forProduct($this->product(7)));
    }

    public function testProductWithoutIdAndFailuresGiveNoPromoId(): void
    {
        $this->assertSame('', $this->promoId->forProduct($this->product(0)));

        $this->collectionFactory->method('create')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('warning');
        $this->assertSame('', $this->promoId->forProduct($this->product(9)));
    }

    public function testCartPromoIdIsAffirmsQuoteChain(): void
    {
        $this->assertSame('FINAL', $this->promoId->forCart());
        $this->helper['getFinancingProgramValue'] = 'CHANGED';
        $this->assertSame('FINAL', $this->promoId->forCart(), 'memoised for the request');
    }

    public function testCartPromoIdFailureGivesNoPromoId(): void
    {
        $asLowAs = $this->createMock(AsLowAs::class);
        $asLowAs->method('getFinancingProgramValue')->willThrowException(new \RuntimeException('quote'));
        $promoId = new PromoId(
            $asLowAs,
            $this->collectionFactory,
            $this->createMock(StoreManagerInterface::class),
            $this->logger
        );
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame('', $promoId->forCart());
    }

    public function testCartRuleWithoutCartSizeSettingNeverSwitches(): void
    {
        $this->helper['getFinancingProgramValue'] = 'DEFAULT';

        $rule = $this->promoId->cartRule();
        $this->assertNull($rule->min);
        $this->assertSame('DEFAULT', $rule->promoInRange);
        $this->assertSame('DEFAULT', $rule->promoOutOfRange);
        $this->assertTrue($rule->higherPrecedenceApplies);
    }

    public function testCartRuleSwitchesBetweenCartSizeAndDatedOrDefault(): void
    {
        $this->setCartSizeRule('SIZE', '1000', '5000');
        $this->helper['getFinancingProgramValue'] = 'DEFAULT';

        $rule = $this->promoId->cartRule();
        $this->assertSame(1000.0, $rule->min);
        $this->assertSame(5000.0, $rule->max);
        $this->assertSame('SIZE', $rule->promoInRange);
        $this->assertSame('DEFAULT', $rule->promoOutOfRange);
        $this->assertFalse($rule->higherPrecedenceApplies);
    }

    public function testCartRuleOutOfRangeUsesTheDatedPromoWhenValid(): void
    {
        $this->setCartSizeRule('SIZE', '1000', '5000');
        $this->helper['isFinancingProgramValidCurrentDate'] = true;
        $this->helper['getFinancingProgramFromCartSize'] = 'SIZE';
        $this->helper['getFinancingProgramValue'] = 'SIZE';

        $rule = $this->promoId->cartRule();
        $this->assertSame('SIZE', $rule->promoInRange);
        $this->assertSame('DATED', $rule->promoOutOfRange);
        $this->assertFalse($rule->higherPrecedenceApplies);
    }

    public function testProductOrCategoryPromoTakesPrecedence(): void
    {
        $this->setCartSizeRule('SIZE', '1000', '5000');
        $this->helper['getFinancingProgramFromCategories'] = 'CATEGORY';
        $this->helper['getFinancingProgramValue'] = 'CATEGORY';

        $rule = $this->promoId->cartRule();
        $this->assertSame('CATEGORY', $rule->promoInRange);
        $this->assertSame('CATEGORY', $rule->promoOutOfRange);
        $this->assertTrue($rule->higherPrecedenceApplies);
    }

    public function testAnOverriddenFinalValueTakesPrecedence(): void
    {
        // A plugin on getFinancingProgramValue() returned something Affirm's chain would not.
        $this->setCartSizeRule('SIZE', '1000', '5000');
        $this->helper['getFinancingProgramValue'] = 'OVERRIDE';

        $rule = $this->promoId->cartRule();
        $this->assertSame('OVERRIDE', $rule->promoInRange);
        $this->assertSame('OVERRIDE', $rule->promoOutOfRange);
        $this->assertTrue($rule->higherPrecedenceApplies);
    }

    public function testZeroMinimumDisablesTheRuleLikeAffirm(): void
    {
        $this->setCartSizeRule('SIZE', '0', '5000');
        $this->helper['getFinancingProgramValue'] = 'DEFAULT';

        $rule = $this->promoId->cartRule();
        $this->assertNull($rule->min);
        $this->assertSame('DEFAULT', $rule->promoInRange);
        $this->assertSame('DEFAULT', $rule->promoOutOfRange);
    }

    public function testBannerPromoIds(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $this->collectionFactory->method('create')->willReturn($collection);
        $this->asLowAs->method('getFinancingProgramValueALS')->willReturn('PRODUCT_PROMO');

        $this->assertSame('PRODUCT_PROMO', $this->promoId->forBanner('product', $this->product(3)));
        $this->assertSame('FINAL', $this->promoId->forBanner('cart'));
        $this->assertSame('FINAL', $this->promoId->forBanner('checkout_cart'));
        // Home and category pages are cached: dated or default promo id only, never the quote chain.
        $this->assertSame('DEFAULT', $this->promoId->forBanner('homepage'));
        $this->assertSame('DEFAULT', $this->promoId->forBanner('category'));
        $this->assertSame('DEFAULT', $this->promoId->forBanner('product'));
    }

    private function setCartSizeRule(string $value, string $min, string $max): void
    {
        $this->helper['getFinancingProgramCartSizeValue'] = $value;
        $this->helper['getFinancingProgramCartSizeMinOrderTotal'] = $min;
        $this->helper['getFinancingProgramCartSizeMaxOrderTotal'] = $max;
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id ?: null);

        return $product;
    }
}
