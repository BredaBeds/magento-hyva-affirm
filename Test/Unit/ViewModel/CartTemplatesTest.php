<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\ViewModel;

use Hyva\AstoundAffirm\ViewModel\CartButton;
use Hyva\AstoundAffirm\ViewModel\CartMessage;
use Hyva\AstoundAffirm\ViewModel\Sdk;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Renders the cart, minicart and cart button templates with mocked template variables, and checks the cart
 * page layout.
 */
class CartTemplatesTest extends TestCase
{
    private const VIEW = __DIR__ . '/../../../src/view/frontend/';
    private const ELEMENT_TEMPLATE = 'Hyva_AstoundAffirm::promotion/as-low-as.phtml';
    private const ELEMENT_HTML = '<p class="affirm-as-low-as" data-amount="150000"></p>';
    private const LOADER = 'Hyva_AstoundAffirm::js/sdk-loader.phtml';
    private const COMPONENT = 'Hyva_AstoundAffirm::js/cart-message.phtml';
    private const REFRESH = 'Hyva_AstoundAffirm::js/cart-refresh.phtml';

    private CartMessage&MockObject $cartMessage;

    private CartButton&MockObject $cartButton;

    private Sdk&MockObject $sdk;

    private ViewModelRegistry&MockObject $viewModels;

    private Escaper&MockObject $escaper;

    /** @var array<string, mixed> */
    private array $elementData = [];

    protected function setUp(): void
    {
        $this->cartMessage = $this->createMock(CartMessage::class);
        $this->cartButton = $this->createMock(CartButton::class);
        $this->sdk = $this->createMock(Sdk::class);
        $this->viewModels = $this->createMock(ViewModelRegistry::class);
        $this->viewModels->method('require')->willReturnMap([
            [CartMessage::class, null, $this->cartMessage],
            [CartButton::class, null, $this->cartButton],
            [Sdk::class, null, $this->sdk],
        ]);
        $this->escaper = $this->createMock(Escaper::class);
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $this->escaper->method('escapeHtml')->willReturnCallback($escape);
        $this->escaper->method('escapeHtmlAttr')->willReturnCallback($escape);
        $this->escaper->method('escapeUrl')->willReturnCallback($escape);
    }

    public function testCartMessageRendersTheComponentAroundTheSharedElement(): void
    {
        $this->givenCartMessage(true, 150000, true);
        $this->cartMessage->method('getComponentConfig')->willReturn([
            'limits' => ['min' => 100000, 'max' => 1750000, 'minMpp' => null],
            'cartRule' => ['min' => 1500.0, 'max' => 5000.0, 'promoInRange' => 'IN"<', 'promoOutOfRange' => 'OUT',
                'higherPrecedenceApplies' => false],
        ]);

        $html = $this->render('templates/cart/message.phtml', $this->blockWithElement(self::ELEMENT_HTML));

        $this->assertSame(
            ['placement' => 'cart', 'amount_cents' => 150000, 'promo_id' => 'CART_PROMO',
                'attributes' => ['data-hyva-affirm-lazy' => true]],
            $this->elementData
        );
        $this->assertStringContainsString('x-data="hyvaAffirmCartMessage"', $html);
        $this->assertStringContainsString('@update-totals.window="onUpdateTotals"', $html);
        $this->assertStringContainsString('@cart-is-loading.window="onCartIsLoading"', $html);
        $this->assertStringContainsString(self::ELEMENT_HTML . '</div>', $html);
        $this->assertMatchesRegularExpression('/data-hyva-affirm-cart="([^"]*)"/', $html);
        preg_match('/data-hyva-affirm-cart="([^"]*)"/', $html, $match);
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        $this->assertSame('IN"<', $config['cartRule']['promoInRange']);
        $this->assertSame(100000, $config['limits']['min']);
    }

    public function testCartMessageOutOfRangeStartsCollapsed(): void
    {
        $this->givenCartMessage(true, 50000, false);

        $this->render('templates/cart/message.phtml', $this->blockWithElement(self::ELEMENT_HTML));

        $this->assertSame(['data-hyva-affirm-lazy' => true, 'class' => 'hidden'], $this->elementData['attributes']);
    }

