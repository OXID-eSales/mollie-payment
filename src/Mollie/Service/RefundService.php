<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DomainException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\Exception\MollieRefundNotYetAvailableException;

/**
 * Admin-initiated refund orchestrator (full & partial, accumulating).
 *
 * Deliberately does NOT extend payment-base's `AbstractPaymentRefundService`: that template's
 * `calculateRefundAmounts()` hook derives the refundable ceiling from local arithmetic
 * (`contract->getCapturedAmount()` minus `TransactionRepositoryInterface::getTotalRefundedForContract()`)
 * and depends on the generic, non-segregated `PaymentAdapterInterface`. Sprint 6's own risk
 * analysis requires the refund bound to come from the Mollie payment (API truth) and requires
 * reuse of the ISP-segregated Mollie adapters — mirrors Stripe's `RefundService`, which made the
 * same call for the same reasons (see docs/dev_day_log 20260629 sprint 06 notes). MOL-30 refined
 * "API truth": while Mollie still books a capture its payment reports nothing settled, so the
 * ceiling is {@see RefundBound} — Mollie's figure once settled, the shop's record until then.
 *
 * VR-21 F6: the refund is booked through payment-base's ledger by its Mollie refund id - the same
 * id the `refunded` webhook books, so the second report is a duplicate. The ledger's transaction row
 * is the one the admin's Payment tab lists.
 *
 * VR-21 F3: whether the refund succeeded goes to the ledger with the booking; the ledger's policy
 * alone decides about stock (back only when a succeeded refund leaves nothing refundable), so this
 * class restocks nothing.
 */
final class RefundService implements RefundServiceInterface
{
    private const REFUND_FAILED = 'failed';
    private const REFUND_CANCELED = 'canceled';

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieRefundAdapterInterface $refundAdapter,
        private readonly ProviderRefundLedgerInterface $ledger,
    ) {
    }

    public function refund(
        PaymentContractInterface $contract,
        ?float $amount = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?string $description = null,
    ): MollieRefundDto {
        $this->assertFulfilled($contract);
        $providerOrderId = ProviderOrderId::requireFrom($contract, 'refund');


        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        // Single source of truth for the ceiling (Sprint 11 Story 4 / F21, MOL-30): the same rule
        // the admin panel offers - {@see RefundBound} - so the form never offers what is refused.
        $refundable = RefundBound::of($contract, $payment);
        $effectiveAmount = $this->resolveRefundAmount($amount, $refundable, $contract->getId() ?? 'unknown');

        // Story 3 (Sprint 9): Optional admin description for audit trail.
        // Stored in Mollie's refund metadata for retrieval.
        $request = new RefundRequest(
            $providerOrderId,
            MollieAmountDto::fromComponents($payment->amount->currency, $effectiveAmount),
            $reason,
            $idempotencyKey,
            $description,
        );
        $refund = $this->createRefund($request, $payment);

        if ($this->wasRun($refund)) {
            $this->ledger->book(
                (string) $contract->getId(),
                $refund->id,
                $refund->amount->value,
                $refund->amount->currency,
                'admin',
                $refund->hasSucceeded()
            );
        }

        return $refund;
    }

    /**
     * MOL-30: in the seconds after "Execute capture" Mollie's payment still reads `authorized`;
     * the shop knows it captured and offers the refund, but Mollie may refuse it until the capture
     * has settled. That refusal is named, so the admin is told to try again in a moment rather
     * than shown a generic failure. Any other refusal propagates as before.
     */
    private function createRefund(RefundRequest $request, MolliePaymentDto $payment): MollieRefundDto
    {
        try {
            return $this->refundAdapter->createRefund($request);
        } catch (MollieAdapterException $refusal) {
            if ($payment->status === MollieStatusMapper::STATUS_AUTHORIZED) {
                throw MollieRefundNotYetAvailableException::whileSettling($request->paymentId, $refusal);
            }

            throw $refusal;
        }
    }

    /**
     * A refund Mollie refused to run (failed / canceled) moved no money; a pending one is the
     * normal start of a refund at Mollie.
     */
    private function wasRun(MollieRefundDto $refund): bool
    {
        return !in_array($refund->status, [self::REFUND_FAILED, self::REFUND_CANCELED], true);
    }

    private function assertFulfilled(PaymentContractInterface $contract): void
    {
        if ($contract->getState()->isFulfilled()) {
            return;
        }

        throw new DomainException(sprintf(
            'Cannot refund contract "%s": only FULFILLED (captured) payments can be refunded.',
            $contract->getId() ?? 'unknown',
        ));
    }


    private function resolveRefundAmount(?float $amount, float $refundable, string $contractId): float
    {
        if ($amount === null) {
            if ($refundable <= 0.0) {
                throw new InvalidArgumentException(sprintf(
                    'Nothing left to refund on contract "%s".',
                    $contractId,
                ));
            }

            return $refundable;
        }

        if ($amount <= 0.0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        if (MoneyComparison::exceeds($amount, $refundable)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot refund %.2f on contract "%s": only %.2f is refundable.',
                $amount,
                $contractId,
                $refundable,
            ));
        }

        return $amount;
    }
}
