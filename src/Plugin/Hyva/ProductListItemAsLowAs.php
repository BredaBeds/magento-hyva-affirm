<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Hyva;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\Theme\ViewModel\ProductListItem;
use Hyva\Theme\ViewModel\ProductPrice;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * Appends Affirm's static as-low-as message to the price of every Hyvä listing item (category, search,
 * sliders, widgets).
 *
 * The markup is static (no script): listing items are block-cached, and the loader is emitted by the page
 * JS dependency pattern that matches the message class.
 */
class ProductListItemAsLowAs
{
    public const TEMPLATE = 'Hyva_AstoundAffirm::promotion/as-low-as.phtml';

    private ?Template $renderer = null;

    public function __construct(
        private readonly AvailabilityInterface $availability,
        private readonly PromoIdInterface $promoId,
        private readonly Config $config,
        private readonly AmountRange $amountRange,
        private readonly ProductPrice $productPrice,
        private readonly LayoutInterface $layout,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ProductListItem $subject
     * @param mixed $result
     * @param Product $product
     * @param array<string, mixed> $priceRendererBlockArgs
     * @return string
     */
    public function afterGetProductPriceHtml(
        ProductListItem $subject,
        $result,
        Product $product,
        array $priceRendererBlockArgs = []
    ): string {
        $html = (string) $result;
        try {
            return $html . $this->renderMessage($product);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: listing message skipped.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return $html;
        }
    }

    private function renderMessage(Product $product): string
    {
        if (!$this->availability->isAvailable(Placement::LISTING, $product)) {
            return '';
        }
        $cents = $this->amountRange->toCents(
            $this->productPrice->getPriceValue(FinalPrice::PRICE_CODE, $product)
        );
        if (!$this->amountRange->isDisplayable($cents, Placement::LISTING, $this->config->getLimits())) {
            return '';
        }

        $renderer = $this->getRenderer();
        if ($renderer === null) {
            return '';
        }
        // One renderer for all items: every key the template reads is set again for each product.
        $renderer->addData([
            'placement' => Placement::LISTING->value,
            'amount_cents' => $cents,
            'promo_id' => $this->promoId->forProduct($product),
            'product' => $product,
            'attributes' => [],
        ]);

        return (string) $renderer->toHtml();
    }

    private function getRenderer(): ?Template
    {
        if ($this->renderer === null) {
            $block = $this->layout->createBlock(Template::class);
            if (!$block instanceof Template) {
                return null;
            }
            $block->setTemplate(self::TEMPLATE);
            $this->renderer = $block;
        }

        return $this->renderer;
    }
}