    public function testCartMessageRendersNothingWhenUnavailableOrVetoed(): void
    {
        $this->givenCartMessage(false, 150000, true);
        $this->assertSame('', trim($this->render('templates/cart/message.phtml', $this->blockWithElement('x'))));

        $this->setUp();
        $this->givenCartMessage(true, 150000, true);
        $this->cartMessage->expects($this->never())->method('getComponentConfig');
        $this->assertSame('', trim($this->render('templates/cart/message.phtml', $this->blockWithElement('  '))));
    }

    public function testCartMessageCreatesTheElementWhenTheLayoutHasNoChild(): void
    {
        $this->givenCartMessage(true, 150000, true);
        $this->cartMessage->method('getComponentConfig')->willReturn(['limits' => [], 'cartRule' => []]);
        $element = $this->element(self::ELEMENT_HTML);
        $element->expects($this->once())->method('setTemplate')->with(self::ELEMENT_TEMPLATE)->willReturnSelf();
        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects($this->once())->method('createBlock')->with(Template::class)->willReturn($element);
        $block = $this->createMock(Template::class);
        $block->method('getChildBlock')->with('element')->willReturn(false);
        $block->method('getLayout')->willReturn($layout);

        $this->assertStringContainsString(self::ELEMENT_HTML, $this->render('templates/cart/message.phtml', $block));
    }

