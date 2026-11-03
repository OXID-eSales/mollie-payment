<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Mollie\Tests\Unit\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Mollie\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Mollie\Admin\MolliePanelOrderLoader;
use OxidEsales\Payments\Mollie\Admin\MolliePanelViewDataBuilder;
use OxidEsales\Payments\Mollie\Admin\MolliePaymentPanelProvider;
use OxidEsales\Payments\Mollie\Admin\ReleaseActionInterface;
use OxidEsales\Payments\Mollie\Core\MollieDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-22 A6: capture, refund and cancel are payment-base's money runner's business (the classic admin controller
 * hands them over before they could reach a panel); what stays here is the panel's view, its reasons, its read-model
 * bounds and Mollie's own release action.
 */
#[CoversClass(MolliePaymentPanelProvider::class)]
final class MolliePaymentPanelProviderTest extends TestCase
{
    private MolliePanelViewDataBuilder&MockObject $viewDataBuilder;
    private TestableMolliePanelOrderLoader $orderLoader;
    private AdminActionBoundsInterface&MockObject $bounds;
    private ReleaseActionInterface&MockObject $releaseAction;
    private MolliePaymentPanelProvider $provider;

    protected function setUp(): void
    {
        $this->viewDataBuilder = $this->createMock(MolliePanelViewDataBuilder::class);
        $this->orderLoader = new TestableMolliePanelOrderLoader();
        $this->bounds = $this->createMock(AdminActionBoundsInterface::class);
        $this->releaseAction = $this->createMock(ReleaseActionInterface::class);

        $this->provider = new MolliePaymentPanelProvider(
            $this->viewDataBuilder,
            $this->orderLoader,
            $this->bounds,
            $this->releaseAction,
        );
    }

    public function testHandleAction_Release_RunsTheReleaseActionAndResetsTheViewCache(): void
    {
        $this->releaseAction->expects(self::once())->method('run')->with('order-rel', 'done capturing');
        $this->viewDataBuilder->expects(self::once())->method('resetViewCache');

        $this->provider->handleAction('release', ['release_reason' => 'done capturing'], new PaymentPanelContext(
            'order-rel',
            MollieDefinitions::PAYMENT_ID,
            null,
        ));
    }

    public function testHandleAction_Release_WithoutAReasonPassesNone(): void
    {
        $this->releaseAction->expects(self::once())->method('run')->with('order-rel', null);

        $this->provider->handleAction('release', ['release_reason' => ''], new PaymentPanelContext('order-rel', MollieDefinitions::PAYMENT_ID, null));
    }

    public function testHandleAction_AnActionThatIsNotThePanelsOwn_DoesNothing(): void
    {
        $this->releaseAction->expects(self::never())->method('run');
        $this->viewDataBuilder->expects(self::never())->method('resetViewCache');

        foreach (['capture', 'refund', 'cancel'] as $moneyAction) {
            $this->provider->handleAction($moneyAction, ['capture_amount' => '10'], new PaymentPanelContext('o', MollieDefinitions::PAYMENT_ID, null));
        }
    }

    public function testActionOptions_AreTheReasonsAndLimitsOfThePanelsForm(): void
    {
        $options = $this->provider->actionOptions($this->createStub(PaymentContractInterface::class));

        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent'], $options->refundReasons);
        self::assertSame(['requested_by_customer', 'duplicate', 'fraudulent', 'abandoned'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
        self::assertSame(140, $options->reasonMaxLength);
    }

    public function testBoundsFor_AreTheBoundsTheClassicPanelShows(): void
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $expected = new AdminActionBounds(60.0, 12.5);
        $this->bounds->expects(self::once())->method('boundsFor')->with($contract)->willReturn($expected);

        self::assertSame($expected, $this->provider->boundsFor($contract));
    }

    public function testTheProviderImplementsPaymentBasesOptionalProviderInterfaces(): void
    {
        self::assertInstanceOf(AdminActionOptionsProviderInterface::class, $this->provider);
        self::assertInstanceOf(AdminActionBoundsProviderInterface::class, $this->provider);
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

    public function testBuild_WhenNoLiveOrderResolves_DegradesToAnEmptyView(): void
    {
        $this->orderLoader->order = null;

        $context = new PaymentPanelContext('missing-order', MollieDefinitions::PAYMENT_ID, null);
        $renderable = $this->provider->build($context);

        self::assertSame([], $renderable->viewData);
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
