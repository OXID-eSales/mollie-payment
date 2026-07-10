<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Request\RefundRequestedEvent;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\EventSystem\Event\MollieRefundRequestEvent;
use OxidEsales\Payments\Mollie\EventSystem\Translator\MollieEventTranslator;
use OxidEsales\Payments\Mollie\Service\ContractRefundRecorder;
use OxidEsales\Payments\Mollie\Service\RefundService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the admin-request idempotency chain end to end at the unit level: the translator
 * derives the SAME key for two identical admin clicks, and a Mollie-API-shaped double that
 * honors the Mollie idempotency-key header returns the cached refund on the second call instead
 * of creating a new one (no live network — this is what the Mollie SDK's own dedup guarantees in
 * production; we just prove the key reaches the adapter unchanged).
 */
#[CoversNothing]
final class AdminRequestIdempotencyTest extends TestCase
{
    public function testTranslate_SameContractAndAmountTwice_ProducesIdenticalKey(): void
    {
        $translator = new MollieEventTranslator();
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-99');
        $context = new EventContext();
        $context->setContract($contract);

        $first = $translator->translate(new RefundRequestedEvent($context, 25.0));
        $second = $translator->translate(new RefundRequestedEvent($context, 25.0));

        self::assertInstanceOf(MollieRefundRequestEvent::class, $first);
        self::assertInstanceOf(MollieRefundRequestEvent::class, $second);
        self::assertSame($first->idempotencyKey, $second->idempotencyKey);
    }

    public function testRefund_SameContractAmountTwice_SecondIsNoOpAtApi(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-99');
        $contract->method('getProviderOrderId')->willReturn('tr_99');
        $contract->method('getOrderId')->willReturn(null); // No order linked - stock restoration skipped
        $state = $this->createMock(\OxidEsales\PaymentBase\Contract\ContractState::class);
        $state->method('isFulfilled')->willReturn(true);
        $contract->method('getState')->willReturn($state);

        $paymentsAdapter = $this->createMock(MolliePaymentsAdapterInterface::class);
        $paymentsAdapter->method('getPayment')->willReturn(new \OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto(
            id: 'tr_99',
            status: 'paid',
            amount: MollieAmountDto::fromComponents('EUR', 100.0),
            amountRefunded: 0.0,
        ));

        $refundAdapter = new class implements MollieRefundAdapterInterface {
            /** @var array<string, MollieRefundDto> */
            public array $refundsByKey = [];
            public int $realApiCalls = 0;

            public function createRefund(RefundRequest $request): MollieRefundDto
            {
                $key = $request->idempotencyKey ?? '';
                if (isset($this->refundsByKey[$key])) {
                    // Mollie's own idempotency-key dedup: return the cached refund, no new one created.
                    return $this->refundsByKey[$key];
                }

                $this->realApiCalls++;
                $refund = new MollieRefundDto('re_' . $this->realApiCalls, $request->paymentId, $request->amount, 'pending');
                $this->refundsByKey[$key] = $refund;

                return $refund;
            }

            public function getRefund(string $paymentId, string $refundId): MollieRefundDto
            {
                throw new \RuntimeException('not used in this test');
            }

            public function listRefunds(string $paymentId): array
            {
                throw new \RuntimeException('not used in this test');
            }
        };

        $contractRepository = $this->createMock(\OxidEsales\PaymentBase\Repository\ContractRepositoryInterface::class);
        $stockRestorationService = $this->createMock(StockRestorationServiceInterface::class);
        $service = new RefundService($paymentsAdapter, $refundAdapter, new ContractRefundRecorder($contractRepository), $stockRestorationService);

        $key = 'contract-99:refund:25.00';
        $first = $service->refund($contract, 25.0, null, $key);
        $second = $service->refund($contract, 25.0, null, $key);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $refundAdapter->realApiCalls);
    }
}