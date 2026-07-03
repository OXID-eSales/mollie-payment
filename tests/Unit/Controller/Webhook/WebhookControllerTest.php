<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Controller\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookController;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookGuardResult;
use OxidEsales\Payments\Mollie\Controller\Webhook\WebhookRequestGuardInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(WebhookController::class)]
final class WebhookControllerTest extends TestCase
{
    public function testMissingId_Returns400(): void
    {
        $controller = new TestableWebhookController(
            processor: $this->processor(),
            testGuard: null,
            testPaymentId: null,
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(400, $response->statusCode);
            self::assertSame('missing_id', $response->action);
        }
    }

    public function testGuardRejection_ReturnsGuardHttpStatus(): void
    {
        $guard = $this->createMock(WebhookRequestGuardInterface::class);
        $guard->method('check')->willReturn(WebhookGuardResult::reject(429, 'rate_limited'));

        $controller = new TestableWebhookController(
            processor: $this->processor(),
            testGuard: $guard,
            testPaymentId: 'tr_x',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(429, $response->statusCode);
            self::assertSame('rate_limited', $response->action);
        }
    }

    public function testProcessorUnavailable_Returns500(): void
    {
        $controller = new TestableWebhookController(
            processor: null,
            testGuard: null,
            testPaymentId: 'tr_x',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(500, $response->statusCode);
        }
    }

    public function testSuccessfulPaidWebhook_Returns200(): void
    {
        $handler = $this->createMock(MollieWebhookEventHandlerInterface::class);
        $handler->method('handledStatuses')->willReturn(['paid']);
        $handler->method('handle')->willReturn(
            MollieWebhookOutcome::of(WebhookResult::success('contract_fulfilled'), 'contract-1'),
        );

        $controller = new TestableWebhookController(
            processor: $this->processor([$handler]),
            testGuard: null,
            testPaymentId: 'tr_paid',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(200, $response->statusCode);
            self::assertSame('contract_fulfilled', $response->action);
        }
    }

    public function testUnverifiablePayment_Returns400(): void
    {
        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willThrowException(
            new \OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException('not found'),
        );

        $processor = new MollieWebhookProcessor(
            $this->createMock(WebhookLogRepositoryInterface::class),
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            [],
        );

        $controller = new TestableWebhookController(
            processor: $processor,
            testGuard: null,
            testPaymentId: 'tr_unknown',
        );

        try {
            $controller->render();
            self::fail('Expected render() to terminate via sendResponse()');
        } catch (WebhookResponseSent $response) {
            self::assertSame(400, $response->statusCode);
        }
    }

    /**
     * @param list<MollieWebhookEventHandlerInterface> $handlers
     */
    private function processor(array $handlers = []): MollieWebhookProcessor
    {
        $logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $logRepository->method('claimEvent')->willReturn(true);

        $webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $webhookAdapter->method('fetchByWebhookId')->willReturn(new MolliePaymentDto(
            id: 'tr_paid',
            status: 'paid',
            amount: new MollieAmountDto('EUR', 10.0),
        ));

        return new MollieWebhookProcessor(
            $logRepository,
            new NullLogger(),
            $webhookAdapter,
            new MollieStatusMapper(),
            $handlers,
        );
    }
}
