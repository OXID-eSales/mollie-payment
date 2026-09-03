<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProvider;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Sprint 136 Story 3: one Mollie round trip per panel render.
 *
 * The panel asks four questions of the same payment (capture bound, refund
 * bound, is-it-an-authorized-hold, which method paid). Before this seam each
 * question was its own HTTP call to Mollie.
 */
#[CoversClass(MolliePaymentSnapshotProvider::class)]
#[Group('sprint-136')]
final class MolliePaymentSnapshotProviderTest extends TestCase
{
    public function testReturnsTheLivePayment(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('getPayment')->with('tr_1')->willReturn($this->payment('tr_1'));

        $snapshot = $this->provider($adapter)->snapshot($this->contract('tr_1'));

        self::assertNotNull($snapshot);
        self::assertSame('tr_1', $snapshot->id);
    }

    /**
     * The hard gate of this story: four consumers, one round trip.
     */
    public function testFourReadsOfTheSameContractCostOneApiCall(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::once())->method('getPayment')->willReturn($this->payment('tr_1'));

        $provider = $this->provider($adapter);
        $contract = $this->contract('tr_1');

        for ($i = 0; $i < 4; $i++) {
            self::assertNotNull($provider->snapshot($contract));
        }
    }

    public function testDistinctPaymentsAreCachedSeparately(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::exactly(2))
            ->method('getPayment')
            ->willReturnCallback(fn (string $id): MolliePaymentDto => $this->payment($id));

        $provider = $this->provider($adapter);

        self::assertSame('tr_1', $provider->snapshot($this->contract('tr_1'))?->id);
        self::assertSame('tr_2', $provider->snapshot($this->contract('tr_2'))?->id);
        self::assertSame('tr_1', $provider->snapshot($this->contract('tr_1'))?->id);
    }

    public function testNoProviderOrderIdYieldsNullWithoutCallingMollie(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::never())->method('getPayment');

        $provider = $this->provider($adapter);

        self::assertNull($provider->snapshot($this->contract(null)));
        self::assertNull($provider->snapshot($this->contract('')));
    }

    public function testFailureYieldsNullAndIsLoggedOnce(): void
    {
        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::once())
            ->method('getPayment')
            ->willThrowException(new RuntimeException('mollie down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('could not load the Mollie payment'),
                self::callback(static fn (array $c): bool => $c['contractId'] === 'contract_1'
                    && $c['providerOrderId'] === 'tr_boom'
                    && $c['error'] === 'mollie down'),
            );

        $provider = new MolliePaymentSnapshotProvider($adapter, $logger);
        $contract = $this->contract('tr_boom');

        // A failing payment must not be retried — nor re-logged — once per
        // consumer: that is three round trips into a PSP that is already down
        // and three identical warnings for one incident.
        self::assertNull($provider->snapshot($contract));
        self::assertNull($provider->snapshot($contract));
        self::assertNull($provider->snapshot($contract));
    }

    private function provider(MolliePaymentsAdapterInterface $adapter): MolliePaymentSnapshotProvider
    {
        return new MolliePaymentSnapshotProvider($adapter, new NullLogger());
    }

    private function contract(?string $providerOrderId): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_1');
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);

        return $contract;
    }

    private function payment(string $id): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: $id,
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        );
    }
}
