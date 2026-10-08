<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm's cart "Checkout with Affirm" button: a form posting to Controller\Cart\Start, which selects Affirm on
 * the quote and continues to the checkout.
 */
class CartButton implements ArgumentInterface
{
    public const START_ROUTE = 'hyva_affirm/cart/start';

    public function __construct(
        private readonly AvailabilityInterface $availability,
        private readonly AmountRange $amountRange,
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether the button is offered for the session quote: the cart-button availability rule plus Affirm's
     * minimum and maximum order total against the quote base grand total, as Magento's payment method list
     * applies them. Controller\Cart\Start enforces the same rule.
     */
    public function isAvailable(): bool
    {
        try {
            if (!$this->availability->isAvailable(Placement::CART_BUTTON)) {
                return false;
            }
            $baseGrandTotal = (float) $this->checkoutSession->getQuote()->getBaseGrandTotal();

            return $this->amountRange->isDisplayable(
                $this->amountRange->toCents($baseGrandTotal),
                Placement::CART_BUTTON,
                $this->config->getLimits()
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: cart button availability check failed, button hidden.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * Button image URL from Affirm's "Checkout Button Code" setting ('' when unset).
     */
    public function getImageUrl(): string
    {
        return $this->config->getCheckoutButtonImage();
    }

    /**
     * Button image width in pixels from Affirm's setting, 0 when unset.
     */
    public function getImageWidth(): int
    {
        return $this->config->getCheckoutButtonWidth();
    }

    public function getActionUrl(): string
    {
        return $this->urlBuilder->getUrl(self::START_ROUTE, ['_secure' => true]);
    }

    public function getFormKey(): string
    {
        return (string) $this->formKey->getFormKey();
    }
}
