<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use DateTimeImmutable;
use Exception;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Base class for the five fulfillment-ladder webhook handlers (paid/authorized/failed/expired/
 * canceled).
 *
 * Owns the mapping from a {@see FulfillmentOutcome} onto a `WebhookResult`, which is the same thing
 * as deciding what HTTP status Mollie sees — and therefore whether Mollie retries. Sprint 11
 * Stories 1 + 3 (F2).
 */
abstract class AbstractMollieWebhookHandler implements MollieWebhookEventHandlerInterface
{
    /**
     * How long a missing contract is treated as "our own commit may still be in flight" rather than
     * "there will never be a contract for this payment".
     *
     * Mollie can call the webhook before the request that created the payment has committed its
     * contract row, so a young payment with no contract must be retried. Past this window, retrying
     * forever is a self-inflicted storm for payments that legitimately have no contract here — one
     * created by another system on the same Mollie account, a hand-deleted contract, a restored
     * backup. Ten minutes is a deliberate over-estimate of "our transaction should have landed by
     * now"; it is a const rather than a setting until a shop is shown to need it configurable.
     */
    protected const CONTRACT_WAIT_WINDOW_SECONDS = 600;

    // The repository left this ctor with review A-M2 (2026-10-14): the FulfillmentResult now
    // carries the contract id, so the base class has nothing left to look up.
    public function __construct(
        protected readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Map what the fulfilment handler did onto the response Mollie will act on.
     *
     * - `Acted`            → success (200)
     * - `NoOp`             → skipped (200): the work was already done, a retry would change nothing
     * - `Failed`           → failure (5xx): retry may succeed
     * - `ContractNotFound` → age-dependent, see {@see self::CONTRACT_WAIT_WINDOW_SECONDS}
     */
    protected function mapHandlerResult(
        FulfillmentResult $result,
        string $providerOrderId,
        string $successAction,
        string $noOpReason,
        WebhookEvent $event,
    ): MollieWebhookOutcome {
        // The fulfilment handler already loaded the contract and carries its id in the result
        // (review 2026-10-14, A-M2) — no second repository lookup per delivery.
        return match ($result->outcome) {
            FulfillmentOutcome::ContractNotFound => $this->contractNotFoundOutcome($providerOrderId, $event),
            FulfillmentOutcome::Acted => MollieWebhookOutcome::of(
                WebhookResult::success($successAction),
                $result->contractId,
            ),
            FulfillmentOutcome::NoOp => MollieWebhookOutcome::of(
                WebhookResult::skipped($noOpReason),
                $result->contractId,
            ),
            FulfillmentOutcome::Failed => MollieWebhookOutcome::of(
                WebhookResult::failure(
                    'fulfillment_failed',
                    sprintf('Could not apply the Mollie status to contract for payment %s', $providerOrderId),
                ),
                $result->contractId,
            ),
        };
    }

    /**
     * Overridable clock seam so the age boundary is testable without sleeping.
     */
    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    private function contractNotFoundOutcome(string $providerOrderId, WebhookEvent $event): MollieWebhookOutcome
    {
        if ($this->paymentIsYoungEnoughToWaitFor($event)) {
            return MollieWebhookOutcome::of(WebhookResult::failure(
                'contract_not_found',
                sprintf(
                    'No contract for Mollie payment %s yet; asking Mollie to redeliver.',
                    $providerOrderId,
                ),
            ));
        }

        $this->logger->warning(
            '[MollieWebhook] dropping delivery: no contract for this Mollie payment and the payment '
            . 'is older than the wait window',
            ['providerOrderId' => $providerOrderId, 'eventType' => $event->type],
        );

        return MollieWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
    }

    /**
     * An absent or unparseable `createdAt` counts as old: an unbounded retry loop is the worse
     * failure, and the drop is logged either way.
     */
    private function paymentIsYoungEnoughToWaitFor(WebhookEvent $event): bool
    {
        $createdAt = $event->getObject()['createdAt'] ?? null;
        if (!is_string($createdAt) || $createdAt === '') {
            return false;
        }

        try {
            $created = new DateTimeImmutable($createdAt);
        } catch (Exception) {
            return false;
        }

        return $this->now()->getTimestamp() - $created->getTimestamp() < self::CONTRACT_WAIT_WINDOW_SECONDS;
    }
}
