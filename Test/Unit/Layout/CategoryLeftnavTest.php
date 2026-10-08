<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Layout;

use Astound\Affirm\Model\Config as AffirmConfig;
use Magento\Framework\App\State;
use Magento\Framework\View\Layout\Condition\ConditionFactory;
use Magento\Framework\View\Layout\Data\Structure;
use Magento\Framework\View\Layout\Element;
use Magento\Framework\View\Layout\GeneratorPool;
use Magento\Framework\View\Layout\ScheduledStructure;
use Magento\Framework\View\Layout\ScheduledStructure\Helper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Affirm's page layouts move catalog.leftnav after Affirm's bml.right.banner.top, which this module removes.
 *
 * Builds a layered category page with Magento's own layout structure code (scheduling, sorting, moves, removals)
 * from Affirm's real layout files and this module's handles, and checks that the layered navigation keeps its
 * place.
 */
class CategoryLeftnavTest extends TestCase
{
    private const OWN_LAYOUT = __DIR__ . '/../../../src/view/frontend/layout/';

    /** A page layout skeleton; the sidebars exist only in multi-column layouts. */
    private const PAGE_LAYOUT = <<<'XML'
<layout>
    <container name="root">
        <container name="top.container"/>
        <container name="columns">
            <container name="div.sidebar.main"><container name="sidebar.main"/></container>
            <container name="main"><container name="content"/></container>
            <container name="div.sidebar.additional"><container name="sidebar.additional"/></container>
        </container>
    </container>
</layout>
XML;

    private const PAGE_LAYOUT_ONE_COLUMN = <<<'XML'
<layout>
    <container name="root">
        <container name="top.container"/>
        <container name="columns">
            <container name="main"><container name="content"/></container>
        </container>
    </container>
</layout>
XML;

    /** The layered navigation and product list, declared as Magento and the Hyvä default theme declare them. */
    private const CATALOG_LAYOUT = <<<'XML'
<page>
    <body>
        <referenceContainer name="content">
            <block name="category.products"/>
        </referenceContainer>
        <referenceContainer name="sidebar.main">
            <block name="catalog.leftnav" before="-"/>
        </referenceContainer>
    </body>
</page>
XML;

    private string $affirmDirectory;

    protected function setUp(): void
    {
        if (!class_exists(AffirmConfig::class)) {
            $this->markTestSkipped('affirm/magento2 is not installed.');
        }
        $this->affirmDirectory = dirname((string) (new \ReflectionClass(AffirmConfig::class))->getFileName(), 2)
            . '/view/frontend/';
    }

    public function testLayeredNavigationKeepsItsPlaceBelowTheSidebarBanner(): void
    {
        $structure = $this->build(self::PAGE_LAYOUT, '2columns-left.xml', true);

        $this->assertSame('sidebar.main', $structure->getParentId('catalog.leftnav'));
        $this->assertSame(
            ['hyva.affirm.banner.category.2', 'catalog.leftnav'],
            array_keys($structure->getChildren('sidebar.main'))
        );
        $this->assertFalse($structure->hasElement('bml.right.banner.top'));
        $this->assertFalse($structure->hasElement('bml.right.banner.bottom'));
    }

    public function testLayeredNavigationParentIsTheSameWithAndWithoutThisModule(): void
    {
        $pageLayouts = ['2columns-left.xml' => self::PAGE_LAYOUT, '1column.xml' => self::PAGE_LAYOUT_ONE_COLUMN];
        foreach ($pageLayouts as $affirmPageLayout => $pageLayout) {
            $affirmOnly = $this->build($pageLayout, $affirmPageLayout, false);
            $withModule = $this->build($pageLayout, $affirmPageLayout, true);

            $this->assertSame(
                $affirmOnly->getParentId('catalog.leftnav'),
                $withModule->getParentId('catalog.leftnav'),
                $affirmPageLayout
            );
        }
    }

