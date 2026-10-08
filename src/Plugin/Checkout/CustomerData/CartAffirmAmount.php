<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Plugin\Checkout\CustomerData;

use Hyva\AstoundAffirm\Model\Config;
use Hyva\AstoundAffirm\Model\Messaging\AmountRange;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Hyva\Theme\Service\CurrentTheme;
use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\DataObject;
use Magento\Tax\Model\Config as TaxConfig;
use Psr\Log\LoggerInterface;

/**
 * Adds what the Hyvä minicart message needs to the customer "cart" section.
 *
 * - affirm_minicart: ['available' => bool, 'limits' => AmountRange::toClientConfig()] for the store;
 * - affirm_amount: the cart subtotal in cents (only while the minicart message is available), with the tax
 *   display rule Affirm's Luma minicart applies: including tax when the cart subtotal is displayed including
 *   tax only, otherwise excluding tax.
 *
 * Affirm's own section plugin already adds mfpValue (promo id), learnMore and allow_affirm_quote_aslowas.
 * Luma store views are left untouched.
 */
class CartAffirmAmount
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly TaxConfig $taxConfig,
        private readonly AvailabilityInterface $availability,
        private readonly Config $config,
        private readonly AmountRange $amountRange,
        private readonly CurrentTheme $currentTheme,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Adds the minicart data to the customer "cart" section.
     *
     * @param Cart $subject
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetSectionData(Cart $subject, array $result): array
    {
        try {
            if (!$this->currentTheme->isHyva()) {
                return $result;
            }
            $available = $this->availability->isAvailable(Placement::MINICART);
            $minicart = [
                'available' => $available,
                'limits' => $this->amountRange->toClientConfig($this->config->getLimits()),
            ];
            if ($available) {
                $result['affirm_amount'] = $this->getSubtotalCents();
            }
            $result['affirm_minicart'] = $minicart;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: minicart data not added to the cart section.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );
        }

        return $result;
    }

    private function getSubtotalCents(): int
    {
        $quote = $this->checkoutSession->getQuote();
        $subtotal = $quote->getTotals()['subtotal'] ?? null;
        if (!$subtotal instanceof DataObject) {
            return 0;
        }
        $value = (float) $subtotal->getData('value');
        $storeId = $quote->getStoreId();
        $inclTaxOnly = $this->taxConfig->displayCartSubtotalInclTax($storeId)
            && !$this->taxConfig->displayCartSubtotalExclTax($storeId);
        // Same fallbacks as Magento_Tax's section plugin (subtotal_incl_tax / subtotal_excl_tax).
        $amount = $inclTaxOnly
            ? ((float) $subtotal->getData('value_incl_tax') ?: $value)
            : ((float) $subtotal->getData('value_excl_tax') ?: $value);

        return $this->amountRange->toCents($amount);
    }
}
