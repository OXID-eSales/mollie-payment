<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Short-circuits on the first guard that rejects. Guard order is fixed at wiring time
 * (services.yaml): cheapest checks first so a malicious/broken request never reaches the
 * expensive ones (currently: payload size → HTTPS → rate limit; IP allowlist is built but not
 * wired by default — see {@see WebhookIpAllowlistGuard}).
 */
final class WebhookGuardChain implements WebhookRequestGuardInterface
{
    /**
     * @param list<WebhookRequestGuardInterface> $guards
     */
    public function __construct(private readonly array $guards)
    {
    }

    public function check(WebhookRequest $request): WebhookGuardResult
    {
        foreach ($this->guards as $guard) {
            $result = $guard->check($request);
            if (!$result->ok) {
                return $result;
            }
        }

        return WebhookGuardResult::pass();
    }
}
