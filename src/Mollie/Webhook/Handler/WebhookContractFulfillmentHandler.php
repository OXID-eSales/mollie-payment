<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\StaleContractException;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieConfirmedFigures;
use Psr\Log\LoggerInterface;

/**
 * Default {@see WebhookContractFulfillmentHandlerInterface} implementation: one collaborator per Mollie status.
 *
 * - `paid`: {@see PaidPaymentFulfiller} (commit through payment-base's commit chain, book the capture, fulfil);
 * - `authorized`: {@see AuthorizedPaymentCommitter};
 * - `failed` / `expired` / `canceled`: {@see UnpaidPaymentTerminator}.
 *
 * What stays here is what every status shares: the retry when the contract moved under the webhook.
 */
final class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly PaidPaymentFulfiller $paid,
        private readonly AuthorizedPaymentCommitter $authorized,
        private readonly UnpaidPaymentTerminator $terminator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handlePaymentAuthorized(string $providerOrderId, MollieConfirmedFigures $figures): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->authorized->commit($providerOrderId, $figures));
    }

    public function handlePaymentPaid(string $providerOrderId, MollieConfirmedFigures $figures): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->paid->fulfil($providerOrderId, $figures));
    }

    public function handlePaymentFailed(string $providerOrderId, string $reason): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->terminator->fail($providerOrderId, $reason));
    }

    public function handlePaymentExpired(string $providerOrderId): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->terminator->expire($providerOrderId));
    }

    public function handlePaymentCanceled(string $providerOrderId, string $reason): FulfillmentResult
    {
        return $this->attemptTwice(fn () => $this->terminator->cancel($providerOrderId, $reason));
    }

    /**
     * MOL-17: the webhook and the shopper's return leg race on the contract. When a save here is refused
     * because the return leg moved the row on meanwhile, the whole step is run once more - it re-loads
     * the contract, so the ladder continues from the state the return leg left. A second refusal is
     * answered Failed (non-200), and Mollie comes back later with a fresh delivery.
     *
     * @param callable(): FulfillmentResult $step
     */
    private function attemptTwice(callable $step): FulfillmentResult
    {
        try {
            return $step();
        } catch (StaleContractException $first) {
            $this->logger->info(
                '[WebhookContractFulfillmentHandler] contract changed under the webhook; retrying on a fresh copy',
                ['contractId' => $first->contractId],
            );
        }

        try {
            return $step();
        } catch (StaleContractException $second) {
            $this->logger->warning(
                '[WebhookContractFulfillmentHandler] contract changed twice; asking Mollie to retry',
                ['contractId' => $second->contractId],
            );

            return FulfillmentResult::failed($second->contractId);
        }
    }
}
