<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentCanceledHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentExpiredHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentExpiredHandler::class)]
#[CoversClass(PaymentCanceledHandler::class)]
final class PaymentExpiredCanceledHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);
    }

    public function testExpired_ExpiresContract(): void
    {
        $handler = new PaymentExpiredHandler($this->fulfillmentHandler, $this->contractRepository);

        self::assertSame(['expired'], $handler->handledStatuses());

        $this->fulfillmentHandler->expects(self::once())
            ->method('handlePaymentExpired')
            ->with('tr_expired')
            ->willReturn(true);

        $outcome = $handler->handle($this->event('tr_expired', 'expired'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('contract_expired', $outcome->result->action);
    }

    public function testExpired_WhenAlreadyTerminal_IsSkipped(): void
    {
        $handler = new PaymentExpiredHandler($this->fulfillmentHandler, $this->contractRepository);
        $this->fulfillmentHandler->method('handlePaymentExpired')->willReturn(false);

        $outcome = $handler->handle($this->event('tr_expired', 'expired'));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testCanceled_CancelsContract(): void
    {
        $handler = new PaymentCanceledHandler($this->fulfillmentHandler, $this->contractRepository);

        self::assertSame(['canceled'], $handler->handledStatuses());

        $this->fulfillmentHandler->expects(self::once())
            ->method('handlePaymentCanceled')
            ->with('tr_canceled', self::isType('string'))
            ->willReturn(true);

        $outcome = $handler->handle($this->event('tr_canceled', 'canceled'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('contract_canceled', $outcome->result->action);
    }

    public function testCanceled_WhenAlreadyTerminal_IsSkipped(): void
    {
        $handler = new PaymentCanceledHandler($this->fulfillmentHandler, $this->contractRepository);
        $this->fulfillmentHandler->method('handlePaymentCanceled')->willReturn(false);

        $outcome = $handler->handle($this->event('tr_canceled', 'canceled'));

        self::assertSame('skipped', $outcome->result->action);
    }

    private function event(string $paymentId, string $type): WebhookEvent
    {
        return new WebhookEvent(
            id: $paymentId,
            type: $type,
            data: ['object' => ['id' => $paymentId]],
            created: time(),
        );
    }
}
