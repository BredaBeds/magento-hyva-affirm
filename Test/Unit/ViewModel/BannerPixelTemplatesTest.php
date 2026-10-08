<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\ViewModel\Banners;
use Hyva\AstoundAffirm\ViewModel\Pixel;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\BlockJsDependencies;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Renders the banner, pixel and Affirm fallback templates with mocked template variables.
 */
class BannerPixelTemplatesTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../src/view/frontend/templates/';

    /** Affirm's frontend templates that the compat fallback resolves to this module on Hyvä themes. */
    private const AFFIRM_TEMPLATES = [
        'promotion/aslowasPDP.phtml',
        'promotion/aslowasCart.phtml',
        'promotion/bml.phtml',
        'onepage/button.phtml',
        'onepage/clearcart.phtml',
        'pixel/confirm.phtml',
    ];

    private Banners&MockObject $banners;

    private Pixel&MockObject $pixel;

    private BlockJsDependencies&MockObject $jsDependencies;

    private ViewModelRegistry&MockObject $viewModels;

    private Escaper&MockObject $escaper;

    protected function setUp(): void
    {
        $this->banners = $this->createMock(Banners::class);
        $this->pixel = $this->createMock(Pixel::class);
        $this->jsDependencies = $this->createMock(BlockJsDependencies::class);
        $this->viewModels = $this->createMock(ViewModelRegistry::class);
        $this->viewModels->method('require')->willReturnMap([
            [Banners::class, null, $this->banners],
            [Pixel::class, null, $this->pixel],
            [BlockJsDependencies::class, null, $this->jsDependencies],
        ]);
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
        $this->escaper = $this->createMock(Escaper::class);
        $this->escaper->method('escapeHtmlAttr')->willReturnCallback($escape);
        $this->escaper->method('escapeUrl')->willReturnCallback($escape);
        $this->escaper->method('escapeHtml')->willReturnCallback($escape);
    }

    public function testBannerRendersAffirmsSiteModalInsideTheContainer(): void
    {
        $this->visibleBanner('home', '0', 'PROMO"1', 'homepage');
        $this->banners->method('getContainer')->willReturn(['start' => '<div style="x">', 'end' => '</div>']);
        $block = $this->block(['section' => 'home', 'position' => '0']);
        $this->jsDependencies->expects($this->once())->method('setBlockTemplateDependencyWithPriority')
            ->with($block, 'Hyva_AstoundAffirm::js/sdk-loader.phtml', 5);

        $html = $this->render('promotion/banner.phtml', $block);

        $this->assertStringStartsWith('<div style="x">', trim($html));
        $this->assertStringEndsWith('</div>', trim($html));
        $this->assertMatchesRegularExpression('#<button type="button"\s+class="affirm-site-modal[^"]*"#', $html);
        $this->assertStringContainsString('data-page-type="homepage"', $html);
        $this->assertStringContainsString('data-promo-id="PROMO&quot;1"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('aria-label="Learn more about financing with Affirm"', $html);
        // affirm.js rebuilds aria-label from the button's innerText; without text of its own it used the markup.
        $this->assertMatchesRegularExpression(
            '#<button[^>]*>\s*<span class="sr-only">Learn more about financing with Affirm</span>#',
            $html
        );
        $this->assertStringContainsString(
            'src="https://cdn-assets.affirm.com/images/banners/468x60.png"',
            $html
        );
        $this->assertStringContainsString('alt=""', $html);
        $this->assertStringContainsString('width="468"', $html);
        $this->assertStringContainsString('height="60"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testBannerOmitsAnEmptyPromoIdAndUsesTheLayoutViewModel(): void
    {
        $layoutViewModel = $this->createMock(Banners::class);
        $layoutViewModel->method('isVisible')->with('cart', '2')->willReturn(true);
        $layoutViewModel->method('getSize')->willReturn(['width' => 300, 'height' => 50]);
        $layoutViewModel->method('getImageUrl')->willReturn('https://cdn-assets.affirm.com/images/banners/300x50.png');
        $layoutViewModel->method('getContainer')->willReturn(['start' => '', 'end' => '']);
        $layoutViewModel->method('getPromoId')->willReturn('');
        $layoutViewModel->method('getPageType')->willReturn('cart');
        $this->banners->expects($this->never())->method('isVisible');

        $html = $this->render(
            'promotion/banner.phtml',
            $this->block(['section' => 'cart', 'position' => '2', 'view_model' => $layoutViewModel])
        );

        $this->assertStringContainsString('data-page-type="cart"', $html);
        $this->assertStringNotContainsString('data-promo-id', $html);
        $this->assertStringStartsWith('<div class="affirm-banner-container">', trim($html));
    }

    public function testBannerRendersNothingWhenNotVisible(): void
    {
        $this->banners->method('isVisible')->willReturn(false);
        $this->banners->expects($this->never())->method('getImageUrl');
        $this->jsDependencies->expects($this->never())->method('setBlockTemplateDependencyWithPriority');

        $html = $this->render('promotion/banner.phtml', $this->block(['section' => 'category', 'position' => '1']));

        $this->assertSame('', trim($html));
    }

    public function testBannerRendersNothingWithoutAPosition(): void
    {
        $this->banners->expects($this->never())->method('isVisible');

        $this->assertSame('', trim($this->render('promotion/banner.phtml', $this->block(['section' => 'home']))));
    }

    public function testBannerRendersNothingWithoutAnImage(): void
    {
        $this->banners->method('isVisible')->willReturn(true);
        $this->banners->method('getSize')->willReturn(['width' => 468, 'height' => 60]);
        $this->banners->method('getImageUrl')->willReturn('');
        $this->jsDependencies->expects($this->never())->method('setBlockTemplateDependencyWithPriority');

        $html = $this->render('promotion/banner.phtml', $this->block(['section' => 'home', 'position' => '0']));

        $this->assertSame('', trim($html));
    }

    public function testPixelRendersThePayloadAndRequiresTheLoaderAndItsScript(): void
    {
        $this->pixel->method('getPayload')->willReturn([
            'orderId' => '100000123',
            'currency' => 'USD',
            'total' => 282400,
            'paymentMethod' => 'affirm_gateway',
        ]);
        $this->pixel->method('isStrict')->willReturn(true);
        $block = $this->block([]);
        $registered = [];
        $record = static function (Template $dependant, string $template, ?int $priority) use (&$registered): void {
            $registered[$template] = [$dependant, $priority];
        };
        $this->jsDependencies->expects($this->exactly(2))->method('setBlockTemplateDependencyWithPriority')
            ->willReturnCallback($record);

        $html = $this->render('pixel/order-confirmed.phtml', $block);

        $this->assertSame(
            [
                'Hyva_AstoundAffirm::js/sdk-loader.phtml' => [$block, 5],
                'Hyva_AstoundAffirm::js/pixel.phtml' => [$block, 10],
            ],
            $registered
        );
        $this->assertStringContainsString('<div hidden', $html);
        $this->assertStringContainsString('data-hyva-affirm-pixel', $html);
        $this->assertStringContainsString('data-order-id="100000123"', $html);
        $this->assertStringContainsString('data-currency="USD"', $html);
        $this->assertStringContainsString('data-total="282400"', $html);
        $this->assertStringContainsString('data-payment-method="affirm_gateway"', $html);
        $this->assertStringContainsString('data-strict="true"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testPixelRendersNothingWithoutAPayload(): void
    {
        $this->pixel->method('getPayload')->willReturn(null);
        $this->jsDependencies->expects($this->never())->method('setBlockTemplateDependencyWithPriority');

        $this->assertSame('', trim($this->render('pixel/order-confirmed.phtml', $this->block([]))));
    }

    public function testPixelScriptLoadsTheSdkAndRegistersTheInlineScript(): void
    {
        $hyvaCsp = $this->createMock(HyvaCsp::class);
        $hyvaCsp->expects($this->once())->method('registerInlineScript');

        $html = trim($this->render('js/pixel.phtml', $this->block([]), $hyvaCsp));

        $this->assertStringStartsWith('<script>', $html);
        $this->assertStringEndsWith('</script>', $html);
        $this->assertSame(1, substr_count($html, '<script>'));
        $this->assertStringContainsString("document.querySelector('[data-hyva-affirm-pixel]')", $html);
        $this->assertStringContainsString('window.hyvaAffirm.load()', $html);
        $this->assertStringContainsString(
            "affirm.analytics.trackOrderConfirmed(order, null, data.strict === 'true')",
            $html
        );
        $this->assertStringNotContainsString('init-external-scripts', $html);
    }

    public function testAffirmTemplateOverridesRenderNothing(): void
    {
        foreach (self::AFFIRM_TEMPLATES as $template) {
            $file = 'Astound_Affirm/' . $template;
            $this->assertFileExists(self::TEMPLATES . $file);
            $this->assertSame('', $this->render($file, $this->block([])), $file);
        }
    }

    public function testOwnTemplatesNeverShadowAffirmsTemplates(): void
    {
        // The compat fallback also resolves Astound_Affirm::<path> to this module's unprefixed templates.
        foreach (self::AFFIRM_TEMPLATES as $template) {
            $this->assertFileDoesNotExist(self::TEMPLATES . $template);
        }
        $this->assertFileDoesNotExist(self::TEMPLATES . 'payment/info/edit.phtml');
    }

    private function visibleBanner(string $section, string $position, string $promoId, string $pageType): void
    {
        $this->banners->method('isVisible')->with($section, $position)->willReturn(true);
        $this->banners->method('getSize')->with($section)->willReturn(['width' => 468, 'height' => 60]);
        $this->banners->method('getImageUrl')->with($section)
            ->willReturn('https://cdn-assets.affirm.com/images/banners/468x60.png');
        $this->banners->method('getPromoId')->with($section)->willReturn($promoId);
        $this->banners->method('getPageType')->with($section)->willReturn($pageType);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function block(array $data): Template&MockObject
    {
        $block = $this->createMock(Template::class);
        $block->method('getData')->willReturnCallback(static fn (string $key) => $data[$key] ?? null);

        return $block;
    }

    private function render(string $template, Template $block, ?HyvaCsp $hyvaCsp = null): string
    {
        $render = function (
            string $file,
            Template $block,
            Escaper $escaper,
            ViewModelRegistry $viewModels,
            ?HyvaCsp $hyvaCsp
        ): string {
            if ($hyvaCsp === null) {
                unset($hyvaCsp);
            }
            ob_start();
            try {
                include $file;
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        };

        return $render(self::TEMPLATES . $template, $block, $this->escaper, $this->viewModels, $hyvaCsp);
    }
}
