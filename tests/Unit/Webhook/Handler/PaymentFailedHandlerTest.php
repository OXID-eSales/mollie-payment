<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentFailedHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentFailedHandler::class)]
final class PaymentFailedHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentFailedHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->handler = new PaymentFailedHandler($this->fulfillmentHandler, $this->contractRepository);
    }

    public function testHandledStatuses_ReturnsFailed(): void
    {
        self::assertSame(['failed'], $this->handler->handledStatuses());
    }

    public function testFailed_FailsContract(): void
    {
        $this->fulfillmentHandler->expects(self::once())
            ->method('handlePaymentFailed')
            ->with('tr_failed', self::isType('string'))
            ->willReturn(true);
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        $outcome = $this->handler->handle($this->event('tr_failed'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('contract_failed', $outcome->result->action);
    }

    public function testHandle_WhenAlreadyTerminal_IsSkipped(): void
    {
        $this->fulfillmentHandler->method('handlePaymentFailed')->willReturn(false);
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        $outcome = $this->handler->handle($this->event('tr_failed'));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testHandle_WhenPaymentIdMissing_ReturnsFailure(): void
    {
        $this->fulfillmentHandler->expects(self::never())->method('handlePaymentFailed');

        $outcome = $this->handler->handle(new WebhookEvent(id: 'x', type: 'failed', data: [], created: time()));

        self::assertTrue($outcome->result->isFailure());
    }

    private function event(string $paymentId): WebhookEvent
    {
        return new WebhookEvent(
            id: $paymentId,
            type: 'failed',
            data: ['object' => ['id' => $paymentId]],
            created: time(),
        );
    }
}
