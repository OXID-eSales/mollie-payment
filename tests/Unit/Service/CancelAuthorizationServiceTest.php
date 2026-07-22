<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\CancelAuthorizationService;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CancelAuthorizationService::class)]
final class CancelAuthorizationServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractLinkedOrderUpdaterInterface&MockObject $orderUpdater;
    private CancelAuthorizationService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);

        $this->service = new CancelAuthorizationService(
            $this->paymentsAdapter,
            $this->contractRepository,
            $this->orderUpdater,
        );
    }

    public function testCancel_UncapturedAuthorization_CallsCancelPayment_CancelsContract(): void
    {
        $contract = $this->authorizedContract('tr_123', 'order-1');

        $this->paymentsAdapter->expects(self::once())->method('cancelPayment')->with('tr_123')
            ->willReturn(new MolliePaymentDto('tr_123', 'canceled', MollieAmountDto::fromComponents('EUR', 10.0)));
        $contract->expects(self::once())->method('cancel')->with('mollie_authorization_canceled');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-1');

        $this->service->cancel($contract);
    }

    public function testCancel_UsesGivenReason(): void
    {
        $contract = $this->authorizedContract('tr_123', 'order-1');
        $this->paymentsAdapter->method('cancelPayment')
            ->willReturn(new MolliePaymentDto('tr_123', 'canceled', MollieAmountDto::fromComponents('EUR', 10.0)));

        $contract->expects(self::once())->method('cancel')->with('fraud_suspected');

        $this->service->cancel($contract, 'fraud_suspected');
    }

    public function testCancel_CommittedContract_CancelsAuthorization(): void
    {
        // Manual-capture order committed by the shared return chain while the Mollie payment is
        // still an uncaptured `authorized` hold: the admin may still void it.
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(false);
        $state->method('isCommitted')->willReturn(true);
        $state->method('isCancelled')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn('tr_c');
        $contract->method('getOrderId')->willReturn('order-c');
        $contract->method('getId')->willReturn('7');

        $this->paymentsAdapter->expects(self::once())->method('cancelPayment')->with('tr_c')
            ->willReturn(new MolliePaymentDto('tr_c', 'canceled', MollieAmountDto::fromComponents('EUR', 10.0)));
        $contract->expects(self::once())->method('cancel');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-c');

        $this->service->cancel($contract);
    }

    public function testCancel_WhenNotInCancellableState_Rejected(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(false);
        $state->method('isCommitted')->willReturn(false);
        $state->method('isCancelled')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getId')->willReturn('5');

        $this->paymentsAdapter->expects(self::never())->method('cancelPayment');
        $contract->expects(self::never())->method('cancel');

        $this->expectException(\DomainException::class);

        $this->service->cancel($contract);
    }

    public function testCancel_WhenAlreadyCancelled_IsIdempotentNoOp(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(false);
        $state->method('isCancelled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        $this->paymentsAdapter->expects(self::never())->method('cancelPayment');
        $contract->expects(self::never())->method('cancel');

        $this->service->cancel($contract);
    }

    private function authorizedContract(string $providerOrderId, string $orderId): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isAuthorized')->willReturn(true);
        $state->method('isCancelled')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getId')->willReturn('5');

        return $contract;
    }
}
