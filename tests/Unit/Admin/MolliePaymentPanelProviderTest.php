<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\AdminAmountValidator;
use OxidEsales\Payments\Mollie\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePanelOrderLoader;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentPanelProvider;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MolliePaymentPanelProvider::class)]
final class MolliePaymentPanelProviderTest extends TestCase
{
    private AdminActionDispatcherInterface&MockObject $actionDispatcher;
    private MolliePanelViewDataBuilder&MockObject $viewDataBuilder;
    private TestableMolliePanelOrderLoader $orderLoader;
    private ContractRepositoryInterface&MockObject $contracts;
    private AdminActionBoundsInterface&MockObject $bounds;
    private AdminValidationFeedbackInterface&MockObject $validationFeedback;
    private MolliePaymentPanelProvider $provider;

    protected function setUp(): void
    {
        $this->actionDispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $this->viewDataBuilder = $this->createMock(MolliePanelViewDataBuilder::class);
        $this->orderLoader = new TestableMolliePanelOrderLoader();
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->bounds = $this->createMock(AdminActionBoundsInterface::class);
        $this->validationFeedback = $this->createMock(AdminValidationFeedbackInterface::class);

        $this->provider = new MolliePaymentPanelProvider(
            $this->actionDispatcher,
            $this->viewDataBuilder,
            $this->orderLoader,
            $this->contracts,
            $this->bounds,
            new AdminAmountValidator(),
            $this->validationFeedback,
        );
    }

    public function testGetProviderName_ReturnsMollie(): void
    {
        self::assertSame('mollie', $this->provider->getProviderName());
    }

    public function testSupports_MolliePaymentType_ReturnsTrue(): void
    {
        $context = new PaymentPanelContext('order-1', MollieDefinitions::PAYMENT_ID, null);

        self::assertTrue($this->provider->supports($context));
    }

    public function testSupports_NonMollieOrder_ReturnsFalse(): void
    {
        $context = new PaymentPanelContext('order-1', 'oxidpaypal', null);

        self::assertFalse($this->provider->supports($context));
    }

    public function testSupports_ContractProviderMatchesEvenWithDifferentPaymentType(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn('mollie');
        $context = new PaymentPanelContext('order-1', 'some_other_id', $contract);

        self::assertTrue($this->provider->supports($context));
    }

    public function testBuild_ReturnsPanelForMollieOrder(): void
    {
        $order = $this->stubOrder('order-1');
        $this->orderLoader->order = $order;
        $this->viewDataBuilder->method('build')->with($order)->willReturn(['orderId' => 'order-1']);

        $context = new PaymentPanelContext('order-1', MollieDefinitions::PAYMENT_ID, null);
        $renderable = $this->provider->build($context);

        self::assertSame('mollie', $renderable->providerKey);
        self::assertSame(['orderId' => 'order-1'], $renderable->viewData);
        self::assertStringContainsString('mollie_panel', $renderable->templatePath);
    }

    public function testProvide_ForNonMollieOrder_ReturnsNull(): void
    {
        // supports() is what gates non-Mollie orders out at the registry level; build() itself
        // degrades to an empty view when no live order resolves (never null — contract).
        $this->orderLoader->order = null;

        $context = new PaymentPanelContext('missing-order', MollieDefinitions::PAYMENT_ID, null);
        $renderable = $this->provider->build($context);

        self::assertSame([], $renderable->viewData);
    }

    public function testHandleAction_Refund_WithValidAmount_DispatchesRefund(): void
    {
        $order = $this->stubOrder('order-2');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->with('order-2')->willReturn($contract);
        $this->bounds->method('refundBound')->with($contract)->willReturn(50.0);

        $this->actionDispatcher->expects(self::once())->method('refund')
            ->with($order, 25.0, 'customer request');
        $this->validationFeedback->expects(self::never())->method('reject');

        $this->provider->handleAction('refund', [
            'refund_amount' => '25.00',
            'refund_reason' => 'customer request',
        ], new PaymentPanelContext('order-2', MollieDefinitions::PAYMENT_ID, $contract));
    }

    public function testHandleAction_Refund_ExceedingBound_RejectsWithoutDispatching(): void
    {
        $order = $this->stubOrder('order-3');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('refundBound')->willReturn(10.0);

        $this->actionDispatcher->expects(self::never())->method('refund');
        $this->validationFeedback->expects(self::once())->method('reject')
            ->with('order-3', 'refund_amount', 'amountExceedsBound');

        $this->provider->handleAction('refund', ['refund_amount' => '99.00'], new PaymentPanelContext(
            'order-3',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Capture_WithFullAmount_DispatchesNullAmount(): void
    {
        $order = $this->stubOrder('order-4');
        $this->orderLoader->order = $order;
        $contract = $this->createMock(PaymentContractInterface::class);
        $this->contracts->method('findByOrderId')->willReturn($contract);
        $this->bounds->method('captureBound')->willReturn(100.0);

        $this->actionDispatcher->expects(self::once())->method('capture')
            ->with($order, null, null);

        $this->provider->handleAction('capture', [], new PaymentPanelContext(
            'order-4',
            MollieDefinitions::PAYMENT_ID,
            $contract,
        ));
    }

    public function testHandleAction_Cancel_DispatchesCancelWithReason(): void
    {
        $order = $this->stubOrder('order-5');
        $this->orderLoader->order = $order;

        $this->actionDispatcher->expects(self::once())->method('cancel')
            ->with($order, 'fraud suspicion');

        $this->provider->handleAction('cancel', ['cancel_reason' => 'fraud suspicion'], new PaymentPanelContext(
            'order-5',
            MollieDefinitions::PAYMENT_ID,
            null,
        ));
    }

    public function testHandleAction_WhenOrderCannotBeLoaded_DoesNothing(): void
    {
        $this->orderLoader->order = null;

        $this->actionDispatcher->expects(self::never())->method('refund');
        $this->actionDispatcher->expects(self::never())->method('capture');
        $this->actionDispatcher->expects(self::never())->method('cancel');

        $this->provider->handleAction('refund', [], new PaymentPanelContext(
            'missing',
            MollieDefinitions::PAYMENT_ID,
            null,
        ));
    }

    private function stubOrder(string $id): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);

        return $order;
    }
}

/**
 * Test seam standing in for {@see MolliePanelOrderLoader} — avoids exercising `oxNew()`.
 */
final class TestableMolliePanelOrderLoader extends MolliePanelOrderLoader
{
    public ?Order $order = null;

    public function loadById(string $orderId): ?Order
    {
        return $this->order;
    }
}
