<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Controller\Cart;

use Astound\Affirm\Api\CheckoutPaymentManagerInterface;
use Hyva\AstoundAffirm\ViewModel\CartButton;
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
 */
class Start implements HttpPostActionInterface
{
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
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create();
        try {
            if ($this->cartButton->isAvailable() && $this->checkoutPaymentManager->initPayment() === true) {
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

        $this->messageManager->addErrorMessage(
            __('Checkout with Affirm is not available for this cart. Please choose another payment method.')
        );

        return $redirect->setPath('checkout/cart');
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
