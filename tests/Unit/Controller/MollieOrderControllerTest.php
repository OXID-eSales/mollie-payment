<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Mollie\Controller\MollieOrderController;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MollieOrderController::class)]
final class MollieOrderControllerTest extends TestCase
{
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
