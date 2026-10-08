<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Layout;

use Hyva\AstoundAffirm\Plugin\Catalog\Block\Product\PriceAsLowAs;
use PHPUnit\Framework\TestCase;

/**
 * The product page layout handles: Affirm's Luma blocks removed, this module's blocks where the plugin and the
 * banner view model expect them.
 */
class ProductPageLayoutTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../../src/view/frontend/layout/';
    private const MESSAGE_TEMPLATE = 'Hyva_AstoundAffirm::promotion/as-low-as.phtml';
    private const ENABLED_PDP = 'affirm/affirm_aslowas/enabled_pdp';

    public function testProductHandleRemovesAffirmsLumaBlocks(): void
    {
        $this->assertSame(
            [
                'affirm_banner_center_bottom',
                'affirm_banner_center_top',
                'aslowas.after.price',
                'aslowas.before.price',
                'aslowas.end.product.info',
                'bml.right.banner.top',
                'bml.right.banner.top1',
            ],
            $this->removedBlocks('hyva_catalog_product_view.xml')
        );
    }

    public function testBundleHandleRemovesAffirmsBundleBlock(): void
    {
        $this->assertSame(['aslowas.bundle'], $this->removedBlocks('hyva_catalog_product_view_type_bundle.xml'));
    }

    public function testMessageBlocksAreChildrenOfThePluginsPriceBlocks(): void
    {
        $priceBlocks = $this->defaultPriceBlocks();
        $files = [
            'product.info.price' => 'hyva_catalog_product_view.xml',
            'product.info.bundle.price' => 'hyva_catalog_product_view_type_bundle.xml',
        ];

        $this->assertSame(array_keys($files), array_keys($priceBlocks));
        foreach ($files as $priceBlock => $file) {
            $blocks = $this->xpath($file, sprintf('//referenceBlock[@name="%s"]/block', $priceBlock));
            $this->assertCount(1, $blocks, $priceBlock);
            $this->assertSame($priceBlocks[$priceBlock], (string) $blocks[0]['name']);
            $this->assertSame(self::MESSAGE_TEMPLATE, (string) $blocks[0]['template']);
            $this->assertSame(self::ENABLED_PDP, (string) $blocks[0]['ifconfig']);
        }
    }

    public function testEndOfInfoBlockSitsInProductInfoAdditionalWithItsMessageChild(): void
    {
        $blocks = $this->xpath(
            'hyva_catalog_product_view.xml',
            '//referenceContainer[@name="product.info.additional"]/block[@name="hyva.affirm.aslowas.product.end"]'
        );

        $this->assertCount(1, $blocks);
        $this->assertSame('Hyva_AstoundAffirm::promotion/product-end.phtml', (string) $blocks[0]['template']);
        $this->assertSame(self::ENABLED_PDP, (string) $blocks[0]['ifconfig']);
        $this->assertSame('message', (string) $blocks[0]->block['as']);
        $this->assertSame(self::MESSAGE_TEMPLATE, (string) $blocks[0]->block['template']);
    }

    public function testProductBannersUseAffirmsPositions(): void
    {
        $expected = [
            'hyva.affirm.banner.product.0' => ['top.container', 'before', '-', '0'],
            'hyva.affirm.banner.product.1' => ['top.container', 'after', '-', '1'],
            'hyva.affirm.banner.product.2' => ['product.info.addtocart', 'after', '-', '2'],
        ];
        $banners = $this->xpath('hyva_catalog_product_view.xml', '//block[starts-with(@name, "hyva.affirm.banner.")]');

        $this->assertCount(count($expected), $banners);
        foreach ($banners as $banner) {
            $name = (string) $banner['name'];
            $this->assertArrayHasKey($name, $expected);
            [$parent, $sibling, $siblingValue, $position] = $expected[$name];
            $parentNode = $banner->xpath('..')[0];
            $this->assertSame($parent, (string) $parentNode['name'], $name);
            $this->assertSame($siblingValue, (string) $banner[$sibling], $name);
            $this->assertSame('Hyva_AstoundAffirm::promotion/banner.phtml', (string) $banner['template']);
            $this->assertSame('affirm/affirm_promo/enabled', (string) $banner['ifconfig']);
            $this->assertSame(
                [
                    'view_model' => 'Hyva\AstoundAffirm\ViewModel\Banners',
                    'section' => 'product',
                    'position' => $position,
                ],
                $this->arguments($banner)
            );
        }
    }

    /**
     * @return string[]
     */
    private function removedBlocks(string $file): array
    {
        $names = array_map(
            static fn (\SimpleXMLElement $node): string => (string) $node['name'],
            $this->xpath($file, '//referenceBlock[@remove="true"]')
        );
        sort($names);

        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function arguments(\SimpleXMLElement $block): array
    {
        $arguments = [];
        foreach ($block->arguments->argument ?? [] as $argument) {
            $arguments[(string) $argument['name']] = trim((string) $argument);
        }

        return $arguments;
    }

    /**
     * @return array<string, string>
     */
    private function defaultPriceBlocks(): array
    {
        foreach ((new \ReflectionMethod(PriceAsLowAs::class, '__construct'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'priceBlocks') {
                $default = $parameter->getDefaultValue();
                $this->assertIsArray($default);

                return $default;
            }
        }
        $this->fail('PriceAsLowAs has no $priceBlocks argument.');
    }

    /**
     * @return \SimpleXMLElement[]
     */
    private function xpath(string $file, string $query): array
    {
        $xml = simplexml_load_file(self::LAYOUT . $file);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, $file);
        $nodes = $xml->xpath($query);

        return is_array($nodes) ? $nodes : [];
    }
}
