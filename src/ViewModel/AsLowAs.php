<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\CartPromoRule;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Attributes of Affirm's as-low-as element (`<p class="affirm-as-low-as" data-amount=...>`), which affirm.js
 * fills with the monthly payment text.
 *
 * Before rendering, the attributes pass through the event `hyva_affirm_as_low_as_data` with a DataObject
 * `transport` holding: placement (Placement), attributes (array<string,string>), cart_rule (array|null, cart
 * placement only), product (ProductInterface|null) and render (bool; set false to render nothing).
 */
class AsLowAs implements ArgumentInterface
{
    public const EVENT = 'hyva_affirm_as_low_as_data';

    /** Attribute names, including Alpine's `:` and `@` shorthands. */
    private const ATTRIBUTE_NAME = '/^[a-zA-Z_:@][a-zA-Z0-9_:@.\-]*$/';

    private ?CartPromoRule $cartRule = null;

    public function __construct(
        private readonly Config $config,
        private readonly PromoIdInterface $promoId,
        private readonly EventManager $eventManager,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Attributes for the message element, or null to render nothing (not a message placement, an observer
     * vetoed it, or an error occurred).
     *
     * Built-in attributes: class, data-page-type, data-amount, data-promo-id (when not empty),
     * data-affirm-color, data-learnmore-show, data-hyva-affirm-placement, and data-hyva-affirm-lazy for the
     * minicart. $extra adds attributes: a string or number sets the value, true renders an empty value, false or
     * null removes the attribute, and 'class' is appended to the built-in classes.
     *
     * @param array<string, string|int|float|bool|null> $extra
     * @return array<string, string>|null
     */
    public function getAttributes(
        Placement $placement,
        int $amountCents,
        string $promoId,
        array $extra = [],
        ?ProductInterface $product = null
    ): ?array {
        $pageType = $placement->pageType();
        if ($pageType === null) {
            return null;
        }

        try {
            $attributes = [
                'class' => 'affirm-as-low-as hyva-affirm-message',
                'data-page-type' => $pageType,
                'data-amount' => (string) $amountCents,
                'data-promo-id' => $promoId,
                'data-affirm-color' => $this->config->getLogoColor(),
                'data-learnmore-show' => $this->config->isLearnMoreEnabled() ? 'true' : 'false',
            ];
            if ($placement === Placement::MINICART) {
                // The minicart requests affirm.js itself when the drawer opens.
                $attributes['data-hyva-affirm-lazy'] = '';
            }
            $attributes = $this->mergeAttributes($attributes, $extra);
            $attributes['data-hyva-affirm-placement'] = $placement->value;

            $cartRule = $placement === Placement::CART ? $this->promoId->cartRule() : null;
            $transport = $this->dispatch($placement, $attributes, $cartRule, $product);
            if ($cartRule !== null) {
                $this->cartRule = $this->readCartRule($transport, $cartRule);
            }
            if (!$transport->getData('render')) {
                return null;
            }

            return $this->cleanAttributes($transport->getData('attributes'));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: as-low-as message skipped.',
                ['placement' => $placement->value, 'error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return null;
        }
    }

    /**
     * The cart-size promo rule for the cart page component, after observers of `hyva_affirm_as_low_as_data`.
     *
     * When getAttributes() already ran for the cart placement its result is reused; otherwise the event is
     * dispatched with placement cart and empty attributes.
     *
     * @return array{min: float|null, max: float|null, promoInRange: string, promoOutOfRange: string,
     *     higherPrecedenceApplies: bool}
     */
    public function getCartRule(): array
    {
        if ($this->cartRule === null) {
            $cartRule = $this->promoId->cartRule();
            try {
                $transport = $this->dispatch(Placement::CART, [], $cartRule, null);
                $this->cartRule = $this->readCartRule($transport, $cartRule);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Hyva_AstoundAffirm: cart promo rule observers failed, using Affirm\'s rule.',
                    ['error' => get_class($e) . ': ' . $e->getMessage()]
                );
                $this->cartRule = $cartRule;
            }
        }

        return $this->cartRule->toArray();
    }

    /**
     * Renders attributes as ` name="value"` pairs, escaped; invalid attribute names are skipped.
     *
     * @param array<mixed> $attributes
     */
    public function renderAttributes(array $attributes): string
    {
        $html = '';
        foreach ($this->cleanAttributes($attributes) as $name => $value) {
            $html .= ' ' . $name . '="' . $this->escaper->escapeHtmlAttr($value) . '"';
        }

        return $html;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function dispatch(
        Placement $placement,
        array $attributes,
        ?CartPromoRule $cartRule,
        ?ProductInterface $product
    ): DataObject {
        $transport = new DataObject([
            'placement' => $placement,
            'attributes' => $attributes,
            'cart_rule' => $cartRule?->toArray(),
            'product' => $product,
            'render' => true,
        ]);
        $this->eventManager->dispatch(self::EVENT, ['transport' => $transport]);

        return $transport;
    }

    private function readCartRule(DataObject $transport, CartPromoRule $fallback): CartPromoRule
    {
        $data = $transport->getData('cart_rule');

        return is_array($data) ? CartPromoRule::fromArray($data, $fallback) : $fallback;
    }

    /**
     * @param array<string, string> $attributes
     * @param array<mixed> $extra
     * @return array<string, string>
     */
    private function mergeAttributes(array $attributes, array $extra): array
    {
        foreach ($extra as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if ($name === 'class') {
                $attributes['class'] = trim($attributes['class'] . ' ' . (is_scalar($value) ? $value : ''));
                continue;
            }
            if ($value === null || $value === false) {
                unset($attributes[$name]);
                continue;
            }
            $attributes[$name] = $value === true ? '' : (is_scalar($value) ? (string) $value : '');
        }

        return $attributes;
    }

    /**
     * Valid attribute names with string values; an empty promo id is dropped (affirm.js then uses the default).
     *
     * @return array<string, string>
     */
    private function cleanAttributes(mixed $attributes): array
    {
        if (!is_array($attributes)) {
            return [];
        }
        $clean = [];
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !preg_match(self::ATTRIBUTE_NAME, $name)) {
                continue;
            }
            if ($value === null || $value === false || !is_scalar($value)) {
                continue;
            }
            $clean[$name] = $value === true ? '' : (string) $value;
        }
        if (($clean['data-promo-id'] ?? null) === '') {
            unset($clean['data-promo-id']);
        }

        return $clean;
    }
}
