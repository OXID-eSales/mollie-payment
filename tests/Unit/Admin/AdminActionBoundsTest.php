<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds as ProviderBounds;
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

    public function testRefundBound_PartiallyCapturedPayment_IsBoundedByTheCapturedAmount(): void
    {
        // The panel displayed the authorized 100.00 as refundable although only 60.00 was
        // ever captured — money that never settled cannot be refunded.
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_partial');
        $this->paymentsAdapter->method('getPayment')->with('tr_partial')->willReturn(new MolliePaymentDto(
            id: 'tr_partial',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRefunded: 10.0,
            amountCaptured: 60.0,
        ));

        self::assertSame(50.0, $this->bounds->refundBound($contract));
    }

    public function testCaptureBound_IsTheLivePaymentsCapturableAmount(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_2');
        $contract->method('getAmount')->willReturn(100.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_2')->willReturn(new MolliePaymentDto(
            id: 'tr_2',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            // MOL-32: Mollie reports the captured total; 70 captured → 30 still capturable.
            amountCaptured: 70.0,
        ));

        self::assertSame(30.0, $this->bounds->captureBound($contract));
    }

    // ---- Capture section stale after a capture (2026-10-01): the contract knows what was captured ----

    public function testCaptureBound_AfterAFullCaptureRecordedLocally_IsZeroWhileMollieStillShowsTheHold(): void
    {
        $contract = $this->capturedContract('tr_full', amount: 100.0, captured: 100.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_full')->willReturn(new MolliePaymentDto(
            id: 'tr_full',
            status: 'authorized',
            // Mollie still shows the whole hold (nothing captured at Mollie yet) while the shop
            // already recorded the capture locally — the contract is the stricter source.
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));

        self::assertSame(0.0, $this->bounds->captureBound($contract), 'what the shop captured is never offered again');
    }

    public function testCaptureBound_AfterAPartialCaptureRecordedLocally_IsTheLocalRemainder(): void
    {
        $contract = $this->capturedContract('tr_part', amount: 100.0, captured: 40.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_part')->willReturn(new MolliePaymentDto(
            id: 'tr_part',
            status: 'authorized',
            // Mollie still shows the whole hold; the shop's local 40 capture caps the bound to 60.
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
        ));

        self::assertSame(60.0, $this->bounds->captureBound($contract));
    }

    public function testCaptureBound_NeverExceedsWhatMollieSaysIsCapturable(): void
    {
        $contract = $this->capturedContract('tr_cap', amount: 100.0, captured: 0.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_cap')->willReturn(new MolliePaymentDto(
            id: 'tr_cap',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            // MOL-32: 70 captured at Mollie → 30 still capturable; the live figure caps the bound.
            amountCaptured: 70.0,
        ));

        self::assertSame(30.0, $this->bounds->captureBound($contract));
    }

    // ---- MOL-30 (2026-10-09): the Refund section right after a capture - the contract knows it captured ----

    public function testRefundBound_AfterALocalCaptureWhileMollieStillShowsTheHold_IsTheCapturedAmount(): void
    {
        // Right after "Execute capture" Mollie's payment still reads `authorized`, captured 0.00 and no
        // refundable remainder, so the live figure alone hid the Refund section until a reload.
        $contract = $this->capturedContract('tr_fresh', amount: 20.9, captured: 20.9, refunded: 0.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_fresh')->willReturn(new MolliePaymentDto(
            id: 'tr_fresh',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 20.9),
            amountCaptured: 0.0,
        ));

        self::assertSame(20.9, $this->bounds->refundBound($contract));
    }

    public function testRefundBound_AfterALocalCaptureAndALocalRefund_IsTheLocalRemainder(): void
    {
        $contract = $this->capturedContract('tr_fresh2', amount: 100.0, captured: 60.0, refunded: 15.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_fresh2')->willReturn(new MolliePaymentDto(
            id: 'tr_fresh2',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountCaptured: 0.0,
        ));

        self::assertSame(45.0, $this->bounds->refundBound($contract));
    }

    public function testRefundBound_OnceMollieHasSettled_NeverExceedsMolliesOwnFigure(): void
    {
        // A refund booked in Mollie's Dashboard is unknown to the shop: Mollie's smaller figure wins.
        $contract = $this->capturedContract('tr_settled', amount: 100.0, captured: 100.0, refunded: 0.0);
        $this->paymentsAdapter->method('getPayment')->with('tr_settled')->willReturn(new MolliePaymentDto(
            id: 'tr_settled',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRemaining: 70.0,
            amountCaptured: 100.0,
        ));

        self::assertSame(70.0, $this->bounds->refundBound($contract));
    }

    public function testRefundBound_WhenPaymentLookupFails_IsZero(): void
    {
        // Fail-closed, like the capture bound: no live payment, no action offered.
        $contract = $this->capturedContract('tr_down', amount: 100.0, captured: 100.0, refunded: 0.0);
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('boom'));

        self::assertSame(0.0, $this->bounds->refundBound($contract));
    }

    private function capturedContract(
        string $providerOrderId,
        float $amount,
        float $captured,
        ?float $refunded = null,
    ): PaymentContractInterface {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCapturedAmount')->willReturn($captured);
        $contract->method('getRefundedAmount')->willReturn($refunded);

        return $contract;
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

    // ── PAY-5: the bounds payment-base's admin API offers and enforces ──────────────────────────

    public function testBoundsFor_AreTheLiveCaptureRemainderAndTheLiveRefundableAmount(): void
    {
        $contract = $this->contract('tr_multi', amount: 100.0, captured: 40.0, refunded: 0.0);
        $this->paymentsAdapter->method('getPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_multi',
            status: 'authorized',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountCaptured: 40.0,
        ));

        $bounds = $this->bounds->boundsFor($contract);

        self::assertSame(60.0, $bounds?->capturable, 'multi-capture: what is left of the authorization, not all or nothing');
        self::assertSame(40.0, $bounds->refundable, 'the shop recorded 40 captured while Mollie still books it');
        self::assertTrue($bounds->available);
    }

    public function testBoundsFor_ARefundAfterADashboardRefund_ReadsTheLiveRemainder(): void
    {
        $contract = $this->contract('tr_dash', amount: 100.0, captured: 100.0, refunded: 0.0);
        $this->paymentsAdapter->method('getPayment')->willReturn(new MolliePaymentDto(
            id: 'tr_dash',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRemaining: 70.0,
        ));

        $bounds = $this->bounds->boundsFor($contract);

        self::assertSame(70.0, $bounds?->refundable, 'the refund made in the Mollie dashboard is not offered twice');
        self::assertSame(0.0, $bounds->capturable);
    }

    public function testBoundsFor_MollieCannotBeRead_IsUnavailableNotZero(): void
    {
        $this->paymentsAdapter->method('getPayment')->willThrowException(new \RuntimeException('down'));

        $bounds = $this->bounds->boundsFor($this->contract('tr_down', amount: 100.0, captured: null, refunded: null));

        self::assertEquals(ProviderBounds::unavailable(), $bounds);
        self::assertFalse($bounds->available);
    }

    public function testBoundsFor_NoMolliePaymentYet_LeavesPaymentBasesOwnNumbers(): void
    {
        $this->paymentsAdapter->expects(self::never())->method('getPayment');

        self::assertNull($this->bounds->boundsFor($this->contract(null, amount: 100.0, captured: null, refunded: null)));
    }

    private function contract(?string $providerOrderId, float $amount, ?float $captured, ?float $refunded): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCapturedAmount')->willReturn($captured);
        $contract->method('getRefundedAmount')->willReturn($refunded);

        return $contract;
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
