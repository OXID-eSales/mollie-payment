<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieCheckoutSessionRequestEvent;
use OxidEsales\Payments\Mollie\Tests\Unit\Support\PreloadsModuleClassChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MollieOrderController::class)]
final class MollieOrderControllerTest extends TestCase
{
    use PreloadsModuleClassChain;

    // TestableMollieOrderController extends the concrete MollieOrderController chain member — build
    // the chain first so instantiating it does not re-enter ModuleChainsGenerator (see trait).
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preloadModuleClassChain('order');
    }

    public function testExecuteWhenMollieSelectedDispatchesCheckoutSessionRequestEventAndRedirects(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(MollieCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function (MollieCheckoutSessionRequestEvent $event) {
                $event->getContext()->set('checkoutUrl', 'https://mollie.test/checkout/tr_1');
                return $event;
            });

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $result = $controller->execute();

        self::assertNull($result);
        self::assertSame(['https://mollie.test/checkout/tr_1'], $controller->redirectedTo);
        self::assertFalse($controller->delegatedToParent);
        self::assertFalse($controller->unavailableErrorShown);
    }

    public function testExecuteWhenNonMollieMethodDelegatesToParentSoTheOrderFinalizesNormally(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $controller = $this->executeController('oxidcashondel', $dispatcher);

        $controller->execute();

        self::assertTrue($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenDispatcherUnavailableShowsErrorAndDoesNotFinalize(): void
    {
        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, null);

        $result = $controller->execute();

        self::assertSame('payment', $result);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertFalse($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenDispatchThrowsShowsErrorAndDoesNotFinalize(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('boom'));

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $result = $controller->execute();

        self::assertSame('payment', $result);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertFalse($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    public function testExecuteWhenNoCheckoutUrlShowsErrorAndDoesNotFinalize(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        // MollieCheckoutSessionHandler already failed the contract in this scenario; the
        // context simply carries no checkoutUrl back to the controller.
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $controller = $this->executeController(MollieDefinitions::PAYMENT_ID, $dispatcher);

        $result = $controller->execute();

        self::assertSame('payment', $result);
        self::assertTrue($controller->unavailableErrorShown);
        self::assertFalse($controller->delegatedToParent);
        self::assertSame([], $controller->redirectedTo);
    }

    private function executeController(
        string $paymentId,
        ?EventDispatcherInterface $dispatcher,
    ): TestableMollieOrderController {
        return new TestableMollieOrderController(
            requestParams: [],
            tokenService: $this->createMock(TokenServiceInterface::class),
            contractRepository: $this->createMock(ContractRepositoryInterface::class),
            resolver: null,
            responder: null,
            paymentId: $paymentId,
            dispatcher: $dispatcher,
        );
    }

    public function testCheckoutReturnWithValidTokenDispatchesReturnFlowAndGoesToThankyou(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $resolver = $this->createMock(ReturnResolverInterface::class);

        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::once())
            ->method('respond')
            ->with(MollieDefinitions::PROVIDER_NAME, $contract, $resolver, [])
            ->willReturn('order-42');

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: $contract,
            resolver: $resolver,
            responder: $responder,
        );

        self::assertSame('thankyou', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWithTamperedTokenIsRejected(): void
    {
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::never())->method('respond');

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'bad-token'],
            tokenValid: false,
            responder: $responder,
        );

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWithMissingParametersIsRejected(): void
    {
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::never())->method('respond');

        $controller = $this->controller(requestParams: [], tokenValid: false, responder: $responder);

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWithUnknownContractIsRejected(): void
    {
        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->expects(self::never())->method('respond');

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-missing', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: null,
            responder: $responder,
        );

        self::assertSame('payment', $controller->checkoutReturn());
    }

    public function testCheckoutReturnWhenResponderReturnsNullIsTreatedAsUnfinalised(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $resolver = $this->createMock(ReturnResolverInterface::class);

        $responder = $this->createMock(CheckoutReturnResponder::class);
        $responder->method('respond')->willReturn(null);

        $controller = $this->controller(
            requestParams: ['contract_id' => 'contract-1', 'contract_token' => 'good-token'],
            tokenValid: true,
            contract: $contract,
            resolver: $resolver,
            responder: $responder,
        );

        self::assertSame('payment', $controller->checkoutReturn());
    }

    /**
     * @param array<string, string> $requestParams
     */
    private function controller(
        array $requestParams,
        bool $tokenValid,
        ?PaymentContractInterface $contract = null,
        ?ReturnResolverInterface $resolver = null,
        ?CheckoutReturnResponder $responder = null,
    ): TestableMollieOrderController {
        $tokenService = $this->createMock(TokenServiceInterface::class);
        $tokenService->method('validateToken')->willReturn($tokenValid);

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findById')->willReturn($contract);

        return new TestableMollieOrderController(
            $requestParams,
            $tokenService,
            $contractRepository,
            $resolver,
            $responder,
        );
    }
}
