<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBounds;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentSnapshotProvider;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(AdminActionBounds::class)]
final class AdminActionBoundsTest extends TestCase
{
    private MolliePaymentsAdapterInterface&MockObject $paymentsAdapter;
    private AdminActionBounds $bounds;

    protected function setUp(): void
    {
        $this->paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->bounds = $this->boundsWith($this->paymentsAdapter, new NullLogger());
    }

    public function testRefundBound_IsTheLivePaymentsRefundableAmount(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_1');
        $this->paymentsAdapter->method('getPayment')->with('tr_1')->willReturn(new MolliePaymentDto(
            id: 'tr_1',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRefunded: 40.0,
        ));

        self::assertSame(60.0, $this->bounds->refundBound($contract));
    }

    public function testCaptureBound_IsTheLivePaymentsCapturableAmount(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_2');
        $this->paymentsAdapter->method('getPayment')->with('tr_2')->willReturn(new MolliePaymentDto(
            id: 'tr_2',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRemaining: 30.0,
        ));

        self::assertSame(30.0, $this->bounds->captureBound($contract));
    }

    public function testRefundBound_WithoutProviderOrderId_IsZero(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);

        self::assertSame(0.0, $this->bounds->refundBound($contract));
    }

    public function testCaptureBound_WhenPaymentLookupFails_IsZero(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_3');
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('boom'));

        self::assertSame(0.0, $this->bounds->captureBound($contract));
    }

    public function testIsAuthorizedHold_WhenPaymentAuthorized_True(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_a');
        $this->paymentsAdapter->method('getPayment')->with('tr_a')->willReturn(new MolliePaymentDto(
            id: 'tr_a',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));

        self::assertTrue($this->bounds->isAuthorizedHold($contract));
    }

    public function testIsAuthorizedHold_WhenPaymentCaptured_False(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_p');
        $this->paymentsAdapter->method('getPayment')->with('tr_p')->willReturn(new MolliePaymentDto(
            id: 'tr_p',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));

        self::assertFalse($this->bounds->isAuthorizedHold($contract));
    }

    public function testIsAuthorizedHold_WhenLookupFails_False(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_x');
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('boom'));

        self::assertFalse($this->bounds->isAuthorizedHold($contract));
    }

    // -------------------------------------------------------------------------
    // Sprint 136 Story 3: characterization net for the snapshot-seam refactor.
    // Written against the pre-refactor implementation; these assert BEHAVIOUR
    // (the warning that reaches the log, the round trip that is never made),
    // not the fact that a line ran.
    // -------------------------------------------------------------------------

    /**
     * A 0.00 bound reads identically for "already fully refunded" and "Mollie is
     * unreachable", and an operator can stop investigating at the first reading.
     * The warning is the only thing that separates them, so it is load-bearing.
     */
    public function testLookupFailureIsLoggedWithContractAndProviderOrderId(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_77');
        $contract->method('getProviderOrderId')->willReturn('tr_boom');

        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->method('getPayment')->willThrowException(new \RuntimeException('mollie down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('could not load the Mollie payment'),
                self::callback(static fn (array $context): bool => $context['contractId'] === 'contract_77'
                    && $context['providerOrderId'] === 'tr_boom'
                    && $context['error'] === 'mollie down'),
            );

        self::assertSame(0.0, $this->boundsWith($adapter, $logger)->refundBound($contract));
    }

    public function testEmptyProviderOrderIdNeverReachesMollie(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('');

        $adapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $adapter->expects(self::never())->method('getPayment');

        $bounds = $this->boundsWith($adapter, new NullLogger());

        self::assertSame(0.0, $bounds->refundBound($contract));
        self::assertSame(0.0, $bounds->captureBound($contract));
        self::assertFalse($bounds->isAuthorizedHold($contract));
    }

    public function testMissingProviderOrderIdIsNotLoggedAsAFailure(): void
    {
        // No provider order id is an ordinary early-lifecycle state, not an
        // incident — warning on it would train operators to ignore the channel.
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertSame(
            0.0,
            $this->boundsWith($this->createMock(MolliePaymentsAdapterInterface::class), $logger)
                ->refundBound($contract)
        );
    }

    /**
     * Single construction seam for this test class: Story 3 swaps what
     * AdminActionBounds is composed of (adapter+logger → memoizing snapshot
     * provider) and every assertion above must survive that swap untouched.
     */
    private function boundsWith(
        MolliePaymentsAdapterInterface $adapter,
        LoggerInterface $logger,
    ): AdminActionBounds {
        return new AdminActionBounds(new MolliePaymentSnapshotProvider($adapter, $logger));
    }
}
