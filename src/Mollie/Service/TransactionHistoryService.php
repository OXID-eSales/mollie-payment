<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Service\Result\TransactionRow;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assembles the admin Transaction History read-model straight from the Mollie API — parity with
 * Stripe's "display from API, audit from DB" strategy documented in the Sprint 7 plan. A payment
 * plus its captures and refunds are fetched independently: a hiccup fetching refunds/captures
 * degrades the row set instead of hiding the payment row entirely (an operator still sees
 * *something* useful rather than a blank panel).
 */
final class TransactionHistoryService implements TransactionHistoryServiceInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieRefundAdapterInterface $refundAdapter,
        private readonly MollieCaptureAdapterInterface $captureAdapter,
        private readonly MollieStatusMapper $statusMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(PaymentContractInterface $contract): array
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            return [];
        }

        $payment = $this->safeGetPayment($providerOrderId);
        if ($payment === null) {
            return [];
        }

        $rows = [$this->paymentRow($payment)];
        foreach ($this->safeListCaptures($providerOrderId) as $capture) {
            $rows[] = $this->captureRow($capture);
        }
        foreach ($this->safeListRefunds($providerOrderId) as $refund) {
            $rows[] = $this->refundRow($refund);
        }

        return $rows;
    }

    private function paymentRow(MolliePaymentDto $payment): TransactionRow
    {
        return new TransactionRow(
            TransactionRow::TYPE_PAYMENT,
            $payment->id,
            $payment->amount->value,
            $payment->amount->currency,
            $payment->status,
            $this->statusMapper->map($payment->status),
            $payment->createdAt,
        );
    }

    private function captureRow(MollieCaptureDto $capture): TransactionRow
    {
        return new TransactionRow(
            TransactionRow::TYPE_CAPTURE,
            $capture->id,
            $capture->amount->value,
            $capture->amount->currency,
            $capture->status,
            $this->statusMapper->map($capture->status),
            $capture->createdAt,
        );
    }

    private function refundRow(MollieRefundDto $refund): TransactionRow
    {
        return new TransactionRow(
            TransactionRow::TYPE_REFUND,
            $refund->id,
            $refund->amount->value,
            $refund->amount->currency,
            $refund->status,
            $this->statusMapper->map($refund->status),
            $refund->createdAt,
        );
    }

    private function safeGetPayment(string $providerOrderId): ?MolliePaymentDto
    {
        try {
            return $this->paymentsAdapter->getPayment($providerOrderId);
        } catch (Throwable $e) {
            // Sprint 11 Story 8: degrading the captures/refunds lists is deliberate and documented,
            // but losing the payment itself means a BLANK panel — the operator sees no transactions
            // at all and nothing distinguishes that from "there are none".
            $this->logger->warning(
                '[TransactionHistoryService] could not load the Mollie payment; the admin transaction '
                . 'panel will render empty',
                ['providerOrderId' => $providerOrderId, 'error' => $e->getMessage()],
            );

            return null;
        }
    }

    /**
     * @return list<MollieCaptureDto>
     */
    private function safeListCaptures(string $providerOrderId): array
    {
        try {
            return $this->captureAdapter->listCaptures($providerOrderId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<MollieRefundDto>
     */
    private function safeListRefunds(string $providerOrderId): array
    {
        try {
            return $this->refundAdapter->listRefunds($providerOrderId);
        } catch (Throwable) {
            return [];
        }
    }
}
