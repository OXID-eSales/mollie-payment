<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook;

use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Exception\MollieAdapterException;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[CoversClass(MollieWebhookProcessor::class)]
final class MollieWebhookProcessorTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private LoggerInterface&MockObject $logger;
    private MollieWebhookAdapterInterface&MockObject $webhookAdapter;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
    }

    public function testGetProviderName_ReturnsMollie(): void
    {
        self::assertSame('mollie', $this->invokeProtected($this->createProcessor(), 'getProviderName'));
    }

    public function testParseAndValidate_FetchesPaymentByIdFromApi(): void
    {
        $this->webhookAdapter->expects(self::once())
            ->method('fetchByWebhookId')
            ->with('tr_WDqYK6vllg')
            ->willReturn($this->paymentDto('tr_WDqYK6vllg', 'paid'));

        $request = $this->webhookRequest('id=tr_WDqYK6vllg');

        $event = $this->invokeProtected($this->createProcessor(), 'parseAndValidateRequest', $request);

        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('tr_WDqYK6vllg', $event->id);
        self::assertSame('paid', $event->type);
    }

    public function testParseAndValidate_WhenApiReturnsNotFound_ThrowsSignatureException(): void
    {
        $this->webhookAdapter->method('fetchByWebhookId')
            ->willThrowException(new MollieAdapterException('not found'));

        $request = $this->webhookRequest('id=tr_unknown');

        $this->expectException(WebhookSignatureException::class);
        $this->invokeProtected($this->createProcessor(), 'parseAndValidateRequest', $request);
    }

    public function testParseAndValidate_WhenIdMissing_ThrowsSignatureException(): void
    {
        $this->webhookAdapter->expects(self::never())->method('fetchByWebhookId');

        $request = $this->webhookRequest('');

        $this->expectException(WebhookSignatureException::class);
        $this->invokeProtected($this->createProcessor(), 'parseAndValidateRequest', $request);
    }

    public function testProcess_WhenApiReturnsNotFound_ReturnsUnverifiedFailure(): void
    {
        $this->webhookAdapter->method('fetchByWebhookId')
            ->willThrowException(new MollieAdapterException('not found'));

        $result = $this->createProcessor()->process($this->webhookRequest('id=tr_unknown'));

        self::assertTrue($result->isFailure());
        self::assertSame('signature_invalid', $result->action);
    }

    public function testProcess_RoutesByMappedStatus(): void
    {
        $this->webhookAdapter->method('fetchByWebhookId')
            ->willReturn($this->paymentDto('tr_paid', 'paid'));
        $this->logRepository->method('claimEvent')->willReturn(true);

        $handler = $this->createMock(MollieWebhookEventHandlerInterface::class);
        $handler->method('handledStatuses')->willReturn(['paid']);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(MollieWebhookOutcome::of(WebhookResult::success('contract_fulfilled'), 'contract-1'));

        $result = $this->createProcessor([$handler])->process($this->webhookRequest('id=tr_paid'));

        self::assertTrue($result->isSuccess());
        self::assertSame('contract_fulfilled', $result->action);
    }

    public function testProcessEvent_ReturnsSkippedWhenNoHandlerSupportsStatus(): void
    {
        $event = new WebhookEvent(id: 'tr_x', type: 'pending', data: ['object' => ['id' => 'tr_x']], created: time());

        $result = $this->invokeProtected($this->createProcessor(), 'processEvent', $event);

        self::assertTrue($result->isSuccess());
        self::assertSame('skipped', $result->action);
        self::assertSame('Unhandled Mollie payment status: pending', $result->error);
    }

    public function testDetermineEventType_PrefersChargebackOverStatus(): void
    {
        $this->webhookAdapter->method('fetchByWebhookId')->willReturn(
            $this->paymentDto('tr_cb', 'paid', amountChargedBack: 10.0)
        );

        $event = $this->invokeProtected(
            $this->createProcessor(),
            'parseAndValidateRequest',
            $this->webhookRequest('id=tr_cb'),
        );

        self::assertSame('chargedback', $event->type);
    }

    public function testDetermineEventType_PrefersRefundedOverStatus(): void
    {
        $this->webhookAdapter->method('fetchByWebhookId')->willReturn(
            $this->paymentDto('tr_rf', 'paid', amountRefunded: 5.0)
        );

        $event = $this->invokeProtected(
            $this->createProcessor(),
            'parseAndValidateRequest',
            $this->webhookRequest('id=tr_rf'),
        );

        self::assertSame('refunded', $event->type);
    }

    /**
     * @param list<MollieWebhookEventHandlerInterface> $handlers
     */
    private function createProcessor(array $handlers = []): MollieWebhookProcessor
    {
        return new MollieWebhookProcessor(
            $this->logRepository,
            $this->logger,
            $this->webhookAdapter,
            new MollieStatusMapper(),
            $handlers,
        );
    }

    private function webhookRequest(string $payload): WebhookRequest
    {
        return new WebhookRequest(
            payload: $payload,
            signature: '',
            remoteIp: '127.0.0.1',
            receivedAt: new \DateTimeImmutable(),
        );
    }

    private function paymentDto(
        string $id,
        string $status,
        float $amountRefunded = 0.0,
        float $amountChargedBack = 0.0,
    ): MolliePaymentDto {
        return new MolliePaymentDto(
            id: $id,
            status: $status,
            amount: new MollieAmountDto('EUR', 19.99),
            amountRefunded: $amountRefunded,
            amountChargedBack: $amountChargedBack,
        );
    }

    private function invokeProtected(MollieWebhookProcessor $processor, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionClass($processor);

        return $reflection->getMethod($method)->invoke($processor, ...$args);
    }
}
