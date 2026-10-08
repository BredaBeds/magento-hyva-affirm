<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Catalog\Block\Product;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\ViewModel\ProductMessage;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Price;
use Magento\Framework\View\Element\AbstractBlock;
use Psr\Log\LoggerInterface;

/**
 * Renders the product page as-low-as message next to the product price block on Hyvä themes.
 *
 * Stock Hyvä has no container around the price, so the message block is declared as a child of the price
 * block and rendered here, before or after the price HTML (affirm/affirm_aslowas_developer/placement). This
 * also keeps the message beside the price in themes that move the price block. Declared on
 * Magento\Catalog\Block\Product\Price (product.info.price) and Magento\Catalog\Block\Product\View
 * (product.info.bundle.price, the bundle summary price); View is the class of many product page blocks, so
 * only the price blocks named in $priceBlocks are touched. Never throws: on any error the price HTML is returned
 * unchanged.
 */
class PriceAsLowAs
{
    /**
     * @param CurrentTheme $currentTheme
     * @param AvailabilityInterface $availability
     * @param Config $config
     * @param ProductMessage $productMessage
     * @param LoggerInterface $logger
     * @param array<string,string> $priceBlocks price block name => name of the message block declared as its
     *     child in layout (DI argument; add entries for themes with their own price blocks)
     */
    public function __construct(
        private readonly CurrentTheme $currentTheme,
        private readonly AvailabilityInterface $availability,
        private readonly Config $config,
        private readonly ProductMessage $productMessage,
        private readonly LoggerInterface $logger,
        private readonly array $priceBlocks = [
            'product.info.price' => 'hyva.affirm.aslowas.product',
            'product.info.bundle.price' => 'hyva.affirm.aslowas.bundle',
        ]
    ) {
    }

    /**
     * Adds the message before or after the HTML of an allow-listed price block.
     *
     * @param AbstractBlock $subject
     * @param string|mixed $result
     * @return string
     */
    public function afterToHtml(AbstractBlock $subject, $result): string
    {
        $html = is_scalar($result) || $result instanceof \Stringable ? (string) $result : '';
        $messageBlockName = $this->priceBlocks[(string) $subject->getNameInLayout()] ?? null;
        if (!is_string($messageBlockName) || $messageBlockName === '' || trim($html) === '') {
            return $html;
        }

        try {
            $message = $this->renderMessage($subject, $messageBlockName);
            if ($message === '') {
                return $html;
            }

            return $this->config->getPlacementPdp() === Config::PLACEMENT_PDP_BEFORE_PRICE
                ? $message . $html
                : $html . $message;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: product message skipped.',
                ['block' => $subject->getNameInLayout(), 'error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return $html;
        }
    }

    /**
     * The message HTML for the price block's product, or '' when there is none.
     *
     * @param AbstractBlock $priceBlock
     * @param string $messageBlockName
     * @return string
     */
    private function renderMessage(AbstractBlock $priceBlock, string $messageBlockName): string
    {
        if (!$this->currentTheme->isHyva()) {
            return '';
        }
        // Absent when the block's ifconfig (affirm/affirm_aslowas/enabled_pdp) is off.
        $messageBlock = $priceBlock->getChildBlock($messageBlockName);
        if (!$messageBlock instanceof AbstractBlock) {
            return '';
        }
        $product = $this->getProduct($priceBlock);
        if ($product === null
            || !$this->availability->isAvailable(Placement::PRODUCT, $product)
            || $this->productMessage->isEndOfInfoPlacement($product)
            || !$this->productMessage->prepareMessageBlock($messageBlock, $product)
        ) {
            return '';
        }

        return trim((string) $priceBlock->getChildHtml($messageBlockName));
    }

    /**
     * The block's product; Product\Price and Product\View blocks fall back to the current product.
     *
     * @param AbstractBlock $block
     * @return ProductInterface|null
     */
    private function getProduct(AbstractBlock $block): ?ProductInterface
    {
        $product = $block instanceof Price || $block instanceof AbstractProduct
            ? $block->getProduct()
            : $block->getData('product');

        return $product instanceof ProductInterface ? $product : null;
    }
}
