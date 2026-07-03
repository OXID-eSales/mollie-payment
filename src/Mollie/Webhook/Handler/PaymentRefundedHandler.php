<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Service\ContractRefundRecorder;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Handles Mollie `refunded` status webhooks.
 *
 * Mollie's payment object already carries the CUMULATIVE `amountRefunded` — the same figure the
 * webhook processor fetched during verification is reused here (no second API round-trip: the
 * fetch-by-id IS the re-fetch). This handler computes the DELTA against what is already recorded
 * on the contract before handing off to {@see ContractRefundRecorder}, which itself only ever
 * accumulates — passing the cumulative total straight through would double-count on a second
 * partial refund.
 */
final class PaymentRefundedHandler implements MollieWebhookEventHandlerInterface
{
    private const STATUS_REFUNDED = 'refunded';

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractRefundRecorder $refundRecorder,
    ) {
    }

    public function handledStatuses(): array
    {
        return [self::STATUS_REFUNDED];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract === null) {
            return MollieWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        $contractId = $contract->getId();
        $delta = $this->extractAmountRefunded($event) - ($contract->getRefundedAmount() ?? 0.0);

        if ($delta <= 0.0) {
            return MollieWebhookOutcome::of(WebhookResult::skipped('No new refund amount to record'), $contractId);
        }

        $this->refundRecorder->record($contract, $delta, $contractId);

        return MollieWebhookOutcome::of(WebhookResult::success('refund_recorded'), $contractId);
    }

    private function extractAmountRefunded(WebhookEvent $event): float
    {
        $value = $event->getObject()['amountRefunded'] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
