<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Controller\Webhook;

use Closure;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;

/**
 * Per-source fixed-window counter in SHARED storage — wired into the guard chain since
 * 2026-10-14 (review finding D-M1).
 *
 * History: the first version kept token buckets in per-request instance state and could never
 * reject anything, so Sprint 11 (F6) unwired it rather than let it read as protection. The
 * stateless `tr_` id-shape precheck in {@see WebhookController::extractPaymentId()} filters
 * garbage, but any well-shaped id still buys an authenticated outbound `getPayment` call —
 * a flood of forged ids exhausts the merchant's Mollie API quota and starves legitimate
 * webhook verification and checkout payment creation. Closing that needs a counter that
 * survives the request, which is exactly payment-base's {@see RateLimitStoreInterface}:
 * the Doctrine store reuses `oe_payments_idempotency` (no new table, no migration — Mollie
 * owns none per CLAUDE.md) and is registered by payment-base's own services.yaml, so this
 * module only consumes the shared binding.
 *
 * Semantics:
 *  - Key: sha1 of the REMOTE_ADDR (hashed so no raw IP lands in the table) + the window
 *    index. An empty REMOTE_ADDR shares the 'unknown' budget — no free pass.
 *  - Fixed window: a burst straddling the boundary can reach 2x the limit; acceptable for
 *    quota protection and the price of needing only increment-and-read.
 *  - Fail OPEN: the Doctrine store answers 0 when the database is unavailable. Deliveries
 *    carry money-deciding statuses and the endpoint's actual authentication is the API
 *    re-fetch, so a broken limiter must not become a webhook outage.
 *  - Order: wired after the payload-size and HTTPS guards — those are free, this one costs
 *    a DB round-trip.
 */
final class WebhookRateLimitGuard implements WebhookRequestGuardInterface
{
    /**
     * @param ?Closure(): int $clock test seam; production uses time()
     */
    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly int $limitPerWindow = 120,
        private readonly int $windowSeconds = 60,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function check(WebhookRequest $request): WebhookGuardResult
    {
        $source = $request->clientIp !== '' ? $request->clientIp : 'unknown';
        $window = intdiv($this->now(), $this->windowSeconds);
        $key = sprintf('mollie:webhook:%s:%d', sha1($source), $window);

        // TTL of two windows: the counter must outlive its own window, and the store may
        // only reset on read-after-expiry.
        $count = $this->store->increment($key, $this->windowSeconds * 2);

        if ($count === 0) {
            return WebhookGuardResult::pass();
        }

        if ($count > $this->limitPerWindow) {
            return WebhookGuardResult::reject(429, 'rate_limited');
        }

        return WebhookGuardResult::pass();
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
