<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Refund\ProviderChargebackLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Handles Mollie `chargedback` status webhooks.
 *
 * VR-21 S-M6: Mollie reports the CUMULATIVE `amountChargedBack` with every status webhook and has no
 * chargeback id, so the total goes to payment-base's chargeback ledger, which books what changed
 * (30 then 50 is +30, +20; a replay books nothing). Before, every delivery recorded the whole total
 * again. Audit only: no automatic reversal of the contract's
 * fulfilled/captured state is performed — dispute resolution is a merchant/operator decision
 * (full dispute handling is out of scope for this sprint; see Sprint 8 stretch goals).
 */
final class ChargebackCreatedHandler implements MollieWebhookEventHandlerInterface
{
    private const STATUS_CHARGEDBACK = 'chargedback';

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ProviderChargebackLedgerInterface $ledger,
    ) {
    }

    public function handledStatuses(): array
    {
        return [self::STATUS_CHARGEDBACK];
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

        $booking = $this->ledger->book(
            (string) $contract->getId(),
            null,
            $this->extractAmountChargedBack($event),
            $contract->getCurrency(),
            'webhook'
        );
        if (!$booking->booked) {
            return MollieWebhookOutcome::of(WebhookResult::skipped('No new chargeback to record'), $contract->getId());
        }

        return MollieWebhookOutcome::of(WebhookResult::success('chargeback_recorded'), $contract->getId());
    }

    private function extractAmountChargedBack(WebhookEvent $event): float
    {
        $value = $event->getObject()['amountChargedBack'] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
