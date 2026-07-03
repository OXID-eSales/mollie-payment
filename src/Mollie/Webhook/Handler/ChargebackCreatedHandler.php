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
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Service\TransactionAuditRecorder;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Handles Mollie `chargedback` status webhooks.
 *
 * Records the chargeback as an audit transaction only. No automatic reversal of the contract's
 * fulfilled/captured state is performed — dispute resolution is a merchant/operator decision
 * (full dispute handling is out of scope for this sprint; see Sprint 8 stretch goals).
 */
final class ChargebackCreatedHandler implements MollieWebhookEventHandlerInterface
{
    private const STATUS_CHARGEDBACK = 'chargedback';

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly TransactionAuditRecorder $auditRecorder,
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

        $amount = $this->extractAmountChargedBack($event);
        $this->auditRecorder->record(
            $contract,
            MollieDefinitions::TRANSACTION_TYPE_CHARGEBACK,
            MollieDefinitions::TRANSACTION_STATUS_COMPLETED,
            $amount,
        );

        return MollieWebhookOutcome::of(WebhookResult::success('chargeback_recorded'), $contract->getId());
    }

    private function extractAmountChargedBack(WebhookEvent $event): float
    {
        $value = $event->getObject()['amountChargedBack'] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
