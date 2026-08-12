<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentAuthorizedHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PaymentAuthorizedHandler::class)]
final class PaymentAuthorizedHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentAuthorizedHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->handler = new PaymentAuthorizedHandler($this->fulfillmentHandler, $this->contractRepository, new NullLogger());
    }

    public function testHandledStatuses_ReturnsAuthorized(): void
    {
        self::assertSame(['authorized'], $this->handler->handledStatuses());
    }

    public function testAuthorized_AdvancesPendingOrNotFinishedContractToAuthorized_AndRecordsTransaction(): void
    {
        $this->fulfillmentHandler->expects(self::once())
            ->method('handlePaymentAuthorized')
            ->with('tr_authorized')
            ->willReturn(FulfillmentOutcome::Acted);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $this->contractRepository->method('findByProviderOrderId')->with('tr_authorized')->willReturn($contract);

        $outcome = $this->handler->handle($this->event('tr_authorized'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('contract_authorized', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    public function testAuthorized_WhenAlreadyAuthorizedOrPastIt_IsNoOp(): void
    {
        $this->fulfillmentHandler->method('handlePaymentAuthorized')->with('tr_authorized')->willReturn(FulfillmentOutcome::NoOp);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $this->contractRepository->method('findByProviderOrderId')->with('tr_authorized')->willReturn($contract);

        $outcome = $this->handler->handle($this->event('tr_authorized'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('skipped', $outcome->result->action);
        self::assertSame('contract-1', $outcome->contractId);
    }

    public function testHandle_WhenContractNotFound_ReturnsSkipped(): void
    {
        $this->fulfillmentHandler->method('handlePaymentAuthorized')->willReturn(FulfillmentOutcome::ContractNotFound);
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        $outcome = $this->handler->handle($this->event('tr_missing'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('skipped', $outcome->result->action);
        self::assertNull($outcome->contractId);
    }

    public function testHandle_WhenPaymentIdMissing_ReturnsFailure(): void
    {
        $this->fulfillmentHandler->expects(self::never())->method('handlePaymentAuthorized');

        $outcome = $this->handler->handle(new WebhookEvent(id: 'x', type: 'authorized', data: [], created: time()));

        self::assertTrue($outcome->result->isFailure());
    }

    private function event(string $paymentId): WebhookEvent
    {
        return new WebhookEvent(
            id: $paymentId,
            type: 'authorized',
            data: ['object' => ['id' => $paymentId]],
            created: time(),
        );
    }
}