    public function testCategoryBannersSitAtAffirmsPositions(): void
    {
        $structure = $this->build(self::PAGE_LAYOUT, '2columns-left.xml', true);

        $topContainer = array_keys($structure->getChildren('top.container'));
        $content = array_keys($structure->getChildren('content'));
        $sidebarAdditional = array_keys($structure->getChildren('sidebar.additional'));

        $this->assertSame('hyva.affirm.banner.category.0', reset($topContainer));
        $this->assertSame(['category.products', 'hyva.affirm.banner.category.1'], $content);
        $this->assertSame('hyva.affirm.banner.category.3', end($sidebarAdditional));
    }

    /**
     * Reads the handles of a layered category page in Magento's order (page layout first, then the handles with
     * module files before theme files, this module's hyva_* handles last) and builds the element structure.
     */
    private function build(string $pageLayout, string $affirmPageLayout, bool $withModule): Structure
    {
        $logger = $this->createMock(LoggerInterface::class);
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn(State::MODE_PRODUCTION);
        $helper = new Helper($logger, $state);
        $scheduled = new ScheduledStructure();

        $files = [
            $this->parse($pageLayout),
            $this->load($this->affirmDirectory . 'page_layout/' . $affirmPageLayout),
            $this->load($this->affirmDirectory . 'layout/catalog_category_view.xml'),
            $this->load($this->affirmDirectory . 'layout/catalog_category_view_type_layered.xml'),
            $this->parse(self::CATALOG_LAYOUT),
        ];
        if ($withModule) {
            $files[] = $this->load(self::OWN_LAYOUT . 'hyva_catalog_category_view.xml');
            $files[] = $this->load(self::OWN_LAYOUT . 'hyva_catalog_category_view_type_layered.xml');
        }
        foreach ($files as $root) {
            $body = $root->getName() === 'page' ? $root->body : $root;
            foreach ($body->children() as $node) {
                $this->read($helper, $scheduled, $node, $body);
            }
        }

        $structure = new Structure($logger, $state);
        $conditionFactory = $this->createMock(ConditionFactory::class);
        $pool = new class($helper, $conditionFactory, $logger, [], $state) extends GeneratorPool {
            public function build(ScheduledStructure $scheduled, Structure $structure): void
            {
                $this->buildStructure($scheduled, $structure);
            }
        };
        $pool->build($scheduled, $structure);

        return $structure;
    }

    /**
     * The structural part of Magento's layout readers: element declarations, references, removals and moves.
     */
    private function read(Helper $helper, ScheduledStructure $scheduled, Element $node, Element $parent): void
    {
        switch ($node->getName()) {
            case 'block':
            case 'container':
                // The readers also store the element's data; none of it matters for the structure.
                $scheduled->setStructureElementData($helper->scheduleStructure($scheduled, $node, $parent), []);
                foreach ($node->children() as $child) {
                    $this->read($helper, $scheduled, $child, $node);
                }
                break;
            case 'referenceBlock':
            case 'referenceContainer':
                if (filter_var($node->getAttribute('remove'), FILTER_VALIDATE_BOOLEAN)) {
                    $scheduled->setElementToRemoveList((string) $node->getAttribute('name'));
                    break;
                }
                foreach ($node->children() as $child) {
                    $this->read($helper, $scheduled, $child, $node);
                }
                break;
            case 'move':
                $sibling = [null, true];
                if ($node->getAttribute('after') !== null) {
                    $sibling = [$node->getAttribute('after'), true];
                } elseif ($node->getAttribute('before') !== null) {
                    $sibling = [$node->getAttribute('before'), false];
                }
                $scheduled->setElementToMove(
                    (string) $node->getAttribute('element'),
                    [(string) $node->getAttribute('destination'), $sibling[0], $sibling[1], '']
                );
                break;
        }
    }

    private function load(string $file): Element
    {
        $this->assertFileExists($file);

        return $this->parse((string) file_get_contents($file));
    }

    private function parse(string $xml): Element
    {
        $element = simplexml_load_string($xml, Element::class);
        $this->assertInstanceOf(Element::class, $element);

        return $element;
    }
}
