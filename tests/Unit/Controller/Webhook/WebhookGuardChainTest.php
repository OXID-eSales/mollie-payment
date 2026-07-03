<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookGuardChain;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookHttpsGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookIpAllowlistGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookPayloadSizeGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRateLimitGuard;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookGuardChain::class)]
#[CoversClass(WebhookHttpsGuard::class)]
#[CoversClass(WebhookPayloadSizeGuard::class)]
#[CoversClass(WebhookRateLimitGuard::class)]
#[CoversClass(WebhookIpAllowlistGuard::class)]
final class WebhookGuardChainTest extends TestCase
{
    public function testNonHttps_Rejected400(): void
    {
        $chain = new WebhookGuardChain([new WebhookHttpsGuard()]);

        $result = $chain->check($this->request(scheme: 'http'));

        self::assertFalse($result->ok);
        self::assertSame(400, $result->httpStatus);
        self::assertSame('tls_required', $result->reason);
    }

    public function testHttps_Passes(): void
    {
        $chain = new WebhookGuardChain([new WebhookHttpsGuard()]);

        $result = $chain->check($this->request(scheme: 'https'));

        self::assertTrue($result->ok);
    }

    public function testOversizePayload_Rejected413(): void
    {
        $chain = new WebhookGuardChain([new WebhookPayloadSizeGuard(maxBytes: 10)]);

        $result = $chain->check($this->request(rawBody: str_repeat('a', 11)));

        self::assertFalse($result->ok);
        self::assertSame(413, $result->httpStatus);
        self::assertSame('payload_too_large', $result->reason);
    }

    public function testWithinPayloadLimit_Passes(): void
    {
        $chain = new WebhookGuardChain([new WebhookPayloadSizeGuard(maxBytes: 1024)]);

        $result = $chain->check($this->request(rawBody: 'id=tr_abc'));

        self::assertTrue($result->ok);
    }

    public function testRateLimitExceeded_Rejected429(): void
    {
        $guard = new WebhookRateLimitGuard(burst: 1.0, refillRatePerSecond: 0.0);
        $chain = new WebhookGuardChain([$guard]);

        $first = $chain->check($this->request());
        $second = $chain->check($this->request());

        self::assertTrue($first->ok);
        self::assertFalse($second->ok);
        self::assertSame(429, $second->httpStatus);
        self::assertSame('rate_limited', $second->reason);
    }

    public function testChainShortCircuitsOnFirstRejection(): void
    {
        $chain = new WebhookGuardChain([
            new WebhookPayloadSizeGuard(maxBytes: 1024),
            new WebhookHttpsGuard(),
        ]);

        $result = $chain->check($this->request(scheme: 'http'));

        self::assertFalse($result->ok);
        self::assertSame('tls_required', $result->reason);
    }

    public function testChainPassesWhenAllGuardsPass(): void
    {
        $chain = new WebhookGuardChain([
            new WebhookPayloadSizeGuard(),
            new WebhookHttpsGuard(),
            new WebhookRateLimitGuard(),
        ]);

        self::assertTrue($chain->check($this->request())->ok);
    }

    public function testIpAllowlist_DisabledByDefault_AllowsAnyIp(): void
    {
        $guard = new WebhookIpAllowlistGuard();

        self::assertTrue($guard->check($this->request(clientIp: '203.0.113.5'))->ok);
    }

    public function testIpAllowlist_WhenConfigured_RejectsUnknownIp(): void
    {
        $guard = new WebhookIpAllowlistGuard(['198.51.100.0/24']);

        $result = $guard->check($this->request(clientIp: '203.0.113.5'));

        self::assertFalse($result->ok);
        self::assertSame(403, $result->httpStatus);
    }

    public function testIpAllowlist_WhenConfigured_AllowsMatchingCidr(): void
    {
        $guard = new WebhookIpAllowlistGuard(['198.51.100.0/24']);

        self::assertTrue($guard->check($this->request(clientIp: '198.51.100.42'))->ok);
    }

    private function request(
        string $scheme = 'https',
        string $clientIp = '127.0.0.1',
        int $contentLength = 0,
        string $rawBody = 'id=tr_abc',
    ): WebhookRequest {
        return new WebhookRequest($scheme, $clientIp, $contentLength, $rawBody);
    }
}
