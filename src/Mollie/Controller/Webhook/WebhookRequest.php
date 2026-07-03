<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Normalised HTTP request passed through the guard chain.
 *
 * Keeps the guards free of any coupling to OXID's Request or PHP superglobals — easier to test,
 * easier to port. Distinct from payment-base's `Webhook\WebhookRequest` (which is what
 * {@see \OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor} consumes) — this one carries
 * the raw HTTP transport facts the guards need (scheme, IP, content length) before the payload
 * is ever handed to the processor.
 */
final readonly class WebhookRequest
{
    public function __construct(
        public string $scheme,
        public string $clientIp,
        public int $contentLength,
        public string $rawBody,
    ) {
    }
}
