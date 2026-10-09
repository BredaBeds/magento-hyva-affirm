<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit\Controller\Cart;

use Astound\Affirm\Api\CheckoutPaymentManagerInterface;
use Hyva\AstoundAffirm\Controller\Cart\Start;
use Hyva\AstoundAffirm\ViewModel\CartButton;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StartTest extends TestCase
{
    private const HYVA_CHECKOUT_CONFIG = 'Hyva\Checkout\Model\Config';

    private Redirect&MockObject $redirect;

    private CheckoutPaymentManagerInterface&MockObject $checkoutPaymentManager;

    private CartButton&MockObject $cartButton;

    private MessageManager&MockObject $messageManager;

    private ModuleManager&MockObject $moduleManager;

    private ObjectManagerInterface&MockObject $objectManager;

    private LoggerInterface&MockObject $logger;

    private CheckoutSession&MockObject $checkoutSession;

    private Quote&MockObject $quote;

    /**
     * @var array<string, mixed>
     */
    private array $session = [];

    private Start $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $this->checkoutPaymentManager = $this->createMock(CheckoutPaymentManagerInterface::class);
        $this->cartButton = $this->createMock(CartButton::class);
        $this->messageManager = $this->createMock(MessageManager::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getId')->willReturn(497503);
        $this->checkoutSession = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote', '__call'])
            ->getMock();
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);
        // SessionManager forwards setData()/unsetData() to the session storage through __call().
        $this->checkoutSession->method('__call')->willReturnCallback(function (string $method, array $args) {
            if ($method === 'setData') {
                $this->session[$args[0]] = $args[1];
            } elseif ($method === 'unsetData') {
                unset($this->session[$args[0]]);
            } else {
                $this->fail('Unexpected session call ' . $method);
            }
            return $this->checkoutSession;
        });

        $this->controller = new Start(
            $redirectFactory,
            $this->checkoutPaymentManager,
            $this->cartButton,
            $this->messageManager,
            $this->moduleManager,
            $this->objectManager,
            $this->logger,
            $this->checkoutSession
        );
    }

    public function testIsAPostActionWhoseFormKeyMagentoValidates(): void
    {
        // Magento's CsrfValidator checks the form key of POST requests unless the action opts out.
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $this->controller);
        $this->assertNotInstanceOf(CsrfAwareActionInterface::class, $this->controller);
    }

    public function testSuccessOpensTheHyvaCheckoutPaymentStep(): void
    {
        $this->givenHyvaCheckoutSteps([
            'shipping' => ['name' => 'shipping', 'route' => 'shipping'],
            'payment' => ['name' => 'payment', 'route' => 'payment'],
        ]);
        $this->givenInitPaymentReturns(true);
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')
            ->with('checkout', ['_query' => ['step' => 'payment']])->willReturnSelf();

        $this->assertSame($this->redirect, $this->controller->execute());
    }

    public function testSuccessWithoutAPaymentStepOpensTheCheckout(): void
    {
        $this->givenHyvaCheckoutSteps(['default' => ['name' => 'default', 'route' => 'default']]);
        $this->givenInitPaymentReturns(true);
        $this->redirect->expects($this->once())->method('setPath')->with('checkout', [])->willReturnSelf();

        $this->controller->execute();
    }

    public function testSuccessWithANonHyvaActiveCheckoutOpensTheCheckout(): void
    {
        $this->requireHyvaCheckout();
        $this->moduleManager->method('isEnabled')->with('Hyva_Checkout')->willReturn(true);
        $config = $this->getMockBuilder(self::HYVA_CHECKOUT_CONFIG)
            ->disableOriginalConstructor()
            ->onlyMethods(['getActiveCheckoutData'])
            ->getMock();
        $config->method('getActiveCheckoutData')->willThrowException(new \RuntimeException('Luma checkout'));
        $this->objectManager->method('get')->with(self::HYVA_CHECKOUT_CONFIG)->willReturn($config);
        $this->givenInitPaymentReturns(true);
        $this->redirect->expects($this->once())->method('setPath')->with('checkout', [])->willReturnSelf();

        $this->controller->execute();
    }

    public function testSuccessWithoutHyvaCheckoutOpensTheCheckout(): void
    {
        $this->moduleManager->method('isEnabled')->with('Hyva_Checkout')->willReturn(false);
        $this->objectManager->expects($this->never())->method('get');
        $this->givenInitPaymentReturns(true);
        $this->redirect->expects($this->once())->method('setPath')->with('checkout', [])->willReturnSelf();

        $this->controller->execute();
    }

    public function testSuccessRecordsTheChoiceForTheCheckout(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(false);
        $this->givenInitPaymentReturns(true);
        $this->redirect->method('setPath')->willReturnSelf();

        $this->controller->execute();

        $this->assertSame('hyva_affirm_preselect_quote_id', Start::PRESELECT_SESSION_KEY);
        $this->assertSame(497503, $this->session[Start::PRESELECT_SESSION_KEY]);
    }

    public function testARecordingFailureStillOpensTheCheckout(): void
    {
        $this->moduleManager->method('isEnabled')->willReturn(false);
        $this->cartButton->method('isAvailable')->willReturn(true);
        $this->checkoutPaymentManager->method('initPayment')->willReturn(true);
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote', '__call'])
            ->getMock();
        $session->method('getQuote')->willThrowException(new \RuntimeException('session storage down'));
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $controller = new Start(
            $redirectFactory,
            $this->checkoutPaymentManager,
            $this->cartButton,
            $this->messageManager,
            $this->moduleManager,
            $this->objectManager,
            $this->logger,
            $session
        );
        $this->logger->expects($this->once())->method('warning')
            ->with($this->stringContains('could not be recorded'), $this->arrayHasKey('error'));
        $this->messageManager->expects($this->never())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('checkout', [])->willReturnSelf();

        $controller->execute();
    }

    public function testFailureForgetsAnEarlierChoice(): void
    {
        $this->session[Start::PRESELECT_SESSION_KEY] = 497503;
        $this->cartButton->method('isAvailable')->willReturn(false);
        $this->expectCartRedirectWithError();

        $this->controller->execute();

        $this->assertArrayNotHasKey(Start::PRESELECT_SESSION_KEY, $this->session);
    }

    public function testUnavailableReturnsToTheCartWithoutTouchingTheQuote(): void
    {
        $this->cartButton->method('isAvailable')->willReturn(false);
        $this->checkoutPaymentManager->expects($this->never())->method('initPayment');
        $this->expectCartRedirectWithError();

        $this->controller->execute();
    }

    public function testFalseFromInitPaymentReturnsToTheCart(): void
    {
        $this->givenInitPaymentReturns(false);
        $this->expectCartRedirectWithError();

        $this->controller->execute();

        $this->assertArrayNotHasKey(Start::PRESELECT_SESSION_KEY, $this->session);
    }

    public function testLocalizedExceptionReturnsToTheCart(): void
    {
        $this->cartButton->method('isAvailable')->willReturn(true);
        $this->checkoutPaymentManager->method('initPayment')
            ->willThrowException(new LocalizedException(new Phrase('The requested Payment Method is not available.')));
        $this->logger->expects($this->once())->method('warning');
        $this->expectCartRedirectWithError();

        $this->controller->execute();

        $this->assertArrayNotHasKey(Start::PRESELECT_SESSION_KEY, $this->session);
    }

    public function testUnexpectedErrorsReturnToTheCart(): void
    {
        $this->cartButton->method('isAvailable')->willReturn(true);
        $this->checkoutPaymentManager->method('initPayment')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error');
        $this->expectCartRedirectWithError();

        $this->controller->execute();
    }

    private function givenInitPaymentReturns(bool $result): void
    {
        $this->cartButton->method('isAvailable')->willReturn(true);
        $this->checkoutPaymentManager->expects($this->once())->method('initPayment')->willReturn($result);
    }

    /**
     * @param array<string, array<string, string>> $steps
     */
    private function givenHyvaCheckoutSteps(array $steps): void
    {
        $this->requireHyvaCheckout();
        $this->moduleManager->method('isEnabled')->with('Hyva_Checkout')->willReturn(true);
        $config = $this->getMockBuilder(self::HYVA_CHECKOUT_CONFIG)
            ->disableOriginalConstructor()
            ->onlyMethods(['getActiveCheckoutData'])
            ->getMock();
        $config->method('getActiveCheckoutData')->willReturn(['name' => 'default', 'steps' => $steps]);
        $this->objectManager->method('get')->with(self::HYVA_CHECKOUT_CONFIG)->willReturn($config);
    }

    private function expectCartRedirectWithError(): void
    {
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with($this->callback(static fn ($message): bool => (string) $message !== ''));
        $this->redirect->expects($this->once())->method('setPath')->with('checkout/cart')->willReturnSelf();
    }

    private function requireHyvaCheckout(): void
    {
        if (!class_exists(self::HYVA_CHECKOUT_CONFIG)) {
            $this->markTestSkipped('Hyvä Checkout is not installed.');
        }
    }
}
