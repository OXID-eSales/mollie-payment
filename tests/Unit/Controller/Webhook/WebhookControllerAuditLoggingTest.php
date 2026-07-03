<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequestGuardInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 8 Story 2 — the webhook endpoint writes to the level-gated file-audit-trail channel
 * (see {@see \OxidEsales\Payments\Mollie\Service\Factory\MollieWebhookFileLoggerFactory}) both
 * when a guard rejects a request and when the processor finishes. When no file logger is wired
 * (level "off" resolves a NullFileLogger upstream, or the container has none), render() must
 * still behave exactly as before — the audit call is purely additive.
 */
#[CoversClass(WebhookController::class)]
final class WebhookControllerAuditLoggingTest extends TestCase
{
    public function testGuardRejection_IsWrittenToFileAuditLog(): void
    {
        $spy = new SpyFileLogger();
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::reject(429, 'rate_limited'));

        $controller = new TestableWebhookController(
            processor: null,
            testGuard: $guard,
            testPaymentId: 'tr_x',
            testFileLogger: $spy,
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent) {
            // expected — assertions below.
        }

        self::assertCount(1, $spy->entries);
        self::assertSame('rate_limited', $spy->entries[0]['context']['reason']);
    }

    public function testSuccessfulProcessing_IsWrittenToFileAuditLog(): void
    {
        $spy = new SpyFileLogger();
        $logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $logRepository->method('claimEvent')->willReturn(true);

        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willReturn(new MolliePaymentDto(
            id: 'tr_paid',
            status: 'paid',
            amount: new MollieAmountDto('EUR', 10.0),
        ));

        $processor = new MollieWebhookProcessor(
            $logRepository,
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $controller = new TestableWebhookController(
            processor: $processor,
            testGuard: null,
            testPaymentId: 'tr_paid',
            testFileLogger: $spy,
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent) {
            // expected — assertions below.
        }

        self::assertCount(1, $spy->entries);
        self::assertArrayHasKey('action', $spy->entries[0]['context']);
    }

    public function testMissingFileLogger_DoesNotBreakRender(): void
    {
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: null,
            testPaymentId: null,
            testFileLogger: null,
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(400, $response->statusCode);
        }
    }
}

/**
 * @internal test spy, not shared beyond this file.
 */
final class SpyFileLogger implements FileLoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function log(string $message, array $context = []): void
    {
        $this->entries[] = ['message' => $message, 'context' => $context];
    }
}
