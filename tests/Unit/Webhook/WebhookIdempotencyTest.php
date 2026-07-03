<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Adapter\MollieWebhookAdapterInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookEventHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome;
use OxidEsales\Payments\Mollie\Webhook\MollieWebhookProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Story 3 — idempotency claim via oe_payments_idempotency (payment-base's WebhookLogRepository).
 *
 * AbstractWebhookProcessor::process() owns the atomic claim; these tests prove the processor
 * wires it correctly: first delivery claims and processes, a replay is a no-op that reports
 * "duplicate" without invoking any status handler a second time.
 */
#[CoversClass(MollieWebhookProcessor::class)]
final class WebhookIdempotencyTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private MollieWebhookAdapterInterface&MockObject $webhookAdapter;
    private MollieWebhookEventHandlerInterface&MockObject $handler;
    private MollieWebhookProcessor $processor;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->webhookAdapter = $this->createMock(MollieWebhookAdapterInterface::class);
        $this->webhookAdapter->method('fetchByWebhookId')->willReturn(new MolliePaymentDto(
            id: 'tr_dupe',
            status: 'paid',
            amount: new MollieAmountDto('EUR', 10.0),
        ));

        $this->handler = $this->createMock(MollieWebhookEventHandlerInterface::class);
        $this->handler->method('handledStatuses')->willReturn(['paid']);

        $this->processor = new MollieWebhookProcessor(
            $this->logRepository,
            $this->createMock(LoggerInterface::class),
            $this->webhookAdapter,
            new MollieStatusMapper(),
            [$this->handler],
        );
    }

    public function testFirstDelivery_ClaimsAndProcesses(): void
    {
        $this->logRepository->method('claimEvent')->with('tr_dupe', 'mollie', 'paid')->willReturn(true);
        $this->handler->expects(self::once())
            ->method('handle')
            ->willReturn(MollieWebhookOutcome::of(WebhookResult::success('contract_fulfilled'), 'contract-1'));

        $result = $this->processor->process($this->request());

        self::assertTrue($result->isSuccess());
        self::assertSame('contract_fulfilled', $result->action);
    }

    public function testReplay_SecondDelivery_IsNoOpReturnsDuplicate(): void
    {
        $this->logRepository->method('claimEvent')->willReturn(false);
        $this->handler->expects(self::never())->method('handle');

        $result = $this->processor->process($this->request());

        self::assertTrue($result->isSuccess());
        self::assertSame('skipped', $result->action);
    }

    private function request(): WebhookRequest
    {
        return new WebhookRequest(
            payload: 'id=tr_dupe',
            signature: '',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable(),
        );
    }
}
