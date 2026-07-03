<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Base class for the four fulfillment-ladder webhook handlers (paid/failed/expired/canceled).
 *
 * Provides the shared "map a tri-state ?bool onto an outcome" helper so each concrete handler
 * only needs to extract the payment id and pick its success/skip wording.
 */
abstract class AbstractMollieWebhookHandler implements MollieWebhookEventHandlerInterface
{
    public function __construct(
        protected readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        protected readonly ContractRepositoryInterface $contractRepository,
    ) {
    }

    /**
     * Map a tri-state fulfillment-handler result (true/false/null) to a MollieWebhookOutcome.
     *
     * A non-null result means the handler ran against a real contract — resolve and link its ID
     * for the webhook log row, regardless of whether the action ultimately ran or was
     * state-guard skipped.
     */
    protected function mapHandlerResult(
        ?bool $result,
        string $providerOrderId,
        string $successAction,
        string $skipReason,
    ): MollieWebhookOutcome {
        if ($result === null) {
            return MollieWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        $contractId = $this->resolveContractIdFromProviderOrderId($providerOrderId);

        if ($result === true) {
            return MollieWebhookOutcome::of(WebhookResult::success($successAction), $contractId);
        }

        return MollieWebhookOutcome::of(WebhookResult::skipped($skipReason), $contractId);
    }

    protected function resolveContractIdFromProviderOrderId(string $providerOrderId): ?string
    {
        return $this->contractRepository->findByProviderOrderId($providerOrderId)?->getId();
    }
}
