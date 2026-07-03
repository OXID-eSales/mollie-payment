<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;

/**
 * Mollie-local handler interface for webhook event processing.
 *
 * Routing is by mapped status string (see {@see MollieWebhookProcessor::determineEventType()}),
 * not by a raw Mollie payload field — Mollie webhooks carry no event type at all, only a
 * payment id. Tagged `mollie.webhook_handler` in services.yaml and collected by
 * MollieWebhookProcessor via `!tagged_iterator`. OCP: a new status is handled by registering a
 * new handler, never by editing the processor.
 */
interface MollieWebhookEventHandlerInterface
{
    /**
     * Statuses this handler processes (e.g. ['paid'], ['refunded']). Exact string match against
     * the event's `type`.
     *
     * @return list<string>
     */
    public function handledStatuses(): array;

    /**
     * Handle the webhook event and return the outcome including any resolved contractId.
     */
    public function handle(WebhookEvent $event): MollieWebhookOutcome;
}
