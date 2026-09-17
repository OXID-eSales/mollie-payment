<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\Return\PendingReturnProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PendingReturnProbe::class)]
final class PendingReturnProbeTest extends TestCase
{
    public function testIsPendingWhenContractHasNoProviderOrderIdReturnsFalse(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::never())->method('getPayment');

        $probe = new PendingReturnProbe($adapter, new MollieStatusMapper());

        self::assertFalse($probe->isPending($this->contractStub(null)));
        self::assertFalse($probe->isPending($this->contractStub('')));
    }

    public function testIsPendingWhenMollieReportsPendingStatusReturnsTrue(): void
    {
        $probe = new PendingReturnProbe(
            $this->adapterReturning(MollieStatusMapper::STATUS_OPEN),
            new MollieStatusMapper(),
        );

        self::assertTrue($probe->isPending($this->contractStub('tr_1')));
    }

    public function testIsPendingWhenMollieReportsPaidStatusReturnsFalse(): void
    {
        $probe = new PendingReturnProbe(
            $this->adapterReturning(MollieStatusMapper::STATUS_PAID),
            new MollieStatusMapper(),
        );

        self::assertFalse($probe->isPending($this->contractStub('tr_1')));
    }

    public function testIsPendingWhenAdapterThrowsFailsClosedToFalse(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('getPayment')->willThrowException(new RuntimeException('api down'));

        $probe = new PendingReturnProbe($adapter, new MollieStatusMapper());

        self::assertFalse($probe->isPending($this->contractStub('tr_1')));
    }

    private function adapterReturning(string $status): MolliePaymentsAdapterInterface
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('getPayment')->willReturn(
            MolliePaymentDto::fromArray(['id' => 'tr_1', 'status' => $status]),
        );

        return $adapter;
    }

    private function contractStub(?string $providerOrderId): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);

        return $contract;
    }
}
