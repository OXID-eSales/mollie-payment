<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequest;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequestGuardInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;

/**
 * Testable subclass: overrides every Registry/container-touching seam so render() can be
 * exercised as a pure unit (mirrors MollieOrderController's TestableMollieOrderController and
 * Stripe's TestableWebhookControllerForGuard).
 */
final class TestableWebhookController extends WebhookController
{
    private WebhookRequest $testGuardRequest;

    public function __construct(
        ?MollieWebhookProcessor $processor,
        private readonly ?WebhookRequestGuardInterface $testGuard,
        private readonly ?string $testPaymentId,
        ?WebhookRequest $guardRequest = null,
        private readonly ?FileLoggerInterface $testFileLogger = null,
        private readonly bool $testTrustProxyHeaders = false,
    ) {
        $this->processor = $processor;
        $this->testGuardRequest = $guardRequest ?? new WebhookRequest('https', '127.0.0.1', 0, 'id=tr_x');
    }

    public function init(): void
    {
        // Intentionally empty — avoids ContainerFactory::getInstance() in unit tests.
    }

    protected function setResponseContentType(): void
    {
        // No-op in tests.
    }

    protected function getGuard(): ?WebhookRequestGuardInterface
    {
        return $this->testGuard;
    }

    protected function getFileLogger(): ?FileLoggerInterface
    {
        return $this->testFileLogger;
    }

    protected function buildGuardRequest(): WebhookRequest
    {
        return $this->testGuardRequest;
    }

    /**
     * Only the Registry touch-point is stubbed, so the real `extractPaymentId()` — including
     * Sprint 11's id-shape precheck (F6) — runs under test.
     */
    protected function readRawPaymentId(): ?string
    {
        return $this->testPaymentId;
    }

    protected function trustProxyHeaders(): bool
    {
        return $this->testTrustProxyHeaders;
    }

    /**
     * Exposes the pure scheme resolution for the proxy-header trust tests (F5).
     *
     * @param array<array-key, mixed> $server
     */
    public static function schemeFor(array $server, bool $trustProxyHeaders): string
    {
        return self::resolveScheme($server, $trustProxyHeaders);
    }

    protected function sendResponse(int $status, string $action): never
    {
        throw new WebhookResponseSent($status, $action);
    }
}
