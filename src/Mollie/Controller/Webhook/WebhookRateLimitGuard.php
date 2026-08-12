<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

/**
 * Per-IP token bucket — **NOT wired into the default guard chain, and it must not be re-wired as
 * it stands.**
 *
 * The arithmetic below is correct, but `$buckets` is instance state on a request-scoped service in a
 * share-nothing PHP process: every request starts with an empty bucket map, spends one of `$burst`
 * tokens and throws the map away. It can only reject if `check()` were called more than `$burst`
 * times *within a single request*, which never happens. The previous docblock claimed "in-memory
 * only (per PHP-FPM worker) — fine for single-node deployments"; both halves were wrong (the state
 * is per-*request*), and a guard that reads as protection while providing none is worse than an
 * absent one, because it satisfies a reviewer looking for a rate limit. See Sprint 11 Story 6 / F6.
 *
 * Sprint 11 removed it from `services.yaml`'s chain rather than leaving the claim standing. The
 * exposure it was aiming at — an unauthenticated POST costing us an outbound Mollie API round-trip —
 * is now closed by {@see WebhookController::extractPaymentId()}'s stateless id-shape precheck, which
 * has no state to rot.
 *
 * Before re-wiring this class, give it a shared backend (APCu, the shop cache, a counter keyed by
 * IP+minute). Note that a counter table is not an option: Mollie owns no migrations (CLAUDE.md).
 * That is a decided-storage-backend task, not a docblock edit.
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
