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
use OxidEsales\Payments\Mollie\Service\ContractRefundRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContractRefundRecorder::class)]
final class ContractRefundRecorderTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractRefundRecorder $recorder;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->recorder = new ContractRefundRecorder($this->contractRepository);
    }

    public function testRecord_AccumulatesRefundOnFulfilledContract(): void
    {
        $contract = $this->fulfilledContract();

        $contract->expects(self::once())->method('addRefundedAmount')->with(25.0);
        $contract->expects(self::once())->method('setRefundedAt');
        $this->contractRepository->expects(self::once())->method('save')->with($contract);

        $this->recorder->record($contract, 25.0);
    }

    public function testRecord_SkipsSilentlyWhenContractNotFulfilled(): void
    {
        $contract = $this->nonFulfilledContract();

        $contract->expects(self::never())->method('addRefundedAmount');
        $contract->expects(self::never())->method('setRefundedAt');
        $this->contractRepository->expects(self::never())->method('save');

        $this->recorder->record($contract, 25.0, 'contract-abc');
    }

    public function testRecord_AcceptsZeroDeltaOnFulfilledContract(): void
    {
        $contract = $this->fulfilledContract();

        $contract->expects(self::once())->method('addRefundedAmount')->with(0.0);
        $this->contractRepository->expects(self::once())->method('save');

        $this->recorder->record($contract, 0.0);
    }

    private function fulfilledContract(): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        return $contract;
    }

    private function nonFulfilledContract(): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getStateValue')->willReturn('committed');

        return $contract;
    }
}
