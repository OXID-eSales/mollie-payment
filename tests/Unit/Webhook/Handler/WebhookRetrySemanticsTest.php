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
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaymentPaidHandler;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint 11 Stories 1 + 3 (F2) — "nothing to do" and "we failed to do it" must not share one
 * HTTP answer.
 *
 * Mollie retries only on a non-2xx response. `WebhookResult::skipped()` sets `success = true`, so
 * every skip was answered `200 OK` and never retried — including a contract that could not be
 * committed and a contract that was not visible yet. `PaymentPaidHandler`'s own skip reason gave
 * the conflation away: *"Contract already fulfilled or could not be committed"*, two outcomes and
 * one response.
 *
 * These tests pin the four distinct cases the {@see FulfillmentOutcome} enum now expresses.
 */
#[CoversClass(PaymentPaidHandler::class)]
#[Group('F2')]
final class WebhookRetrySemanticsTest extends TestCase
{
    private const PAYMENT_ID = 'tr_semantics';

    public function testFulfilmentThatCouldNotCompleteIsAFailureSoMollieRetries(): void
    {
        $outcome = $this->handle(FulfillmentOutcome::Failed);

        self::assertTrue($outcome->result->isFailure(), 'a failed fulfilment must be retried by Mollie');
        self::assertNotSame('skipped', $outcome->result->action);
    }

    public function testAlreadyFulfilledContractStaysASuccessfulSkip(): void
    {
        $outcome = $this->handle(FulfillmentOutcome::NoOp);

        self::assertTrue($outcome->result->isSuccess(), 'a genuine no-op must not trigger a retry');
        self::assertSame('skipped', $outcome->result->action);
    }

    public function testActedIsSuccess(): void
    {
        $outcome = $this->handle(FulfillmentOutcome::Acted);

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('contract_fulfilled', $outcome->result->action);
    }

    /**
     * Story 3 / D3: a webhook can beat our own commit, so a missing contract is retry-worthy —
     * but only while the payment is young enough that our transaction plausibly has not landed.
     */
    public function testMissingContractOnAYoungPaymentIsRetried(): void
    {
        $outcome = $this->handle(FulfillmentOutcome::ContractNotFound, createdAt: '-30 seconds');

        self::assertTrue($outcome->result->isFailure(), 'a fresh payment whose contract is not visible yet must be retried');
    }

    /**
     * Unbounded retries on a payment that will never have a contract in THIS shop (created by
     * another system on the same Mollie account, contract deleted by hand, restored backup) is a
     * self-inflicted retry storm. Past the window it becomes terminal.
     */
    #[DataProvider('terminalAges')]
    public function testMissingContractOnAnOldOrUndatedPaymentIsTerminal(?string $createdAt): void
    {
        $outcome = $this->handle(FulfillmentOutcome::ContractNotFound, createdAt: $createdAt);

        self::assertTrue($outcome->result->isSuccess(), 'must not retry forever');
        self::assertSame('skipped', $outcome->result->action);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function terminalAges(): array
    {
        return [
            'well past the window' => ['-2 hours'],
            'just past the window' => ['-11 minutes'],
            'createdAt absent' => [null],
            'createdAt unparseable' => ['not-a-date'],
        ];
    }

    public function testBoundaryIsInclusiveOfTheWindow(): void
    {
        $stillRetried = $this->handle(FulfillmentOutcome::ContractNotFound, createdAt: '-9 minutes');

        self::assertTrue($stillRetried->result->isFailure());
    }

    private function handle(FulfillmentOutcome $outcome, ?string $createdAt = '-1 second'): \OxidEsales\Payments\Mollie\Webhook\MollieWebhookOutcome
    {
        $fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $fulfillmentHandler->method('handlePaymentPaid')->willReturn($outcome);

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = new PaymentPaidHandler($fulfillmentHandler, $contractRepository, new NullLogger());

        return $handler->handle($this->event($createdAt));
    }

    private function event(?string $createdAt): WebhookEvent
    {
        $object = ['id' => self::PAYMENT_ID, 'status' => 'paid'];
        if ($createdAt !== null) {
            $object['createdAt'] = $createdAt === 'not-a-date'
                ? 'not-a-date'
                : (new \DateTimeImmutable($createdAt))->format(DATE_ATOM);
        }

        return new WebhookEvent(
            id: self::PAYMENT_ID . ':paid',
            type: 'paid',
            data: ['object' => $object],
            created: time(),
        );
    }
}
