<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook\Handler;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieConfirmedFigures;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;

/**
 * Handles Mollie `paid` status webhooks — the event that finalizes an order.
 */
final class PaymentPaidHandler extends AbstractMollieWebhookHandler
{
    private const STATUS_PAID = 'paid';

    public function handledStatuses(): array
    {
        return [self::STATUS_PAID];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $figures = MollieConfirmedFigures::fromEventObject($event->getObject());
        if ($figures === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure(
                'provider_amount_missing',
                sprintf('Mollie payment %s carries no amount or currency; nothing is committed', $providerOrderId),
            ));
        }

        $result = $this->fulfillmentHandler->handlePaymentPaid($providerOrderId, $figures);

        return $this->mapHandlerResult(
            $result,
            $providerOrderId,
            'contract_fulfilled',
            'Contract already fulfilled',
            $event,
        );
    }
}
