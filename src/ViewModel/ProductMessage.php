<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\BackorderResolver;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Hyva\Theme\ViewModel\BlockJsDependencies;
use Hyva\Theme\ViewModel\ProductPrice;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\LayoutInterface;
use Psr\Log\LoggerInterface;

/**
 * Product page as-low-as message: the data of the message element and of its Alpine component.
 *
 * The message is rendered by the shared template `promotion/as-low-as.phtml`; prepareMessageBlock() fills a
 * block of that template for a product. The element carries the server-rendered amount and a JSON configuration
 * (bounds, backordered configurable children, tax display key) for the component `hyvaAffirmProductMessage`,
 * which follows Hyvä's price events through the price-state recorder.
 */
class ProductMessage implements ArgumentInterface
{
    /** Alpine component of the product page message. */
    public const COMPONENT = 'hyvaAffirmProductMessage';

    /** Records Hyvä's price events for components that start later; emitted once per page. */
    public const RECORDER_TEMPLATE = 'Hyva_AstoundAffirm::js/price-state-recorder.phtml';

    /** Before the loader (5) and the component (10). */
    public const RECORDER_PRIORITY = 3;

    /** Registers the Alpine component; emitted once per page. */
    public const COMPONENT_TEMPLATE = 'Hyva_AstoundAffirm::js/product-message.phtml';

    public const COMPONENT_PRIORITY = BlockJsDependencies::DEFAULT_PRIORITY;

    /** The product price block; the end-of-info message needs it on the page. */
    public const PRICE_BLOCK = 'product.info.price';

    private const TYPE_CONFIGURABLE = 'configurable';

    /** Grouped products have no product page message; bundles show it in the bundle summary only. */
    private const TYPES_WITHOUT_END_OF_INFO = ['grouped', 'bundle'];

    /**
     * @param Config $config
     * @param AvailabilityInterface $availability
     * @param PromoIdInterface $promoId
     * @param BackorderResolver $backorderResolver
     * @param AmountRange $amountRange
     * @param ProductPrice $productPrice
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly AvailabilityInterface $availability,
        private readonly PromoIdInterface $promoId,
        private readonly BackorderResolver $backorderResolver,
        private readonly AmountRange $amountRange,
        private readonly ProductPrice $productPrice,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether the product's message belongs at the end of the product info instead of next to its price.
     *
     * True for placement 2 (End of Product Info), except for grouped products (no message) and bundles (their
     * message stays in the bundle summary).
     *
     * @param ProductInterface $product
     * @return bool
     */
    public function isEndOfInfoPlacement(ProductInterface $product): bool
    {
        return $this->config->getPlacementPdp() === Config::PLACEMENT_PDP_END_OF_INFO
            && !in_array((string) $product->getTypeId(), self::TYPES_WITHOUT_END_OF_INFO, true);
    }

    /**
     * Whether the end-of-info message may render for the product on this page.
     *
     * Requires placement 2, a product type that uses it, the product price block in the layout, and the product
     * placement available. Never throws.
     *
     * @param ProductInterface $product
     * @param LayoutInterface|null $layout
     * @return bool
     */
    public function canRenderEndOfInfo(ProductInterface $product, ?LayoutInterface $layout): bool
    {
        try {
            return $layout !== null
                && $this->isEndOfInfoPlacement($product)
                && $layout->getBlock(self::PRICE_BLOCK) !== false
                && $this->availability->isAvailable(Placement::PRODUCT, $product);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: end-of-info product message skipped.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * Fills a block of the shared message template for the product; false when there is nothing to render.
     *
     * Also registers the page scripts the message needs (the price-state recorder and the component).
     * Availability is not checked here; callers check AvailabilityInterface first.
     *
     * @param AbstractBlock $block
     * @param ProductInterface $product
     * @return bool
     */
    public function prepareMessageBlock(AbstractBlock $block, ProductInterface $product): bool
    {
        $data = $this->getMessageData($product);
        if ($data === null) {
            return false;
        }
        // Dependencies already set on the block (for example a theme switching one off with false) win.
        $dependencies = $block->getData(BlockJsDependencies::HYVA_JS_TEMPLATE_DEPENDENCIES_KEY);
        $data[BlockJsDependencies::HYVA_JS_TEMPLATE_DEPENDENCIES_KEY] = (is_array($dependencies) ? $dependencies : [])
            + [
                self::RECORDER_TEMPLATE => self::RECORDER_PRIORITY,
                self::COMPONENT_TEMPLATE => self::COMPONENT_PRIORITY,
            ];
        $block->addData($data);

        return true;
    }

    /**
     * Block data for `promotion/as-low-as.phtml`, or null when there is no message (fail closed).
     *
     * Keys: placement, amount_cents, promo_id, product and attributes. The amount is the product's final price
     * as Hyvä displays it (tax display setting): the configurable's own final price, the bundle's minimal price.
     * An amount Affirm would not show yet (outside the bounds or below min_mpp) still renders, collapsed,
     * because custom options or a configurable selection can bring it into range.
     *
     * @param ProductInterface $product
     * @return array{placement: string, amount_cents: int, promo_id: string, product: ProductInterface,
     *     attributes: array<string, string|bool>}|null
     */
    public function getMessageData(ProductInterface $product): ?array
    {
        if (!$product instanceof Product || !$product->getId()) {
            return null;
        }

        try {
            $limits = $this->config->getLimits();
            $cents = $this->amountRange->toCents(
                $this->productPrice->getPriceValue(FinalPrice::PRICE_CODE, $product)
            );
            $clientConfig = $this->amountRange->toClientConfig($limits) + [
                'backordered' => $this->getBackorderedChildIds($product),
                // Key of the price in Hyvä's update-prices-<id> payload for the tax display setting.
                'priceKey' => $this->productPrice->displayPriceIncludingTax() ? 'finalPrice' : 'basePrice',
            ];
            $attributes = [
                'x-data' => self::COMPONENT,
                'data-hyva-affirm-product-id' => (string) $product->getId(),
                'data-hyva-affirm-config' => json_encode($clientConfig, JSON_THROW_ON_ERROR),
                // The component requests affirm.js once the amount is displayable.
                'data-hyva-affirm-lazy' => true,
            ];
            if (!$this->amountRange->isDisplayable($cents, Placement::PRODUCT, $limits)) {
                $attributes['class'] = 'hidden';
            }

            return [
                'placement' => Placement::PRODUCT->value,
                'amount_cents' => $cents,
                'promo_id' => $this->promoId->forProduct($product),
                'product' => $product,
                'attributes' => $attributes,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: product message skipped.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return null;
        }
    }

    /**
     * Backordered children of a configurable product while Affirm's backorder rule is on.
     *
     * The component hides the message when the shopper's selection resolves to one of them, as Affirm's Luma
     * widget does.
     *
     * @param Product $product
     * @return int[]
     */
    private function getBackorderedChildIds(Product $product): array
    {
        if ($product->getTypeId() !== self::TYPE_CONFIGURABLE) {
            return [];
        }

        return array_values($this->backorderResolver->backorderedChildIds($product));
    }
}
