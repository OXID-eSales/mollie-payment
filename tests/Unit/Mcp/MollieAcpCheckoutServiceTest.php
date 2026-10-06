<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Mcp;

use OxidEsales\PaymentBase\Checkout\Headless\ContractOpeningServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\GuestUserResolverInterface;
use OxidEsales\PaymentBase\Checkout\Headless\UserBasketFactoryInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentBase\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentBase\Mcp\AgentContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use OxidEsales\Payments\Mollie\Mcp\MollieAcpCheckoutService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GRAPH-QL / MS5 — Mollie's ACP checkout service: create_checkout is the base
 * class's default with the Mollie payment id; complete_checkout is refused with
 * a reason the agent can act on (Mollie has no server-side token charge), and
 * nothing is committed.
 */
final class MollieAcpCheckoutServiceTest extends TestCase
{
    private AcpResponseFormatterInterface&MockObject $formatter;
    private ContractRepositoryInterface&MockObject $contracts;
    private ContractCommitServiceInterface&MockObject $commit;
    private PaymentContract $contract;

    protected function setUp(): void
    {
        $this->formatter = $this->createMock(AcpResponseFormatterInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
        $this->contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 30.9, 'totalNet' => 25.97, 'totalVat' => 4.93, 'currency' => 'EUR',
        ]), 'ctr-1');
        $this->contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $this->contract->transitionToNotFinished('order-1');
        $this->contract->transitionToPending();
        $this->contracts->method('findById')->with('ctr-1')->willReturn($this->contract);
        $this->formatter->method('validationError')->willReturnCallback(
            static fn(string $message, ?string $param = null): array => ['error' => ['message' => $message, 'param' => $param]]
        );
    }

    public function testIsTheAcpCheckoutServiceForMollie(): void
    {
        self::assertInstanceOf(AcpCheckoutServiceInterface::class, $this->service());
    }

    public function testCreateCheckoutUsesTheBaseDefaultWithTheMolliePaymentId(): void
    {
        $buyers = $this->createMock(GuestUserResolverInterface::class);
        $buyers->method('resolve')->willReturn('user-1');
        $baskets = $this->createMock(UserBasketFactoryInterface::class);
        $baskets->expects($this->once())->method('create')
            ->with('user-1', [['id' => 'art-1', 'quantity' => 1]], MollieDefinitions::PAYMENT_ID)
            ->willReturn('ub-1');
        $opening = $this->createMock(ContractOpeningServiceInterface::class);
        $opening->expects($this->once())->method('open')
            ->with('user-1', 'ub-1', MollieDefinitions::PAYMENT_ID, 'acp')
            ->willReturn($this->contract);
        $this->formatter->method('formatCheckout')->willReturn(['id' => 'ctr-1', 'status' => 'ready_for_payment']);

        $result = $this->service($opening, $baskets, $buyers)->createCheckout(
            ['items' => [['id' => 'art-1', 'quantity' => 1]], 'buyer' => ['email' => 'a@example.com']],
            new AgentContext('agent-1', 'tok')
        );

        self::assertSame('ctr-1', $result['id']);
    }

    public function testCompleteCheckoutIsRefusedWithTheReasonAndCommitsNothing(): void
    {
        $this->commit->expects($this->never())->method('commit');

        $result = $this->service()->completeCheckout('ctr-1', ['token' => 'tok_card'], new AgentContext('agent-1', 'tok'));

        self::assertSame(MollieAcpCheckoutService::COMPLETE_NOT_SUPPORTED, $result['error']['message']);
        self::assertSame('payment_data.token', $result['error']['param']);
        self::assertStringContainsString('mollieCheckoutStart', $result['error']['message']);
        self::assertTrue($this->contract->getState()->isPending(), 'the contract stays open for the hosted checkout');
    }

    private function service(
        ?ContractOpeningServiceInterface $opening = null,
        ?UserBasketFactoryInterface $baskets = null,
        ?GuestUserResolverInterface $buyers = null
    ): MollieAcpCheckoutService {
        return new MollieAcpCheckoutService(
            $this->createMock(ContractServiceInterface::class),
            $this->contracts,
            $this->createMock(EventDispatcherInterface::class),
            $this->formatter,
            $opening,
            $baskets,
            $buyers,
            $this->commit
        );
    }
}
