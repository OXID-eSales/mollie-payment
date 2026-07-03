<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\CaptureRequest;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieCaptureDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieCaptureAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;

/**
 * Admin-initiated capture of an authorized two-step Mollie payment (card/Klarna).
 *
 * Same design choice as {@see RefundService}: implements a focused interface rather than
 * extending payment-base's `AbstractPaymentCaptureService`, whose default `afterCapture()` hook
 * calls `$contract->fulfill()` — wrong transition for Mollie's two-step ladder, which advances
 * AUTHORIZED → READY_TO_COMMIT via `captureAuthorization()` and leaves final FULFILLED to the
 * existing webhook/commit path. `CaptureNotSupportedException` (thrown by the adapter when the
 * live Mollie payment isn't in `authorized` status) is intentionally left uncaught here — it is
 * the caller-facing signal that the payment method doesn't support two-step capture.
 */
final class CaptureService implements CaptureServiceInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieCaptureAdapterInterface $captureAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
    ) {
    }

    public function capture(
        PaymentContractInterface $contract,
        ?float $amount = null,
        ?string $idempotencyKey = null,
    ): MollieCaptureDto {
        $this->assertAuthorized($contract);
        $providerOrderId = $this->requireProviderOrderId($contract);

        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        $capturable = $this->capturableAmount($payment);
        $this->assertWithinCapturable($amount, $capturable, $contract->getId() ?? 'unknown');

        $capture = $this->captureAdapter->createCapture(new CaptureRequest(
            $providerOrderId,
            $amount !== null ? MollieAmountDto::fromComponents($payment->amount->currency, $amount) : null,
            $idempotencyKey,
        ));

        $this->applyCapture($contract, (float) $capture->amount->value);

        return $capture;
    }

    private function assertAuthorized(PaymentContractInterface $contract): void
    {
        if ($contract->getState()->isAuthorized()) {
            return;
        }

        throw new DomainException(sprintf(
            'Cannot capture contract "%s": not in an authorized (two-step) state.',
            $contract->getId() ?? 'unknown',
        ));
    }

    private function requireProviderOrderId(PaymentContractInterface $contract): string
    {
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId === null || $providerOrderId === '') {
            throw new DomainException(sprintf(
                'Cannot capture contract "%s": no Mollie payment id on record.',
                $contract->getId() ?? 'unknown',
            ));
        }

        return $providerOrderId;
    }

    private function capturableAmount(MolliePaymentDto $payment): float
    {
        return $payment->amountRemaining > 0.0 ? $payment->amountRemaining : $payment->amount->value;
    }

    private function assertWithinCapturable(?float $amount, float $capturable, string $contractId): void
    {
        if ($amount === null) {
            return;
        }

        if ($amount <= 0.0) {
            throw new InvalidArgumentException('Capture amount must be positive.');
        }

        if ($amount > $capturable + 0.00001) {
            throw new InvalidArgumentException(sprintf(
                'Cannot capture %.2f on contract "%s": only %.2f is authorized.',
                $amount,
                $contractId,
                $capturable,
            ));
        }
    }

    private function applyCapture(PaymentContractInterface $contract, float $capturedValue): void
    {
        $existing = $contract->getCapturedAmount() ?? 0.0;
        $contract->setCapturedAmount($existing + $capturedValue);
        $contract->setCapturedAt(new DateTimeImmutable());
        $contract->captureAuthorization();
        $this->contractRepository->save($contract);
    }
}
