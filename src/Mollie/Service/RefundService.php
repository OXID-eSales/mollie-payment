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
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use Psr\Log\LoggerInterface;

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
 *
 * Story 1 (Sprint 9): Stock restoration on admin refund. When a refund succeeds, stock is restored
 * for the associated order articles via {@see StockRestorationServiceInterface}.
 */
final class RefundService implements RefundServiceInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieRefundAdapterInterface $refundAdapter,
        private readonly ContractRefundRecorder $refundRecorder,
        private readonly StockRestorationServiceInterface $stockRestorationService,
        private readonly LoggerInterface $logger,
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
        $providerOrderId = $this->requireProviderOrderId($contract);


        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        // Single source of truth for the ceiling (Sprint 11 Story 4 / F21): this service used to
        // carry its own copy of the formula, so a fix to one was not a fix to the other.
        $refundable = $payment->refundableAmount();
        $effectiveAmount = $this->resolveRefundAmount($amount, $refundable, $contract->getId() ?? 'unknown');

        // Story 3 (Sprint 9): Optional admin description for audit trail.
        // Stored in Mollie's refund metadata for retrieval.
        $refund = $this->refundAdapter->createRefund(new RefundRequest(
            $providerOrderId,
            MollieAmountDto::fromComponents($payment->amount->currency, $effectiveAmount),
            $reason,
            $idempotencyKey,
            $description,
        ));

        $this->refundRecorder->record($contract, (float) $refund->amount->value, $contract->getId());

        $this->restoreStockIfOrderLinked($contract);

        return $refund;
    }

    /**
     * Restore stock for the order if one is linked to the contract.
     * Silently skips if no order ID is present (order may not be created yet
     * in edge cases).
     */
    private function restoreStockIfOrderLinked(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $articlesProcessed = $this->stockRestorationService->restoreStockForOrder($orderId);
        $this->logger->info('Stock restored after refund', [
            'contractId' => $contract->getId(),
            'orderId' => $orderId,
            'articlesProcessed' => $articlesProcessed,
        ]);
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
