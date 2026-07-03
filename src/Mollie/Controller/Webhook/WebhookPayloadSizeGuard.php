<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Rejects oversized webhook payloads before they ever reach the fetch-by-id verification round
 * trip. Mollie webhook bodies are a single `id=tr_xxx` field in practice; default cap of 1 MB is
 * generous.
 */
final class WebhookPayloadSizeGuard implements WebhookRequestGuardInterface
{
    public function __construct(private readonly int $maxBytes = 1_048_576)
    {
    }

    public function check(WebhookRequest $request): WebhookGuardResult
    {
        $size = max($request->contentLength, strlen($request->rawBody));

        if ($size <= $this->maxBytes) {
            return WebhookGuardResult::pass();
        }

        return WebhookGuardResult::reject(413, 'payload_too_large');
    }
}
