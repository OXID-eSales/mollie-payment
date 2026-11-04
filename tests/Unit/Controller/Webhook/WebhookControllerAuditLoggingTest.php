<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookController;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardResult;
use OxidEsales\PaymentBase\Webhook\Guard\WebhookGuardInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;
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
        $guard = $this->createMock(WebhookGuardInterface::class);
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
            new ThrowableSummary([]),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $controller = new TestableWebhookController(
            processor: $processor,
            testGuard: new PassingWebhookGuard(),
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

    public function testMissingId_IsWrittenWithItsReason(): void
    {
        $spy = new SpyFileLogger();
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: new PassingWebhookGuard(),
            testPaymentId: null,
            testFileLogger: $spy,
        );

        $this->renderUntilResponse($controller);

        self::assertCount(1, $spy->entries);
        self::assertSame('webhook', $spy->entries[0]['message']);
        $this->assertRecord($spy->entries[0]['context'], action: 'missing_id', reason: 'missing_id', httpCode: 400, objectId: null);
    }

    public function testProcessorUnavailable_IsWrittenWithThePaymentIdAndItsReason(): void
    {
        $spy = new SpyFileLogger();
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: new PassingWebhookGuard(),
            testPaymentId: 'tr_x',
            testFileLogger: $spy,
        );

        $this->renderUntilResponse($controller);

        self::assertCount(1, $spy->entries);
        $this->assertRecord($spy->entries[0]['context'], action: 'processor_unavailable', reason: 'processor_unavailable', httpCode: 500, objectId: 'tr_x');
    }

    public function testGuardRejection_IsWrittenAsADeliveryRecord(): void
    {
        $spy = new SpyFileLogger();
        $guard = $this->createMock(WebhookGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::reject(400, 'tls_required'));
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: $guard,
            testPaymentId: 'tr_x',
            testFileLogger: $spy,
        );

        $this->renderUntilResponse($controller);

        $this->assertRecord($spy->entries[0]['context'], action: 'webhook_rejected', reason: 'tls_required', httpCode: 400, objectId: null);
    }

    public function testAnUnavailableGuard_IsWrittenAsADeliveryRecord(): void
    {
        $spy = new SpyFileLogger();
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: null,
            testPaymentId: 'tr_x',
            testFileLogger: $spy,
        );

        $this->renderUntilResponse($controller);

        $this->assertRecord($spy->entries[0]['context'], action: 'webhook_rejected', reason: 'guard_unavailable', httpCode: 503, objectId: null);
    }

    public function testAProcessedDelivery_CarriesThePaymentIdTheContractIdAndTheOutcome(): void
    {
        $spy = new SpyFileLogger();
        $handler = $this->createMock(MollieWebhookEventHandlerInterface::class);
        $handler->method('handledStatuses')->willReturn(['paid']);
        $handler->method('handle')->willReturn(
            MollieWebhookOutcome::of(WebhookResult::failure('fulfillment_failed', 'retry'), 'contract-1'),
        );
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
            new ThrowableSummary([]),
            $webhookAdapter,
            new MollieStatusMapper(),
            [$handler],
        );
        $controller = new TestableWebhookController(
            processor: $processor,
            testGuard: new PassingWebhookGuard(),
            testPaymentId: 'tr_paid',
            testFileLogger: $spy,
        );

        $this->renderUntilResponse($controller);

        $context = $spy->entries[0]['context'];
        $this->assertRecord($context, action: 'fulfillment_failed', reason: 'retry', httpCode: 500, objectId: 'tr_paid');
        self::assertSame('contract-1', $context['contractId']);
        self::assertSame('mollie', $context['provider']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function assertRecord(array $context, string $action, ?string $reason, int $httpCode, ?string $objectId): void
    {
        self::assertSame($action, $context['action']);
        self::assertSame($reason, $context['reason']);
        self::assertSame($httpCode, $context['httpCode']);
        self::assertSame($objectId, $context['objectId']);
        self::assertSame('mollie', $context['provider']);
        self::assertIsInt($context['durationMs']);
    }

    private function renderUntilResponse(TestableWebhookController $controller): void
    {
        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent) {
            // expected
        }
    }

    public function testMissingFileLogger_DoesNotBreakRender(): void
    {
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: new PassingWebhookGuard(),
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
