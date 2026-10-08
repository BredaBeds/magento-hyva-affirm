<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Layout;

use Astound\Affirm\Model\Config as AffirmConfig;
use PHPUnit\Framework\TestCase;

/**
 * The home, category and success page handles: Affirm's Luma blocks removed, the banners and the pixel declared
 * where Affirm placed them.
 */
class StorefrontLayoutTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../../src/view/frontend/layout/';
    private const BANNER_TEMPLATE = 'Hyva_AstoundAffirm::promotion/banner.phtml';

    /** Affirm's layout handle => this module's handle that must remove every block Affirm declares there. */
    private const REPLACED_HANDLES = [
        'cms_index_index.xml' => 'hyva_cms_index_index.xml',
        'catalog_category_view.xml' => 'hyva_catalog_category_view.xml',
        'catalog_category_view_type_layered.xml' => 'hyva_catalog_category_view_type_layered.xml',
        'checkout_onepage_success.xml' => 'hyva_checkout_onepage_success.xml',
    ];

    public function testHomeHandleReplacesAffirmsBannersAtAffirmsPositions(): void
    {
        $this->assertSame(
            [
                'affirm_banner_bottom',
                'affirm_banner_center_bottom',
                'affirm_banner_center_top',
                'affirm_banner_sidebar_top',
            ],
            $this->removedBlocks('hyva_cms_index_index.xml')
        );
        $this->assertBanners('hyva_cms_index_index.xml', 'home', [
            0 => ['content', 'before'],
            1 => ['content', 'after'],
            2 => ['sidebar.additional', 'before'],
            3 => ['sidebar.additional', 'after'],
        ]);
    }

    public function testHomeHandleLeavesThePageLayoutToTheTheme(): void
    {
        $xml = $this->load('hyva_cms_index_index.xml');

        $this->assertNull($xml['layout'] ?? null);
    }

    public function testCategoryHandleReplacesAffirmsBannersAtAffirmsPositions(): void
    {
        $this->assertSame(
            [
                'affirm_banner_center_bottom',
                'affirm_banner_center_top',
                'bml.right.banner.bottom',
                'bml.right.banner.top',
            ],
            $this->removedBlocks('hyva_catalog_category_view.xml')
        );
        $this->assertBanners('hyva_catalog_category_view.xml', 'category', [
            0 => ['top.container', 'before'],
            1 => ['content', 'after'],
            2 => ['sidebar.main', 'before'],
            3 => ['sidebar.additional', 'after'],
        ]);
    }

    public function testLayeredCategoryHandleOnlyRemovesAffirmsRedeclaredBanner(): void
    {
        $file = 'hyva_catalog_category_view_type_layered.xml';

        $this->assertSame(['bml.right.banner.top'], $this->removedBlocks($file));
        $this->assertSame([], $this->xpath($file, '//block'));
    }

    public function testCategoryHandlesNeverMoveTheLayeredNavigation(): void
    {
        foreach (['hyva_catalog_category_view.xml', 'hyva_catalog_category_view_type_layered.xml'] as $file) {
            $this->assertSame([], $this->xpath($file, '//move'), $file);
            $this->assertStringNotContainsString(
                'name="catalog.leftnav"',
                (string) file_get_contents(self::LAYOUT . $file),
                $file
            );
        }
    }

    public function testSuccessHandleReplacesTheLumaPixelBeforePageJsDependencies(): void
    {
        $this->assertSame(
            ['affirm_clear_mini_cart', 'affirm_pixel_code_confirm'],
            $this->removedBlocks('hyva_checkout_onepage_success.xml')
        );
        $blocks = $this->xpath(
            'hyva_checkout_onepage_success.xml',
            '//referenceContainer[@name="before.body.end"]/block'
        );

        $this->assertCount(1, $blocks);
        $this->assertSame('hyva.affirm.pixel', (string) $blocks[0]['name']);
        $this->assertSame('Hyva_AstoundAffirm::pixel/order-confirmed.phtml', (string) $blocks[0]['template']);
        $this->assertSame('affirm/affirm_pixel/enabled', (string) $blocks[0]['ifconfig']);
        $this->assertSame('page-js-dependencies', (string) $blocks[0]['before']);
    }

    public function testLoaderIsDetectedForAffirmMarkupInCmsContentAndThisModulesBannersAndPixel(): void
    {
        $items = $this->xpath(
            'hyva_default.xml',
            '//referenceBlock[@name="page-js-dependencies"]//item[@name="hyva_astound_affirm.sdk_loader"]'
        );
        $this->assertCount(1, $items);
        $regex = '';
        foreach ($items[0]->item as $item) {
            if ((string) $item['name'] === 'regex') {
                $regex = trim((string) $item);
            }
        }
        $matching = [
            // Affirm's documented markup, as merchants paste it into CMS pages, blocks and Page Builder.
            '<p class="affirm-as-low-as" data-page-type="product" data-amount="129900"></p>',
            '<a class="affirm-site-modal" data-page-type="banner" data-promo-id="promo_set_home">Learn more</a>',
            '<a class="affirm-product-modal" data-page-type="product" data-amount="50000">Learn more</a>',
            '<div class="promo affirm-site-modal">x</div>',
            // This module's banner and pixel markup.
            '<button type="button" class="affirm-site-modal hyva-affirm-banner max-w-full" data-page-type="cart">',
            '<div hidden data-hyva-affirm-pixel data-order-id="100000001"></div>',
        ];
        $notMatching = [
            '<p class="affirmation">We affirm our commitment.</p>',
            '<a class="site-modal" href="#">Open</a>',
        ];

        foreach ($matching as $html) {
            $this->assertSame(1, preg_match($regex, $html), $html);
        }
        foreach ($notMatching as $html) {
            $this->assertSame(0, preg_match($regex, $html), $html);
        }
    }

    public function testEveryAffirmBlockOfTheReplacedHandlesIsRemoved(): void
    {
        $affirmLayout = $this->affirmLayoutDirectory();
        foreach (self::REPLACED_HANDLES as $affirmFile => $ownFile) {
            $affirm = simplexml_load_file($affirmLayout . $affirmFile);
            $this->assertInstanceOf(\SimpleXMLElement::class, $affirm, $affirmFile);
            $declared = array_map(
                static fn (\SimpleXMLElement $node): string => (string) $node['name'],
                $affirm->xpath('//block') ?: []
            );
            $declared = array_values(array_unique($declared));
            sort($declared);

            $this->assertNotEmpty($declared, $affirmFile);
            $this->assertSame($declared, $this->removedBlocks($ownFile), $affirmFile);
        }
    }

    /**
     * @param array<int, array{0: string, 1: string}> $positions position => [container, before|after]
     */
    private function assertBanners(string $file, string $section, array $positions): void
    {
        $banners = $this->xpath($file, '//block[starts-with(@name, "hyva.affirm.banner.")]');
        $this->assertCount(count($positions), $banners);

        foreach ($positions as $position => [$container, $order]) {
            $name = 'hyva.affirm.banner.' . $section . '.' . $position;
            $query = sprintf('//referenceContainer[@name="%s"]/block[@name="%s"]', $container, $name);
            $matches = $this->xpath($file, $query);
            $this->assertCount(1, $matches, $name);
            $banner = $matches[0];
            $this->assertSame('-', (string) $banner[$order], $name);
            $this->assertSame(self::BANNER_TEMPLATE, (string) $banner['template'], $name);
            $this->assertSame('affirm/affirm_promo/enabled', (string) $banner['ifconfig'], $name);
            $this->assertSame(
                [
                    'view_model' => 'Hyva\AstoundAffirm\ViewModel\Banners',
                    'section' => $section,
                    'position' => (string) $position,
                ],
                $this->arguments($banner),
                $name
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
     * @return \SimpleXMLElement[]
     */
    private function xpath(string $file, string $query): array
    {
        $nodes = $this->load($file)->xpath($query);

        return is_array($nodes) ? $nodes : [];
    }

    private function load(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::LAYOUT . $file);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, $file);

        return $xml;
    }

    private function affirmLayoutDirectory(): string
    {
        if (!class_exists(AffirmConfig::class)) {
            $this->markTestSkipped('affirm/magento2 is not installed.');
        }
        $file = (string) (new \ReflectionClass(AffirmConfig::class))->getFileName();

        return dirname($file, 2) . '/view/frontend/layout/';
    }
}
