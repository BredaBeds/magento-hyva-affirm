<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\ViewModel;

use Astound\Affirm\Gateway\Helper\Util;
use Hyva\AstoundAffirm\Model\Messaging\AvailabilityInterface;
use Hyva\AstoundAffirm\Model\Messaging\Placement;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Psr\Log\LoggerInterface;

/**
 * Affirm's order-confirmed analytics pixel ("Enable Affirm Checkout Tracking Pixel") for the checkout success page.
 *
 * The payload matches Affirm's Luma block (Block\Promotion\Pixel\Confirm) for the session's last order: the
 * increment id, the order currency, the total in cents and the payment method code. Like Affirm, the total is
 * the BASE grand total while the currency is the order currency. The pixel is sent for every payment method,
 * is suppressed in sandbox mode unless the hidden flag affirm/hyva/pixel_in_sandbox is set (tests only), and
 * requires the gateway to be active with a public key (affirm.js sends it).
 */
class Pixel implements ArgumentInterface
{
    /** The script that sends the pixel, a page JS dependency rendered after the loader. */
    public const SCRIPT_TEMPLATE = 'Hyva_AstoundAffirm::js/pixel.phtml';

    public const SCRIPT_PRIORITY = 10;

    /** @var array{orderId: string, currency: string, total: int, paymentMethod: string}|null */
    private ?array $payload = null;

    private bool $resolved = false;

    public function __construct(
        private readonly AvailabilityInterface $availability,
        private readonly CheckoutSession $checkoutSession,
        private readonly Util $util,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The first argument of affirm.analytics.trackOrderConfirmed(), or null when the pixel is not sent (disabled,
     * sandbox, gateway unavailable, or no order in the session).
     *
     * @return array{orderId: string, currency: string, total: int, paymentMethod: string}|null
     */
    public function getPayload(): ?array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->payload = $this->buildPayload();
        }

        return $this->payload;
    }

    /**
     * The third argument of trackOrderConfirmed(): true when every payload value is non-empty, as Affirm sets it.
     */
    public function isStrict(): bool
    {
        $payload = $this->getPayload();
        if ($payload === null) {
            return false;
        }
        foreach ($payload as $value) {
            if (empty($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Payload of the session's last order, or null when the pixel is not sent.
     *
     * @return array{orderId: string, currency: string, total: int, paymentMethod: string}|null
     */
    private function buildPayload(): ?array
    {
        try {
            if (!$this->availability->isAvailable(Placement::PIXEL)) {
                return null;
            }
            $order = $this->checkoutSession->getLastRealOrder();
            $payment = $order->getPayment();
            if (!$order->getId() || !$payment instanceof OrderPaymentInterface) {
                return null;
            }

            $baseGrandTotal = $order->getBaseGrandTotal();

            return [
                'orderId' => (string) $order->getIncrementId(),
                'currency' => (string) $order->getOrderCurrencyCode(),
                // @phpstan-ignore argument.type (Affirm documents an int but formats a money amount, as Luma passes it)
                'total' => (int) $this->util->formatToCents($baseGrandTotal),
                'paymentMethod' => (string) $payment->getMethod(),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyva_AstoundAffirm: order-confirmed pixel data unavailable, pixel skipped.',
                ['error' => get_class($e) . ': ' . $e->getMessage()]
            );

            return null;
        }
    }
}
