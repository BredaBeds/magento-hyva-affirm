<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\ViewModel\AsLowAs;
use Hyva\AstoundAffirm\ViewModel\Sdk;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\BlockJsDependencies;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Renders the module's templates with mocked template variables.
 */
class TemplatesTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../src/view/frontend/templates/';

    private AsLowAs&MockObject $asLowAs;

    private Sdk&MockObject $sdk;

    private BlockJsDependencies&MockObject $jsDependencies;

    private ViewModelRegistry&MockObject $viewModels;

    protected function setUp(): void
    {
        $this->asLowAs = $this->createMock(AsLowAs::class);
        $this->sdk = $this->createMock(Sdk::class);
        $this->jsDependencies = $this->createMock(BlockJsDependencies::class);
        $this->viewModels = $this->createMock(ViewModelRegistry::class);
        $this->viewModels->method('require')->willReturnMap([
            [AsLowAs::class, null, $this->asLowAs],
            [Sdk::class, null, $this->sdk],
            [BlockJsDependencies::class, null, $this->jsDependencies],
        ]);
    }

    public function testMessageTemplateRendersTheElementAndRequiresTheLoader(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $block = $this->block([
            'placement' => 'product',
            'amount_cents' => '129900',
            'promo_id' => 'PROMO',
            'product' => $product,
            'attributes' => ['data-hyva-affirm-product-id' => '7'],
        ]);
        $this->asLowAs->expects($this->once())->method('getAttributes')
            ->with(Placement::PRODUCT, 129900, 'PROMO', ['data-hyva-affirm-product-id' => '7'], $product)
            ->willReturn(['class' => 'affirm-as-low-as hyva-affirm-message', 'data-amount' => '129900']);
        $this->asLowAs->method('renderAttributes')->willReturnCallback(
            static fn (array $attributes): string => ' class="' . $attributes['class'] . '"'
        );
        $this->jsDependencies->expects($this->once())->method('setBlockTemplateDependencyWithPriority')
            ->with($block, 'Hyva_AstoundAffirm::js/sdk-loader.phtml', 5);

        $html = $this->render('promotion/as-low-as.phtml', $block);

        $this->assertSame(
            '<p class="affirm-as-low-as hyva-affirm-message min-h-[1.5rem] invisible"></p>',
            trim($html)
        );
    }

    public function testListingMessageIsVisibleStaticMarkup(): void
    {
        $block = $this->block(['placement' => 'listing', 'amount_cents' => 9900, 'promo_id' => null]);
        $this->asLowAs->method('getAttributes')
            ->with(Placement::LISTING, 9900, '', [], null)
            ->willReturn(['class' => 'affirm-as-low-as']);
        $this->asLowAs->method('renderAttributes')->willReturnCallback(
            static fn (array $attributes): string => ' class="' . $attributes['class'] . '"'
        );

        $this->assertSame(
            '<p class="affirm-as-low-as min-h-[1.5rem]"></p>',
            trim($this->render('promotion/as-low-as.phtml', $block))
        );
    }

    public function testMessageTemplateRendersNothingWithoutAttributesOrPlacement(): void
    {
        $this->asLowAs->method('getAttributes')->willReturn(null);
        $this->jsDependencies->expects($this->never())->method('setBlockTemplateDependencyWithPriority');

        $this->assertSame('', trim($this->render('promotion/as-low-as.phtml', $this->block(['placement' => 'cart']))));
        $this->assertSame('', trim($this->render('promotion/as-low-as.phtml', $this->block(['placement' => 'x']))));
    }

    public function testLoaderTemplateEmitsConfigAndRegistersTheInlineScript(): void
    {
        $this->sdk->method('isConfigured')->willReturn(true);
        $this->sdk->method('getLoaderConfig')->willReturn([
            'public_api_key' => 'PUB</script>',
            'script' => 'https://cdn1-sandbox.affirm.com/js/v2/affirm.js',
            'locale' => 'en_US',
            'country_code' => 'USA',
        ]);
        $hyvaCsp = $this->createMock(HyvaCsp::class);
        $hyvaCsp->expects($this->once())->method('registerInlineScript');

        $html = $this->render('js/sdk-loader.phtml', $this->block([]), $hyvaCsp);

        $this->assertStringStartsWith('<script>', trim($html));
        $this->assertStringEndsWith('</script>', trim($html));
        $this->assertStringContainsString('window.hyvaAffirmConfig = window.hyvaAffirmConfig || {', $html);
        $this->assertStringContainsString(trim((string) json_encode('PUB</script>', JSON_HEX_TAG), '"'), $html);
        $this->assertStringNotContainsString('PUB</script>', $html);
        $this->assertStringContainsString('window.hyvaAffirm = {version: VERSION, load, refresh, whenIdle};', $html);
    }

    public function testLoaderTemplateIsByteIdenticalToTheContract(): void
    {
        $template = (string) file_get_contents(self::TEMPLATES . 'js/sdk-loader.phtml');
        $start = strpos($template, "(() => {\n    'use strict';");
        $end = strrpos($template, "})();\n");

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $body = substr($template, (int) $start, (int) $end - (int) $start + strlen("})();\n"));
        $this->assertStringContainsString("const VERSION = 1;\n", $body);
        $this->assertSame(1, substr_count($template, '<script>'));

        // sha256 of the whole <script> body: the same constant is asserted by Hyva_CheckoutAffirm's copy.
        $this->assertSame(1, preg_match('#<script>\n(.*?)</script>#s', $template, $match));
        $this->assertSame(
            'c45fa08e48cac4518ad0aa8938e562971a49e0e36f700b2510bdc13bddcce23d',
            hash('sha256', $match[1])
        );
    }

    public function testLoaderTemplateRendersNothingWhenNotConfigured(): void
    {
        $this->sdk->method('isConfigured')->willReturn(false);
        $this->sdk->expects($this->never())->method('getLoaderConfig');

        $this->assertSame('', trim($this->render('js/sdk-loader.phtml', $this->block([]))));
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
        $render = function (string $file, Template $block, ViewModelRegistry $viewModels, ?HyvaCsp $hyvaCsp): string {
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

        return $render(self::TEMPLATES . $template, $block, $this->viewModels, $hyvaCsp);
    }
}
