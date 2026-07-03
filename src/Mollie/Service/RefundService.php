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
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;

/**
 * Admin-initiated refund orchestrator (full & partial, accumulating).
 *
 * Deliberately does NOT extend payment-base's `AbstractPaymentRefundService`: that template's
 * `calculateRefundAmounts()` hook derives the refundable ceiling from local arithmetic
 * (`contract->getCapturedAmount()` minus `TransactionRepositoryInterface::getTotalRefundedForContract()`)
 * and depends on the generic, non-segregated `PaymentAdapterInterface`. Sprint 6's own risk
 * analysis requires the refund bound to come from the Mollie payment (API truth) and requires
 * reuse of the ISP-segregated Mollie adapters — mirrors Stripe's `RefundService`, which made the
 * same call for the same reasons (see docs/dev_day_log 20260629 sprint 06 notes).
 *
 * DRY: the actual contract bookkeeping (FULFILLED guard + delta-only accumulation) is delegated
 * to {@see ContractRefundRecorder}, the same collaborator the webhook-driven refund path uses.
 */
final class RefundService implements RefundServiceInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieRefundAdapterInterface $refundAdapter,
        private readonly ContractRefundRecorder $refundRecorder,
    ) {
    }

    public function refund(
        PaymentContractInterface $contract,
        ?float $amount = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): MollieRefundDto {
        $this->assertFulfilled($contract);
        $providerOrderId = $this->requireProviderOrderId($contract);

        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        $refundable = $this->refundableAmount($payment);
        $effectiveAmount = $this->resolveRefundAmount($amount, $refundable, $contract->getId() ?? 'unknown');

        $refund = $this->refundAdapter->createRefund(new RefundRequest(
            $providerOrderId,
            MollieAmountDto::fromComponents($payment->amount->currency, $effectiveAmount),
            $reason,
            $idempotencyKey,
        ));

        $this->refundRecorder->record($contract, (float) $refund->amount->value, $contract->getId());

        return $refund;
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

    private function requireProviderOrderId(PaymentContractInterface $contract): string
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            throw new DomainException(sprintf(
                'Cannot refund contract "%s": no Mollie payment id on record.',
                $contract->getId() ?? 'unknown',
            ));
        }

        return $providerOrderId;
    }

    private function refundableAmount(MolliePaymentDto $payment): float
    {
        return max(0.0, $payment->amount->value - $payment->amountRefunded - $payment->amountChargedBack);
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

        if ($amount > $refundable + 0.00001) {
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
