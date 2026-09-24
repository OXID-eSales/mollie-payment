<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Command;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractStateQueryInterface;
use OxidEsales\Payments\Mollie\Adapter\Dto\MolliePaymentDto;
use OxidEsales\Payments\Mollie\Adapter\MolliePaymentsAdapterInterface;
use OxidEsales\Payments\Mollie\Adapter\MollieStatusMapper;
use OxidEsales\Payments\Mollie\Command\ReconcilePaidContractsCommand;
use OxidEsales\Payments\Mollie\Webhook\Handler\FulfillmentOutcome;
use OxidEsales\Payments\Mollie\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReconcilePaidContractsCommand::class)]
final class ReconcilePaidContractsCommandTest extends TestCase
{
    private ContractStateQueryInterface&MockObject $contracts;
    private MolliePaymentsAdapterInterface&MockObject $payments;
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillment;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractStateQueryInterface::class);
        $this->payments = $this->createMock(MolliePaymentsAdapterInterface::class);
        $this->fulfillment = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->tester = new CommandTester(new ReconcilePaidContractsCommand(
            $this->contracts,
            $this->payments,
            new MollieStatusMapper(),
            $this->fulfillment,
        ));
    }

    public function testFulfilsACommittedContractWhoseMolliePaymentIsPaid(): void
    {
        $this->contracts->method('findByStateAndProvider')->with('committed', 'mollie', null)
            ->willReturn([$this->contract('c-1', 'tr_1')]);
        $this->payments->method('getPayment')->with('tr_1')->willReturn($this->payment('paid'));
        $this->fulfillment->expects(self::once())->method('handlePaymentPaid')->with('tr_1')
            ->willReturn(FulfillmentOutcome::Acted);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('1 contract(s) fulfilled', $this->tester->getDisplay());
    }

    public function testLeavesAContractAloneWhenMollieDoesNotSayPaid(): void
    {
        $this->contracts->method('findByStateAndProvider')->willReturn([$this->contract('c-2', 'tr_2')]);
        $this->payments->method('getPayment')->willReturn($this->payment('open'));
        $this->fulfillment->expects(self::never())->method('handlePaymentPaid');

        $this->tester->execute([]);

        self::assertStringContainsString('keep', $this->tester->getDisplay());
        self::assertStringContainsString('0 contract(s) fulfilled', $this->tester->getDisplay());
    }

    public function testDryRunListsWithoutFulfilling(): void
    {
        $this->contracts->method('findByStateAndProvider')->with('committed', 'mollie', 10)
            ->willReturn([$this->contract('c-3', 'tr_3')]);
        $this->payments->method('getPayment')->willReturn($this->payment('paid'));
        $this->fulfillment->expects(self::never())->method('handlePaymentPaid');

        $this->tester->execute(['--dry-run' => true, '--limit' => '10']);

        self::assertStringContainsString('would fulfil', $this->tester->getDisplay());
    }

    public function testSkipsWhenMollieIsUnreachableOrNoPaymentIsLinked(): void
    {
        $this->contracts->method('findByStateAndProvider')
            ->willReturn([$this->contract('c-4', 'tr_4'), $this->contract('c-5', null)]);
        $this->payments->method('getPayment')->willThrowException(new RuntimeException('timeout'));
        $this->fulfillment->expects(self::never())->method('handlePaymentPaid');

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('Mollie unreachable', $this->tester->getDisplay());
        self::assertStringContainsString('no Mollie payment linked', $this->tester->getDisplay());
    }

    private function contract(string $id, ?string $providerOrderId): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getOrderId')->willReturn('order-' . $id);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);

        return $contract;
    }

    private function payment(string $status): MolliePaymentDto
    {
        return MolliePaymentDto::fromArray(['id' => 'tr_x', 'status' => $status, 'amount' => ['value' => '10.00', 'currency' => 'EUR']]);
    }
}
