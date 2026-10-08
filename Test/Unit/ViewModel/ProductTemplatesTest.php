<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\ViewModel\ProductMessage;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Renders the product page templates with mocked template variables.
 */
class ProductTemplatesTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../../src/view/frontend/templates/';

    private CurrentProduct&MockObject $currentProduct;

    private ProductMessage&MockObject $productMessage;

    private ViewModelRegistry&MockObject $viewModels;

    private Product&MockObject $product;

    private LayoutInterface&MockObject $layout;

    private Template&MockObject $message;

    protected function setUp(): void
    {
        $this->currentProduct = $this->createMock(CurrentProduct::class);
        $this->productMessage = $this->createMock(ProductMessage::class);
        $this->viewModels = $this->createMock(ViewModelRegistry::class);
        $this->viewModels->method('require')->willReturnMap([
            [CurrentProduct::class, null, $this->currentProduct],
            [ProductMessage::class, null, $this->productMessage],
        ]);
        $this->product = $this->createMock(Product::class);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->message = $this->createMock(Template::class);
    }

    public function testEndOfInfoRendersTheMessageChildForTheCurrentProduct(): void
    {
        $this->currentProduct->method('exists')->willReturn(true);
        $this->currentProduct->method('get')->willReturn($this->product);
        $this->productMessage->expects($this->once())->method('canRenderEndOfInfo')
            ->with($this->product, $this->layout)->willReturn(true);
        $this->productMessage->expects($this->once())->method('prepareMessageBlock')
            ->with($this->message, $this->product)->willReturn(true);
        $block = $this->endBlock('<p class="affirm-as-low-as"></p>');

        $html = $this->render('promotion/product-end.phtml', $block);

        $this->assertSame('<p class="affirm-as-low-as"></p>', trim($html));
    }

    public function testEndOfInfoRendersNothingWithoutACurrentProduct(): void
    {
        $this->currentProduct->method('exists')->willReturn(false);
        $this->currentProduct->expects($this->never())->method('get');
        $this->productMessage->expects($this->never())->method('canRenderEndOfInfo');

        $this->assertSame('', trim($this->render('promotion/product-end.phtml', $this->endBlock(null))));
    }

    public function testEndOfInfoRendersNothingWhenNotAllowed(): void
    {
        $this->currentProduct->method('exists')->willReturn(true);
        $this->currentProduct->method('get')->willReturn($this->product);
        $this->productMessage->method('canRenderEndOfInfo')->willReturn(false);
        $this->productMessage->expects($this->never())->method('prepareMessageBlock');

        $this->assertSame('', trim($this->render('promotion/product-end.phtml', $this->endBlock(null))));
    }

    public function testEndOfInfoRendersNothingWithoutMessageData(): void
    {
        $this->currentProduct->method('exists')->willReturn(true);
        $this->currentProduct->method('get')->willReturn($this->product);
        $this->productMessage->method('canRenderEndOfInfo')->willReturn(true);
        $this->productMessage->method('prepareMessageBlock')->willReturn(false);

        $this->assertSame('', trim($this->render('promotion/product-end.phtml', $this->endBlock(null))));
    }

    public function testEndOfInfoRendersNothingWithoutTheMessageChild(): void
    {
        $this->currentProduct->method('exists')->willReturn(true);
        $block = $this->createMock(Template::class);
        $block->method('getChildBlock')->with('message')->willReturn(false);
        $block->expects($this->never())->method('getChildHtml');

        $this->assertSame('', trim($this->render('promotion/product-end.phtml', $block)));
    }

    public function testRecorderTemplateIsOneRegisteredInlineScript(): void
    {
        $html = $this->renderScript('js/price-state-recorder.phtml');

        $this->assertStringContainsString('window.hyvaAffirmPriceState = state;', $html);
        $this->assertStringContainsString('/* recorder:start */', $html);
        $this->assertStringContainsString('/* recorder:end */', $html);
    }

    public function testComponentTemplateIsOneRegisteredInlineScript(): void
    {
        $html = $this->renderScript('js/product-message.phtml');

        $this->assertStringContainsString("Alpine.data('hyvaAffirmProductMessage', hyvaAffirmProductMessage)", $html);
        $this->assertStringContainsString('/* reducer:start */', $html);
        $this->assertStringContainsString('/* reducer:end */', $html);
    }

    public function testScriptTemplatesRenderWithoutCsp(): void
    {
        $html = $this->render('js/product-message.phtml', $this->createMock(Template::class));

        $this->assertStringStartsWith('<script>', trim($html));
        $this->assertStringEndsWith('</script>', trim($html));
    }

    private function renderScript(string $template): string
    {
        $hyvaCsp = $this->createMock(HyvaCsp::class);
        $hyvaCsp->expects($this->once())->method('registerInlineScript');
        $html = trim($this->render($template, $this->createMock(Template::class), $hyvaCsp));

        $this->assertStringStartsWith('<script>', $html);
        $this->assertStringEndsWith('</script>', $html);
        $this->assertSame(1, substr_count($html, '<script>'));
        $this->assertStringNotContainsString('<?', $html);

        return $html;
    }

    private function endBlock(?string $childHtml): Template&MockObject
    {
        $block = $this->createMock(Template::class);
        $block->method('getChildBlock')->with('message')->willReturn($this->message);
        $block->method('getLayout')->willReturn($this->layout);
        $block->expects($childHtml === null ? $this->never() : $this->once())->method('getChildHtml')
            ->with('message')->willReturn((string) $childHtml);

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
