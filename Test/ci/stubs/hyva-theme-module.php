<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * Test double written from the public API for CI only; not the original code; MIT.
 *
 * Stands in for the classes of hyva-themes/magento2-theme-module (public signatures as of 1.5.2) that this
 * module's code and unit tests use, so CI runs without Hyvä packages. Only the members used here are declared,
 * with the same names, parameter types and return types. Methods that need a Magento runtime throw: unit tests
 * mock them.
 */
declare(strict_types=1);

namespace Hyva\Theme\Service {
    class CurrentTheme
    {
        public function isHyva(): bool
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}

namespace Hyva\Theme\Model {
    use Magento\Framework\View\Element\AbstractBlock;
    use Magento\Framework\View\Element\Block\ArgumentInterface;

    class ViewModelRegistry
    {
        /**
         * @template T of ArgumentInterface
         * @param class-string<T> $viewModelClass
         * @param AbstractBlock|null $block
         * @return T
         */
        public function require(string $viewModelClass, ?AbstractBlock $block = null): ArgumentInterface
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}

namespace Hyva\Theme\ViewModel {
    use Magento\Catalog\Api\Data\ProductInterface;
    use Magento\Catalog\Model\Product;
    use Magento\Framework\View\Element\AbstractBlock;
    use Magento\Framework\View\Element\Block\ArgumentInterface;

    class BlockJsDependencies implements ArgumentInterface
    {
        public const DEFAULT_PRIORITY = 10;

        public const HYVA_JS_TEMPLATE_DEPENDENCIES_KEY = 'hyva_js_template_dependencies';

        public function setBlockTemplateDependencyWithPriority(
            AbstractBlock $dependantBlock,
            string $dependencyTemplate,
            ?int $priority
        ): void {
            $dependencies = $dependantBlock->getData(self::HYVA_JS_TEMPLATE_DEPENDENCIES_KEY);
            $dependencies = is_array($dependencies) ? $dependencies : [];
            $dependencies[$dependencyTemplate] = $priority ?? self::DEFAULT_PRIORITY;
            $dependantBlock->setData(self::HYVA_JS_TEMPLATE_DEPENDENCIES_KEY, $dependencies);
        }
    }

    class CurrentProduct implements ArgumentInterface
    {
        public function get(): ProductInterface
        {
            throw new \RuntimeException('Test double: no current product.');
        }

        public function exists(): bool
        {
            return false;
        }
    }

    class HyvaCsp implements ArgumentInterface
    {
        public function registerInlineScript(): void
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }

    class ProductListItem implements ArgumentInterface
    {
        public function getProductPriceHtml(Product $product, array $priceRendererBlockArgs = [])
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }

        public function getItemCacheKeyInfo(
            Product $product,
            AbstractBlock $block,
            string $viewMode,
            string $templateType
        ): array {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }

    class ProductPrice implements ArgumentInterface
    {
        public function getPriceValue(string $priceType, ?Product $product = null): float
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }

        public function displayPriceIncludingTax(): bool
        {
            throw new \LogicException('Test double: mock ' . __METHOD__ . '().');
        }
    }
}
