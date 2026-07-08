<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Command;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Command\ReconcileOxpaidCommand;
use OxidEsales\Payments\Mollie\Service\OxpaidReconciliationServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReconcileOxpaidCommand::class)]
final class ReconcileOxpaidCommandTest extends TestCase
{
    private OxpaidReconciliationServiceInterface&MockObject $reconciliation;
    private ContractRepositoryInterface&MockObject $contracts;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->reconciliation = $this->createMock(OxpaidReconciliationServiceInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->tester = new CommandTester(
            new ReconcileOxpaidCommand($this->reconciliation, $this->contracts),
        );
    }

    public function testExecute_WhenNoContract_FailsWithoutTouchingService(): void
    {
        $this->contracts->method('findByOrderId')->with('order-1')->willReturn(null);
        $this->reconciliation->expects(self::never())->method('reconcile');

        $exit = $this->tester->execute(['orderId' => 'order-1']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('No Mollie payment', $this->tester->getDisplay());
    }

    public function testExecute_WhenContractHasNoProviderOrderId_Fails(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);
        $this->contracts->method('findByOrderId')->with('order-2')->willReturn($contract);
        $this->reconciliation->expects(self::never())->method('reconcile');

        $exit = $this->tester->execute(['orderId' => 'order-2']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testExecute_WhenHealed_ReportsSuccess(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_1');
        $this->contracts->method('findByOrderId')->with('order-3')->willReturn($contract);
        $this->reconciliation->expects(self::once())
            ->method('reconcile')
            ->with('order-3', 'tr_1')
            ->willReturn(true);

        $exit = $this->tester->execute(['orderId' => 'order-3']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('healed', $this->tester->getDisplay());
    }

    public function testExecute_WhenConsistent_ReportsNoOpAsSuccess(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('tr_2');
        $this->contracts->method('findByOrderId')->with('order-4')->willReturn($contract);
        $this->reconciliation->method('reconcile')->with('order-4', 'tr_2')->willReturn(false);

        $exit = $this->tester->execute(['orderId' => 'order-4']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Nothing to reconcile', $this->tester->getDisplay());
    }
}
