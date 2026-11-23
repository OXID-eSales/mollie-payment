<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\PaymentBase\Payment\CaptureBooking;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\ReplacedAttemptFinderInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Mollie\Webhook\Handler\ClosedContractLateMoney;
use OxidEsales\Payments\Mollie\Adapter\Dto\MollieConfirmedFigures;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use OxidEsales\Payments\Mollie\Webhook\Handler\PaidPaymentFulfiller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sprint 149 (AW): the commit service and the capture ledger save their own copy of the contract, so the
 * fulfiller reads the contract again. When that read found nothing, it continued with - and saved - the
 * copy from before, with no log line.
 */
final class PaidPaymentFulfillerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private ContractFulfillmentServiceInterface&MockObject $fulfillment;
    private ContractCommitServiceInterface&MockObject $commit;
    private ProviderCaptureLedgerInterface&MockObject $captures;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillment = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
        $this->captures = $this->createMock(ProviderCaptureLedgerInterface::class);
        $this->captures->method('book')->willReturn(CaptureBooking::booked(10.0));
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testFulfil_WhenTheContractIsAbsentAfterTheCommit_FailsAndSavesNothing(): void
    {
        $pending = $this->pendingContract();
        $this->contracts->method('findByProviderOrderId')->willReturnOnConsecutiveCalls($pending, null);
        $this->commit->method('commit')->willReturn(CommitOutcome::committed('order-1'));
        $this->contracts->expects(self::never())->method('save');
        $this->fulfillment->expects(self::never())->method('fulfill');
        $this->logger->expects(self::once())->method('error');

        $result = $this->fulfiller()->fulfil('tr_1', new MollieConfirmedFigures(10.0, 'EUR'));

        self::assertSame(FulfillmentOutcome::Failed, $result->outcome, 'Mollie must send the event again');
    }

    public function testFulfil_WhenTheContractIsAbsentAfterTheCaptureBooking_FailsAndSavesNothing(): void
    {
        $committed = $this->committedContract();
        $this->contracts->method('findByProviderOrderId')->willReturnOnConsecutiveCalls($committed, null);
        $this->contracts->expects(self::never())->method('save');
        $this->fulfillment->expects(self::never())->method('fulfill');
        $this->logger->expects(self::once())->method('error');

        $result = $this->fulfiller()->fulfil('tr_1', new MollieConfirmedFigures(10.0, 'EUR'));

        self::assertSame(FulfillmentOutcome::Failed, $result->outcome);
    }

    public function testFulfil_WhenTheReloadFindsTheContract_ContinuesWithTheFreshCopy(): void
    {
        $pending = $this->pendingContract();
        $fresh = $this->committedContract();
        $this->contracts->method('findByProviderOrderId')->willReturnOnConsecutiveCalls($pending, $fresh, $fresh);
        $this->commit->method('commit')->willReturn(CommitOutcome::committed('order-1'));
        $this->contracts->expects(self::once())->method('save')->with($fresh);
        $this->fulfillment->expects(self::once())->method('fulfill')->with($fresh)->willReturn(true);

        $result = $this->fulfiller()->fulfil('tr_1', new MollieConfirmedFigures(10.0, 'EUR'));

        self::assertSame(FulfillmentOutcome::Acted, $result->outcome);
    }

    private function fulfiller(): PaidPaymentFulfiller
    {
        return new PaidPaymentFulfiller(
            $this->contracts,
            $this->fulfillment,
            $this->commit,
            $this->captures,
            new ClosedContractLateMoney(
                $this->createMock(LateMoneyResolverInterface::class),
                $this->createMock(ReplacedAttemptFinderInterface::class)
            ),
            $this->logger,
        );
    }

    private function pendingContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 10.0, 'totalNet' => 8.4, 'totalVat' => 1.6, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('mollie', 'tr_1');

        return $contract;
    }

    private function committedContract(): PaymentContract
    {
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, []);
        $contract->commitToOrder('order-1');

        return $contract;
    }
}
