<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Per-IP token bucket. Prevents a broken (or hostile) webhook source from hammering the
 * fetch-by-id verification path. Each allowed request consumes one token; tokens refill at
 * `$refillRatePerSecond`, bucket capacity capped at `$burst`.
 *
 * In-memory only (per PHP-FPM worker) — fine for single-node deployments; multi-node production
 * deployments should supply a shared cache backend instead.
 */
final class WebhookRateLimitGuard implements WebhookRequestGuardInterface
{
    /**
     * @var array<string, array{tokens: float, updatedAt: float}>
     */
    private array $buckets = [];

    public function __construct(
        private readonly float $burst = 60.0,
        private readonly float $refillRatePerSecond = 1.0,
    ) {
    }

    public function check(WebhookRequest $request): WebhookGuardResult
    {
        $key = $request->clientIp !== '' ? $request->clientIp : 'unknown';
        $now = microtime(true);
        $bucket = $this->buckets[$key] ?? ['tokens' => $this->burst, 'updatedAt' => $now];

        $elapsed = max(0.0, $now - $bucket['updatedAt']);
        $tokens = min($this->burst, $bucket['tokens'] + $elapsed * $this->refillRatePerSecond);

        if ($tokens < 1.0) {
            $this->buckets[$key] = ['tokens' => $tokens, 'updatedAt' => $now];

            return WebhookGuardResult::reject(429, 'rate_limited');
        }

        $this->buckets[$key] = ['tokens' => $tokens - 1.0, 'updatedAt' => $now];

        return WebhookGuardResult::pass();
    }
}
