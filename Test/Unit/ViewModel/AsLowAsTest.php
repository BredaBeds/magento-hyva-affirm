<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\CartPromoRule;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\AstoundAffirm\ViewModel\AsLowAs;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Magento\Framework\Event\ManagerInterface as EventManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AsLowAsTest extends TestCase
{
    private PromoIdInterface&MockObject $promoId;

    private EventManager&MockObject $eventManager;

    private LoggerInterface&MockObject $logger;

    /** @var callable|null */
    private $observer = null;

    /** @var DataObject[] */
    private array $transports = [];

    private AsLowAs $asLowAs;

    protected function setUp(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getLogoColor')->willReturn('blue');
        $config->method('isLearnMoreEnabled')->willReturn(true);
        $this->promoId = $this->createMock(PromoIdInterface::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->eventManager->method('dispatch')->willReturnCallback(function (string $name, array $data): void {
            $this->assertSame('hyva_affirm_as_low_as_data', $name);
            $this->transports[] = $data['transport'];
            if ($this->observer !== null) {
                ($this->observer)($data['transport']);
            }
        });
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->asLowAs = new AsLowAs($config, $this->promoId, $this->eventManager, $escaper, $this->logger);
    }

    public function testProductAttributes(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $this->assertSame(
            [
                'class' => 'affirm-as-low-as hyva-affirm-message',
                'data-page-type' => 'product',
                'data-amount' => '129900',
                'data-promo-id' => 'PROMO',
                'data-affirm-color' => 'blue',
                'data-learnmore-show' => 'true',
                'data-hyva-affirm-placement' => 'product',
            ],
            $this->asLowAs->getAttributes(Placement::PRODUCT, 129900, 'PROMO', [], $product)
        );
        $transport = $this->transports[0];
        $this->assertSame(Placement::PRODUCT, $transport->getData('placement'));
        $this->assertSame($product, $transport->getData('product'));
        $this->assertNull($transport->getData('cart_rule'));
        $this->assertTrue($transport->getData('render'));
    }

    public function testPageTypesAndLazyMinicart(): void
    {
        $listing = $this->asLowAs->getAttributes(Placement::LISTING, 9900, '');
        $this->assertSame('category', $listing['data-page-type']);
        $this->assertArrayNotHasKey('data-promo-id', $listing);
        $this->assertArrayNotHasKey('data-hyva-affirm-lazy', $listing);

        $minicart = $this->asLowAs->getAttributes(Placement::MINICART, 9900, 'P');
        $this->assertSame('cart', $minicart['data-page-type']);
        $this->assertSame('', $minicart['data-hyva-affirm-lazy']);
    }

    public function testExtraAttributes(): void
    {
        $attributes = $this->asLowAs->getAttributes(Placement::PRODUCT, 100, 'P', [
            'class' => 'mt-2',
            'data-hyva-affirm-product-id' => 7,
            'data-learnmore-show' => null,
            'data-affirm-type' => 'logo',
            'x-ref' => true,
            'data-affirm-color' => false,
            ':data-amount' => 'amountCents',
            '@private-content-loaded.window' => 'refresh',
            0 => 'ignored',
        ]);

        $this->assertSame('affirm-as-low-as hyva-affirm-message mt-2', $attributes['class']);
        $this->assertSame('7', $attributes['data-hyva-affirm-product-id']);
        $this->assertSame('logo', $attributes['data-affirm-type']);
        $this->assertSame('', $attributes['x-ref']);
        $this->assertSame('amountCents', $attributes[':data-amount']);
        $this->assertSame('refresh', $attributes['@private-content-loaded.window']);
        $this->assertArrayNotHasKey('data-learnmore-show', $attributes);
        $this->assertArrayNotHasKey('data-affirm-color', $attributes);
        $this->assertArrayNotHasKey(0, $attributes);
    }

    public function testPlacementsWithoutMessagesRenderNothing(): void
    {
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->assertNull($this->asLowAs->getAttributes(Placement::BANNER, 100, 'P'));
        $this->assertNull($this->asLowAs->getAttributes(Placement::CART_BUTTON, 100, 'P'));
        $this->assertNull($this->asLowAs->getAttributes(Placement::PIXEL, 100, 'P'));
    }

    public function testObserversCanChangeOrVetoTheMessage(): void
    {
        $this->observer = static function (DataObject $transport): void {
            $attributes = $transport->getData('attributes');
            $attributes['data-promo-id'] = 'QUEUED';
            $attributes['data-amount'] = 5;
            $attributes['bad name'] = 'x';
            $transport->setData('attributes', $attributes);
        };
        $attributes = $this->asLowAs->getAttributes(Placement::PRODUCT, 100, 'P');
        $this->assertSame('QUEUED', $attributes['data-promo-id']);
        $this->assertSame('5', $attributes['data-amount']);
        $this->assertArrayNotHasKey('bad name', $attributes);

        $this->observer = static fn (DataObject $transport) => $transport->setData('render', false);
        $this->assertNull($this->asLowAs->getAttributes(Placement::PRODUCT, 100, 'P'));
    }

    public function testObserverErrorsRenderNothing(): void
    {
        $this->observer = static function (): void {
            throw new \RuntimeException('observer');
        };
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->asLowAs->getAttributes(Placement::CART, 100, 'P'));
    }

    public function testCartRulePassesThroughTheSameEvent(): void
    {
        $this->promoId->expects($this->once())->method('cartRule')
            ->willReturn(new CartPromoRule(1000.0, 5000.0, 'SIZE', 'DEFAULT', false));
        $seen = null;
        $this->observer = static function (DataObject $transport) use (&$seen): void {
            $seen = $rule = $transport->getData('cart_rule');
            $rule['promoInRange'] = 'QUEUED';
            $rule['promoOutOfRange'] = 'QUEUED';
            $transport->setData('cart_rule', $rule);
        };

        $attributes = $this->asLowAs->getAttributes(Placement::CART, 150000, 'DEFAULT');
        $this->assertSame('cart', $attributes['data-page-type']);
        $this->assertSame(
            ['min' => 1000.0, 'max' => 5000.0, 'promoInRange' => 'SIZE', 'promoOutOfRange' => 'DEFAULT',
                'higherPrecedenceApplies' => false],
            $seen
        );
        $this->assertSame(
            ['min' => 1000.0, 'max' => 5000.0, 'promoInRange' => 'QUEUED', 'promoOutOfRange' => 'QUEUED',
                'higherPrecedenceApplies' => false],
            $this->asLowAs->getCartRule()
        );
        $this->assertCount(1, $this->transports, 'getCartRule() reuses the cart message event');
    }

    public function testCartRuleOnItsOwn(): void
    {
        $this->promoId->method('cartRule')->willReturn(CartPromoRule::fixed('P'));

        $this->assertSame(
            ['min' => null, 'max' => null, 'promoInRange' => 'P', 'promoOutOfRange' => 'P',
                'higherPrecedenceApplies' => true],
            $this->asLowAs->getCartRule()
        );
        $this->assertCount(1, $this->transports);
        $this->assertSame(Placement::CART, $this->transports[0]->getData('placement'));
        $this->assertSame([], $this->transports[0]->getData('attributes'));
        $this->asLowAs->getCartRule();
        $this->assertCount(1, $this->transports, 'memoised');
    }

    public function testCartRuleObserverErrorsKeepAffirmsRule(): void
    {
        $this->promoId->method('cartRule')->willReturn(new CartPromoRule(1.0, 2.0, 'A', 'B', false));
        $this->observer = static function (): void {
            throw new \RuntimeException('observer');
        };
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame('A', $this->asLowAs->getCartRule()['promoInRange']);
    }

    public function testRenderAttributesEscapesValuesAndSkipsInvalidNames(): void
    {
        $html = $this->asLowAs->renderAttributes([
            'class' => 'a b',
            'data-promo-id' => '"><script>',
            'data-hyva-affirm-lazy' => '',
            'on load' => 'x',
            'data-null' => null,
            'data-array' => ['x'],
            'data-number' => 12,
        ]);

        $this->assertSame(
            ' class="a b" data-promo-id="&quot;&gt;&lt;script&gt;" data-hyva-affirm-lazy="" data-number="12"',
            $html
        );
    }
}
