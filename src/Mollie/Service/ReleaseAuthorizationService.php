<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Service;

use DomainException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;

/**
 * MOL-32 S6 — releases the remaining authorized amount of a two-step Mollie payment
 * (`release-authorization`), keeping whatever was already captured.
 *
 * Distinct from {@see CancelAuthorizationService}: cancel deletes the whole *uncaptured* hold;
 * release gives back only the remainder after one or more partial captures and finalises the order
 * with the captured amount. Follows the focused-interface design of {@see CaptureService}/
 * {@see RefundService} rather than extending a payment-base template.
 *
 * Mollie releases the *full* remaining amount (there is no partial release), so after this call
 * nothing is left to capture. The contract is then finalised by what was captured:
 *  - captured &gt; 0 → the order is settled with the captured amount (COMMITTED → fulfilled, or the
 *    AUTHORIZED ladder advanced for the webhook to fulfil);
 *  - captured == 0 → releasing the whole hold is a cancellation.
 *
 * Idempotent: a terminal contract returns without touching the Mollie API; Mollie re-validates and
 * rejects a payment that cannot be released (e.g. already finalised), surfaced by the handler.
 */
final class ReleaseAuthorizationService implements ReleaseAuthorizationServiceInterface
{
    private const RELEASE_REASON = 'mollie_authorization_released';

    public function __construct(
        private readonly MolliePaymentsAdapterInterface $paymentsAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
    ) {
    }

    public function release(PaymentContractInterface $contract, ?string $reason = null): void
    {
        if ($contract->getState()->isTerminal()) {
            return;
        }

        $this->assertReleasable($contract);
        $providerOrderId = ProviderOrderId::requireFrom($contract, 'release');

        $this->paymentsAdapter->releaseAuthorization($providerOrderId);

        if (($contract->getCapturedAmount() ?? 0.0) > 0.0) {
            $this->finaliseWithCapturedAmount($contract);

            return;
        }

        // Nothing captured: releasing the whole authorization is a cancellation.
        $contract->cancel($reason ?? self::RELEASE_REASON);
        $this->contractRepository->save($contract);
        $this->mirrorCancellation($contract);
    }

    private function assertReleasable(PaymentContractInterface $contract): void
    {
        // AUTHORIZED or COMMITTED (STRP-118 pattern — a manual-capture order is committed by the
        // shared return chain while its Mollie payment is still an authorized hold).
        if ($contract->getState()->isAuthorized() || $contract->getState()->isCommitted()) {
            return;
        }

        throw new DomainException(sprintf(
            'Cannot release contract "%s": not in a releasable (authorized/committed) state.',
            $contract->getId() ?? 'unknown',
        ));
    }

    private function finaliseWithCapturedAmount(PaymentContractInterface $contract): void
    {
        if ($contract->getState()->isAuthorized()) {
            // Standalone ladder: AUTHORIZED → READY_TO_COMMIT; the webhook fulfils.
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);

            return;
        }

        // COMMITTED: the captured funds settle the order — COMMITTED → FULFILLED (stamps OXPAID
        // via ContractFulfilledEvent).
        $this->contractRepository->save($contract);
        $this->contractFulfillmentService->fulfill($contract);
    }

    private function mirrorCancellation(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->orderUpdater->markCancelled($orderId);
    }
}
