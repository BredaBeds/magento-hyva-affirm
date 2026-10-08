<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Astound\Affirm\Helper\Payment as AffirmPaymentHelper;
use Astound\Affirm\Model\Config as AffirmConfig;
use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\AstoundAffirm\ViewModel\Banners;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Api\Data\ProductInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BannersTest extends TestCase
{
    private const DEFAULT_CONTAINER = '<div style="width: 100%; text-align: center; padding-bottom: 7px ">'
        . '{container}</div>';

    /** @var array<string, mixed> */
    private array $values = [];

    private Config&MockObject $config;

    private AffirmConfig&MockObject $affirmConfig;

    private AvailabilityInterface&MockObject $availability;

    private PromoIdInterface&MockObject $promoId;

    private AffirmPaymentHelper&MockObject $paymentHelper;

    private CurrentProduct&MockObject $currentProduct;

    private LoggerInterface&MockObject $logger;

    private Banners $banners;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getValue')->willReturnCallback(fn (string $path) => $this->values[$path] ?? null);
        $this->affirmConfig = $this->createMock(AffirmConfig::class);
        $this->affirmConfig->method('getAffirmAssetsUrl')->willReturn('https://cdn-assets.affirm.com/images/banners/');
        $this->availability = $this->createMock(AvailabilityInterface::class);
        $this->availability->method('isAvailable')->willReturn(true);
        $this->promoId = $this->createMock(PromoIdInterface::class);
        $this->paymentHelper = $this->createMock(AffirmPaymentHelper::class);
        $this->currentProduct = $this->createMock(CurrentProduct::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->banners = new Banners(
            $this->config,
            $this->affirmConfig,
            $this->availability,
            $this->promoId,
            $this->paymentHelper,
            $this->currentProduct,
            $this->logger
        );

        foreach (['homepage', 'category', 'product', 'checkout_cart'] as $section) {
            $this->configure($section, '1', '0', '--468x60');
        }
    }

    public function testEachSectionIsVisibleOnlyAtItsConfiguredPosition(): void
    {
        $this->paymentHelper->method('isAffirmAvailable')->willReturn(true);
        $sections = ['home' => 'homepage', 'category' => 'category', 'product' => 'product', 'cart' => 'checkout_cart'];

        foreach ($sections as $section => $affirmSection) {
            foreach ([0, 1, 2, 3] as $configured) {
                $this->configure($affirmSection, '1', (string) $configured, '170x100');
                foreach ([0, 1, 2, 3] as $position) {
                    $this->assertSame(
                        $configured === $position,
                        $this->banners->isVisible($section, (string) $position),
                        sprintf('%s configured at %d, asked for %d', $section, $configured, $position)
                    );
                }
            }
        }
    }

    public function testAffirmsOwnSectionNamesAndIntegerPositionsAreAccepted(): void
    {
        $this->configure('homepage', '1', '2', '170x100');
        $this->configure('checkout_cart', '1', '1', '468x60');
        $this->paymentHelper->method('isAffirmAvailable')->willReturn(true);

        $this->assertTrue($this->banners->isVisible('homepage', 2));
        $this->assertTrue($this->banners->isVisible('checkout_cart', 1));
    }

    public function testUnknownSectionsAndPositionsAreNeverVisible(): void
    {
        $this->assertFalse($this->banners->isVisible('search', '0'));
        $this->assertFalse($this->banners->isVisible('home', ''));
        $this->assertFalse($this->banners->isVisible('home', 'top'));
        $this->assertSame('', $this->banners->getPageType('search'));
        $this->assertSame('', $this->banners->getPromoId('search'));
        $this->assertNull($this->banners->getSize('search'));
        $this->assertSame(['start' => '', 'end' => ''], $this->banners->getContainer('search'));
    }

    public function testDisplayFlagOffHidesTheSection(): void
    {
        $this->configure('category', '0', '0', '170x100');

        $this->assertFalse($this->banners->isVisible('category', '0'));
        $this->assertTrue($this->banners->isVisible('home', '0'));
    }

    public function testNonNumericConfiguredPositionMeansCenterTop(): void
    {
        // Affirm's config.xml default for the cart section.
        $this->configure('checkout_cart', '1', 'center-top', '468x60');
        $this->paymentHelper->method('isAffirmAvailable')->willReturn(true);

        $this->assertTrue($this->banners->isVisible('cart', '0'));
        $this->assertFalse($this->banners->isVisible('cart', '1'));
        $this->assertFalse($this->banners->isVisible('cart', '2'));
    }

    public function testUnsetPositionMeansCenterTop(): void
    {
        $this->configure('homepage', '1', null, '170x100');

        $this->assertTrue($this->banners->isVisible('home', '0'));
        $this->assertFalse($this->banners->isVisible('home', '1'));
    }

    public function testSizeGroupHeadingsAndMalformedSizesHideTheBanner(): void
    {
        // Affirm's size-group headings (admin option values with literal quotes), then malformed values.
        $headings = ['"Pay over time banner sizes"', '"Make Monthly Payments banner sizes"'];
        foreach ([...$headings, '', 'x60', '60x0', 'big', null] as $size) {
            $this->configure('homepage', '1', '0', $size);

            $this->assertFalse($this->banners->isVisible('home', '0'), var_export($size, true));
            $this->assertNull($this->banners->getSize('home'));
            $this->assertSame('', $this->banners->getImageUrl('home'));
        }
    }

    public function testSizeIsParsedFromAdminOptionValuesAndDefaults(): void
    {
        $this->configure('homepage', '1', '0', '170x100');
        $this->configure('product', '1', '0', '--728x90');

        $this->assertSame(['width' => 170, 'height' => 100], $this->banners->getSize('home'));
        $this->assertSame(['width' => 728, 'height' => 90], $this->banners->getSize('product'));
    }

    public function testUnavailableBannerPlacementHidesEverySection(): void
    {
        $availability = $this->createMock(AvailabilityInterface::class);
        $availability->method('isAvailable')->willReturn(false);
        $banners = $this->banners($availability);

        foreach (['home', 'category', 'product', 'cart'] as $section) {
            $this->assertFalse($banners->isVisible($section, '0'), $section);
        }
    }

    public function testProductBannerChecksAvailabilityForTheCurrentProduct(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $this->currentProduct->method('exists')->willReturn(true);
        $this->currentProduct->method('get')->willReturn($product);
        $availability = $this->createMock(AvailabilityInterface::class);
        $availability->expects($this->once())->method('isAvailable')
            ->with(Placement::BANNER, $product)
            ->willReturn(false);

        $this->assertFalse($this->banners($availability)->isVisible('product', '0'));
    }

    public function testHomeBannerChecksAvailabilityWithoutAProduct(): void
    {
        $this->currentProduct->expects($this->never())->method('get');
        $availability = $this->createMock(AvailabilityInterface::class);
        $availability->expects($this->once())->method('isAvailable')->with(Placement::BANNER, null)->willReturn(true);
        $this->paymentHelper->expects($this->never())->method('isAffirmAvailable');

        $this->assertTrue($this->banners($availability)->isVisible('home', '0'));
    }

    public function testCartBannerRequiresAffirmsCartAvailability(): void
    {
        $this->paymentHelper->expects($this->exactly(2))->method('isAffirmAvailable')->willReturnOnConsecutiveCalls(
            false,
            true
        );

        $this->assertFalse($this->banners->isVisible('cart', '0'));
        $this->assertTrue($this->banners->isVisible('cart', '0'));
    }

    public function testCartAvailabilityIsNotAskedForOtherPositions(): void
    {
        $this->paymentHelper->expects($this->never())->method('isAffirmAvailable');

        $this->assertFalse($this->banners->isVisible('cart', '2'));
    }

    public function testFailuresHideTheBannerAndAreLogged(): void
    {
        $this->paymentHelper->method('isAffirmAvailable')->willThrowException(new \RuntimeException('quote failure'));
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('banner'), $this->arrayHasKey('error'));

        $this->assertFalse($this->banners->isVisible('cart', '0'));
    }

    public function testImageUrlUsesAffirmsAssetsUrlAndTheSize(): void
    {
        $this->assertSame(
            'https://cdn-assets.affirm.com/images/banners/468x60.png',
            $this->banners->getImageUrl('cart')
        );
    }

    public function testImageUrlUsesAffirmsFrenchImagesForFrCa(): void
    {
        $affirmConfig = $this->createMock(AffirmConfig::class);
        $affirmConfig->method('getAffirmAssetsUrl')
            ->willReturn('https://cdn-assets.affirm.com/images/banners/fr/AFFIRM-DSP-B-');
        $banners = new Banners(
            $this->config,
            $affirmConfig,
            $this->availability,
            $this->promoId,
            $this->paymentHelper,
            $this->currentProduct,
            $this->logger
        );

        $this->assertSame(
            'https://cdn-assets.affirm.com/images/banners/fr/AFFIRM-DSP-B-468x60.png',
            $banners->getImageUrl('home')
        );
    }

    public function testImageUrlIsEmptyWhenAffirmsConfigFails(): void
    {
        $affirmConfig = $this->createMock(AffirmConfig::class);
        $affirmConfig->method('getAffirmAssetsUrl')->willThrowException(new \RuntimeException('no store'));
        $this->logger->expects($this->once())->method('warning');
        $banners = new Banners(
            $this->config,
            $affirmConfig,
            $this->availability,
            $this->promoId,
            $this->paymentHelper,
            $this->currentProduct,
            $this->logger
        );

        $this->assertSame('', $banners->getImageUrl('home'));
    }

    public function testContainerIsSplitAtThePlaceholder(): void
    {
        $this->values['affirm/affirm_developer/homepage_container'] = self::DEFAULT_CONTAINER;
        $this->values['affirm/affirm_developer/checkout_cart_container'] =
            '<section>{container}<hr>{container}</section>';

        $this->assertSame(
            ['start' => '<div style="width: 100%; text-align: center; padding-bottom: 7px ">', 'end' => '</div>'],
            $this->banners->getContainer('home')
        );
        // Like Affirm: the text before the first and after the last placeholder.
        $this->assertSame(['start' => '<section>', 'end' => '</section>'], $this->banners->getContainer('cart'));
    }

    public function testContainerWithoutPlaceholderIsIgnored(): void
    {
        $this->values['affirm/affirm_developer/category_container'] = '<div class="promo">';

        $this->assertSame(['start' => '', 'end' => ''], $this->banners->getContainer('category'));
        $this->assertSame(['start' => '', 'end' => ''], $this->banners->getContainer('product'));
    }

    public function testPromoIdUsesAffirmsSectionAndTheCurrentProductOnProductPages(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $this->currentProduct->method('exists')->willReturn(true);
        $this->currentProduct->method('get')->willReturn($product);
        $this->promoId->method('forBanner')->willReturnMap([
            ['homepage', null, 'HOME'],
            ['category', null, 'CAT'],
            ['product', $product, 'PROD'],
            ['checkout_cart', null, 'CART'],
        ]);

        $this->assertSame('HOME', $this->banners->getPromoId('home'));
        $this->assertSame('CAT', $this->banners->getPromoId('category'));
        $this->assertSame('PROD', $this->banners->getPromoId('product'));
        $this->assertSame('CART', $this->banners->getPromoId('cart'));
    }

    public function testPromoIdFailureMeansNone(): void
    {
        $this->promoId->method('forBanner')->willThrowException(new \RuntimeException('helper failure'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame('', $this->banners->getPromoId('home'));
    }

    public function testPageTypesFollowAffirm(): void
    {
        $this->assertSame('homepage', $this->banners->getPageType('home'));
        $this->assertSame('homepage', $this->banners->getPageType('homepage'));
        $this->assertSame('category', $this->banners->getPageType('category'));
        $this->assertSame('product', $this->banners->getPageType('product'));
        $this->assertSame('cart', $this->banners->getPageType('cart'));
        $this->assertSame('cart', $this->banners->getPageType('checkout_cart'));
    }

    private function configure(string $affirmSection, ?string $display, ?string $position, ?string $size): void
    {
        $this->values['affirm/affirm_promo_' . $affirmSection . '/display'] = $display;
        $this->values['affirm/affirm_promo_' . $affirmSection . '/position'] = $position;
        $this->values['affirm/affirm_promo_' . $affirmSection . '/size'] = $size;
    }

    private function banners(AvailabilityInterface $availability): Banners
    {
        return new Banners(
            $this->config,
            $this->affirmConfig,
            $availability,
            $this->promoId,
            $this->paymentHelper,
            $this->currentProduct,
            $this->logger
        );
    }
}