    public function testMinicartMessageIsVisitorIndependentAndCollapsed(): void
    {
        $this->cartMessage->method('isMinicartAvailable')->willReturn(true);
        $this->cartMessage->expects($this->never())->method('getAmountCents');
        $this->cartMessage->expects($this->never())->method('getPromoId');

        $html = $this->render('templates/minicart/message.phtml', $this->blockWithElement(self::ELEMENT_HTML));

        $this->assertSame(
            ['placement' => 'minicart', 'amount_cents' => 0, 'promo_id' => '', 'attributes' => ['class' => 'hidden']],
            $this->elementData
        );
        $this->assertStringContainsString('x-data="hyvaAffirmMinicartMessage"', $html);
        $this->assertStringContainsString('@private-content-loaded.window="onPrivateContentLoaded"', $html);
        $this->assertStringContainsString('@toggle-cart.window="onToggleCart"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testMinicartMessageRendersNothingWhenUnavailable(): void
    {
        $this->cartMessage->method('isMinicartAvailable')->willReturn(false);

        $this->assertSame('', trim($this->render('templates/minicart/message.phtml', $this->blockWithElement('x'))));
    }

    public function testCartButtonPostsTheFormKeyWithAffirmsImage(): void
    {
        $this->givenCartButton(true, 'https://cdn.example/button.png?a=1&b=2', 148);

        $html = $this->render('templates/cart/affirm-button.phtml', $this->createMock(Template::class));

        $this->assertStringContainsString('<form method="post"', $html);
        $this->assertStringContainsString('action="https://shop.example/hyva_affirm/cart/start/"', $html);
        $this->assertStringContainsString('<input type="hidden" name="form_key" value="FORMKEY">', $html);
        $this->assertStringContainsString('<button type="submit"', $html);
        $this->assertStringContainsString('src="https://cdn.example/button.png?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('alt="Checkout with Affirm"', $html);
        $this->assertStringContainsString('width="148"', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function testCartButtonWithoutWidthOrImage(): void
    {
        $this->givenCartButton(true, 'https://cdn.example/button.png', 0);
        $html = $this->render('templates/cart/affirm-button.phtml', $this->createMock(Template::class));
        $this->assertStringNotContainsString('width=', $html);

        $this->setUp();
        $this->givenCartButton(true, '', 0);
        $html = $this->render('templates/cart/affirm-button.phtml', $this->createMock(Template::class));
        $this->assertStringNotContainsString('<img', $html);
        $this->assertMatchesRegularExpression(
            '#<button type="submit"[^>]*>\s*Checkout with Affirm\s*</button>#',
            $html
        );
    }

    public function testCartButtonRendersNothingWhenUnavailable(): void
    {
        $this->givenCartButton(false, 'https://cdn.example/button.png', 148);
        $this->cartButton->expects($this->never())->method('getFormKey');

        $html = $this->render('templates/cart/affirm-button.phtml', $this->createMock(Template::class));
        $this->assertSame('', trim($html));
    }

    public function testComponentScriptsRegisterNamedAlpineComponentsAndTheirInlineScript(): void
    {
        $this->cartMessage->method('isMinicartAvailable')->willReturn(true);
        $components = [
            'js/cart-message.phtml' => 'hyvaAffirmCartMessage',
            'js/minicart-message.phtml' => 'hyvaAffirmMinicartMessage',
        ];
        foreach ($components as $template => $name) {
            $hyvaCsp = $this->createMock(HyvaCsp::class);
            $hyvaCsp->expects($this->once())->method('registerInlineScript');

            $html = trim($this->render('templates/' . $template, $this->createMock(Template::class), $hyvaCsp));

            $this->assertStringStartsWith('<script>', $html);
            $this->assertStringEndsWith('</script>', $html);
            $this->assertSame(1, substr_count($html, '<script'));
            $this->assertStringContainsString("window.Alpine.data('" . $name . "', " . $name . ')', $html);
            $this->assertStringContainsString("window.addEventListener('alpine:init', register, {once: true})", $html);
        }
    }

    public function testCartRefreshScriptRegistersItsInlineScript(): void
    {
        $this->sdk->method('isConfigured')->willReturn(true);
        $hyvaCsp = $this->createMock(HyvaCsp::class);
        $hyvaCsp->expects($this->once())->method('registerInlineScript');

        $html = trim($this->render('templates/js/cart-refresh.phtml', $this->createMock(Template::class), $hyvaCsp));

        $this->assertStringStartsWith('<script>', $html);
        $this->assertStringEndsWith('</script>', $html);
        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertStringContainsString(
            "window.addEventListener('private-content-loaded', refreshNewPlacements);",
            $html
        );
        $this->assertStringContainsString("window.addEventListener('cart-is-loading',", $html);
        $this->assertStringContainsString('loader.load().then(() => loader.refresh()).catch(() => {});', $html);
    }

    public function testCartRefreshScriptIsSkippedWhenAffirmJsCannotWork(): void
    {
        $this->sdk->method('isConfigured')->willReturn(false);
        $hyvaCsp = $this->createMock(HyvaCsp::class);
        $hyvaCsp->expects($this->never())->method('registerInlineScript');

        $html = $this->render('templates/js/cart-refresh.phtml', $this->createMock(Template::class), $hyvaCsp);
        $this->assertSame('', trim($html));
    }

    public function testMinicartScriptIsSkippedWhenTheMinicartMessageIsUnavailable(): void
    {
        $this->cartMessage->method('isMinicartAvailable')->willReturn(false);

        $html = $this->render('templates/js/minicart-message.phtml', $this->createMock(Template::class));
        $this->assertSame('', trim($html));
    }

    public function testCartLayoutReplacesAffirmsCartBlocks(): void
    {
        $layout = $this->loadLayout('hyva_checkout_cart_index.xml');
        $removed = [];
        foreach ($layout->xpath('//referenceBlock[@remove="true"]') ?: [] as $node) {
            $removed[] = (string) $node['name'];
        }
        sort($removed);

        $expected = [
            'affirm.cart.shortcut.buttons',
            'affirm_banner_center_bottom',
            'affirm_banner_center_top',
            'affirm_banner_methods_bottom',
            'affirm_banner_methods_top',
            'aslowas.checkout.cart.widget',
        ];
        $this->assertSame($expected, $removed);

        $affirmLayout = $this->findAffirmCartLayout();
        if ($affirmLayout !== null) {
            $declared = [];
            foreach ($affirmLayout->xpath('//block') ?: [] as $node) {
                $declared[] = (string) $node['name'];
            }
            sort($declared);
            $this->assertSame($declared, $removed, 'Every block of Affirm\'s checkout_cart_index.xml is removed.');
        }
    }

    public function testCartLayoutDeclaresTheMessageButtonAndBanners(): void
    {
        $layout = $this->loadLayout('hyva_checkout_cart_index.xml');

        $methods = '//referenceContainer[@name="checkout.cart.methods"]';
        $message = $this->single($layout, $methods . '/block[@name="hyva.affirm.aslowas.cart"]');
        $this->assertSame('Hyva_AstoundAffirm::cart/message.phtml', (string) $message['template']);
        $this->assertSame('affirm/affirm_aslowas/enabled_cc', (string) $message['ifconfig']);
        $this->assertSame([self::LOADER => 5, self::REFRESH => 10], $this->dependencies($message));
        $element = $this->single($message, 'block[@as="element"]');
        $this->assertSame(self::ELEMENT_TEMPLATE, (string) $element['template']);

        $button = $this->single($layout, $methods . '/block[@name="hyva.affirm.cart.button"]');
        $this->assertSame('Hyva_AstoundAffirm::cart/affirm-button.phtml', (string) $button['template']);
        $this->assertSame('payment/affirm_gateway/enable_checkout_button', (string) $button['ifconfig']);

        $banners = [
            '0' => ['content', 'before', '-'],
            '1' => ['content', 'after', '-'],
            '2' => ['checkout.cart.methods', 'before', '-'],
        ];
        foreach ($banners as $position => [$container, $attribute, $sibling]) {
            $banner = $this->single(
                $layout,
                '//referenceContainer[@name="' . $container . '"]'
                    . '/block[@name="hyva.affirm.banner.cart.' . $position . '"]'
            );
            $this->assertSame('Hyva_AstoundAffirm::promotion/banner.phtml', (string) $banner['template']);
            $this->assertSame('affirm/affirm_promo/enabled', (string) $banner['ifconfig']);
            $this->assertSame($sibling, (string) $banner[$attribute]);
            $arguments = [];
            foreach ($banner->xpath('arguments/argument[@xsi:type!="array"]') ?: [] as $argument) {
                $arguments[(string) $argument['name']] = trim((string) $argument);
            }
            $this->assertSame(
                [
                    'view_model' => 'Hyva\AstoundAffirm\ViewModel\Banners',
                    'section' => 'cart',
                    'position' => (string) $position,
                ],
                $arguments
            );
            $this->assertSame([self::LOADER => 5, self::REFRESH => 10], $this->dependencies($banner));
        }
    }

    public function testCartScriptsAreRegisteredOnEveryCartPageWhileTheirSettingIsOn(): void
    {
        $layout = $this->loadLayout('hyva_checkout_cart_index.xml');

        // checkout.cart.methods renders only for a cart with items; "content" renders on every cart page.
        $scripts = $this->single(
            $layout,
            '//referenceContainer[@name="content"]/block[@name="hyva.affirm.aslowas.cart.scripts"]'
        );
        $this->assertSame('affirm/affirm_aslowas/enabled_cc', (string) $scripts['ifconfig']);
        $this->assertSame('', (string) $scripts['template'], 'prints nothing');
        $this->assertSame(
            [self::LOADER => 5, self::COMPONENT => 10, self::REFRESH => 10],
            $this->dependencies($scripts)
        );
        foreach (['0', '1'] as $position) {
            $banner = $this->single(
                $layout,
                '//referenceContainer[@name="content"]/block[@name="hyva.affirm.banner.cart.' . $position . '"]'
            );
            $this->assertSame([self::LOADER => 5, self::REFRESH => 10], $this->dependencies($banner));
        }
        $this->assertSame(
            [],
            $layout->xpath('//block[@name!="hyva.affirm.aslowas.cart.scripts"]//item[@name="' . self::COMPONENT . '"]')
        );
    }

    public function testCartRefreshScriptIsDetectedForAffirmPlacementsInOtherBlocks(): void
    {
        $items = $this->loadLayout('hyva_checkout_cart_index.xml')->xpath(
            '//referenceBlock[@name="page-js-dependencies"]/arguments/argument[@name="blockOutputPatternMap"]'
                . '/item[@name="hyva_astound_affirm.cart_refresh"]/item'
        ) ?: [];
        $rule = [];
        foreach ($items as $item) {
            $rule[(string) $item['name']] = trim((string) $item);
        }

        $this->assertSame(self::REFRESH, $rule['template'] ?? null);
        $this->assertSame('10', $rule['priority'] ?? null);
        $matching = [
            '<p class="affirm-as-low-as hyva-affirm-message min-h-[1.5rem]" data-page-type="category"></p>',
            '<a class="affirm-site-modal" data-page-type="banner">Learn more</a>',
            '<a class="affirm-product-modal" data-amount="50000">Learn more</a>',
        ];
        foreach ($matching as $html) {
            $this->assertSame(1, preg_match((string) $rule['regex'], $html), $html);
        }
        $this->assertSame(0, preg_match((string) $rule['regex'], '<p class="affirmation">We affirm.</p>'));
    }

    public function testTemplatesReferencedForCartAndMinicartExist(): void
    {
        $templates = [
            'Hyva_AstoundAffirm::cart/message.phtml',
            'Hyva_AstoundAffirm::cart/affirm-button.phtml',
            'Hyva_AstoundAffirm::js/cart-message.phtml',
            'Hyva_AstoundAffirm::js/cart-refresh.phtml',
            'Hyva_AstoundAffirm::js/minicart-message.phtml',
            'Hyva_AstoundAffirm::minicart/message.phtml',
            self::ELEMENT_TEMPLATE,
        ];
        foreach ($templates as $template) {
            $this->assertFileExists(self::VIEW . 'templates/' . substr($template, strlen('Hyva_AstoundAffirm::')));
        }

        // The minicart blocks are declared in hyva_default.xml.
        $default = $this->loadLayout('hyva_default.xml');
        $minicart = $this->single(
            $default,
            '//referenceContainer[@name="cart-drawer.totals.before"]/block[@name="hyva.affirm.aslowas.minicart"]'
        );
        $this->assertSame('Hyva_AstoundAffirm::minicart/message.phtml', (string) $minicart['template']);
        $script = $this->single(
            $default,
            '//referenceContainer[@name="cart-drawer.bottom"]/block[@name="hyva.affirm.aslowas.minicart.js"]'
        );
        $this->assertSame('Hyva_AstoundAffirm::js/minicart-message.phtml', (string) $script['template']);
    }

    private function givenCartMessage(bool $available, int $amountCents, bool $displayable): void
    {
        $this->cartMessage->method('isAvailable')->willReturn($available);
        $this->cartMessage->method('getAmountCents')->willReturn($amountCents);
        $this->cartMessage->method('isDisplayable')->with($amountCents)->willReturn($displayable);
        $this->cartMessage->method('getPromoId')->willReturn('CART_PROMO');
    }

    private function givenCartButton(bool $available, string $image, int $width): void
    {
        $this->cartButton->method('isAvailable')->willReturn($available);
        $this->cartButton->method('getImageUrl')->willReturn($image);
        $this->cartButton->method('getImageWidth')->willReturn($width);
        $this->cartButton->method('getActionUrl')->willReturn('https://shop.example/hyva_affirm/cart/start/');
        $this->cartButton->method('getFormKey')->willReturn('FORMKEY');
    }

    private function blockWithElement(string $elementHtml): Template&MockObject
    {
        $block = $this->createMock(Template::class);
        $block->method('getChildBlock')->with('element')->willReturn($this->element($elementHtml));

        return $block;
    }

    private function element(string $html): Template&MockObject
    {
        $element = $this->createMock(Template::class);
        $element->method('addData')->willReturnCallback(function (array $data) use ($element): Template {
            $this->elementData = $data;

            return $element;
        });
        $element->method('toHtml')->willReturn($html);

        return $element;
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

        return $render(self::VIEW . $template, $block, $this->escaper, $this->viewModels, $hyvaCsp);
    }

    private function loadLayout(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::VIEW . 'layout/' . $file);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);

        return $xml;
    }

    /**
     * @return array<string, int>
     */
    private function dependencies(\SimpleXMLElement $block): array
    {
        $dependencies = [];
        foreach ($block->xpath('arguments/argument[@name="hyva_js_template_dependencies"]/item') ?: [] as $item) {
            $dependencies[(string) $item['name']] = (int) $item;
        }

        return $dependencies;
    }

    private function single(\SimpleXMLElement $xml, string $xpath): \SimpleXMLElement
    {
        $nodes = $xml->xpath($xpath) ?: [];
        $this->assertCount(1, $nodes, $xpath);

        return $nodes[0];
    }

    /**
     * Affirm's own cart layout, when Affirm's module is installed.
     */
    private function findAffirmCartLayout(): ?\SimpleXMLElement
    {
        if (!class_exists(\Astound\Affirm\Model\Config::class)) {
            return null;
        }
        $moduleDir = dirname((string) (new \ReflectionClass(\Astound\Affirm\Model\Config::class))->getFileName(), 2);
        $file = $moduleDir . '/view/frontend/layout/checkout_cart_index.xml';
        if (!is_file($file)) {
            return null;
        }
        $xml = simplexml_load_file($file);

        return $xml instanceof \SimpleXMLElement ? $xml : null;
    }
}
