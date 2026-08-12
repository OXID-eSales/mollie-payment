<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Handles Mollie `expired` status webhooks.
 */
final class PaymentExpiredHandler extends AbstractMollieWebhookHandler
{
    private const STATUS_EXPIRED = 'expired';

    public function handledStatuses(): array
    {
        return [self::STATUS_EXPIRED];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $result = $this->fulfillmentHandler->handlePaymentExpired($providerOrderId);

        return $this->mapHandlerResult(
            $result,
            $providerOrderId,
            'contract_expired',
            'Contract already in a terminal state',
            $event,
        );
    }
}
