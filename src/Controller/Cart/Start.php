<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Controller\Cart;

use Astound\Affirm\Api\CheckoutPaymentManagerInterface;
use Hyva\AstoundAffirm\ViewModel\CartButton;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Target of the cart "Checkout with Affirm" button (POST hyva_affirm/cart/start).
 *
 * Selects Affirm as the quote's payment method through Affirm's own CheckoutPaymentManager and continues to the
 * checkout, which preselects it. Magento validates the form key of this POST action before execute() runs.
 *
 * A checkout may replace the method on its first page load (Hyvä Checkout's "first available payment method"
 * setting does), so the shopper's choice is also recorded in the checkout session under PRESELECT_SESSION_KEY: the
 * id of the quote that Affirm was selected for. A checkout integration (Hyva_CheckoutAffirm for Hyvä Checkout)
 * re-applies it once, when Affirm is available, and removes the key; this module only writes and clears it.
 */
class Start implements HttpPostActionInterface
{
    /**
     * Checkout session key (Magento\Checkout\Model\Session data): the int id of the quote whose shopper chose
     * Affirm with the cart button. Set after a successful selection, removed on any failure.
     */
    public const PRESELECT_SESSION_KEY = 'hyva_affirm_preselect_quote_id';

    /** Hyvä Checkout is optional: it is looked up by name so that this module does not depend on it. */
    private const HYVA_CHECKOUT_MODULE = 'Hyva_Checkout';
    private const HYVA_CHECKOUT_CONFIG = 'Hyva\Checkout\Model\Config'; // phpcs:ignore Magento2.PHP.LiteralNamespaces
    private const PAYMENT_STEP_ROUTE = 'payment';

    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly CheckoutPaymentManagerInterface $checkoutPaymentManager,
        private readonly CartButton $cartButton,
        private readonly MessageManager $messageManager,
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
        private readonly LoggerInterface $logger,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create();
        try {
            if ($this->cartButton->isAvailable() && $this->checkoutPaymentManager->initPayment() === true) {
                $this->rememberChoice();

                return $redirect->setPath('checkout', $this->getCheckoutParams());
            }
        } catch (LocalizedException $e) {
            // For example "The requested Payment Method is not available." from the payment import.
            $this->logger->warning(
                'Hyva_AstoundAffirm: Affirm could not be selected from the cart.',
                ['error' => $e->getMessage()]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Hyva_AstoundAffirm: Affirm could not be selected from the cart.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );
        }

        $this->forgetChoice();
        $this->messageManager->addErrorMessage(
            __('Checkout with Affirm is not available for this cart. Please choose another payment method.')
        );

        return $redirect->setPath('checkout/cart');
    }

    /**
     * Record the quote that Affirm was selected for (see PRESELECT_SESSION_KEY).
     *
     * Not fatal: Affirm is already on the quote, and without the record the checkout keeps its own default.
     */
    private function rememberChoice(): void
    {
        try {
            $quoteId = (int) $this->checkoutSession->getQuote()->getId();
            if ($quoteId > 0) {
                // SessionManager forwards set*() and uns*() calls to the session storage.
                $key = self::PRESELECT_SESSION_KEY;
                $this->checkoutSession->setData($key, $quoteId); // @phpstan-ignore method.notFound
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: the cart button choice could not be recorded in the checkout session.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );
        }
    }

    /**
     * Drop an earlier, not yet applied choice: the shopper was just told that Affirm is not available.
     */
    private function forgetChoice(): void
    {
        try {
            $this->checkoutSession->unsetData(self::PRESELECT_SESSION_KEY); // @phpstan-ignore method.notFound
        } catch (\Throwable) {
            // Nothing to forget without a session.
        }
    }

    /**
     * Route parameters for the checkout redirect.
     *
     * Hyvä Checkout opens on its payment step when the active checkout has one; any other checkout gets no step
     * parameter.
     *
     * @return array<string, mixed>
     */
    private function getCheckoutParams(): array
    {
        if (!$this->moduleManager->isEnabled(self::HYVA_CHECKOUT_MODULE)
            || !class_exists(self::HYVA_CHECKOUT_CONFIG)
        ) {
            return [];
        }

        try {
            $checkoutConfig = $this->objectManager->get(self::HYVA_CHECKOUT_CONFIG);
            // Throws when the active checkout is not a Hyvä checkout (for example the Luma checkout).
            $steps = $checkoutConfig->getActiveCheckoutData()['steps'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        foreach (is_array($steps) ? $steps : [] as $step) {
            if (is_array($step) && ($step['route'] ?? null) === self::PAYMENT_STEP_ROUTE) {
                return ['_query' => ['step' => self::PAYMENT_STEP_ROUTE]];
            }
        }

        return [];
    }
}
