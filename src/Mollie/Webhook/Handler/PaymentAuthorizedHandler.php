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
 * Handles Mollie `authorized` status webhooks — the two-step/manual-capture path.
 *
 * This is the handler that closes the previously-unreachable AUTHORIZED contract state: it
 * advances the contract just far enough for the admin panel's Capture/Cancel-authorization
 * actions ({@see \OxidEsales\Payments\Mollie\Service\CaptureService},
 * {@see \OxidEsales\Payments\Mollie\Service\CancelAuthorizationService}) to apply. It never sets
 * OXPAID or fulfills the order — that only happens once the funds are actually captured, via the
 * `paid` status handled by {@see PaymentPaidHandler}.
 */
final class PaymentAuthorizedHandler extends AbstractMollieWebhookHandler
{
    private const STATUS_AUTHORIZED = 'authorized';

    public function handledStatuses(): array
    {
        return [self::STATUS_AUTHORIZED];
    }

    public function handle(WebhookEvent $event): MollieWebhookOutcome
    {
        $providerOrderId = $event->getObjectId();
        if ($providerOrderId === null) {
            return MollieWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing Mollie payment id'));
        }

        $result = $this->fulfillmentHandler->handlePaymentAuthorized($providerOrderId);

        return $this->mapHandlerResult(
            $result,
            $providerOrderId,
            'contract_authorized',
            'Contract already authorized or not eligible for authorization',
        );
    }
}
