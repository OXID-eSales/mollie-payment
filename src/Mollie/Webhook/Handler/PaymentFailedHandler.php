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
 * Handles Mollie `failed` status webhooks.
 */
final class PaymentFailedHandler extends AbstractMollieWebhookHandler
{
    private const STATUS_FAILED = 'failed';
    private const FAILURE_REASON = 'Mollie payment failed';

    public function handledStatuses(): array
    {
        return [self::STATUS_FAILED];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $result = $this->fulfillmentHandler->handlePaymentFailed($providerOrderId, self::FAILURE_REASON);

        return $this->mapHandlerResult(
            $result,
            $providerOrderId,
            'contract_failed',
            'Contract already in a terminal state',
        );
    }
}
