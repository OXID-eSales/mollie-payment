<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\PaymentBase\Validation\RateLimit\InMemoryRateLimitStore;
use OxidEsales\PaymentBase\Validation\RateLimit\RateLimitStoreInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRateLimitGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Review 2026-10-14 finding D-M1 — the webhook endpoint had no effective rate limit: the old
 * token-bucket guard kept per-request state (could never reject, Sprint 11 unwired it) and every
 * `tr_`-shaped id bought an authenticated Mollie API round-trip. The reworked guard counts in
 * payment-base's shared RateLimitStore (Doctrine, oe_payments_idempotency — no new table), so a
 * flood from one source is rejected with 429 before the API re-fetch spends quota.
 */
#[CoversClass(WebhookRateLimitGuard::class)]
final class WebhookRateLimitGuardTest extends TestCase
{
    public function testRequestsOverTheWindowLimitAreRejected429(): void
    {
        $guard = $this->guard(limitPerWindow: 2);

        self::assertTrue($guard->check($this->request())->ok);
        self::assertTrue($guard->check($this->request())->ok);

        $third = $guard->check($this->request());

        self::assertFalse($third->ok);
        self::assertSame(429, $third->httpStatus);
        self::assertSame('rate_limited', $third->reason);
    }

    public function testDistinctSourceIpsAreCountedSeparately(): void
    {
        $guard = $this->guard(limitPerWindow: 1);

        self::assertTrue($guard->check($this->request(ip: '5.5.5.5'))->ok);
        self::assertFalse($guard->check($this->request(ip: '5.5.5.5'))->ok);
        self::assertTrue($guard->check($this->request(ip: '6.6.6.6'))->ok, 'another source has its own budget');
    }

    public function testTheCounterResetsInTheNextWindow(): void
    {
        $now = 1_000_000;
        $guard = $this->guard(limitPerWindow: 1, windowSeconds: 60, clock: function () use (&$now): int {
            return $now;
        });

        self::assertTrue($guard->check($this->request())->ok);
        self::assertFalse($guard->check($this->request())->ok, 'budget spent inside the window');

        $now += 60;

        self::assertTrue($guard->check($this->request())->ok, 'a new window starts a new count');
    }

    public function testAnEmptyClientIpStillConsumesABudget(): void
    {
        $guard = $this->guard(limitPerWindow: 1);

        self::assertTrue($guard->check($this->request(ip: ''))->ok);
        self::assertFalse($guard->check($this->request(ip: ''))->ok, 'no free pass for a missing REMOTE_ADDR');
    }

    /**
     * DoctrineRateLimitStore answers 0 when the database is unavailable. A broken limiter must
     * not become a webhook outage: deliveries still carry money-deciding statuses, and the
     * endpoint's actual authentication is the API re-fetch. Fail open, never closed.
     */
    public function testStorageFailureFailsOpen(): void
    {
        $failing = $this->createStub(RateLimitStoreInterface::class);
        $failing->method('increment')->willReturn(0);

        $guard = new WebhookRateLimitGuard($failing, limitPerWindow: 1);

        self::assertTrue($guard->check($this->request())->ok);
        self::assertTrue($guard->check($this->request())->ok);
    }

    private function guard(int $limitPerWindow, int $windowSeconds = 60, ?callable $clock = null): WebhookRateLimitGuard
    {
        return new WebhookRateLimitGuard(
            new InMemoryRateLimitStore(),
            $limitPerWindow,
            $windowSeconds,
            $clock === null ? null : \Closure::fromCallable($clock),
        );
    }

    private function request(string $ip = '5.5.5.5'): WebhookRequest
    {
        return new WebhookRequest('https', $ip, 10, 'id=tr_x');
    }
}
