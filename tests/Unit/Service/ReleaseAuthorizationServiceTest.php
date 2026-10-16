<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use DomainException;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Mollie\Service\ReleaseAuthorizationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * MOL-32 S6 — release the remaining authorized amount after one or more partial captures (Mollie's
 * `release-authorization`). Distinct from cancel (which deletes the whole uncaptured hold): release
 * keeps what was captured and gives the rest back, finalising the order.
 */
#[CoversClass(ReleaseAuthorizationService::class)]
final class ReleaseAuthorizationServiceTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $fulfillmentService;
    private ContractLinkedOrderUpdaterInterface&MockObject $orderUpdater;
    private ReleaseAuthorizationService $service;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);

        $this->service = new ReleaseAuthorizationService(
            $this->paymentsAdapter,
            $this->contractRepository,
            $this->fulfillmentService,
            $this->orderUpdater,
        );
    }

    public function testRelease_PartiallyCapturedCommitted_ReleasesRemainderAndCompletesTheOrder(): void
    {
        $contract = $this->contract(committed: true, captured: 40.0, providerOrderId: 'tr_r1');

        $this->paymentsAdapter->expects(self::once())->method('releaseAuthorization')->with('tr_r1');
        // 40 was captured → the order is settled with the captured amount; the remainder is gone.
        $this->fulfillmentService->expects(self::once())->method('fulfill')->with($contract);
        $this->orderUpdater->expects(self::never())->method('markCancelled');

        $this->service->release($contract);
    }

    public function testRelease_PartiallyCapturedAuthorized_AdvancesTheLadder(): void
    {
        $contract = $this->contract(committed: false, captured: 40.0, providerOrderId: 'tr_r2');

        $this->paymentsAdapter->expects(self::once())->method('releaseAuthorization')->with('tr_r2');
        $contract->expects(self::once())->method('captureAuthorization');
        $this->fulfillmentService->expects(self::never())->method('fulfill');

        $this->service->release($contract);
    }

    public function testRelease_NothingCaptured_IsACancellation(): void
    {
        $contract = $this->contract(committed: true, captured: 0.0, providerOrderId: 'tr_r3');

        $this->paymentsAdapter->expects(self::once())->method('releaseAuthorization')->with('tr_r3');
        // Nothing was captured → releasing the whole hold cancels the order.
        $contract->expects(self::once())->method('cancel');
        $this->fulfillmentService->expects(self::never())->method('fulfill');
        $this->orderUpdater->expects(self::once())->method('markCancelled')->with('order-9');

        $this->service->release($contract);
    }

    public function testRelease_AlreadyTerminalContract_IsIdempotentAndTouchesNoApi(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(true);
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');

        $this->service->release($contract);
    }

    public function testRelease_NonReleasableState_Rejected(): void
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(false);
        $state->method('isAuthorized')->willReturn(false);
        $state->method('isCommitted')->willReturn(false);
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getId')->willReturn('9');

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');
        $this->expectException(DomainException::class);

        $this->service->release($contract);
    }

    public function testRelease_NoProviderOrderId_Rejected(): void
    {
        $contract = $this->contract(committed: true, captured: 40.0, providerOrderId: null);

        $this->paymentsAdapter->expects(self::never())->method('releaseAuthorization');
        $this->expectException(DomainException::class);

        $this->service->release($contract);
    }

    private function contract(bool $committed, float $captured, ?string $providerOrderId): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isTerminal')->willReturn(false);
        $state->method('isAuthorized')->willReturn(!$committed);
        $state->method('isCommitted')->willReturn($committed);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getCapturedAmount')->willReturn($captured);
        $contract->method('getOrderId')->willReturn('order-9');
        $contract->method('getId')->willReturn('9');

        return $contract;
    }
}
