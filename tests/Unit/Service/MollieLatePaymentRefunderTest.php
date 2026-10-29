<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Service;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\ReplacedAttempts;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieAmountDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieRefundDto;
use OxidEsales\Payments\Mollie\Adapter\Dto\RefundRequest;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieRefundAdapterInterface;
use OxidEsales\Payments\Mollie\Service\MollieLatePaymentRefunder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OPC-175 / S2g — the customer paid a Mollie payment of an attempt they had
 * left for another payment method (Mollie could not cancel it). The money goes
 * back, once, and the contract's history records it.
 */
final class MollieLatePaymentRefunderTest extends TestCase
{
    private ReplacedAttemptFinderInterface&MockObject $finder;
    private MolliePaymentsAdapterInterface&MockObject $payments;
    private MollieRefundAdapterInterface&MockObject $refunds;
    private ContractRepositoryInterface&MockObject $contracts;

    protected function setUp(): void
    {
        $this->finder = $this->createMock(ReplacedAttemptFinderInterface::class);
        $this->payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->refunds = $this->createMock(MollieRefundAdapterInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testSettle_PaymentOfNoReplacedAttempt_IsNotHandledHere(): void
    {
        $this->finder->method('findByReplacedAttempt')->willReturn(null);
        $this->payments->expects($this->never())->method('getPayment');

        self::assertNull($this->refunder()->settleIfReplaced('tr_unknown'));
    }

    public function testSettle_PaidPayment_IsRefundedInFullAndRecorded(): void
    {
        $contract = $this->contractThatReplaced('tr_old');
        $this->finder->method('findByReplacedAttempt')->with('tr_old')->willReturn($contract);
        $this->payments->method('getPayment')->with('tr_old')->willReturn($this->payment('paid'));
        $this->refunds->expects($this->once())->method('createRefund')
            ->with($this->callback(static fn (RefundRequest $r): bool => $r->paymentId === 'tr_old' && $r->amount->value === 42.0))
            ->willReturn(new MollieRefundDto('re_1', 'tr_old', MollieAmountDto::fromArray(['currency' => 'EUR', 'value' => '42.00']), 'pending'));
        $this->contracts->expects($this->once())->method('save')->with($contract);

        self::assertSame('ctr-1', $this->refunder()->settleIfReplaced('tr_old'));
        self::assertSame('refunded:re_1', ReplacedAttempts::lateMoneyIn($contract->getMetadata(ReplacedAttempts::METADATA_KEY), 0));
    }

    public function testSettle_AuthorizedPayment_IsReleasedAndRecorded(): void
    {
        $contract = $this->contractThatReplaced('tr_old');
        $this->finder->method('findByReplacedAttempt')->willReturn($contract);
        $this->payments->method('getPayment')->willReturn($this->payment('authorized'));
        $this->payments->expects($this->once())->method('releaseAuthorization')->with('tr_old');
        $this->refunds->expects($this->never())->method('createRefund');

        $this->refunder()->settleIfReplaced('tr_old');

        self::assertSame('released', ReplacedAttempts::lateMoneyIn($contract->getMetadata(ReplacedAttempts::METADATA_KEY), 0));
    }

    public function testSettle_AlreadySettled_IsNotRefundedTwice(): void
    {
        $contract = $this->contractThatReplaced('tr_old');
        $contract->setMetadata(ReplacedAttempts::METADATA_KEY, ReplacedAttempts::withLateMoney(
            $contract->getMetadata(ReplacedAttempts::METADATA_KEY),
            0,
            'refunded:re_1'
        ));
        $this->finder->method('findByReplacedAttempt')->willReturn($contract);
        $this->payments->expects($this->never())->method('getPayment');
        $this->refunds->expects($this->never())->method('createRefund');

        self::assertSame('ctr-1', $this->refunder()->settleIfReplaced('tr_old'));
    }

    private function refunder(): MollieLatePaymentRefunder
    {
        return new MollieLatePaymentRefunder($this->finder, $this->payments, $this->refunds, $this->contracts, new NullLogger());
    }

    private function payment(string $status): MolliePaymentDto
    {
        return new MolliePaymentDto(
            id: 'tr_old',
            status: $status,
            amount: MollieAmountDto::fromArray(['currency' => 'EUR', 'value' => '42.00']),
        );
    }

    private function contractThatReplaced(string $paymentId): PaymentContract
    {
        $contract = new PaymentContract(1, 'u-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 42.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('mollie', $paymentId);
        $contract->reopenForNewAttempt(AttemptClosure::Open, $contract->getBasketSnapshot(), [ContractCondition::TYPE_PAYMENT_AUTHORIZED]);

        return $contract;
    }
}
