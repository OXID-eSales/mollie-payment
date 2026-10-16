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
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
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
 * extending payment-base's `AbstractPaymentCaptureService`.
 *
 * Capturable-state policy (mirrors Stripe's STRP-118 fix): both AUTHORIZED and COMMITTED
 * contracts may be captured. A manual-capture order is driven to COMMITTED by the shared
 * checkout-return chain (it never visits AUTHORIZED when Stripe/PayPal/OPC are co-active), so
 * the live Mollie payment status — re-validated by the adapter, which throws
 * `CaptureNotSupportedException` when the payment isn't in `authorized` status — is the real
 * guard, not contract state. The post-capture transition branches on the current state:
 * AUTHORIZED → READY_TO_COMMIT via `captureAuthorization()` (standalone ladder, webhook
 * fulfills); COMMITTED → FULFILLED via the shared `ContractFulfillmentService` (dispatches
 * ContractFulfilledEvent → stamps OXPAID).
 */
final class CaptureService implements CaptureServiceInterface
{
    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly MollieCaptureAdapterInterface $captureAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly CaptureCompletionPolicyInterface $completionPolicy,
    ) {
    }

    public function capture(
        PaymentContractInterface $contract,
        ?float $amount = null,
        ?string $idempotencyKey = null,
    ): MollieCaptureDto {
        $this->assertCapturable($contract);
        $providerOrderId = ProviderOrderId::requireFrom($contract, 'capture');

        $payment = $this->paymentsAdapter->getPayment($providerOrderId);
        // One formula, one place (Sprint 11 Story 10 / F11) — this service used to carry its own
        // copy, so the status-awareness fix would have had to be made twice.
        $capturable = $payment->capturableAmount();
        $this->assertWithinCapturable($amount, $capturable, $contract->getId() ?? 'unknown');

        $capture = $this->captureAdapter->createCapture(new CaptureRequest(
            $providerOrderId,
            $amount !== null ? MollieAmountDto::fromComponents($payment->amount->currency, $amount) : null,
            $idempotencyKey,
        ));

        $this->applyCapture($contract, (float) $capture->amount->value);

        return $capture;
    }

    private function assertCapturable(PaymentContractInterface $contract): void
    {
        if ($contract->getState()->isAuthorized() || $contract->getState()->isCommitted()) {
            return;
        }

        throw new DomainException(sprintf(
            'Cannot capture contract "%s": not in a capturable (authorized/committed) state.',
            $contract->getId() ?? 'unknown',
        ));
    }


    private function assertWithinCapturable(?float $amount, float $capturable, string $contractId): void
    {
        if ($amount === null) {
            return;
        }

        if ($amount <= 0.0) {
            throw new InvalidArgumentException('Capture amount must be positive.');
        }

        if (MoneyComparison::exceeds($amount, $capturable)) {
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
        $cumulativeCaptured = ($contract->getCapturedAmount() ?? 0.0) + $capturedValue;
        $contract->setCapturedAmount($cumulativeCaptured);
        $contract->setCapturedAt(new DateTimeImmutable());

        $remainingToCapture = round($contract->getAmount() - $cumulativeCaptured, 2);

        // MOL-32: on a multi-capture account a capture that leaves an authorized remainder must NOT
        // complete the order — record it and keep the contract in its capturable state (AUTHORIZED
        // or COMMITTED) so the next capture is allowed and OXPAID is not stamped for a part payment.
        if ($this->completionPolicy->classify($remainingToCapture) === CaptureCompletion::PartialCaptureRemains) {
            $this->contractRepository->save($contract);

            return;
        }

        // Fully captured (or a single-capture account, where the first capture is final).
        if ($contract->getState()->isAuthorized()) {
            // Standalone ladder (headless/GraphQL): AUTHORIZED → READY_TO_COMMIT; the webhook fulfils.
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);

            return;
        }

        // COMMITTED (manual-capture order already finalized by the shared return chain):
        // capturing the funds completes fulfillment — COMMITTED → FULFILLED, which stamps
        // OXPAID via the ContractFulfilledEvent the fulfillment service dispatches.
        $this->contractRepository->save($contract);
        $this->contractFulfillmentService->fulfill($contract);
    }
}
