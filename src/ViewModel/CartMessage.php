<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\CartPromoRule;
use Hyva\AstoundAffirm\Model\Messaging\Limits;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\AstoundAffirm\Model\Messaging\PromoIdInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Data for the cart page message and the minicart message.
 *
 * The cart page is not full-page cached, so its message carries the session quote's grand total and promo id.
 * The minicart sits on cached pages: its markup is visitor-independent and the amount comes from the customer
 * "cart" section (see Plugin\Checkout\CustomerData\CartAffirmAmount).
 */
class CartMessage implements ArgumentInterface
{
    public function __construct(
        private readonly AvailabilityInterface $availability,
        private readonly PromoIdInterface $promoId,
        private readonly AsLowAs $asLowAs,
        private readonly AmountRange $amountRange,
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether the cart page message is offered for the session quote.
     */
    public function isAvailable(): bool
    {
        return $this->availability->isAvailable(Placement::CART);
    }

    /**
     * Whether the minicart message is offered (configuration only; the quote is checked in the browser).
     */
    public function isMinicartAvailable(): bool
    {
        return $this->availability->isAvailable(Placement::MINICART);
    }

    /**
     * The quote grand total in cents, in the displayed currency (what Affirm's Luma cart message used).
     */
    public function getAmountCents(): int
    {
        try {
            return $this->amountRange->toCents((float) $this->checkoutSession->getQuote()->getGrandTotal());
        } catch (\Throwable $e) {
            $this->logFailure('amount', $e);

            return 0;
        }
    }

    /**
     * Whether a cart message for this amount is shown now (the browser re-evaluates on totals updates).
     *
     * @param int $amountCents
     * @return bool
     */
    public function isDisplayable(int $amountCents): bool
    {
        return $this->amountRange->isDisplayable($amountCents, Placement::CART, $this->config->getLimits());
    }

    /**
     * Affirm's promo id for the session quote.
     */
    public function getPromoId(): string
    {
        return $this->promoId->forCart();
    }

    /**
     * Configuration of the hyvaAffirmCartMessage component.
     *
     * The amount bounds in cents and the cart-size promo rule after observers of `hyva_affirm_as_low_as_data`.
     * Call it after the message element rendered: the rule observers saw for the element is then reused
     * instead of dispatching the event again.
     *
     * @return array{
     *     limits: array{min: int|null, max: int|null, minMpp: int|null},
     *     cartRule: array{min: float|null, max: float|null, promoInRange: string, promoOutOfRange: string,
     *         higherPrecedenceApplies: bool}
     * }
     */
    public function getComponentConfig(): array
    {
        try {
            $limits = $this->config->getLimits();
        } catch (\Throwable $e) {
            $this->logFailure('limits', $e);
            $limits = new Limits(null, null, null);
        }

        return [
            'limits' => $this->amountRange->toClientConfig($limits),
            'cartRule' => $this->getCartRule(),
        ];
    }

    /**
     * The cart-size promo rule; a rule that never switches when it cannot be built.
     *
     * @return array{min: float|null, max: float|null, promoInRange: string, promoOutOfRange: string,
     *     higherPrecedenceApplies: bool}
     */
    private function getCartRule(): array
    {
        try {
            return $this->asLowAs->getCartRule();
        } catch (\Throwable $e) {
            $this->logFailure('cart rule', $e);

            return CartPromoRule::fixed('')->toArray();
        }
    }

    private function logFailure(string $context, \Throwable $e): void
    {
        $this->logger->warning(
            'Hyva_AstoundAffirm: cart message data unavailable (' . $context . ').',
            ['error' => get_class($e) . ': ' . $e->getMessage()]
        );
    }
}
