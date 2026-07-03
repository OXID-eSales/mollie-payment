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
 * Handles Mollie `canceled` status webhooks.
 */
final class PaymentCanceledHandler extends AbstractMollieWebhookHandler
{
    private const STATUS_CANCELED = 'canceled';
    private const CANCELLATION_REASON = 'Mollie payment canceled';

    public function handledStatuses(): array
    {
        return [self::STATUS_CANCELED];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $result = $this->fulfillmentHandler->handlePaymentCanceled($providerOrderId, self::CANCELLATION_REASON);

        return $this->mapHandlerResult(
            $result,
            $providerOrderId,
            'contract_canceled',
            'Contract already in a terminal state',
        );
    }
}
