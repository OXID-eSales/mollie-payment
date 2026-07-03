<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Rejects any non-TLS request in production. An opt-in dev-mode flag lets local HTTP work when
 * debugging on a non-TLS localhost.
 */
final class WebhookHttpsGuard implements WebhookRequestGuardInterface
{
    public function __construct(private readonly bool $allowHttpForDevelopment = false)
    {
    }

    public function check(WebhookRequest $request): WebhookGuardResult
    {
        if (strtolower($request->scheme) === 'https') {
            return WebhookGuardResult::pass();
        }

        if ($this->allowHttpForDevelopment) {
            return WebhookGuardResult::pass();
        }

        return WebhookGuardResult::reject(400, 'tls_required');
    }
}
