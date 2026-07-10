<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Mollie\Service\MollieUrlBuilder;
use OxidEsales\Payments\Mollie\Service\TransactionHistoryServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MolliePanelViewDataBuilder::class)]
final class MolliePanelViewDataBuilderTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private TransactionHistoryServiceInterface&MockObject $transactionHistory;
    private AdminActionBoundsInterface&MockObject $bounds;
    private MollieUrlBuilder $urlBuilder;
    private AdminValidationFeedbackInterface&MockObject $validationFeedback;
    private MolliePanelViewDataBuilder $builder;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionHistory = $this->createMock(TransactionHistoryServiceInterface::class);
        $this->bounds = $this->createMock(AdminActionBoundsInterface::class);
        $moduleConfig = $this->createMock(ModuleConfigurationServiceInterface::class);
        $moduleConfig->method('isTestMode')->willReturn(true);
        $this->urlBuilder = new MollieUrlBuilder($moduleConfig);
        $this->validationFeedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $this->validationFeedback->method('consume')->willReturn([]);

        $this->builder = new MolliePanelViewDataBuilder(
            $this->contracts,
            $this->transactionHistory,
            $this->bounds,
            $this->urlBuilder,
            $this->validationFeedback,
        );
    }

    public function testBuild_WithNoContract_ReturnsEmptyViewWithErrorMessage(): void
    {
        $order = $this->stubOrder('order-1');
        $this->contracts->method('findByOrderId')->with('order-1')->willReturn(null);

        $viewData = $this->builder->build($order);

        self::assertSame('order-1', $viewData['orderId']);
        self::assertNotNull($viewData['errorMessage']);
        self::assertFalse($viewData['isRefundable']);
        self::assertFalse($viewData['isCapturable']);
        self::assertSame([], $viewData['transactions']);
    }

    public function testBuild_IncludesCaptureAndRefundBounds(): void
    {
        $order = $this->stubOrder('order-2');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->with($contract)->willReturn(45.5);
        $this->bounds->method('refundBound')->with($contract)->willReturn(0.0);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertSame(45.5, $viewData['captureBound']);
        self::assertSame('45.50', $viewData['captureBoundFormatted']);
        self::assertTrue($viewData['isCapturable']);
        self::assertFalse($viewData['isRefundable'], 'refund bound is 0.0, so refund must not be offered');
    }

    public function testBuild_IncludesOrderNumberAndPaymentType(): void
    {
        $order = $this->stubOrder('order-fields', '4711', 'oe_payments_mollie');
        $contract = $this->authorizedContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(10.0);
        $this->bounds->method('refundBound')->willReturn(0.0);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertSame('4711', $viewData['orderNumber']);
        self::assertSame('oe_payments_mollie', $viewData['paymentType']);
    }

    public function testBuild_FulfilledContractWithRefundBalance_IsRefundable(): void
    {
        $order = $this->stubOrder('order-3');
        $contract = $this->fulfilledContract();
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(0.0);
        $this->bounds->method('refundBound')->willReturn(20.0);
        $this->transactionHistory->method('fetch')->willReturn([]);

        $viewData = $this->builder->build($order);

        self::assertTrue($viewData['isRefundable']);
        self::assertFalse($viewData['isCapturable']);
    }

    // =========================================================================
    // Story 2: View Cache Reset (no-op for Mollie)
    // =========================================================================

    public function testResetViewCache_NoOpDoesNotThrow(): void
    {
        // Mollie reads directly from API on each fetch(), so resetViewCache() is a no-op.
        // This test ensures the method exists and doesn't throw.
        $this->builder->resetViewCache();
        $this->assertTrue(true); // Assert passes if no exception thrown
    }

    private function stubOrder(string $id, string $orderNumber = '', string $paymentType = ''): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getFieldData')->willReturnMap([
            ['oxordernr', $orderNumber],
            ['oxpaymenttype', $paymentType],
        ]);

        return $order;
    }

    private function authorizedContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-1');
        $contract->method('getProviderOrderId')->willReturn('tr_1');
        $contract->method('getStateValue')->willReturn('authorized');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getState')->willReturn(ContractState::authorized());

        return $contract;
    }

    private function fulfilledContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract-2');
        $contract->method('getProviderOrderId')->willReturn('tr_2');
        $contract->method('getStateValue')->willReturn('fulfilled');
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getState')->willReturn(ContractState::fulfilled());

        return $contract;
    }
}
